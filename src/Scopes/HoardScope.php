<?php

namespace Jaulz\Hoard\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use Illuminate\Support\Str;

class HoardScope implements Scope {
  /**
   * Scope a query to include cache.
   *
   * @param  \Illuminate\Database\Eloquent\Builder  $builder
   * @param  \Illuminate\Database\Eloquent\Model  $model
   */
  public function apply(Builder $query, Model $model)
  {
    $keyName = $model->getKeyName();
    $tableName = $model->getTable();
    $cacheViewName = HoardSchema::getCacheViewName($tableName);
    $cachePrimaryKeyName = HoardSchema::getCachePrimaryKeyName($tableName, $keyName);
    $className = class_basename($model);
    $alias = Str::snake($className) . '_hoard_' . rand(1, 1000);

    $query->addSelect($alias . '.*')->crossJoin(DB::raw('
      LATERAL (
        SELECT  *
        FROM    ' . $cacheViewName . '
        WHERE   ' . $tableName . '.' . $keyName . ' = ' . $cacheViewName . '.' . $cachePrimaryKeyName . '
      ) ' . $alias . ' 
    '));

    return $query;
  }
}