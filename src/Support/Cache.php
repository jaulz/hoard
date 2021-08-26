<?php

namespace Jaulz\Eloquence\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Eloquence\Exceptions\InvalidRelationException;
use Jaulz\Eloquence\Exceptions\UnableToCacheException;
use Jaulz\Eloquence\Exceptions\UnableToPropagateException;
use PDO;
use ReflectionProperty;

class Cache
{
  /**
   * @var Model
   */
  private Model $model;

  /**
   * @var string
   */
  private string $modelName;

  /**
   * @var ?Model
   */
  private ?Model $pivotModel;

  /**
   * @var ?string
   */
  private ?string $pivotModelName;

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
    $this->model = $model->pivotParent ?? $model;
    $this->modelName = get_class($this->model);
    $this->pivotModel = $model->pivotParent ? $model : null;
    $this->pivotModelName = $model->pivotModel ? get_class($this->pivotModel) : null;
    $this->propagatedBy = $propagatedBy;
    $this->configurations = collect(get_class($this->model)::getCacheConfigurations())
      ->map(fn ($configuration) => static::prepareConfiguration($model, $configuration))
      ->filter()
      ->filter(function ($configuration) {
        return !empty($this->propagatedBy)
          ? collect($this->propagatedBy)->contains($configuration['valueName'])
          : true;
      })
      ->filter(function ($configuration) {
        return $this->pivotModel ? $configuration['pivotModelName'] === get_class($this->pivotModel) : true;
      });
  }

  /**
   * Take a user configuration and standardize it.
   *
   * @param Model|string $model
   * @param array $configuration
   * @param bool $checkForeignModel
   * @return array
   */
  public static function prepareConfiguration(
    $model,
    $configuration,
    ?bool $checkForeignModel = true
  ) {
    $model = $model instanceof Model ? $model : new $model();
    $modelName = get_class($model);

    // Merge defaults and actual configuration
    $defaults = [
      'function' => 'count',
      'valueName' => 'id',
      'key' => 'id',
      'where' => [],
      'context' => null,
      'relationName' => null,
      'ignoreEmptyForeignKeys' => false,
      'propagate' => false,
    ];
    $configuration = array_merge($defaults, $configuration);

    // In case we have a relation field we can easily fill the required fields
    $pivotModelName = null;
    $relationType = null;
    if ($configuration['relationName']) {
      $relationName = $configuration['relationName'];

      if (!method_exists(new $model, $relationName)) {
        return null;
      }

      $relation = (new $model())->{$relationName}();
      if (!($relation instanceof Relation)) {
        throw new InvalidRelationException('The specified relation "' . $configuration['relationName'] . '" does not inherit from "' . Relation::class . '".');
      }
      $configuration['foreignModelName'] = $relation->getRelated();

      // Handle relations differently
      if ($relation instanceof BelongsTo) {
        if ($relation instanceof MorphTo) {
          $relationType = 'MorphTo';
          $morphType = $relation->getMorphType();

          $configuration['foreignModelName'] = $model[$morphType];
          $configuration['foreignKeyName'] = $relation->getForeignKeyName();
        } else if ($relation instanceof MorphToMany) {
          $relationType = 'MorphToMany';
          $configuration['foreignModelName'] = $relation->getRelated();
          $configuration['foreignKeyName'] = $relation->getForeignKeyName();
          $configuration['key'] = $relation->getOwnerKeyName();
        }
      } else if ($relation instanceof HasOneOrMany) {
        $relationType = 'HasOneOrMany';
        $configuration['foreignModelName'] = $relation->getRelated();
      } else if ($relation instanceof MorphToMany) {
        $relationType = 'MorphToMany';
        $relatedPivotKeyName = $relation->getRelatedPivotKeyName();
        $foreignPivotKeyName = $relation->getForeignPivotKeyName();
        $parentKeyName = $relation->getParentKeyName();
        $morphClass = $relation->getMorphClass();
        $morphType = $relation->getMorphType();
        $pivotModelName = $relation->getPivotClass();

        $configuration['foreignModelName'] = $relation->getRelated();
        $configuration['ignoreEmptyForeignKeys'] = true;
        $configuration['foreignKeyName'] = [
          $relation->getParentKeyName(),
          function ($key, $model, $pivotModel, $eventName, $foreignKeyCache) use ($pivotModelName, $morphClass, $morphType, $foreignPivotKeyName, $relatedPivotKeyName) {
            if ($pivotModel) {
              return $pivotModel[$relatedPivotKeyName];
            }

            // Create and update events can be neglected in a MorphToMany situation
            if ($eventName === 'create' || $eventName === 'update') {
              return null;
            }

            // Cache foreign keys to avoid expensive queries
            $cacheKey = implode('-', [$pivotModelName, $foreignPivotKeyName, $key, $morphType, $morphClass]);
            if ($foreignKeyCache->has($cacheKey)) {
              return $foreignKeyCache->get($cacheKey);
            }

            // Get all foreign keys by querying the pivot table
            $keys = $pivotModelName::where($foreignPivotKeyName, $key)->where($morphType, $morphClass)->pluck($relatedPivotKeyName);
            $foreignKeyCache->put($cacheKey, $keys);

            return $keys;
          },
          function ($query, $foreignKey) use ($parentKeyName, $morphType, $morphClass, $foreignPivotKeyName, $relatedPivotKeyName, $pivotModelName) {
            // Get all models that are mentioned in the pivot table
            $query->whereIn($parentKeyName, function ($whereQuery) use ($pivotModelName, $morphType, $morphClass, $foreignPivotKeyName, $relatedPivotKeyName, $foreignKey) {
              $whereQuery->select($foreignPivotKeyName)
                ->from(static::getModelTable($pivotModelName))
                ->where($relatedPivotKeyName, $foreignKey)
                ->where($morphType, $morphClass);
            });
          },
        ];
      }
    }

    // Adjust configuration
    $foreignModelName = $configuration['foreignModelName'] instanceof Model ? get_class($configuration['foreignModelName']) : $configuration['foreignModelName'];
    $ignoreEmptyForeignKeys = $configuration['ignoreEmptyForeignKeys'] || $foreignModelName === $modelName;
    $function = Str::lower($configuration['function']);
    $summaryName = Str::snake($configuration['summaryName'] ?? static::generateFieldName(Str::plural($modelName), $function));
    $keyName = Str::snake(static::getKeyName($modelName, $configuration['key']));

    if (!$foreignModelName) {
      dump('Fix this.');
      return null;
    }

    $table = static::getModelTable($foreignModelName);

    $foreignKey = $configuration['foreignKeyName'] ?? static::generateFieldName($foreignModelName, 'id');
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
            $configuration['foreignModelName'] .
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
      'valueName' => $configuration['valueName'],
      'keyName' => $keyName,
      'foreignKeyName' => $foreignKeyName,
      'extractForeignKeys' => $extractForeignKeys,
      'selectForeignKeys' => $selectForeignKeys,
      'ignoreEmptyForeignKeys' => $ignoreEmptyForeignKeys,
      'where' => $configuration['where'],
      'propagate' => $propagate,
      'getContext' => $configuration['context'],
      'pivotModelName' => $pivotModelName,
      'relationType' => $relationType,
    ];
  }

  /**
   * Get database driver
   *
   * @return string
   */
  public static function getDatabaseDriver()
  {
    return DB::getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
  }

  /**
   * Get full updates for cache rebuild.
   *
   * @param Model $model
   * @param ?Model $pivotModel
   * @return array
   */
  public static function getFullUpdate(Model $model, ?Model $pivotModel)
  {
    $updates = collect([]);

    collect(get_class($model)::getForeignCacheConfigurations())->each(function (
      $foreignConfigurations,
      $foreignModelName
    ) use ($updates, $model, $pivotModel) {
      dump($foreignConfigurations);
      collect($foreignConfigurations)
        ->each(function ($foreignConfiguration) use ($foreignModelName, $updates, $model, $pivotModel) {
          $summaryName = $foreignConfiguration['summaryName'];
          $keyName = $foreignConfiguration['keyName'];
          $function = $foreignConfiguration['function'];
          $key = $model[$keyName];

          // Get query that retrieves the summary value
          $cacheQuery = static::prepareCacheQuery(
            $foreignModelName,
            $foreignConfiguration
          );
          $foreignConfiguration['selectForeignKeys']($cacheQuery, $key, $model, $pivotModel);
          $sql = '(' . Cache::convertQueryToRawSQL($cacheQuery) . ')';
          dump('Get intermediate value for recalculation', $cacheQuery->toSql(), $cacheQuery->get());

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
                // Unfortunately, dialects use different names to get the minimum value
                $function = static::getDatabaseDriver() === 'sqlite' ? 'MAX' : 'GREATEST';

                // We need to cast null values to a temporary low value because MAX(999999999999, NULL) leads to NULL in SQLite
                $temporaryValue = '"0"';
                $sql = 'NULLIF(' . $function . '(COALESCE(' . $existingSql . ', ' . $temporaryValue . '), COALESCE(' . $sql . ', ' . $temporaryValue . ')), ' . $temporaryValue . ')';
                break;

              case 'min':
                // Unfortunately, dialects use different names to get the minimum value
                $function = static::getDatabaseDriver() === 'sqlite' ? 'MIN' : 'LEAST';

                // We need to cast null values to a temporary high value because MIN(0, NULL) leads to NULL in SQLite
                $temporaryValue = '"999999999999999999"';
                $sql = 'NULLIF(' . $function . '(COALESCE(' . $existingSql . ', ' . $temporaryValue . '), COALESCE(' . $sql . ', ' . $temporaryValue . ')), ' . $temporaryValue . ')';
                break;
            }
          }

          $updates->put($summaryName, $sql);
        });
    });

    return $updates->map(fn ($update) => DB::raw($update))->toArray();
  }

  /**
   * Rebuild the count caches from the database
   *
   * @param array $foreignConfigurations
   * @return array
   */
  public function rebuild()
  {
    $updates = static::getFullUpdate($this->model, $this->pivotModel);
    if (count($updates) > 0) {
      DB::table(static::getModelTable($this->model))
        ->where($this->model->getKeyName(), $this->model->getKey())
        ->update($updates);
    }

    return $this->model;
  }

  /*
   * Create the cache entry
   */
  public function create()
  {
    // dump('create', $this->modelName);
    $this->apply('create', function ($eventName, $configuration, $foreignKeys, $foreignKeyName, $isRelevant, $wasRelevant) {
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
        $eventName,
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
    // dump('delete', $this->modelName);
    $this->apply('delete', function ($eventName, $configuration, $foreignKeys, $foreignKeyName, $isRelevant, $wasRelevant) {
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
      }

      return $this->prepareCacheUpdate(
        $foreignKeys,
        $configuration,
        $eventName,
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
    $restored =
      $this->model->deleted_at !== $this->model->getOriginal('deleted_at');
    $eventName = $restored ? 'restore' : 'update';
    // dump($eventName, $this->modelName);

    $this->apply($eventName, function ($eventName, $configuration, $foreignKeys, $foreignKeyName, $isRelevant, $wasRelevant) {
      $extractForeignKeys = $configuration['extractForeignKeys'];
      $originalForeignKeys =  collect(
        $this->model->getOriginal($foreignKeyName) !== $this->model->{$foreignKeyName} ?
          $extractForeignKeys(
            $this->model->getOriginal($foreignKeyName),
            $this->model,
            $this->pivotModel,
            $eventName
          )
          : $foreignKeys
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
                  $eventName,
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )
              ->concat(
                $this->prepareCacheUpdate(
                  $removedForeignModelKeys,
                  $configuration,
                  $eventName,
                  DB::raw("$summaryName + (-1 * $value)"),
                  -1 * $value
                )
              )
              ->toArray();
          }

          if ($isRelevant && $wasRelevant) {
            // Nothing to do
            if ($eventName !== 'restore') {
              return null;
            }

            // Restore count indicator if item is restored
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
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
                  $eventName,
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )

              ->concat(
                $this->prepareCacheUpdate(
                  $originalForeignKeys,
                  $configuration,
                  $eventName,
                  DB::raw("$summaryName + (-1 * $value)"),
                  -1 * $value
                )
              )
              ->toArray();
          }

          if ($isRelevant && $wasRelevant) {
            if ($eventName === 'restore') {
              return $this->prepareCacheUpdate(
                $foreignKeys,
                $configuration,
                $eventName,
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
              $eventName,
              DB::raw("$summaryName + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName - $originalValue"),
              -1 * $originalValue
            );
          }

          break;
      }

      // Run update with recalculation
      return $this->prepareCacheUpdate(
        $foreignKeys,
        $configuration,
        $eventName
      );
    });
  }

  /**
   * Applies the provided function to the count cache setup/configuration.
   *
   * @param string $eventName
   * @param \Closure $callback
   */
  public function apply(string $eventName, \Closure $callback)
  {
    // Gather all updates from every configuration
    $foreignKeyCache = collect();
    $allUpdates = collect($this->configurations)
      ->map(function ($configuration) use ($eventName, $callback, $foreignKeyCache) {
        $where = $configuration['where'];
        $isRelevant = $this::checkWhereCondition(
          $this->model,
          $this->model->getAttributes(),
          $where,
          true,
          $configuration
        );
        $wasRelevant = $this::checkWhereCondition(
          $this->model,
          $this->model->getRawOriginal(),
          $where,
          false,
          $configuration
        );

        // Check if we need to update anything
        if (!$isRelevant && !$wasRelevant) {
          return null;
        }

        // Get foreign keys
        $foreignKeyName = static::getForeignKeyName(
          $this->model,
          $configuration['foreignKeyName']
        );
        $extractForeignKeys = $configuration['extractForeignKeys'];
        $foreignKeys = collect(
          $extractForeignKeys($this->model->{$foreignKeyName}, $this->model, $this->pivotModel, $eventName, $foreignKeyCache)
        );
        // dump('Get intermediate value for recalculation', $cacheQuery->toSql(), $cacheQuery->get());

        // Get updates for configuration
        $updates = $callback($eventName, $configuration, $foreignKeys, $foreignKeyName, $isRelevant, $wasRelevant) ?? [];

        return collect(Arr::isAssoc($updates) ? [$updates] : $updates)
          ->filter(function ($update) {
            return $update !== null;
          });
      })->filter()
      ->reduce(function ($cumulatedUpdates, $updates) {
        return $cumulatedUpdates->concat(
          $updates
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
   * @param string $event Possible events: create/delete/update
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

        // If there is no simplified query, we need to recalculate the whole field
        if (!$rawValue) {
          $foreignModel = new $foreignModelName();
          $foreignModel->{$foreignModel->getKeyName()} = $foreignKey;
          $rawValue = static::getFullUpdate($foreignModel, $this->pivotModel)[$summaryName];

          if ($summaryName === 'TEST') {
            dump(
              'Get intermediate value',
              $foreignModelName,
              $rawValue,
              $foreignModelName::query()->select($rawValue)->first()->toArray()
            );
          }
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

    return $cacheQuery->take(1);
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
   * @param Builder $query
   *
   * @return string
   */
  protected static function convertQueryToRawSQL(
    Builder $query
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
    $throw,
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
            $configuration['valueName'] .
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
