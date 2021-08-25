<?php

namespace Jaulz\Eloquence\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Eloquence\Exceptions\InvalidRelationException;
use Jaulz\Eloquence\Exceptions\UnableToCacheException;
use Jaulz\Eloquence\Exceptions\UnableToPropagateException;
use ReflectionProperty;

class Cache
{
  /**
   * @var Model
   */
  private Model $model;

  /**
   * @var ?Model
   */
  private ?Model $pivotModel;

  /**
   * @var array<string>
   */
  private $propagatedBy;

  /**
   * @var array
   */
  private $configurations;

  /**
   * @var array
   */
  private $foreignConfigurations;

  /**
   * @param Model $model
   */
  public function __construct(Model $model, $propagatedBy = [])
  {
    $this->model = $model->pivotParent ?? $model;
    $this->pivotModel = $model->pivotParent ? $model : null;
    $this->foreignConfigurations = get_class($this->model)::getForeignCacheConfigurations();
    $this->propagatedBy = $propagatedBy;
    $this->configurations = collect(get_class($this->model)::getCacheConfigurations())
      ->map(function ($configuration) {
        return $this->prepareConfiguration($this->model, $configuration);
      })
      ->filter(function ($configuration) {
        return !empty($this->propagatedBy)
          ? collect($this->propagatedBy)->contains($configuration['valueName'])
          : true;
      });
  }

  /**
   * Take a user configuration and standardize it.
   *
   * @param Model $model
   * @param array $configuration
   * @param bool $checkForeignModel
   * @return array
   */
  protected static function prepareConfiguration(
    $model,
    $configuration,
    ?bool $checkForeignModel = true
  ) {
    $modelName = $model instanceof Model ? get_class($model) : $model;

    // Merge defaults and actual configuration
    $defaults = [
      'function' => 'count',
      'value' => 'id',
      'key' => 'id',
      'where' => [],
      'context' => null,
      'relation' => null,
      'ignore_empty_foreign_keys' => false,
      'propagate' => false,
    ];
    $configuration = array_merge($defaults, $configuration);

    // In case we have a relation field we can easily fill the required fields
    if ($configuration['relation']) {
      $relationName = $configuration['relation'];
      $relation = (new $model())->{$relationName}();
      if (!($relation instanceof Relation)) {
        throw new InvalidRelationException('The specified relation "' . $configuration['relation'] . '" does not inherit from "' . Relation::class . '".');
      }
      $configuration['foreign_model'] = $relation->getRelated();

      // Handle relations differently
      if ($relation instanceof BelongsTo) {
        if ($relation instanceof MorphTo) {
          $morphType = $relation->getMorphType();

          $configuration['foreign_model'] = $model[$morphType];
          $configuration['foreign_key'] = $relation->getForeignKeyName();
        } else if ($relation instanceof MorphToMany) {
          $configuration['foreign_model'] = $relation->getRelated();
          $configuration['foreign_key'] = $relation->getForeignKeyName();
          $configuration['key'] = $relation->getOwnerKeyName();
        }
      } else if ($relation instanceof HasOneOrMany) {
        $configuration['foreign_model'] = $relation->getRelated();
      } else if ($relation instanceof MorphToMany) {
        $relatedPivotKeyName = $relation->getRelatedPivotKeyName();
        $foreignPivotKeyName = $relation->getForeignPivotKeyName();
        $parentKeyName = $relation->getParentKeyName();
        $pivotClass = $relation->getPivotClass();
        $morphClass = $relation->getMorphClass();
        $morphType = $relation->getMorphType();

        $configuration['foreign_model'] = $relation->getRelated();
        $configuration['ignore_empty_foreign_keys'] = true;
        $configuration['foreign_key'] = [
          $relation->getParentKeyName(),
          function ($key, $model, $pivotModel) use ($pivotClass, $morphClass, $morphType, $foreignPivotKeyName, $relatedPivotKeyName) {
            if ($pivotModel) {
              return $pivotModel[$relatedPivotKeyName];
            }
            
            $keys = $pivotClass::where($foreignPivotKeyName, $key)->where($morphType, $morphClass)->pluck($relatedPivotKeyName);
            return $keys;
          },
          function ($query, $foreignKey) use ($modelName, $parentKeyName, $relationName, $relatedPivotKeyName) {
            $keys = $modelName::whereHas($relationName, function ($query) use ($foreignKey, $relatedPivotKeyName) {
              return $query->where($relatedPivotKeyName, $foreignKey);
            })->pluck($parentKeyName);
            $query->whereIn($parentKeyName, $keys);
          },
        ];
      } else {
        dd($relation);
      }
    }

    // Adjust configuration
    $foreignModelName = $configuration['foreign_model'] instanceof Model ? get_class($configuration['foreign_model']) : $configuration['foreign_model'];
    $ignoreEmptyForeignKeys = $configuration['ignore_empty_foreign_keys'] || $foreignModelName === $modelName;
    $function = Str::lower($configuration['function']);
    $summaryName = Str::snake($configuration['summary'] ?? static::generateFieldName(Str::plural($modelName), $function));
    $keyName = Str::snake(static::getKeyName($modelName, $configuration['key']));
    $table = static::getModelTable($foreignModelName);

    $foreignKey = $configuration['foreign_key'] ?? static::generateFieldName($foreignModelName, 'id');
    $foreignKeyName = Str::snake(
      static::getKeyName(
        $modelName,
        is_array($foreignKey)
          ? $foreignKey[0]
          : $foreignKey
      )
    );
    $extractForeignKeys = is_array($foreignKey)
      ? $foreignKey[1] : function ($key) {
        return $key;
      };
    $selectForeignKeys = is_array($foreignKey)
      ? $foreignKey[2] : function ($query, $foreignKey) use ($foreignKeyName) {
        $query->where($foreignKeyName, $foreignKey);
      };

    // Check if we need to propagate changes by checking if the foreign model is also cacheable
    $propagate = $configuration['propagate'];
    if ($checkForeignModel) {
      if (!method_exists($foreignModelName, 'bootIsCacheableTrait')) {
        throw new UnableToCacheException(
          'Referenced model "' .
            $configuration['foreign_model'] .
            '" must use IsCacheableTrait trait.'
        );
      }

      $foreignConfiguration = $foreignModelName::getCacheConfigurations();

      $propagate = collect($foreignConfiguration)->some(function (
        $foreignConfiguration
      ) use ($foreignModelName, $summaryName) {
        $foreignConfiguration = static::prepareConfiguration(
          $foreignModelName,
          $foreignConfiguration,
          false
        );
        $propagate =
          $summaryName === $foreignConfiguration['valueName'];

        return $propagate;
      });
    }

    return [
      'function' => $function,
      'foreignModelName' => $foreignModelName,
      'table' =>  $table,
      'summaryName' => $summaryName,
      'valueName' => $configuration['value'],
      'keyName' => $keyName,
      'foreignKeyName' => $foreignKeyName,
      'extractForeignKeys' => $extractForeignKeys,
      'selectForeignKeys' => $selectForeignKeys,
      'ignoreEmptyForeignKeys' => $ignoreEmptyForeignKeys,
      'where' => $configuration['where'],
      'propagate' => $propagate,
      'getContext' => $configuration['context'],
    ];
  }

  /**
   * Rebuild the count caches from the database
   *
   * @param array $foreignConfigurations
   * @return array
   */
  public function rebuild()
  {
    // Prepare all update statements
    $valueColumns = collect([]);
    $updates = collect([]);

    collect($this->foreignConfigurations)->each(function (
      $configurations,
      $foreignModelName
    ) use ($valueColumns, $updates) {
      collect($configurations)
        ->map(function ($configuration) use ($foreignModelName, $valueColumns) {
          // Normalize config
          $configuration = static::prepareConfiguration($foreignModelName, $configuration);

          // Collect all value columns
          $valueColumns->push($configuration['valueName']);

          return $configuration;
        })
        ->each(function ($configuration) use ($foreignModelName, $updates) {
          $summaryName = $configuration['summaryName'];
          $keyName = $configuration['keyName'];
          $function = $configuration['function'];
          $key = $this->model[$keyName];

          // Get query that retrieves the summary value
          $cacheQuery = static::prepareCacheQuery(
            $foreignModelName,
            $configuration
          );
          $configuration['selectForeignKeys']($cacheQuery, $key, $this->model, $this->pivotModel);
          $sql = '(' . Cache::convertQueryToRawSQL($cacheQuery->take(1)) . ')';

          // In case we have duplicate updates for the same column we need to merge the updates
          $existingSql = $updates->get($summaryName);
          if ($existingSql) {
            switch ($function) {
              case 'count':
                $sql = '(' . $existingSql . ' + ' . $sql . ')';
                break;

              case 'sum':
                $sql = '(' . $existingSql . ' + ' . $sql . ')';
                break;

              case 'max':
                $sql = 'MAX(' . $existingSql . ', ' . $sql . ')';
                break;

              case 'min':
                $sql = 'MIN(' . $existingSql . ', ' . $sql . ')';

                break;
            }
          }

          $updates->put($summaryName, $sql);
        });
    });

    // Save
    if ($updates->count() > 0) {
      DB::table(static::getModelTable($this->model))
        ->where($this->model->getKeyName(), $this->model->getKey())
        ->update($updates->map(fn ($update) => DB::raw($update))->toArray());
    }

    return $this->model;
  }

  /*
   * Create the cache entry
   */
  public function create()
  {
    $this->apply(function ($configuration) {
      $foreignKeyName = static::getForeignKeyName(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignKeys = collect(
        $configuration['extractForeignKeys']($this->model->{$foreignKeyName}, $this->model, $this->pivotModel)
      );
      $function = $configuration['function'];
      $summaryName = $configuration['summaryName'];
      $valueName = $configuration['valueName'];
      $value = $this->model->$valueName ?? null;

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summaryName + 1");
          $propagateValue = 1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summaryName + $value");
          $propagateValue = $value;
          break;

        case 'max':
          $rawUpdate = DB::raw(
            "CASE WHEN $summaryName > '$value' THEN $summaryName ELSE '$value' END"
          );
          $propagateValue = $value;
          break;

        case 'min':
          $rawUpdate = DB::raw(
            "CASE WHEN $summaryName < '$value' THEN $summaryName ELSE '$value' END"
          );
          $propagateValue = $value;
          break;
      }

      return $this->prepareCacheUpdate(
        $foreignKeys,
        $configuration,
        'created',
        $rawUpdate,
        $propagateValue
      );
    });
  }

  /*
   * Delete the cache entry
   */
  public function delete()
  {
    $this->apply(function ($configuration) {
      $foreignKeyName = static::getForeignKeyName(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignModelKeys = collect(
        $configuration['extractForeignKeys']($this->model->{$foreignKeyName}, $this->model, $this->pivotModel)
      );
      $function = $configuration['function'];
      $summaryName = $configuration['summaryName'];
      $valueName = $configuration['valueName'];
      $value = $this->model->{$valueName};

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summaryName - 1");
          $propagateValue = -1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summaryName - $value");
          $propagateValue = -1 * $value;
          break;

        default:
          if ($this->pivotModel) {
            // Complete rebuild necessary because other models might affect the column as well
          }
          break;
      }

      return $this->prepareCacheUpdate(
        $foreignModelKeys,
        $configuration,
        'deleted',
        $rawUpdate,
        $propagateValue
      );
    });
  }

  /**
   * Update the cache for all operations.
   */
  public function update()
  {
    $this->apply(function ($configuration, $isRelevant, $wasRelevant) {
      $foreignKeyName = static::getForeignKeyName(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignKeys = collect(
        $configuration['extractForeignKeys']($this->model->{$foreignKeyName}, $this->model, $this->pivotModel)
      );
      $originalForeignKeys = collect(
        $configuration['extractForeignKeys'](
          $this->model->getOriginal($foreignKeyName), $this->model, $this->pivotModel
        )
      );
      $removedForeignModelKeys = collect($originalForeignKeys)->diff(
        $foreignKeys
      );
      $addedForeignModelKeys = collect($foreignKeys)->diff(
        $originalForeignKeys
      );
      $changedForeignKeys =
        $addedForeignModelKeys->count() > 0 ||
        $removedForeignModelKeys->count() > 0;
      $summaryName = $configuration['summaryName'];
      $valueName = $configuration['valueName'];
      $value =
        $this->model->{$valueName} ??
        static::getDefaultValue($configuration['function']);
      $originalValue = $this->model->getOriginal($valueName);
      $restored =
        $this->model->deleted_at !== $this->model->getOriginal('deleted_at');
      $dirty = $this->model->isDirty();

      // Handle certain cases more efficiently
      switch ($configuration['function']) {
        case 'count':
          // In case the foreign keys changed, we just transfer the values from one model to the other
          if ($changedForeignKeys) {
            return collect([])
              ->concat(
                $this->prepareCacheUpdate(
                  $addedForeignModelKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )
              ->concat(
                $this->prepareCacheUpdate(
                  $removedForeignModelKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryName + (-1 * $value)"),
                  -1 * $value
                )
              )
              ->toArray();
          }

          if ($isRelevant && $wasRelevant) {
            // Nothing to do
            if (!$restored) {
              return null;
            }

            // Restore count indicator if item is restored
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName - 1"),
              -1
            );
          }

          break;

        case 'sum':
          // In case the foreign key changed, we just transfer the values from one model to the other
          if ($changedForeignKeys) {
            return collect([])
              ->concat(
                $this->prepareCacheUpdate(
                  $foreignKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )

              ->concat(
                $this->prepareCacheUpdate(
                  $originalForeignKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryName + (-1 * $value)"),
                  -1 * $value
                )
              )
              ->toArray();
          }

          if ($isRelevant && $wasRelevant) {
            if ($restored) {
              return $this->prepareCacheUpdate(
                $foreignKeys,
                $configuration,
                'updated',
                DB::raw("$summaryName + $value"),
                $value
              );
            }

            // We need to add the difference in case it is as relevant as before
            $difference = $value - ($originalValue ?? 0);

            if ($difference === 0) {
              return [];
            }

            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryName - $originalValue"),
              -1 * $originalValue
            );
          }

          break;
      }

      // Run update with recalculation
      return $this->prepareCacheUpdate($foreignKeys, $configuration, 'updated');
    });
  }

  /**
   * Applies the provided function to the count cache setup/configuration.
   *
   * @param \Closure $function
   */
  public function apply(\Closure $function)
  {
    // Gather all updates from every configuration
    $allUpdates = collect($this->configurations)
      ->map(function ($configuration) use ($function) {
        $isRelevant = $this::checkWhereCondition(
          $this->model,
          $this->model->getAttributes(),
          $configuration['where'],
          true,
          $configuration
        );
        $wasRelevant = $this::checkWhereCondition(
          $this->model,
          $this->model->getRawOriginal(),
          $configuration['where'],
          false,
          $configuration
        );

        if (!$isRelevant && !$wasRelevant) {
          return null;
        }

        return $function($configuration, $isRelevant, $wasRelevant);
      })
      ->filter(function ($update) {
        return $update !== null;
      })
      ->reduce(function ($cumulatedUpdates, $update) {
        return $cumulatedUpdates->concat(
          Arr::isAssoc($update) ? [$update] : $update
        );
      }, collect([]));

    // Group updates by model, key, foreign key and propagate and update each group independently
    $allUpdates
      ->groupBy(['foreignModelName', 'keyName', 'foreignKey', 'propagate'])
      ->each(function ($keyNames, $foreignModelName) {
        $keyNames->each(function ($foreignKeys, $keyName) use ($foreignModelName) {
          $foreignKeys->each(function ($propagates, $foreignKey) use (
            $foreignModelName,
            $keyName
          ) {
            $propagates->each(function ($updates, $propagate) use (
              $foreignModelName,
              $keyName,
              $foreignKey
            ) {
              $foreignModel = new $foreignModelName();

              // Update entity in one go
              $query = DB::table(static::getModelTable($foreignModelName))->where(
                $keyName,
                $foreignKey
              );
              $values = $updates->mapWithKeys(function ($update) {
                return [
                  $update['summaryName'] => $update['rawValue'],
                ];
              });
              $query->update($values->toArray());

              // Propagate fields and trigger cache update on model above
              if ($propagate) {
                // Provide context (must be set before propagation fields!)
                $updates->each(function ($update) use ($foreignModel) {
                  if ($update['getContext']) {
                    $foreignModel->setRawAttributes(
                      $update['getContext']($this->model),
                      true
                    );
                  }
                });

                // Set foreign key
                $foreignModel->{$keyName} = $foreignKey;

                // Fill foreign model with field that should be propagated
                $propagations = $updates->map(function ($update) {
                  return [
                    'summaryName' => $update['summaryName'],
                    'propagateValue' => $update['propagateValue'],
                  ];
                });
                $propagations->each(function ($propagation) use (
                  $foreignModel
                ) {
                  $foreignModel->{$propagation['summaryName']} =
                    $propagation['propagateValue'];
                });

                // Update foreign model as well
                (new Cache(
                  $foreignModel,
                  $propagations
                    ->map(function ($propagation) {
                      return $propagation['summaryName'];
                    })
                    ->toArray()
                ))->update();
              }
            });
          });
        });
      });
  }

  /**
   * Prepares a cache update by collecting all information for the update.
   *
   * @param Collection $foreignKeys Foreign model key
   * @param array $configuration
   * @param string $event Possible events: created/deleted/updated
   * @param ?any $rawValue Raw value
   * @param ?any $propagateValue Value to propagate
   * @return array
   */
  public function prepareCacheUpdate(
    $foreignKeys,
    array $configuration,
    $event,
    $rawValue = null,
    $propagateValue = null
  ) {
    $validForeignKeys = $foreignKeys->filter(function ($foreignKey) {
      return !!$foreignKey;
    });

    if ($validForeignKeys->count() === 0) {
      if ($configuration['ignoreEmptyForeignKeys']) {
        return [];
      }

      throw new UnableToPropagateException(
        'Unable to propagate cache update to "' .
          $configuration['function'] .
          '(' .
          $configuration['valueName'] .
          ')" into "' .
          $configuration['table'] .
          '"."' .
          $configuration['summaryName'] .
          '" because "' .
          $configuration['foreignKeyName'] .
          '" is not an attribute on "' . get_class($this->model) . '".'
      );
    }

    return collect($validForeignKeys)
      ->map(function ($foreignKey) use (
        $event,
        $configuration,
        $propagateValue,
        $rawValue
      ) {
        $foreignModelName = $configuration['foreignModelName'];
        $summaryName = $configuration['summaryName'];
        $keyName = $configuration['keyName'];
        $foreignKeyName = $configuration['foreignKeyName'];

        // Create cache query
        if (!$rawValue) {
          $query = static::prepareCacheQuery(
            $this->model,
            $configuration
          )->where($foreignKeyName, $foreignKey);

          $rawValue = DB::raw('(' . Cache::convertQueryToRawSQL($query) . ')');
        }

        return [
          'event' => $event,
          'foreignModelName' => $foreignModelName,
          'summaryName' => $summaryName,
          'keyName' => $keyName,
          'foreignKey' => $foreignKey,
          'rawValue' =>
          $rawValue,
          'propagate' => $configuration['propagate'],
          'propagateValue' => $propagateValue,
          'getContext' => $configuration['getContext'],
        ];
      })
      ->toArray();
  }

  /**
   * Create cache query
   *
   * @param mixed $configuration
   *
   * @return \Illuminate\Database\Query\Builder
   */
  protected static function prepareCacheQuery($model, $configuration)
  {
    $function = $configuration['function'];
    $valueName = $configuration['valueName'];
    $defaultValue = static::getDefaultValue($function);

    // Create cache query
    $cacheQuery = DB::table(static::getModelTable($model))
      ->select(DB::raw("COALESCE($function($valueName), $defaultValue)"))
      ->where($configuration['where']);

    // Respect soft delete
    if (
      in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model))
    ) {
      $cacheQuery->where('deleted_at', '=', null);
    }

    return $cacheQuery;
  }

  /**
   * Get default value for specified function
   *
   * @param string $function
   *
   * @return string
   */
  protected static function getDefaultValue($function)
  {
    switch ($function) {
      case 'sum':
        return 0;

      case 'count':
        return 0;
    }

    return 'null';
  }

  /**
   * Convert SQL query to raw SQL string.
   *
   * @param \Illuminate\Database\Query\Builder $query
   *
   * @return string
   */
  protected static function convertQueryToRawSQL(
    \Illuminate\Database\Query\Builder $query
  ) {
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    return vsprintf(str_replace(['?'], ['\'%s\''], $sql), $bindings);
  }

  /**
   * Creates the key based on model properties and rules.
   *
   * @param string $model
   * @param string $field
   *
   * @return string
   */
  protected static function generateFieldName(string $model, string $field)
  {
    $class = strtolower(class_basename($model));
    $field = $class . '_' . $field;

    return $field;
  }

  /**
   * Returns the true key for a given field.
   *
   * @param Model|string $model
   * @param string $field
   * @return mixed
   */
  protected static function getKeyName($model, $field)
  {
    /*if (!($model instanceof Model)) {
            $model = new $model();
        }

        if (method_exists($model, 'getTrueKey')) {
            return $model->getTrueKey($field);
        }*/

    return $field;
  }

  /**
   * Returns the true key for a given field.
   *
   * @param Model|string $model
   * @param string $field
   * @return mixed
   */
  protected static function getForeignKeyName($model, $field)
  {
    return Str::snake(static::getKeyName($model, $field));
  }

  /**
   * Returns the table for a given model. Model can be an Eloquent model object, or a full namespaced
   * class string.
   *
   * @param string|Model $model
   * @return mixed
   */
  protected static function getModelTable($model)
  {
    if (!is_object($model)) {
      $model = new $model();
    }

    return DB::getTablePrefix() . $model->getTable();
  }

  /**
   * Checks if the where condition matches the model.
   *
   * @param Model     $model
   * @param array   $attributes
   * @param array       $where
   * @param boolean   $throw
   */
  protected static function checkWhereCondition(
    $model,
    $attributes,
    $where,
    $throw = true,
    $configuration
  ) {
    // In case we are dealing with a Pivot model we need to add the morph type to the attributes
    if ($model instanceof MorphPivot) {
      // Ugly workaround to get access to the morphClass property
      $morphClassProperty = new ReflectionProperty(
        MorphPivot::class,
        'morphClass'
      );
      $morphClassProperty->setAccessible(true);
      $morphClass = $morphClassProperty->getValue($model);

      $morphType = $model->getMorphType();
      $attributes[$morphType] = $attributes[$morphType] ?? $morphClass;
    }

    // Loop through conditions and see if the attributes match the conditions
    foreach ($where as $attribute => $value) {
      // Get attribute, operator and value
      $operator = '=';
      if (is_array($value)) {
        if (count($value) > 2) {
          $attribute = $value[0];
          $operator = $value[1];
          $value = $value[2];
        } else {
          $attribute = $value[0];
          $value = $value[2];
        }
      }

      // Determine if model is relevant for count
      if ($throw && !array_key_exists($attribute, $attributes)) {
        throw new UnableToCacheException(
          'Unable to cache "' .
            $configuration['function'] .
            '(' .
            $configuration['valueNName'] .
            ')" into "' .
            $configuration['table'] .
            '"."' .
            $configuration['summaryName'] .
            '" because "' .
            $attribute .
            '" is part of the where condition but it is not set explicitly on the entity.'
        );
      }

      $relevant = false;
      $modelValue = $attributes[$attribute] ?? null;
      switch ($operator) {
        case '=':
          $relevant = $modelValue === $value;
          break;
        case '<':
          $relevant = $modelValue < $value;
          break;
        case '<=':
          $relevant = $modelValue <= $value;
          break;
        case '>':
          $relevant = $modelValue > $value;
          break;
        case '>=':
          $relevant = $modelValue > $value;
          break;
        case '<>':
          $relevant = $modelValue !== $value;
          break;
      }

      if (!$relevant) {
        return false;
      }
    }

    return true;
  }
}
