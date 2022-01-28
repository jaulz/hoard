<?php

namespace Jaulz\Hoard\Traits;

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
    $instance = with(new static);
    $keyName = $instance->getKeyName();
    $tableName = $instance->getTable();
    $cacheViewName = HoardSchema::getCacheViewName($tableName);
    $cachePrimaryKeyName = HoardSchema::getCachePrimaryKeyName($tableName, $keyName);

    static::addGlobalScope('select', function (Builder $query) use ($tableName) {
      $query->addSelect($tableName . '.*');
    });

    static::addGlobalScope(new HoardScope());
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
  public function scopeHoard(\Illuminate\Database\Eloquent\Builder $query, ?string $alias = null)
  {
      (new HoardScope($alias))->apply($query, $this);
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
    $connection = $this->getConnection();
    $config = $connection->getConfig();

    return DB::select('SELECT ' . HoardSchema::$schema . '.refresh_all(?, ?, ?)', [
      $config['schema'] ?? 'public',
      $this->getTable(), 
      $this->getKeyName() . ' = ' . $this->getKey()
    ]);
  }
}
