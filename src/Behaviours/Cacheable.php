<?php

namespace Jaulz\Eloquence\Behaviours;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jaulz\Eloquence\Exceptions\UnableToCacheException;

trait Cacheable
{
    /**
     * Applies the provided function to the count cache setup/configuration.
     *
     * @param string   $type Either sum or count.
     * @param \Closure $function
     * @param ?boolean   $rebuild Rebuild cache and thus force the operation.
     */
    public function apply(string $type, \Closure $function, ?bool $rebuild = false)
    {
        foreach ($this->model->{$type . 'Caches'}() as $key => $cache) {
            $config = $this->config(get_class($this->model), $key, $cache);

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
     * @param array $config
     * @param string $operation Whether to increase or decrease a value. Valid values: +/-
     * @param int|float|double $amount
     * @param string $foreignKey
     */
    public function updateCacheRecord(
        array $config,
        $operation,
        $amount,
        $foreignKey
    ) {
        if (is_null($foreignKey) || !$amount) {
            return;
        }

        $config = self::processConfig(get_class($this->model), $config);

        $sql = DB::table($config['table'])->where($config['key'], $foreignKey);

        /*
     * Increment for + operator
     */
        if ($operation == '+') {
            return $sql->increment($config['field'], $amount);
        }

        /*
     * Decrement for - operator
     */
        return $sql->decrement($config['field'], $amount);
    }

    /**
     * Rebuilds the cache for the records in question.
     *
     * @param Model $model
     * @param array $foreignConfigs
     * @param $aggregate
     * @return array
     */
    public static function rebuildCacheRecords(
        Model $model,
        array $foreignConfigs,
        $aggregate
    ) {
        // Get all update statements
        $fields = [];
        $updates = collect($foreignConfigs)->mapWithKeys(function ($configs, $foreignModel) use ($model, $aggregate, $fields) {
            return collect($configs)->map(function ($config) use ($foreignModel, $fields) {
                // Normalize config
                $config = self::processConfig($foreignModel, $config);
                
                // Collect all fields
                array_push($fields, $config['field']);

                return $config;
            })->mapWithKeys(function ($config) use ($model, $aggregate, $foreignModel) {
                // Create query that selects the aggregated field from the foreign table
                $aggregateField = $aggregate === 'sum' ? $config['columnToSum'] : '*';
                $query = $foreignModel::select(
                    DB::raw("$aggregate($aggregateField)")
                )
                    ->where($config['where'])->where($config['foreignKey'], $model[$config['key']])->take(1);

                // Convert query builder to raw sql statement so we can use it in the update statement
                $sql = $query->toSql();
                $bindings = $query->getBindings();
                $rawSql = vsprintf(str_replace(['?'], ['\'%s\''], $sql), $bindings);

                return [$config['field'] => DB::raw('(' . $rawSql . ')')];
            });
        })->toArray();

        // Run updates unguarded
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
            'difference' => $before->diffAssoc($after)->toArray()
        ];
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
     * Process configuration parameters to check key names, fix snake casing, etc..
     *
     * @param string $model
     * @param array $config
     * @return array
     */
    protected static function processConfig(string $model, array $config)
    {
        return [
            'model' => $config['model'],
            'table' => self::getModelTable($config['model']),
            'field' => Str::snake($config['field']),
            'key' => Str::snake(self::key($config['model'], $config['key'])),
            'foreignKey' => Str::snake(self::key($model, $config['foreignKey'])),
            'where' => $config['where'] ?? [],
        ];
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
        if (!is_object($model)) {
            $model = new $model();
        }

        if (method_exists($model, 'getTrueKey')) {
            return $model->getTrueKey($field);
        }

        return $field;
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
        $relevant = true;

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
                break;
            }
        }

        return $relevant;
    }
}
