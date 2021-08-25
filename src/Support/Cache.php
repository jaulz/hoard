<?php

namespace Jaulz\Eloquence\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
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
   * @var array<string>
   */
  private $propagatedBy;

  /**
   * @var array
   */
  private $configurations;

  /**
   * @param Model $model
   */
  public function __construct(Model $model, $propagatedBy = [])
  {
    $this->model = $model;
    $this->propagatedBy = $propagatedBy;
    $this->configurations = collect($model->getCacheConfigurations())
      ->map(function ($configuration) use ($model) {
        return $this->prepareConfiguration($model, $configuration);
      })
      ->filter(function ($configuration) use ($propagatedBy) {
        return !empty($propagatedBy)
          ? collect($propagatedBy)->contains($configuration['value'])
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
      'ignoreEmptyForeignKeys' => false,
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
        $configuration['ignoreEmptyForeignKeys'] = true;
        $configuration['foreign_key'] = [
          $relation->getParentKeyName(),
          function ($key) use ($pivotClass, $morphClass, $morphType, $foreignPivotKeyName, $relatedPivotKeyName) {
            return $pivotClass::where($foreignPivotKeyName, $key)->where($morphType, $morphClass)->pluck($relatedPivotKeyName);
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
    $ignoreEmptyForeignKeys = $configuration['ignoreEmptyForeignKeys'] || $foreignModelName === $modelName;
    $function = Str::lower($configuration['function']);
    $summary = Str::snake($configuration['summary'] ?? static::field(Str::plural($modelName), $function));
    $key = Str::snake(static::key($modelName, $configuration['key']));
    $table = static::getModelTable($foreignModelName);

    $foreignKey = $configuration['foreign_key'] ?? static::field($foreignModelName, 'id');
    $foreignKeyName = Str::snake(
      static::key(
        $modelName,
        is_array($foreignKey)
          ? $foreignKey[0]
          : $foreignKey
      )
    );
    $foreignKeyExtractor = is_array($foreignKey)
      ? $foreignKey[1] : function ($key) {
        return $key;
      };
    $foreignKeySelector = is_array($foreignKey)
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
      ) use ($foreignModelName, $summary) {
        $foreignConfiguration = static::prepareConfiguration(
          $foreignModelName,
          $foreignConfiguration,
          false
        );
        $propagate =
          $summary === $foreignConfiguration['value'];

        return $propagate;
      });
    }

    return [
      'function' => $function,
      'foreignModelName' => $foreignModelName,
      'table' =>  $table,
      'summary' => $summary,
      'value' => $configuration['value'],
      'key' => $key,
      'foreignKeyName' => $foreignKeyName,
      'foreignKeyExtractor' => $foreignKeyExtractor,
      'foreignKeySelector' => $foreignKeySelector,
      'ignoreEmptyForeignKeys' => $ignoreEmptyForeignKeys,
      'where' => $configuration['where'],
      'propagate' => $propagate,
      'context' => $configuration['context'],
    ];
  }

  /**
   * Rebuild the count caches from the database
   *
   * @param array $foreignConfigurations
   * @return array
   */
  public function rebuild($foreignConfigurations)
  {
    // Prepare all update statements
    $valueColumns = collect([]);
    $updates = collect([]);

    collect($foreignConfigurations)->each(function (
      $configurations,
      $foreignModelName
    ) use ($valueColumns, $updates) {
      collect($configurations)
        ->map(function ($configuration) use ($foreignModelName, $valueColumns) {
          // Normalize config
          $configuration = static::prepareConfiguration($foreignModelName, $configuration);

          // Collect all value columns
          $valueColumns->push($configuration['value']);

          return $configuration;
        })
        ->each(function ($configuration) use ($foreignModelName, $updates) {
          $summary = $configuration['summary'];
          $keyName = $configuration['key'];
          $function = $configuration['function'];
          $key = $this->model[$keyName];

          // Get query that retrieves the summary value
          $cacheQuery = static::prepareCacheQuery(
            $foreignModelName,
            $configuration
          );
          $configuration['foreignKeySelector']($cacheQuery, $key);
          $sql = '(' . Cache::convertQueryToRawSQL($cacheQuery->take(1)) . ')';

          // In case we have duplicate updates for the same column we need to merge the updates
          $existingSql = $updates->get($summary);
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

          $updates->put($summary, $sql);
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
      $foreignKeyName = static::foreignKey(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKeyName})
      );
      $function = $configuration['function'];
      $summaryColumn = $configuration['summary'];
      $valueColumn = $configuration['value'];
      $value = $this->model->{$valueColumn};

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summaryColumn + 1");
          $propagateValue = 1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summaryColumn + $value");
          $propagateValue = $value;
          break;

        case 'max':
          $rawUpdate = DB::raw(
            "CASE WHEN $summaryColumn > '$value' THEN $summaryColumn ELSE '$value' END"
          );
          $propagateValue = $value;
          break;

        case 'min':
          $rawUpdate = DB::raw(
            "CASE WHEN $summaryColumn < '$value' THEN $summaryColumn ELSE '$value' END"
          );
          $propagateValue = $value;
          break;
      }

      if (get_class($this->model) === 'Tests\Acceptance\Models\Taggable') {
// dump( $this->model, $value, $valueColumn);
// dump($configuration);
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
      $foreignKeyName = static::foreignKey(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignModelKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKeyName})
      );
      $function = $configuration['function'];
      $summaryColumn = $configuration['summary'];
      $valueColumn = $configuration['value'];
      $value = $this->model->{$valueColumn};

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summaryColumn - 1");
          $propagateValue = -1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summaryColumn - $value");
          $propagateValue = -1 * $value;
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
      $foreignKeyName = static::foreignKey(
        $this->model,
        $configuration['foreignKeyName']
      );
      $foreignKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKeyName})
      );
      $originalForeignKeys = collect(
        $configuration['foreignKeyExtractor'](
          $this->model->getOriginal($foreignKeyName)
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
      $summaryColumn = $configuration['summary'];
      $valueColumn = $configuration['value'];
      $value =
        $this->model->{$valueColumn} ??
        static::getDefaultValue($configuration['function']);
      $originalValue = $this->model->getOriginal($valueColumn);
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
                  DB::raw("$summaryColumn + $value"),
                  $value
                )
              )
              ->concat(
                $this->prepareCacheUpdate(
                  $removedForeignModelKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryColumn + (-1 * $value)"),
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
              DB::raw("$summaryColumn + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryColumn + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryColumn - 1"),
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
                  DB::raw("$summaryColumn + $value"),
                  $value
                )
              )

              ->concat(
                $this->prepareCacheUpdate(
                  $originalForeignKeys,
                  $configuration,
                  'updated',
                  DB::raw("$summaryColumn + (-1 * $value)"),
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
                DB::raw("$summaryColumn + $value"),
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
              DB::raw("$summaryColumn + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryColumn + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              'updated',
              DB::raw("$summaryColumn - $originalValue"),
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
          true,
          $configuration
        );
        $wasRelevant = $this::checkWhereCondition(
          $this->model,
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
      ->groupBy(['foreignModelName', 'key', 'foreignKey', 'propagate'])
      ->each(function ($keys, $foreignModelName) {
        $keys->each(function ($foreignKeys, $key) use ($foreignModelName) {
          $foreignKeys->each(function ($propagates, $foreignKey) use (
            $foreignModelName,
            $key
          ) {
            $propagates->each(function ($updates, $propagate) use (
              $foreignModelName,
              $key,
              $foreignKey
            ) {
              $foreignModel = new $foreignModelName();
              $foreignModel->timestamps = false;

              // Update entity in one go
              $query = DB::table(static::getModelTable($foreignModelName))->where(
                $key,
                $foreignKey
              );
              $values = $updates->mapWithKeys(function ($update) {
                return [
                  $update['summaryColumn'] => $update['rawValue'],
                ];
              });
              $query->update($values->toArray());

              // Propagate fields and trigger cache update on model above
              if ($propagate) {
                // Provide context (must be set before propagation fields!)
                $updates->each(function ($update) use ($foreignModel) {
                  if ($update['context']) {
                    dd('as');
                    $foreignModel->setRawAttributes(
                      $update['context']($this->model),
                      true
                    );
                  }
                });

                // Set foreign key
                $foreignModel->{$key} = $foreignKey;

                // Fill foreign model with field that should be propagated
                $propagations = $updates->map(function ($update) {
                  return [
                    'summaryColumn' => $update['summaryColumn'],
                    'propagateValue' => $update['propagateValue'],
                  ];
                });
                $propagations->each(function ($propagation) use (
                  $foreignModel
                ) {
                  $foreignModel->{$propagation['summaryColumn']} =
                    $propagation['propagateValue'];
                });

                // Update foreign model as well
                (new Cache(
                  $foreignModel,
                  $propagations
                    ->map(function ($propagation) {
                      return $propagation['summaryColumn'];
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
          $configuration['value'] .
          ')" into "' .
          $configuration['table'] .
          '"."' .
          $configuration['summary'] .
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
        $function = $configuration['function'];
        $summaryColumn = $configuration['summary'];
        $valueColumn = $configuration['value'];
        $keyColumn = $configuration['key'];
        $defaultValue = static::getDefaultValue($function);
        $foreignKeyName = $configuration['foreignKeyName'];

        // Create cache query
        $cacheQuery = static::prepareCacheQuery(
          $this->model,
          $configuration
        )->where($foreignKeyName, $foreignKey);
        return [
          'event' => $event,
          'foreignModelName' => $foreignModelName,
          'summaryColumn' => $summaryColumn,
          'key' => $keyColumn,
          'foreignKey' => $foreignKey,
          'rawValue' =>
          $rawValue ??
            DB::raw('(' . Cache::convertQueryToRawSQL($cacheQuery) . ')'),
          'propagate' => $configuration['propagate'],
          'propagateValue' => $propagateValue,
          'context' => $configuration['context'],
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
    $valueColumn = $configuration['value'];
    $defaultValue = static::getDefaultValue($function);

    // Create cache query
    $cacheQuery = DB::table(static::getModelTable($model))
      ->select(DB::raw("COALESCE($function($valueColumn), $defaultValue)"))
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
  protected static function field(string $model, string $field)
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
  protected static function key($model, $field)
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
  protected static function foreignKey($model, $field)
  {
    return Str::snake(static::key($model, $field));
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
   * @param boolean   $current Use current or original values.
   * @param any       $configuration
   * @param \Closure $function
   */
  protected static function checkWhereCondition(
    $model,
    $current,
    $configuration
  ) {
    $attributes = $model->getAttributes();
    $originalAttributes = $model->getRawOriginal();

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
      $originalAttributes[$morphType] =
        $originalAttributes[$morphType] ?? $morphClass;
    }

    // Loop through conditions and see if the attributes match the conditions
    foreach ($configuration['where'] as $attribute => $value) {
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
      if ($current && !array_key_exists($attribute, $attributes)) {
        throw new UnableToCacheException(
          'Unable to cache "' .
            $configuration['function'] .
            '(' .
            $configuration['value'] .
            ')" into "' .
            $configuration['table'] .
            '"."' .
            $configuration['summary'] .
            '" because "' .
            $attribute .
            '" is part of the where condition but it is not set explicitly on the entity.'
        );
      }

      $relevant = false;
      $modelValue = $current
        ? $attributes[$attribute] ?? null
        : $originalAttributes[$attribute] ?? null;
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
