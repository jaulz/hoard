<?php

namespace Jaulz\Hoard\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Hoard\Exceptions\InvalidRelationException;
use Jaulz\Hoard\Exceptions\UnableToHoardException;
use Jaulz\Hoard\Exceptions\UnableToPropagateException;
use PDO;
use ReflectionProperty;

class Hoard
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
   * @var Collection
   */
  private $ignoredUpdates;

  /**
   * @param Model $model
   */
  public function __construct(Model $model, array $propagatedBy = [], Pivot $pivotModel = null, Collection $ignoredUpdates = null)
  {
    $this->model = $model;
    $this->modelName = get_class($this->model);
    $this->pivotModel = $pivotModel;
    $this->pivotModelName = $this->pivotModel
      ? get_class($this->pivotModel)
      : null;
    $this->propagatedBy = $propagatedBy;
    $this->ignoredUpdates = $ignoredUpdates ?? collect();

    // Get all the configurations that are relevant for this model
    $this->configurations = collect(
      get_class($this->model)::getHoardConfigurations()
    )
      ->map(
        fn ($configuration) => static::prepareConfiguration(
          $this->model,
          $configuration
        )
      )
      ->filter()
      ->filter(function ($configuration) {
        return !empty($this->propagatedBy)
          ? collect($this->propagatedBy)->contains($configuration['valueName'])
          : true;
      })
      ->filter(function ($configuration) {
        return $this->pivotModel
          ? $configuration['pivotModelName'] === get_class($this->pivotModel)
          : true;
      });
  }

  /**
   * Take a user configuration and standardize it.
   *
   * @param Model|string $model
   * @param array $configuration
   * @param ?bool $checkForeignModel
   * @param ?string $foreignModelName
   * @return array
   */
  public static function prepareConfiguration(
    $model,
    $configuration,
    ?string $foreignModelName = null,
    ?bool $checkForeignModel = true,
  ) {
    $model = $model instanceof Model ? $model : new $model();
    $modelName = get_class($model);

    // Merge defaults and actual configuration
    $defaults = [
      'function' => 'count',
      'valueName' => 'id',
      'keyName' => 'id',
      'where' => [],
      'wherePivot' => [],
      'relationName' => null,
      'ignoreEmptyForeignKeys' => false,
      'foreignKeyStrategy' => '',
      'foreignKeyOptions' => [],
      'propagate' => false,
    ];
    $configuration = array_merge($defaults, $configuration);

    // In case we have a relation field we can easily fill the required fields
    $pivotModelName = null;
    $relationType = null;
    if (isset($configuration['relationName'])) {
      $relationName = $configuration['relationName'];

      $relation = (new $model())->{$relationName}();
      if (!($relation instanceof Relation)) {
        throw new InvalidRelationException(
          'The specified relation "' .
            $configuration['relationName'] .
            '" does not inherit from "' .
            Relation::class .
            '".'
        );
      }

      // Handle relations differently
      if ($relation instanceof BelongsTo) {
        // $configuration['foreignModelName'] = $configuration['foreignModelName'] ?? $relation->getRelated();

        if ($relation instanceof MorphTo) {
          $relationType = 'MorphTo';
          $morphType = $relation->getMorphType();

          // If both the relation name and the foreign model name are provided, then we limit the update to that specific foreign model name only
          $foreignModelName = $foreignModelName ?? $model[$morphType];
          if (isset($configuration['foreignModelName']) && $configuration['foreignModelName'] !== $foreignModelName) {
            return null;
          }

          $configuration['foreignModelName'] = $foreignModelName;
          $configuration['foreignKeyName'] = $configuration['foreignKeyName'] ?? $relation->getForeignKeyName();
          $configuration['where'][$morphType] = $configuration['foreignModelName'];
        } else {
          $relationType = 'BelongsTo';

          $foreignModelName = $foreignModelName ?? $relation->getRelated();

          $configuration['foreignModelName'] = $foreignModelName;
        }
      } elseif ($relation instanceof HasOneOrMany) {
        if ($relation instanceof MorphOneOrMany) {
          $relationType = 'MorphOneOrMany';

          throw new \Exception('MorphOneOrMany is not implemented in Hoard.');
        } else {
          $relationType = 'HasOneOrMany';

          $foreignModelName = $foreignModelName ?? $relation->getRelated();
  
          $configuration['foreignModelName'] = $foreignModelName;
        }
      } elseif ($relation instanceof BelongsToMany) {
        if ($relation instanceof MorphToMany) {
          $relationType = 'MorphToMany';
          $relatedPivotKeyName = $relation->getRelatedPivotKeyName();
          $foreignPivotKeyName = $relation->getForeignPivotKeyName();
          $parentKeyName = $relation->getParentKeyName();
          $morphClass = $relation->getMorphClass();
          $morphType = $relation->getMorphType();
          $pivotModelName = $relation->getPivotClass();
  
          $foreignModelName = $foreignModelName ?? $relation->getRelated();
  
          $configuration['foreignModelName'] = $foreignModelName;
          $configuration['ignoreEmptyForeignKeys'] = true;
          $configuration['foreignKeyName'] = $configuration['foreignKeyName'] ?? $relation->getParentKeyName();
          $configuration['foreignKeyStrategy'] = 'MorphToMany';
          $configuration['foreignKeyOptions'] = [
            'pivotModelName' => $pivotModelName,
            'morphClass' =>  $morphClass,
            'morphType' =>  $morphType,
            'foreignPivotKeyName' =>  $foreignPivotKeyName,
            'relatedPivotKeyName' =>  $relatedPivotKeyName,
            'parentKeyName' =>  $parentKeyName,
          ];
        } else {
          throw new \Exception('BelongsToMany is not implemented in Hoard.');
        }
      } else if ($relation instanceof HasManyThrough) {
        if ($relation instanceof HasOneThrough) {
          throw new \Exception('HasOneThrough is not implemented in Hoard.');
        } else {
          throw new \Exception('HasManyThrough is not implemented in Hoard.');
        }
      }
    }

    // Adjust configuration
    $foreignModelName =
      $configuration['foreignModelName'] instanceof Model
      ? get_class($configuration['foreignModelName'])
      : $configuration['foreignModelName'];
    $ignoreEmptyForeignKeys =
      $configuration['ignoreEmptyForeignKeys'] ||
      $foreignModelName === $modelName;
    $function = Str::lower($configuration['function']);
    $summaryName = Str::snake(
      $configuration['summaryName'] ??
        static::generateFieldName(Str::plural($modelName), $function)
    );
    $keyName = Str::snake(
      static::getKeyName($modelName, $configuration['keyName'])
    );

    $table = static::getModelTable($foreignModelName);

    $foreignKeyName = Str::snake(
      static::getKeyName(
        $modelName,
        $configuration['foreignKeyName'] ??
          static::generateFieldName($foreignModelName, 'id')
      )
    );
    $foreignKeyStrategy = $configuration['foreignKeyStrategy'];
    $foreignKeyOptions = array_merge([
      'foreignKeyName' =>  $foreignKeyName
    ], $configuration['foreignKeyOptions']);

    // Check if we need to propagate changes by checking if the foreign model is also cacheable and references the summary field
    $propagate = $configuration['propagate'];
    if ($checkForeignModel) {
      if (!method_exists($foreignModelName, 'bootIsHoardableTrait')) {
        throw new UnableToHoardException(
          'Referenced model "' .
            $configuration['foreignModelName'] .
            '" must use IsHoardableTrait trait.'
        );
      }

      // If the summary field is used in a foreign configuration we need to propagate the changes
      $foreignConfigurations = $foreignModelName::getHoardConfigurations();
      $propagate = collect($foreignConfigurations)->some(function (
        $foreignConfiguration
      ) use ($foreignModelName, $summaryName, $modelName) {
        $foreignConfiguration = static::prepareConfiguration(
          $foreignModelName,
          $foreignConfiguration,
          $modelName,
          false
        );

        if (!$foreignConfiguration) {
          return false;
        }

        // If the summary field is used as another source of another configuration we are sure that we need to propagate the changes
        $propagate = $summaryName === $foreignConfiguration['valueName'];

        return $propagate;
      });
    }

    return [
      'function' => $function,
      'foreignModelName' => $foreignModelName,
      'table' => $table,
      'summaryName' => $summaryName,
      'valueName' => $configuration['valueName'],
      'keyName' => $keyName,
      'foreignKeyName' => $foreignKeyName,
      'foreignKeyStrategy' => $foreignKeyStrategy,
      'foreignKeyOptions' => $foreignKeyOptions,
      'ignoreEmptyForeignKeys' => $ignoreEmptyForeignKeys,
      'where' => $configuration['where'],
      'relationType' => $relationType,
      'wherePivot' => $configuration['wherePivot'],
      'pivotModelName' => $pivotModelName,
      'propagate' => $propagate,
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
   * Get foreign key selector
   *
   * @param string $strategy
   * @return string
   */
  public static function getForeignKeySelector(string $strategy, array $options)
  {
    switch ($strategy) {
      case 'Path': {
          return function ($query, $key) {
            $query->where('path', '~', '*.' . $key . '.*');
          };

          break;
        }

      case 'MorphToMany': {
          return function ($query, $foreignKey) use (
            $options
          ) {
            $parentKeyName = $options['parentKeyName'];
            $morphType = $options['morphType'];
            $morphClass = $options['morphClass'];
            $foreignPivotKeyName = $options['foreignPivotKeyName'];
            $relatedPivotKeyName = $options['relatedPivotKeyName'];
            $pivotModelName = $options['pivotModelName'];

            // Get all models that are mentioned in the pivot table
            $query->whereIn($parentKeyName, function ($whereQuery) use (
              $pivotModelName,
              $morphType,
              $morphClass,
              $foreignPivotKeyName,
              $relatedPivotKeyName,
              $foreignKey
            ) {
              $whereQuery
                ->select($foreignPivotKeyName)
                ->from(static::getModelTable($pivotModelName))
                ->where($relatedPivotKeyName, $foreignKey)
                ->where($morphType, $morphClass);
            });
          };

          break;
        }
    }

    return function ($query, $foreignKey) use ($options) {
      $foreignKeyName = $options['foreignKeyName'];

      $query->where($foreignKeyName, $foreignKey);
    };
  }

  /**
   * Get foreign key extractor
   *
   * @param string $strategy
   * @return string
   */
  public static function getForeignKeyExtractor(string $strategy, array $options)
  {
    switch ($strategy) {
      case 'Path': {
          return function ($key) {
            return explode('.', $key);
          };

          break;
        }

      case 'MorphToMany': {
          return function (
            $key,
            $model,
            $pivotModel,
            $eventName,
            $foreignKeyCache
          ) use (
            $options
          ) {
            $morphType = $options['morphType'];
            $morphClass = $options['morphClass'];
            $foreignPivotKeyName = $options['foreignPivotKeyName'];
            $relatedPivotKeyName = $options['relatedPivotKeyName'];
            $pivotModelName = $options['pivotModelName'];

            if ($pivotModel) {
              return $pivotModel[$relatedPivotKeyName];
            }

            // Create and update events can be neglected in a MorphToMany situation
            if ($eventName === 'create' || $eventName === 'update') {
              return null;
            }

            // Hoard foreign keys to avoid expensive queries
            $cacheKey = implode('-', [
              $pivotModelName,
              $foreignPivotKeyName,
              $key,
              $morphType,
              $morphClass,
            ]);
            if ($foreignKeyCache->has($cacheKey)) {
              return $foreignKeyCache->get($cacheKey);
            }

            // Get all foreign keys by querying the pivot table
            $keys = $pivotModelName
              ::where($foreignPivotKeyName, $key)
              ->where($morphType, $morphClass)
              ->pluck($relatedPivotKeyName);
            $foreignKeyCache->put($cacheKey, $keys);

            return $keys;
          };

          break;
        }
    }

    return function ($key) {
      return $key;
    };
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

    collect(get_class($model)::getForeignHoardConfigurations())->each(function (
      $foreignConfigurations,
      $foreignModelName
    ) use ($updates, $model, $pivotModel) {
      collect($foreignConfigurations)->each(function (
        $foreignConfiguration
      ) use ($foreignModelName, $updates, $model, $pivotModel) {
        $summaryName = $foreignConfiguration['summaryName'];
        $keyName = $foreignConfiguration['keyName'];
        $function = $foreignConfiguration['function'];
        $valueName = $foreignConfiguration['valueName'];
        $date = in_array($valueName, $model->getDates());
        $key = $model[$keyName];

        // Get query that retrieves the summary value
        $cacheQuery = static::prepareHoardQuery(
          $foreignModelName,
          $foreignConfiguration
        );
        $selectForeignKeys = static::getForeignKeySelector($foreignConfiguration['foreignKeyStrategy'], $foreignConfiguration['foreignKeyOptions']);
        $selectForeignKeys(
          $cacheQuery,
          $key,
          $model,
          $pivotModel
        );
        $sql = '(' . Hoard::convertQueryToRawSQL($cacheQuery) . ')';
        // dump('Get intermediate value for recalculation', $summaryName, $cacheQuery->toSql(), $cacheQuery->getBindings(), $cacheQuery->get());

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
              $function =
                static::getDatabaseDriver() === 'sqlite' ? 'MAX' : 'GREATEST';

              // We need to cast null values to a temporary low value because MAX(999999999999, NULL) leads to NULL in SQLite
              $temporaryValue = $date ? "'1900-01-01 00:00:00+00'" : "'0'";
              $sql =
                'NULLIF(' .
                $function .
                '(COALESCE(' .
                $existingSql .
                ', ' .
                $temporaryValue .
                '), COALESCE(' .
                $sql .
                ', ' .
                $temporaryValue .
                ')), ' .
                $temporaryValue .
                ')';
              break;

            case 'min':
              // Unfortunately, dialects use different names to get the minimum value
              $function =
                static::getDatabaseDriver() === 'sqlite' ? 'MIN' : 'LEAST';

              // We need to cast null values to a temporary high value because MIN(0, NULL) leads to NULL in SQLite
              $temporaryValue = $date ? "'2100-01-01 00:00:00+00'" : "'ZZZZZZZZZZZZZZZZZ'";
              $sql =
                'NULLIF(' .
                $function .
                '(COALESCE(' .
                $existingSql .
                ', ' .
                $temporaryValue .
                '), COALESCE(' .
                $sql .
                ', ' .
                $temporaryValue .
                ')), ' .
                $temporaryValue .
                ')';
              break;
          }
        }

        $updates->put($summaryName, $sql);
      });
    });

    return $updates->map(fn ($update) => DB::raw($update))->toArray();
  }

  /**
   * Rebuild the caches from the database
   *
   * @param array $foreignConfigurations
   * @return array
   */
  public function run()
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
    return $this->apply('create', function (
      $eventName,
      $configuration,
      $foreignKeys,
      $foreignKeyName,
      $isRelevant,
      $wasRelevant
    ) {
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
          if (!is_null($value)) {
            $rawUpdate = DB::raw(
              "CASE WHEN $summaryName > '$value' THEN $summaryName ELSE '$value' END"
            );
            $propagateValue = $value;
          }
          break;

        case 'min':
          if (!is_null($value)) {
            $rawUpdate = DB::raw(
              "CASE WHEN $summaryName < '$value' THEN $summaryName ELSE '$value' END"
            );
            $propagateValue = $value;
          }
          break;
      }

      return $this->prepareHoardUpdate(
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
    return $this->apply('delete', function (
      $eventName,
      $configuration,
      $foreignKeys,
      $foreignKeyName,
      $isRelevant,
      $wasRelevant
    ) {
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

      return $this->prepareHoardUpdate(
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

    return $this->apply($eventName, function (
      $eventName,
      $configuration,
      $foreignKeys,
      $foreignKeyName,
      $isRelevant,
      $wasRelevant
    ) {
      $extractForeignKeys = static::getForeignKeyExtractor($configuration['foreignKeyStrategy'], $configuration['foreignKeyOptions']);
      $originalForeignKeys = collect(
        $this->model->getOriginal($foreignKeyName) !==
          $this->model->{$foreignKeyName}
          ? $extractForeignKeys(
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
                $this->prepareHoardUpdate(
                  $addedForeignModelKeys,
                  $configuration,
                  $eventName,
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )
              ->concat(
                $this->prepareHoardUpdate(
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
            return $this->prepareHoardUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareHoardUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareHoardUpdate(
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
                $this->prepareHoardUpdate(
                  $foreignKeys,
                  $configuration,
                  $eventName,
                  DB::raw("$summaryName + $value"),
                  $value
                )
              )

              ->concat(
                $this->prepareHoardUpdate(
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
              return $this->prepareHoardUpdate(
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

            return $this->prepareHoardUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareHoardUpdate(
              $foreignKeys,
              $configuration,
              $eventName,
              DB::raw("$summaryName + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareHoardUpdate(
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
      return $this->prepareHoardUpdate(
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
      ->map(function ($configuration) use (
        $eventName,
        $callback,
        $foreignKeyCache
      ) {
        $where = $configuration['where'];
        $wherePivot = $configuration['wherePivot'];

        // Check if the pivot model is relevant
        if ($this->pivotModel) {
          $isPivotRelevant = $this::checkWhereCondition(
            $this->pivotModel,
            $this->pivotModel->getAttributes(),
            $wherePivot,
            true,
            $configuration
          );

          if (!$isPivotRelevant) {
            return;
          }
        }

          // Check if the actual model is relevant
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
        $extractForeignKeys = static::getForeignKeyExtractor($configuration['foreignKeyStrategy'], $configuration['foreignKeyOptions']);
        $foreignKeys = collect(
          $extractForeignKeys(
            $this->model->{$foreignKeyName},
            $this->model,
            $this->pivotModel,
            $eventName,
            $foreignKeyCache
          )
        );
        // dump('Get intermediate value for recalculation', $cacheQuery->toSql(), $cacheQuery->get());

        // Get updates for configuration
        $updates =
          $callback(
            $eventName,
            $configuration,
            $foreignKeys,
            $foreignKeyName,
            $isRelevant,
            $wasRelevant
          ) ?? [];

        return collect(Arr::isAssoc($updates) ? [$updates] : $updates)->filter(
          function ($update) {
            return $update !== null;
          }
        );
      })
      ->filter()
      ->reduce(function ($cumulatedUpdates, $updates) {
        return $cumulatedUpdates->concat($updates);
      }, collect([]))
      ->filter(function ($update) {
        // Filter out updates that should be ignored (i.e. usually updates that were executed before to avoid duplicates)
        return !$this->ignoredUpdates->some(function ($ignoredUpdate) use ($update) {
          return $update['foreignModelName'] === $ignoredUpdate['foreignModelName']
            &&
            $update['foreignKey'] === $ignoredUpdate['foreignKey']
            &&
            $update['summaryName'] === $ignoredUpdate['summaryName']
            &&
            $update['keyName'] === $ignoredUpdate['keyName'];
        });
      });

    // Group updates by model, key, foreign key and propagate and update each group independently
    $allUpdates
      ->groupBy(['foreignModelName', 'keyName', 'foreignKey', 'propagate'])
      ->each(function ($keyNames, $foreignModelName) {
        $keyNames->each(function ($foreignKeys, $keyName) use (
          $foreignModelName
        ) {
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
              $query = DB::table(
                static::getModelTable($foreignModelName)
              )->where($keyName, $foreignKey);
              $values = $updates->mapWithKeys(function ($update) {
                return [
                  $update['summaryName'] => $update['rawValue'],
                ];
              });
              $query->update($values->toArray());

              // Propagate fields and trigger cache update on model above
              if ($propagate) {
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
                (new Hoard(
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

    return $allUpdates;
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
  public function prepareHoardUpdate(
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
          '" is not an attribute on "' .
          get_class($this->model) .
          '".'
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

          /*if ($summaryName === 'TEST') {
            dump(
              'Get intermediate value',
              $foreignModelName,
              $rawValue,
              $foreignModelName
                ::query()
                ->select($rawValue)
                ->first()
                ->toArray()
            );
          }*/
        }

        return [
          'event' => $event,
          'foreignModelName' => $foreignModelName,
          'summaryName' => $summaryName,
          'keyName' => $keyName,
          'foreignKey' => $foreignKey,
          'rawValue' => $rawValue,
          'propagate' => $configuration['propagate'],
          'propagateValue' => $propagateValue,
        ];
      })
      ->toArray();
  }

  /**
   * Create cache query
   *
   * @param string $modelName
   * @param mixed $configuration
   *
   * @return \Illuminate\Database\Query\Builder
   */
  protected static function prepareHoardQuery($modelName, $configuration)
  {
    $function = $configuration['function'];
    $valueName = $configuration['valueName'];
    $where = $configuration['where'];
    $defaultValue = static::getDefaultValue($function);

    // Create cache query
    $cacheQuery = DB::table(static::getModelTable($modelName))
      ->select(DB::raw("COALESCE($function($valueName), $defaultValue)"))
      ->where($configuration['where']);

      // Add where statement
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

          if ($operator === 'in') {
            $cacheQuery->whereIn($attribute, $value);
          } else {
            $cacheQuery->where($attribute, $operator, $value);
          }
        }

    // Respect soft delete
    if (
      in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelName))
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
  protected static function convertQueryToRawSQL(Builder $query)
  {
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
      $morphClass = static::getMorphClass($model);
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
        throw new UnableToHoardException(
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

      $isRelevant = false;
      $modelValue = $attributes[$attribute] ?? null;
      switch ($operator) {
        case '=':
          $isRelevant = $modelValue === $value;
          break;
        case '<':
          $isRelevant = $modelValue < $value;
          break;
        case '<=':
          $isRelevant = $modelValue <= $value;
          break;
        case '>':
          $isRelevant = $modelValue > $value;
          break;
        case '>=':
          $isRelevant = $modelValue > $value;
          break;
        case '<>':
          $isRelevant = $modelValue !== $value;
          break;
        case 'in':
          $isRelevant = collect($value)->some(fn ($singleValue) => $modelValue === $singleValue);
          break;
      }

      if (!$isRelevant) {
        return false;
      }
    }

    return true;
  }

  /**
   * Get protected "morphClass" attribute of model.
   *
   * @param Model     $model
   */
  public static function getMorphClass(
    Model $model,
  ) {
    // Ugly workaround to get access to the morphClass property
    $morphClassProperty = new ReflectionProperty(
      MorphPivot::class,
      'morphClass'
    );
    $morphClassProperty->setAccessible(true);

    return $morphClassProperty->getValue($model);
  }
}
