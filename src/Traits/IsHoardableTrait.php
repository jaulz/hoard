<?php

namespace Jaulz\Hoard\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use Illuminate\Support\Str;

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
    $cacheTableName = HoardSchema::getCacheTableName($tableName);
    $cachePrimaryKeyName = HoardSchema::getCachePrimaryKey($tableName, $keyName);

    static::addGlobalScope('select', function (Builder $query) use ($tableName, $cacheTableName) {
      $query->addSelect($tableName . '.*');
    });

    static::addGlobalScope('hoard', function (Builder $query) use ($keyName, $tableName, $cacheTableName, $cachePrimaryKeyName) {
      $query->addSelect('hoard.*')->crossJoin(DB::raw('
        LATERAL (
          SELECT  *
          FROM    ' . $cacheTableName . '
          WHERE   ' . $tableName . '.' . $keyName . ' = ' . $cacheTableName . '.' . $cachePrimaryKeyName . '
        ) hoard 
      '));
    });
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
