<?php
namespace Jaulz\Eloquence\Behaviours\Cacheable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Eloquence\Exceptions\UnableToCacheException;

class Cache
{
  /**
   * @var Model
   */
  private $model;

  /**
   * @var array
   */
  private $configs;

  /**
   * @param Model $model
   */
  public function __construct(Model $model)
  {
    $this->model = $model;
    $this->configs = collect($model->caches())->map(function ($config) use (
      $model
    ) {
      return $this->config($model, $config);
    });
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
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summary + 1");
          break;

        case 'sum':
          $rawUpdate = DB::raw("$summary + $value");
          break;
      }

      $this->updateCacheRecord(
        $this->model->{$foreignKey},
        $config,
        'created',
        $rawUpdate
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
      switch ($function) {
        case 'count':
          $rawUpdate = DB::raw("$summary - 1");
          break;

        case 'sum':
          $rawUpdate = DB::raw("$summary - $value");
          break;
      }

      $this->updateCacheRecord(
        $this->model->{$foreignKey},
        $config,
        'deleted',
        $rawUpdate
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

      // Handle certain cases more efficiently
      switch ($config['function']) {
        case 'count':
          // In case the foreign key changed, we just transfer the values from one model to the other
          if ($changedForeignKey) {
            $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + $value")
            );
            $this->updateCacheRecord(
              $this->model->getOriginal($foreignKey),
              $config,
              'updated',
              DB::raw("$summary + (-1 * $value)")
            );
            return;
          }

          break;

        case 'sum':
          // In case the foreign key changed, we just transfer the values from one model to the other
          if ($changedForeignKey) {
            $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              DB::raw("$summary + $value")
            );
            $this->updateCacheRecord(
              $this->model->getOriginal($foreignKey),
              $config,
              'updated',
              DB::raw("$summary + (-1 * $value)")
            );
            return;
          }

          if ($isRelevant && $wasRelevant) {
            // We need to add the difference in case it is as relevant as before
            $difference = $value - $originalValue;

            if ($difference > 0) {
              $this->updateCacheRecord(
                $this->model->{$foreignKey},
                $config,
                'updated',
                $difference
              );
            }
          } elseif ($isRelevant && !$wasRelevant) {
            // Increment because it was not relevant before but now it is
            $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              $value
            );
          } elseif (!$isRelevant && $wasRelevant) {
            // Decrement because it was relevant before but now it is not anymore
            $this->updateCacheRecord(
              $this->model->{$foreignKey},
              $config,
              'updated',
              -1 * $originalValue
            );
          }

          break;
      }

      // Run update with recalculation
      $this->updateCacheRecord($this->model->{$foreignKey}, $config, 'updated');
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
    $defaults = [
      'model' => $foreignModelName,
      'summary' => self::field($modelName, Str::lower($config['function'])),
      'field' => 'id',
      'foreignKey' => self::field($foreignModelName, 'id'),
      'key' => 'id',
      'where' => [],
    ];
    $config = array_merge($defaults, $config);
    $function = Str::lower($config['function']);
    $relatedModel = $config['model'];

    return [
      'function' => $function,
      'model' => $foreignModelName,
      'table' => self::getModelTable($config['model']),
      'summary' => Str::snake($config['summary']),
      'field' => $config['field'],
      'key' => Str::snake(self::key($modelName, $config['key'])),
      'foreignKey' => Str::snake(self::key($modelName, $config['foreignKey'])),
      'where' => $config['where'],
    ];
  }

  /**
   * Applies the provided function to the count cache setup/configuration.
   *
   * @param \Closure $function
   * @param ?boolean   $rebuild Rebuild cache and thus force the operation.
   */
  public function apply(\Closure $function, ?bool $rebuild = false)
  {
    foreach ($this->configs as $config) {
      $isRelevant = $this::checkWhereCondition($this->model, true, $config);
      $wasRelevant = $this::checkWhereCondition($this->model, false, $config);

      if (!$isRelevant && !$wasRelevant) {
        continue;
      }

      $function($config, $isRelevant, $wasRelevant);
    }
  }

  /**
   * Updates a table's record based on the query information provided in the $config variable.
   *
   * @param any $foreignModel Foreign model
   * @param array $config
   * @param string $event Possible events: created/deleted/updated
   * @param ?any $rawValue Raw value
   */
  public function updateCacheRecord(
    $foreignModel,
    array $config,
    $event,
    $rawValue = null
  ) {
    $model = $config['model'];
    $function = $config['function'];
    $summary = $config['summary'];
    $field = $config['field'];
    $key = $config['key'];
    $foreignKey =
      $foreignModel instanceof Model ? $foreignModel[$key] : $foreignModel;

    $cacheQuery = $this->model
      ::select(DB::raw("$function($field)"))
      ->where($config['where'])
      ->where($config['foreignKey'], $foreignKey);
    $result = $model::where($config['key'], $foreignKey)->update([
      $config['summary'] =>
        $rawValue ??
        DB::raw('(' . Cache::convertQueryToRawSQL($cacheQuery) . ')'),
    ]);

    return $result;
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
            $query = $foreignModel
              ::select(DB::raw("$function($field)"))
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

      if (
        !$relevant &&
        $current &&
        !array_key_exists($attribute, $model->getAttributes())
      ) {
        throw new UnableToCacheException(
          'Unable to cache ' .
            $config['field'] .
            ' because ' .
            $attribute .
            ' is part of the where condition but it is not set explicitly on the entity.'
        );
      }

      if (!$relevant) {
        return false;
      }
    }

    return true;
  }
}
