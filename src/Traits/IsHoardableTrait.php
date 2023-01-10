<?php

namespace Jaulz\Hoard\Traits;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use Jaulz\Hoard\Scopes\HoardScope;

trait IsHoardableTrait
{
  /**
   * Boot the trait.
   */
  public static function bootIsHoardableTrait()
  {
  }

  /**
   * Initialize the trait
   *
   * @return void
   */
  public function initializeIsHoardableTrait()
  {
  }

  /**
   * Scope a query to include cache.
   *
   * @param  \Illuminate\Database\Eloquent\Builder  $query
   * @return void
   */
  public function scopeHoard(\Illuminate\Database\Eloquent\Builder $query, ?Closure $implementation = null, ?string $alias = null)
  {
      (new HoardScope($implementation, $alias))->apply($query, $this);
  }

  /**
   * Refresh cache for the model.
   *
   * @return array
   */
  public function refreshHoard()
  {
    return DB::select('SELECT ' . HoardSchema::$cacheSchema . '.refresh(?, ?, NULL, ?)', [
      HoardSchema::$schema,
      $this->getTable(), 
      $this->getKeyName() . ' = ' . $this->getKey()
    ]);
  }

  /**
   * Process logs and refresh cache for the model.
   *
   * @return array
   */
  public static function processHoard($foreignAggregationName = '%')
  {
    return DB::select('SELECT ' . HoardSchema::$cacheSchema . '.process(?, ?, ?)', [
      HoardSchema::$schema,
      (new static())->getTable(),
      $foreignAggregationName
    ]);
  }
}
