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
  public function withoutSoftDeletes(string $column = 'deleted_at') {
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
   * @param  string|array $conditions
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function aggregate(string $tableName,
  string $aggregationFunction,
  string $valueName, string|array $conditions = '') {
    $attributes = $this->command->getAttributes();
    $attributes['tableName'] = $tableName;
    $attributes['aggregationFunction'] = $aggregationFunction;
    $attributes['valueName'] = $valueName;
    $attributes['conditions'] = is_string($conditions) ? [$conditions] : $conditions;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the key names.
   *
   * @param  string  $keyName
   * @param  string  $foreignKeyName
   * @param  string|array  $foreignConditions
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function via(string $keyName, string $foreignKeyName = 'id', string|array $foreignConditions = '') {
    $attributes = $this->command->getAttributes();
    $attributes['keyName'] = $keyName;
    $attributes['foreignKeyName'] = $foreignKeyName;
    $attributes['foreignConditions'] = is_string($foreignConditions) ? [$foreignConditions] : $foreignConditions;

    $this->setAttributes($attributes);

    return $this;
  }

  /**
   * Set the type of the aggregated value column.
   *
   * @param  string  $type
   * @return \Jaulz\Hoard\HoardDefinition
   */
  public function type(string $type) {
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
  public function lazy() {
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
  public function refreshImmediately(string|array $refreshConditions = '') {
    $attributes = $this->command->getAttributes();
    $attributes['refreshConditions'] = $refreshConditions;

    $this->setAttributes($attributes);

    return $this;
  }

  private function setAttributes(array $attributes) {
    // Ugly workaround to get access to the attributes property
    $attributesProperty = new ReflectionProperty(
      Fluent::class,
      'attributes'
    );
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($this->command, $attributes);
  }
}