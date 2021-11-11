<?php

namespace Jaulz\Hoard\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use Jaulz\Hoard\Scopes\CacheScope;

trait IsHoardableTrait
{
  /**
   * Boot the trait.
   */
  public static function bootIsHoardableTrait()
  {
    $instance = with(new static);
    $keyName = $instance->getKeyName();
    $tableName = $instance->getTable();
    $cacheViewName = HoardSchema::getCacheViewName($tableName);
    $cachePrimaryKeyName = HoardSchema::getCachePrimaryKeyName($tableName, $keyName);

    static::addGlobalScope('select', function (Builder $query) use ($tableName) {
      $query->addSelect($tableName . '.*');
    });

    static::addGlobalScope(new CacheScope());
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
   * Get a new query builder for the model's table.
   *
   * @return \Illuminate\Database\Eloquent\Builder
   */
  public function newQuery()
  {
    /*if (!HoardSchema::isCacheViewName($this->getTable())) {
      $this->setTable(HoardSchema::getCacheViewName($this->getTable()));
    }*/

    return parent::newQuery();
  }

  /**
   * Reload the current model instance with fresh attributes from the database.
   *
   * @param  array|string  $with
   * @param  array|string  $scopes
   * @return $this
   */
  public function forceRefresh($with = [], $scopes = [])
  {
    if (!$this->exists) {
      return $this;
    }

    $query = $this->newQuery();

    foreach ($scopes as $scope) {
      $query->$scope();
    }

    $this->setRawAttributes(
      $this->setKeysForSelectQuery(
        $query
      )->firstOrFail()->attributes
    );

    $this->load(
      $with ?? collect($this->relations)
        ->keys()
        ->all()
    );

    $this->syncOriginal();

    return $this;
  }

  /**
   * Refresh cache for the model.
   *
   * @param ?bool $native
   * @return array
   */
  public function refreshHoard()
  {
    return DB::select('SELECT hoard_refresh_all(?, ?)', [$this->getTable(), $this->getKeyName() . ' = ' . $this->getKey()]);
  }
}
