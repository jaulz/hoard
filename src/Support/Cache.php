<?php
namespace Jaulz\Eloquence\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Eloquence\Exceptions\UnableToCacheException;
use Jaulz\Eloquence\Exceptions\UnableToPropagateException;

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
        return $this->config($model, $configuration);
      })
      ->filter(function ($configuration) use ($propagatedBy) {
        return !empty($propagatedBy)
          ? collect($propagatedBy)->contains($configuration['value'])
          : true;
      });
  }

  /**
   * Takes a registered sum cache, and setups up defaults.
   *
   * @param Model $model
   * @param array $configuration
   * @param bool $checkForeignModel
   * @return array
   */
  protected static function config(
    $model,
    $configuration,
    ?bool $checkForeignModel = true
  ) {
    $foreignModelName = $configuration['foreign_model'];
    $modelName = $model instanceof Model ? get_class($model) : $model;
    $function = Str::lower($configuration['function']);

    // Prepare defaults
    $defaults = [
      'value' => 'id',
      'foreign_key' => self::field($foreignModelName, 'id'),
      'key' => 'id',
      'where' => [],
      'summary' => self::field(Str::plural($modelName), $function),
      'context' => null,
      'through' => null,
    ];

    // Merge defaults and actual config
    $configuration = array_merge($defaults, $configuration);

    // Check if we need to propagate changes by checking if the foreign model is also cacheable
    $propagate = false;
    if (
      $checkForeignModel
    ) {
      if (!method_exists(new $foreignModelName(), 'bootIsCacheableTrait')) {
        throw new UnableToCacheException('Referenced model "' . $configuration['foreign_model'] . '" must use IsCacheableTrait trait.');
      }

      $foreignModelInstance = new $foreignModelName();
      $foreignConfiguration = $foreignModelInstance->getCacheConfigurations();

      $propagate = collect($foreignConfiguration)->some(function ($foreignConfiguration) use (
        $modelName,
        $foreignModelName,
        $configuration
      ) {
        $foreignConfiguration = static::config(
          $foreignModelName,
          $foreignConfiguration,
          false
        );
        $propagate = $configuration['summary'] === $foreignConfiguration['value'];

        return $propagate;
      });
    }

    // Prepare options
    $foreignKey = Str::snake(
      self::key(
        $modelName,
        is_array($configuration['foreign_key'])
          ? $configuration['foreign_key'][0]
          : $configuration['foreign_key']
      )
    );
    $foreignKeyExtractor = function ($foreignKey) {
      return $foreignKey;
    };
    $foreignKeySelector = function ($query, $key) use ($foreignKey) {
      $query->where($foreignKey, $key);
    };
    if (is_array($configuration['foreign_key'])) {
      $foreignKeyExtractor = $configuration['foreign_key'][1];
      $foreignKeySelector = $configuration['foreign_key'][2];
    }

    return [
      'function' => $function,
      'foreignModel' => $foreignModelName,
      'table' => self::getModelTable($configuration['foreign_model']),
      'summary' => Str::snake($configuration['summary']),
      'value' => $configuration['value'],
      'key' => Str::snake(self::key($modelName, $configuration['key'])),
      'foreignKey' => $foreignKey,
      'foreignKeyExtractor' => $foreignKeyExtractor,
      'foreignKeySelector' => $foreignKeySelector,
      'ignoreEmptyForeignKeys' => (is_array($configuration['foreign_key'])) || ($foreignModelName === $modelName),
      'where' => $configuration['where'],
      'propagate' => $configuration['propagate'] ?? $propagate,
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
    $updates = collect($foreignConfigurations)
      ->mapWithKeys(function ($configurations, $foreignModelName) use (
        $valueColumns
      ) {
        return collect($configurations)
          ->map(function ($configuration) use ($foreignModelName, $valueColumns) {
            // Normalize config
            $configuration = self::config($foreignModelName, $configuration);

            // Collect all value columns
            $valueColumns->push($configuration['value']);

            return $configuration;
          })
          ->mapWithKeys(function ($configuration) use ($foreignModelName) {
            $cacheQuery = static::prepareCacheQuery($foreignModelName, $configuration);
            $configuration['foreignKeySelector']($cacheQuery, $this->model[$configuration['key']]);

            return [
              $configuration['summary'] => DB::raw(
                '(' . Cache::convertQueryToRawSQL($cacheQuery->take(1)) . ')'
              ),
            ];
          });
      })
      ->toArray();

    // Run update
    if (count($updates) > 0) {
      DB::table(self::getModelTable($this->model))
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
    $this->apply(function ($configuration) {
      $foreignKeyColumn = self::foreignKey($this->model, $configuration['foreignKey']);
      $foreignKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKeyColumn})
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
          $rawUpdate = DB::raw("CASE WHEN $summaryColumn > '$value' then $summaryColumn ELSE '$value' END");
          $propagateValue = $value;
          break;

        case 'min':
          $rawUpdate = DB::raw("CASE WHEN $summaryColumn < '$value' then $summaryColumn ELSE '$value' END");
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
      $foreignKey = self::foreignKey($this->model, $configuration['foreignKey']);
      $foreignModelKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKey})
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
      $foreignKeyColumn = self::foreignKey($this->model, $configuration['foreignKey']);
      $foreignKeys = collect(
        $configuration['foreignKeyExtractor']($this->model->{$foreignKeyColumn})
      );
      $originalForeignKeys = collect(
        $configuration['foreignKeyExtractor'](
          $this->model->getOriginal($foreignKeyColumn)
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
      $value = $this->model->{$valueColumn} ?? static::getDefaultValue($configuration['function']);
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
   * @param ?boolean   $rebuild Rebuild cache and thus force the operation.
   */
  public function apply(\Closure $function, ?bool $rebuild = false)
  {
    // Gather all updates from every config
    $allUpdates = collect($this->configurations)
      ->map(function ($configuration) use ($function) {
        $isRelevant = $this::checkWhereCondition($this->model, true, $configuration);
        $wasRelevant = $this::checkWhereCondition($this->model, false, $configuration);

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
      ->groupBy(['foreignModel', 'key', 'foreignKey', 'propagate'])
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
              $query = DB::table(self::getModelTable($foreignModelName))->where($key, $foreignKey);
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
                  $foreignModel,
                  $propagate
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
          $configuration['foreignKey'] .
          '" is not an attribute on the model.'
      );
    }

    return collect($validForeignKeys)
      ->map(function ($foreignKey) use ($event, $configuration, $propagateValue, $rawValue) {
        $model = $configuration['foreignModel'];
        $function = $configuration['function'];
        $summaryColumn = $configuration['summary'];
        $valueColumn = $configuration['value'];
        $keyColumn = $configuration['key'];
        $defaultValue = static::getDefaultValue($function);
        $foreignKeyColumn = $configuration['foreignKey'];

        // Create cache query
        $cacheQuery = static::prepareCacheQuery($this->model, $configuration)->where($foreignKeyColumn , $foreignKey);

        return [
          'event' => $event,
          'foreignModel' => $model,
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
    $foreignModel = $configuration['foreignModel'];
    $function = $configuration['function'];
    $summaryColumn = $configuration['summary'];
    $valueColumn = $configuration['value'];
    $keyColumn = $configuration['key'];
    $defaultValue = static::getDefaultValue($function);

    // Create cache query
    $cacheQuery = DB::table(self::getModelTable($model))->select(DB::raw("COALESCE($function($valueColumn), $defaultValue)"))->where($configuration['where']);
    if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model))) {
      $cacheQuery->whereNull('deleted_at');
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
    return Str::snake(self::key($model, $field));
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
  protected static function checkWhereCondition($model, $current, $configuration)
  {
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
      if ($current && !array_key_exists($attribute, $model->getAttributes())) {
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
        ? $model->getAttributes()[$attribute]
        : $model->getRawOriginal($attribute);
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
