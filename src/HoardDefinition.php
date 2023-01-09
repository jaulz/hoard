<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Jaulz\Hoard\Enums\HoardAggregationFunctionEnum;
use ReflectionProperty;

class HoardDefinition
{
  /**
   * The schema builder blueprint instance.
   *
   * @var \Illuminate\Database\Schema\Blueprint
   */
  protected $blueprint;

  /**
   * The Fluent command that can still be manipulated.
   *
   * @var \Illuminate\Support\Fluent
   */
  protected $command;

  /**
   * Create a new foreign ID column definition.
   *
   * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
   * @param  \Illuminate\Support\Fluent  $command
   * @return void
   */
  public function __construct(Blueprint $blueprint, $command)
  {
    $this->blueprint = $blueprint;
    $this->command = $command;
  }

  /**
   * Filter soft deleted rows.
   *
   * @param  string  $column
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function withoutSoftDeletes(string $column = 'deleted_at')
  {
    $attributes = $this->command->getAttributes();
    $attributes['conditions'][] = [$column, 'IS', null];

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the aggregation.
   *
   * @param  string  $tableName
   * @param  string  $aggregationFunction
   * @param  string  $valueName
   * @param  ?string|array $conditions
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function aggregate(
    string $tableName,
    string $aggregationFunction,
    string|array $valueNames,
    string|array|null $conditions = null
  ) {
    $attributes = $this->command->getAttributes();
    $attributes['tableName'] = $tableName;
    $attributes['aggregationFunction'] = $aggregationFunction;
    $attributes['valueNames'] = (is_array($valueNames) ? $valueNames : [$valueNames]) ?? null;
    $attributes['conditions'] = array_merge($attributes['conditions'] ?? [], is_string($conditions) ? [$conditions] : ($conditions ?? []));

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the aggregation type.
   *
   * @param  string  $name
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function type(
    string $type
  ) {
    $attributes = $this->command->getAttributes();
    $attributes['aggregationType'] = $type;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the group.
   *
   * @param  string  $name
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function group(
    string $name
  ) {
    $attributes = $this->command->getAttributes();
    $attributes['cacheGroupName'] = $name;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the key names where $foreignKeyName is referring to the table where the cache will be stored and 
   * $keyName is referring to the table that is the basis for the cache calculation (e.g. where the COUNT 
   * or MAX is applied to) and $keyName is referring to the table where the cache
   * will be stored.
   * 
   * These are the typical use cases:
   * - The name of the key in the table is different (e.g. name, or code) and then you would need to call 
   *   "->via('country_code', 'code')".
   * - For polymorphic relations where you cache something into the pivot table and hence another condition 
   *   must be applied (i.e. the type of the polymorphism) and then you would need to call 
   *   "->via('id', 'commentable_id', [ 'commentable_type' => Post::class ]])".
   *
   * @param  string  $foreignKeyName
   * @param  ?string  $keyName
   * @param  ?string|array  $foreignConditions
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function via(string $foreignKeyName = null, ?string $keyName = null, string|array|null $foreignConditions = null)
  {
    $attributes = $this->command->getAttributes();
    $attributes['keyName'] = $keyName ?? $attributes['keyName'] ?? null;
    $attributes['foreignKeyName'] = $foreignKeyName ?? $attributes['foreignKeyName'] ?? null;
    $attributes['foreignConditions'] = array_merge($attributes['foreignConditions'] ?? [], is_string($foreignConditions) ? [$foreignConditions] : ($foreignConditions ?? []));
    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the key names for the scenario when the own table is referenced.
   *
   * @param  ?string  $keyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaOwn(?string $keyName = null)
  {
    return $this->via($keyName ?? null, $keyName ?? null);
  }

  /**
   * Set the key names for the scenario when the own table is referenced via a parent key.
   *
   * @param  string  $keyName
   * @param  string  $parentPrefix
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaParent(?string $keyName = 'id', ?string $parentPrefix = 'parent')
  {
    return $this->via($keyName, $parentPrefix . '_' . $keyName);
  }

  /**
   * Set the key names for the morphable scenario.
   *
   * @param  string  $morphable
   * @param  string  $morphableTypeValue
   * @param  ?string  $keyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaMorph(string $morphable, string $morphableTypeValue, ?string $keyName = 'id', ?string $morphableKeyName = 'id')
  {
    $morphableKey = $morphable . '_' . $morphableKeyName;
    $morphableType = $morphable . '_type';
    $conditions = [];
    $conditions[$morphableType] = (new $morphableTypeValue())->getMorphClass();

    $attributes = $this->command->getAttributes();
    $attributes['conditions'] = array_merge($attributes['conditions'] ?? [], $conditions);
    $this->setAttributes($attributes);

    return $this->via($keyName, $morphableKey);
  }

  /**
   * Set the key names for the morphable scenario.
   *
   * @param  string  $morphable
   * @param  string  $morphableTypeValue
   * @param  ?string  $keyName
   * @param  ?string  $morphableKeyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaMorphPivot(string $morphable, string $morphableTypeValue, ?string $keyName = 'id', ?string $morphableKeyName = 'id')
  {
    $morphableKey = $morphable . '_' . $morphableKeyName;
    $morphableType = $morphable . '_type';
    $foreignConditions = [];

    if ($morphableTypeValue) {
      $foreignConditions[$morphableType] = (new $morphableTypeValue())->getMorphClass();
    }

    return $this->via($morphableKey, $keyName, $foreignConditions);
  }

  /**
   * Set the options of the aggregated value column.
   *
   * @param  array  $options
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function options(array $options)
  {
    $attributes = $this->command->getAttributes();
    $attributes['options'] = $options;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Manual aggregations are usually generated columns that are automatically updated by Postgres itself.
   * If the definition is manual it means that "refresh_all" must be used to update the cached values.
   *
   * @param  string  $aggregationType
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function manual(string $aggregationType)
  {
    $attributes = $this->command->getAttributes();
    $attributes['manual'] = true;
    $attributes['aggregationType'] = $aggregationType;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Stored aggregations are special manual aggregations and usually the primary use case of those.
   *
   * @param  string  $aggregationType
   * @param  string  $expression
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function stored(string $aggregationType, string $expression)
  {
    return $this->manual($aggregationType . ' GENERATED ALWAYS AS (' . $expression . ') STORED');
  }

  /**
   * If the definition is lazy it means that it will keep as it is even if the record is deleted.
   *
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function lazy()
  {
    $attributes = $this->command->getAttributes();
    $attributes['lazy'] = true;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * If the definition is hidden it means it will be excluded in the generated cache view.
   *
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function hidden()
  {
    $attributes = $this->command->getAttributes();
    $attributes['hidden'] = true;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * If the definition is asynchronous it means that the cached value will not be updated in the same transaction.
   *
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function asynchronous()
  {
    $attributes = $this->command->getAttributes();
    $attributes['asynchronous'] = true;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Refresh immediately.
   *
   * @param  string|array  $refreshConditions
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function refreshImmediately(string|array $refreshConditions = '')
  {
    $attributes = $this->command->getAttributes();
    $attributes['refreshConditions'] = $refreshConditions;

    $this->setAttributes($attributes);

    return $this;
  }

  private function setAttributes(array $attributes)
  {
    // Ugly workaround to get access to the attributes property
    $attributesProperty = new ReflectionProperty(
      Fluent::class,
      'attributes'
    );
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($this->command, $attributes);
  }
}
