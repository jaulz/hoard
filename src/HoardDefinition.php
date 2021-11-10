<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
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
   * @param  ?bool $cached
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function aggregate(
    string $tableName,
    string $aggregationFunction,
    string $valueName,
    string|array|null $conditions = null,
    bool $cached = null
  ) {
    $attributes = $this->command->getAttributes();
    $attributes['tableName'] = $tableName;
    $attributes['aggregationFunction'] = $aggregationFunction;
    $attributes['valueName'] = $valueName;
    $attributes['conditions'] = array_merge($attributes['conditions'] ?? [], is_string($conditions) ? [$conditions] : ($conditions ?? []));
    $attributes['cached'] = $cached;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the key names:
   * $keyName is related to the table that is the basis for the cache calculation (i.e. where the COUNT or MAX is applied to).
   * $foreignKeyName is related to the table where the cache will be stored.
   * 
   * These are the typical use cases:
   * - The name of the key in the table is different (e.g. name, or code) and then you would need to call "->via('country_code', 'code')".
   * - For polymorphic relations where you cache something into the pivot table and hence another condition must be applied 
   *  (i.e. the type of the polymorphism) and then you would need to call "->via('id', 'commentable_id', [ 'commentable_type' => Post::class ]])
   *
   * @param  string  $foreignKeyName
   * @param  ?string  $keyName
   * @param  ?string|array  $foreignConditions
   * @param  ?string  $foreignPrimaryKeyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function via(string $foreignKeyName = null, ?string $keyName = null, string|array|null $foreignConditions = null, string $foreignPrimaryKeyName = null)
  {
    $attributes = $this->command->getAttributes();
    $attributes['keyName'] = $keyName;
    $attributes['foreignKeyName'] = $foreignKeyName;
    $attributes['foreignConditions'] = array_merge($attributes['foreignConditions'] ?? [], is_string($foreignConditions) ? [$foreignConditions] : ($foreignConditions ?? []));
    $attributes['foreignPrimaryKeyName'] = $foreignPrimaryKeyName;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the key names for the scenario when the own table is referenced.
   *
   * @param  string  $keyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaOwn(?string $keyName = 'id')
  {
    return $this->via($keyName, $keyName);
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
  public function viaMorph(string $morphable, string $morphableTypeValue, ?string $keyName = 'id')
  {
    $morphableKey = $morphable . '_' . $keyName;
    $morphableType = $morphable . '_type';
    $conditions = [];
    $conditions[$morphableType] = $morphableTypeValue;

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
   * @param  ?string  $foreignPrimaryKeyName
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function viaMorphPivot(string $morphable, string $morphableTypeValue, ?string $keyName = 'id', ?string $foreignPrimaryKeyName = 'id')
  {
    $morphableKey = $morphable . '_' . $keyName;
    $morphableType = $morphable . '_type';
    $foreignConditions = [];

    if ($morphableTypeValue) {
      $foreignConditions[$morphableType] = $morphableTypeValue;
    }

    return $this->via($morphableKey, $keyName, $foreignConditions, $foreignPrimaryKeyName);
  }

  /**
   * Set the type of the aggregated value column.
   *
   * @param  string  $type
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function type(string $type)
  {
    $attributes = $this->command->getAttributes();
    $attributes['valueType'] = $type;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the lazyness of the cache definition.
   *
   * @param  string  $type
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
