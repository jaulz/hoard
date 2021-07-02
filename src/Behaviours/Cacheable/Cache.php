<?php
namespace Jaulz\Eloquence\Behaviours\Cacheable;

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
  private $model;

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
    $this->configs = collect($model->caches())->map(function ($config) use (
      $model
    ) {
      return $this->config($model, $config);
    })->filter(function ($config) use ($propagatedBy) {
      return !empty($propagatedBy) ? collect($propagatedBy)->contains($config['field']) : true;
    });
  }

  /**
   * Takes a registered sum cache, and setups up defaults.
   *
   * @param Model $model
   * @param array $config
   * @return array
   */
  protected static function config($model, $config)
  {
    $foreignModelName = $config['model'];
    $modelName = $model instanceof Model ? get_class($model) : $model;
    $function = Str::lower($config['function']);

    // Prepare defaults
    $defaults = [
      'field' => 'id',
      'foreignKey' => self::field($foreignModelName, 'id'),
      'key' => 'id',
      'where' => [],
      'summary' => self::field($modelName, $function),
      'propagate' => false
    ];

    // Merge defaults and actual config
    $config = array_merge($defaults, $config);
    $relatedModel = $config['model'];

    // Check if we need to propagate changes by checking if the foreign model is also cacheable
    $propagate = false;
    if (method_exists((new $foreignModelName()), 'bootCacheable')) {
      $foreignModelInstance = new $foreignModelName();
      $foreignConfig = $foreignModelInstance->caches();

      $propagate = collect($foreignConfig)->some(function($foreignConfig) use ($modelName, $foreignModelName, $config) {
        $foreignConfig = static::config($foreignModelName, $foreignConfig);
        $propagate = $config['summary'] === $foreignConfig['field'];

        if ($propagate && !$config['propagate']) {
          throw new UnableToPropagateException('Unable to propagate "' . $foreignConfig['foreignKey'] . '" from "' . $modelName . '" to "' . $foreignModelName . '" because no propagation field was defined.');
        }

        return $propagate;
      });

    }

    return [
      'function' => $function,
      'model' => $foreignModelName,
      'table' => self::getModelTable($config['model']),
      'summary' => Str::snake($config['summary']),
      'field' => $config['field'],
      'key' => Str::snake(self::key($modelName, $config['key'])),
      'foreignKey' => Str::snake(self::key($modelName, $config['foreignKey'])),
      'where' => $config['where'],
      'propagate' => $propagate ? $config['propagate'] ?? [] : false,
    ];
  }

  /**
   * Rebuild the count caches from the database
   *
   * @param array $configs
   * @return array
   */
  public function rebuild($configs)
  {
    return self::rebuildCacheRecords($this->model, $configs);
  }

  /*
   * Create the cache entry
   */
  public function create()
  {
    $this->apply(function ($config) {
      $foreignKey = self::foreignKey($this->model, $config['foreignKey']);
      $function = $config['function'];
      $summary = $config['summary'];
      $field = $config['field'];
      $value = $this->model->{$field};

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summary + 1");
          $propagateValue = 1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summary + $value");
          $propagateValue = $value;
          break;
      }

      return $this->updateCacheRecord(
        $this->model->{$foreignKey},
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
      $foreignKey = self::foreignKey($this->model, $config['foreignKey']);
      $function = $config['function'];
      $summary = $config['summary'];
      $field = $config['field'];
      $value = $this->model->{$field};

      // Handle certain cases more efficiently
      $rawUpdate = null;
      $propagateValue = null;
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summary - 1");
          $propagateValue = -1;
          break;

        case 'sum':
          $value = $value ?? 0;
          $rawUpdate = DB::raw("$summary - $value");
          $propagateValue = -1 * $value;
          break;
      }

      return $this->updateCacheRecord(
        $this->model->{$foreignKey},
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
      $foreignKey = self::foreignKey($this->model, $config['foreignKey']);
      $summary = $config['summary'];
      $value = $this->model->{$config['field']};
      $originalValue = $this->model->getOriginal($config['field']);
      $changedForeignKey =
        $this->model->getOriginal($foreignKey) &&
        $this->model->{$foreignKey} != $this->model->getOriginal($foreignKey);
      $restored =  $this->model->deleted_at !== $this->model->getOriginal('deleted_at');
      $dirty = $this->model->isDirty();

      // Handle certain cases more efficiently
      switch ($config['function']) {
        case 'count':
          // In case the foreign key changed, we just transfer the values from one model to the other
          if ($changedForeignKey) {
            return [
              $this->updateCacheRecord(
                $this->model->{$foreignKey},
                $config,
                'updated',
                DB::raw("$summary + $value"),
                $value
              ),

              $this->updateCacheRecord(
                $this->model->getOriginal($foreignKey),
                $config,
                'updated',
                DB::raw("$summary + (-1 * $value)"),
                -1 * $value,
              )
            ];
          }

          if ($isRelevant && $wasRelevant) {
            // Nothing to do
            if (!$restored) {
              return null;
            }

            // Restore count indicator if item is restored
            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + 1"),
              1
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + 1"),
              1
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary - 1"),
              -1
            );
          }

          break;

        case 'sum':
          // In case the foreign key changed, we just transfer the values from one model to the other
          if ($changedForeignKey) {
            return [
              $this->updateCacheRecord(
                $this->model->{$foreignKey},
                $config,
                'updated',
                DB::raw("$summary + $value"),
                $value,
              ),

              $this->updateCacheRecord(
                $this->model->getOriginal($foreignKey),
                $config,
                'updated',
                DB::raw("$summary + (-1 * $value)"),
                -1 * $value
              )
            ];
          }

          if ($isRelevant && $wasRelevant) {
            if ($restored) {
              return $this->updateCacheRecord(
                $this->model->{$foreignKey},
                $config,
                'updated',
                DB::raw("$summary + $value"),
                $value
              );
            }

            // We need to add the difference in case it is as relevant as before
            $difference = $value - ($originalValue ?? 0);

            if ($difference === 0) {
              return [];
            }

            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + $difference"),
              $difference
            );
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + $value"),
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            return $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary - $originalValue"),
              -1 * $originalValue,
            );
          }

          break;
      }

      // Run update with recalculation
      return $this->updateCacheRecord($this->model->{$foreignKey}, $config, 'updated');
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
    collect($this->configs)->map(function ($config) use ($function) {
      $isRelevant = $this::checkWhereCondition($this->model, true, $config);
      $wasRelevant = $this::checkWhereCondition($this->model, false, $config);

      if (!$isRelevant && !$wasRelevant) {
        return null;
      }

      return $function($config, $isRelevant, $wasRelevant);
    })->filter(function ($update) {
      return $update !== null;
    })
    ->reduce(function ($cumulatedUpdates, $update) {
      return $cumulatedUpdates->concat(Arr::isAssoc($update) ? [$update] : $update);
    }, collect([]))
    ->groupBy(['model', 'key', 'foreignKey', 'propagate'])->each(function ($keys, $foreignModel) {
      $keys->each(function($foreignKeys, $key) use ($foreignModel, $keys) {
        $foreignKeys->each(function($propagates, $foreignKey) use ($foreignModel, $key, $keys) {
          $propagates->each(function($updates, $propagate) use ($foreignModel, $key, $keys, $foreignKey) {
            $query = $foreignModel::where($key, $foreignKey);
            $foreignModelInstance = new $foreignModel();

            // Update entity in one go
            $values = $updates->mapWithKeys(function($update) use ($foreignModelInstance) {
              return [
                $update['summary'] => $update['rawValue']
              ];
            });
            $query->update($values->toArray());

            // Propagate fields and trigger cache update on model above
            if ($propagate) {
              $foreignModelInstance->{$key} = $foreignKey;

              // Fill foreign model with field that should be propagated
              $propagations = $updates->map(function ($update) use ($updates) {
                return [
                  'summary' => $update['summary'],
                  'propagate' => $update['propagate'],
                  'propagateValue' => $update['propagateValue'],
                ];
              }); 
              $propagations->each(function ($propagation) use ($foreignModelInstance) {
                $foreignModelInstance->{$propagation['summary']} = $propagation['propagateValue'];

                // Propagations can either be defined as simple strings (in case the field has the same name in both models) or arrays (['source' => 'target'])
                if (is_array($propagation['propagate'])) {
                  $foreignModelInstance->{$propagation['propagate'][1]} = $this->model[$propagation['propagate'][0]];
                } else {
                  $foreignModelInstance->{$propagation['propagate']} = $this->model[$propagation['propagate']];
                }
              });

              (new Cache($foreignModelInstance, $propagations->map(function ($propagation) {
                return $propagation['summary'];
              })->toArray()))->update();
            }
          });
        });
      });
    });
  }

  /**
   * Updates a table's record based on the query information provided in the $config variable.
   *
   * @param any $foreignKey Foreign key
   * @param array $config
   * @param string $event Possible events: created/deleted/updated
   * @param ?any $rawValue Raw value
   * @param ?any $rawValue Propagate value
   * @return array
   */
  public function updateCacheRecord(
    $foreignKey,
    array $config,
    $event,
    $rawValue = null,
    $propagateValue = null
  ) {
    $model = $config['model'];
    $function = $config['function'];
    $summary = $config['summary'];
    $field = $config['field'];
    $key = $config['key'];
    $foreignKey =
      $foreignKey instanceof Model ? $foreignKey[$key] : $foreignKey;
    $defaultValue = $function === 'sum' ? 0 : 'null';

    $cacheQuery = $this->model
      ::select(DB::raw("COALESCE($function($field), $defaultValue)"))
      ->where($config['where'])
      ->where($config['foreignKey'], $foreignKey);

    return [
      'model' => $model,
      'summary' => $config['summary'],
      'key' => $config['key'],
      'foreignKey' => $foreignKey,
      'rawValue' => $rawValue ?? DB::raw('(' . Cache::convertQueryToRawSQL($cacheQuery) . ')'),
      'propagate' => $config['propagate'],
      'propagateValue' => $propagateValue
    ];
  }

  /**
   * Rebuilds the cache for the records in question.
   *
   * @param Model $model
   * @param array $foreignConfigs
   * @return array
   */
  public static function rebuildCacheRecords(
    Model $model,
    array $foreignConfigs
  ) {
    // Get all update statements
    $fields = collect([]);
    $updates = collect($foreignConfigs)
      ->mapWithKeys(function ($configs, $foreignModel) use ($model, $fields) {
        return collect($configs)
          ->map(function ($config) use ($foreignModel, $fields) {
            // Normalize config
            $config = self::config($foreignModel, $config);

            // Collect all fields
            $fields->push($config['field']);

            return $config;
          })
          ->mapWithKeys(function ($config) use ($model, $foreignModel) {
            // Create query that selects the aggregated field from the foreign table
            $function = $config['function'];
            $field = $config['field'];
            $defaultValue = $function === 'sum' ? 0 : 'null';
            $query = $foreignModel
              ::select(DB::raw("COALESCE($function($field), $defaultValue)"))
              ->where($config['where'])
              ->where($config['foreignKey'], $model[$config['key']])
              ->take(1);

            return [
              $config['summary'] => DB::raw(
                '(' . Cache::convertQueryToRawSQL($query) . ')'
              ),
            ];
          });
      })
      ->toArray();

    // Run updates unguarded and without timestamps
    $before = collect($model->getAttributes())->only($fields);
    $success = $model::unguarded(function () use ($model, $updates) {
      $model->fill($updates);
      $model->timestamps = false;

      return $model->saveQuietly();
    });
    $after = collect($model->refresh()->getAttributes())->only($fields);

    return [
      'before' => $before->toArray(),
      'after' => $after->toArray(),
      'difference' => $before->diffAssoc($after)->toArray(),
    ];
  }

  /**
   * Convert SQL query to raw SQL string.
   *
   * @param \Illuminate\Database\Eloquent\Builder $query
   *
   * @return string
   */
  protected static function convertQueryToRawSQL(
    \Illuminate\Database\Eloquent\Builder $query
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
   * @param string $model
   * @param string $field
   * @return mixed
   */
  protected static function key(string $model, $field)
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
   * @param string $model
   * @param string $field
   * @return mixed
   */
  protected static function foreignKey(string $model, $field)
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
      if (
        $current &&
        !array_key_exists($attribute, $model->getAttributes())
      ) {
        throw new UnableToCacheException(
          'Unable to cache "' . $config['function'] .'(' .
            $config['field'] .
            ')" into "' . $config['summary'] . '" because ' .
            $attribute .
            ' is part of the where condition but it is not set explicitly on the entity.'
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
