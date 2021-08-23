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
  private $configs;

  /**
   * @param Model $model
   */
  public function __construct(Model $model, $propagatedBy = [])
  {
    $this->model = $model;
    $this->propagatedBy = $propagatedBy;
    $this->configs = collect($model->caches())
      ->map(function ($config) use ($model) {
        return $this->config($model, $config);
      })
      ->filter(function ($config) use ($propagatedBy) {
        return !empty($propagatedBy)
          ? collect($propagatedBy)->contains($config['value'])
          : true;
      });
  }

  /**
   * Takes a registered sum cache, and setups up defaults.
   *
   * @param Model $model
   * @param array $config
   * @param bool $checkForeignModel
   * @return array
   */
  protected static function config(
    $model,
    $config,
    ?bool $checkForeignModel = true
  ) {
    $foreignModelName = $config['foreign_model'];
    $modelName = $model instanceof Model ? get_class($model) : $model;
    $function = Str::lower($config['function']);

    // Prepare defaults
    $defaults = [
      'value' => 'id',
      'foreign_key' => self::field($foreignModelName, 'id'),
      'key' => 'id',
      'where' => [],
      'summary' => self::field(Str::plural($modelName), $function),
      'context' => null,
    ];

    // Merge defaults and actual config
    $config = array_merge($defaults, $config);

    // Check if we need to propagate changes by checking if the foreign model is also cacheable
    $propagate = false;
    if (
      $checkForeignModel
    ) {
      if (!method_exists(new $foreignModelName(), 'bootIsCacheableTrait')) {
        throw new UnableToCacheException('Referenced model "' . $config['foreign_model'] . '" must use IsCacheableTrait trait.');
      }

      $foreignModelInstance = new $foreignModelName();
      $foreignConfig = $foreignModelInstance->caches();

      $propagate = collect($foreignConfig)->some(function ($foreignConfig) use (
        $modelName,
        $foreignModelName,
        $config
      ) {
        $foreignConfig = static::config(
          $foreignModelName,
          $foreignConfig,
          false
        );
        $propagate = $config['summary'] === $foreignConfig['value'];

        return $propagate;
      });
    }

    // Prepare options
    $foreignKey = Str::snake(
      self::key(
        $modelName,
        is_array($config['foreign_key'])
          ? $config['foreign_key'][0]
          : $config['foreign_key']
      )
    );

    return [
      'function' => $function,
      'foreign_model' => $foreignModelName,
      'table' => self::getModelTable($config['foreign_model']),
      'summary' => Str::snake($config['summary']),
      'value' => $config['value'],
      'key' => Str::snake(self::key($modelName, $config['key'])),
      'foreign_key' => $foreignKey,
      'foreign_key_extractor' => is_array($config['foreign_key'])
        ? $config['foreign_key'][1]
        : function ($foreignKey) {
          return $foreignKey;
        },
      'foreign_key_selector' => is_array($config['foreign_key'])
        ? $config['foreign_key'][2]
        : function ($query, $key) use ($foreignKey) {
          $query->where($foreignKey, $key);
        },
      'where' => $config['where'],
      'propagate' => $config['propagate'] ?? $propagate,
      'context' => $config['context'],
      'recursive' => $foreignModelName === $modelName,
    ];
  }

  /**
   * Rebuild the count caches from the database
   *
   * @param array $foreignConfigs
   * @return array
   */
  public function rebuild($foreignConfigs)
  {
    // Prepare all update statements
    $valueColumns = collect([]);
    $updates = collect($foreignConfigs)
      ->mapWithKeys(function ($configs, $foreignModelName) use (
        $valueColumns
      ) {
        return collect($configs)
          ->map(function ($config) use ($foreignModelName, $valueColumns) {
            // Normalize config
            $config = self::config($foreignModelName, $config);

            // Collect all value columns
            $valueColumns->push($config['value']);

            return $config;
          })
          ->mapWithKeys(function ($config) use ($foreignModelName) {
            $cacheQuery = static::prepareCacheQuery($foreignModelName, $config);
            $config['foreign_key_selector']($cacheQuery, $this->model[$config['key']]);

            return [
              $config['summary'] => DB::raw(
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
    $this->apply(function ($config) {
      $foreignKeyColumn = self::foreignKey($this->model, $config['foreign_key']);
      $foreignKeys = collect(
        $config['foreign_key_extractor']($this->model->{$foreignKeyColumn})
      );
      $function = $config['function'];
      $summaryColumn = $config['summary'];
      $valueColumn = $config['value'];
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
        $config,
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
    $this->apply(function ($config) {
      $foreignKey = self::foreignKey($this->model, $config['foreign_key']);
      $foreignModelKeys = collect(
        $config['foreign_key_extractor']($this->model->{$foreignKey})
      );
      $function = $config['function'];
      $summaryColumn = $config['summary'];
      $valueColumn = $config['value'];
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
        $config,
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
    $this->apply(function ($config, $isRelevant, $wasRelevant) {
      $foreignKeyColumn = self::foreignKey($this->model, $config['foreign_key']);
      $foreignKeys = collect(
        $config['foreign_key_extractor']($this->model->{$foreignKeyColumn})
      );
      $originalForeignKeys = collect(
        $config['foreign_key_extractor'](
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
      $summaryColumn = $config['summary'];
      $valueColumn = $config['value'];
      $value = $this->model->{$valueColumn};
      $originalValue = $this->model->getOriginal($valueColumn);
      $restored =
        $this->model->deleted_at !== $this->model->getOriginal('deleted_at');
      $dirty = $this->model->isDirty();

      // Handle certain cases more efficiently
      switch ($config['function']) {
        case 'count':
          // In case the foreign keys changed, we just transfer the values from one model to the other
          if ($changedForeignKeys) {
            return collect([])
              ->concat(
                $this->prepareCacheUpdate(
                  $addedForeignModelKeys,
                  $config,
                  'updated',
                  DB::raw("$summaryColumn + $value"),
                  $value
                )
              )
              ->concat(
                $this->prepareCacheUpdate(
                  $removedForeignModelKeys,
                  $config,
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
              $config,
              'updated',
              DB::raw("$summaryColumn + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $config,
              'updated',
              DB::raw("$summaryColumn + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $config,
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
                  $config,
                  'updated',
                  DB::raw("$summaryColumn + $value"),
                  $value
                )
              )

              ->concat(
                $this->prepareCacheUpdate(
                  $originalForeignKeys,
                  $config,
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
                $config,
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
              $config,
              'updated',
              DB::raw("$summaryColumn + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $config,
              'updated',
              DB::raw("$summaryColumn + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->prepareCacheUpdate(
              $foreignKeys,
              $config,
              'updated',
              DB::raw("$summaryColumn - $originalValue"),
              -1 * $originalValue
            );
          }

          break;
      }

      // Run update with recalculation
      return $this->prepareCacheUpdate($foreignKeys, $config, 'updated');
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
    $allUpdates = collect($this->configs)
      ->map(function ($config) use ($function) {
        $isRelevant = $this::checkWhereCondition($this->model, true, $config);
        $wasRelevant = $this::checkWhereCondition($this->model, false, $config);

        if (!$isRelevant && !$wasRelevant) {
          return null;
        }

        return $function($config, $isRelevant, $wasRelevant);
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
      ->groupBy(['foreign_model', 'key', 'foreign_key', 'propagate'])
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
   * @param array $config
   * @param string $event Possible events: created/deleted/updated
   * @param ?any $rawValue Raw value
   * @param ?any $propagateValue Value to propagate
   * @return array
   */
  public function prepareCacheUpdate(
    $foreignKeys,
    array $config,
    $event,
    $rawValue = null,
    $propagateValue = null
  ) {
    $validForeignKeys = $foreignKeys->filter(function ($foreignKey) {
      return !!$foreignKey;
    });

    if ($validForeignKeys->count() === 0) {
      if ($config['recursive']) {
        return [];
      }

      throw new UnableToPropagateException(
        'Unable to propagate cache update to "' .
          $config['function'] .
          '(' .
          $config['value'] .
          ')" into "' .
          $config['table'] .
          '"."' .
          $config['summary'] .
          '" because "' .
          $config['foreign_key'] .
          '" was not part of the context.'
      );
    }

    return collect($validForeignKeys)
      ->map(function ($foreignKey) use ($event, $config, $propagateValue, $rawValue) {
        $model = $config['foreign_model'];
        $function = $config['function'];
        $summaryColumn = $config['summary'];
        $valueColumn = $config['value'];
        $keyColumn = $config['key'];
        $defaultValue = static::getDefaultValue($function);
        $foreignKeyColumn = $config['foreign_key'];

        // Create cache query
        $cacheQuery = static::prepareCacheQuery($this->model, $config)->where($foreignKeyColumn , $foreignKey);

        return [
          'foreign_model' => $model,
          'summaryColumn' => $summaryColumn,
          'key' => $keyColumn,
          'foreign_key' => $foreignKey,
          'rawValue' =>
            $rawValue ??
            DB::raw('(' . Cache::convertQueryToRawSQL($cacheQuery) . ')'),
          'propagate' => $config['propagate'],
          'propagateValue' => $propagateValue,
          'context' => $config['context'],
        ];
      })
      ->toArray();
  }

  /**
   * Create cache query
   *
   * @param mixed $config
   *
   * @return \Illuminate\Database\Query\Builder
   */
  protected static function prepareCacheQuery($model, $config)
  {
    $foreignModel = $config['foreign_model'];
    $function = $config['function'];
    $summaryColumn = $config['summary'];
    $valueColumn = $config['value'];
    $keyColumn = $config['key'];
    $defaultValue = static::getDefaultValue($function);

    // Create cache query
    $cacheQuery = DB::table(self::getModelTable($model))->select(DB::raw("COALESCE($function($valueColumn), $defaultValue)"))->where($config['where']);
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
   * @param any       $config
   * @param \Closure $function
   */
  protected static function checkWhereCondition($model, $current, $config)
  {
    foreach ($config['where'] as $attribute => $value) {
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
            $config['function'] .
            '(' .
            $config['value'] .
            ')" into "' .
            $config['table'] .
            '"."' .
            $config['summary'] .
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