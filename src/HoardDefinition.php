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
  public function withoutSoftDeletes($column = 'deleted_at') {
    $attributes = $this->command->getAttributes();
    $attributes['conditions'][] = [$column, 'IS', DB::raw('NULL')];

    // Ugly workaround to get access to the attributes property
    $attributesProperty = new ReflectionProperty(
      Fluent::class,
      'attributes'
    );
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($this->command, $attributes);

    return $this;
  }
}