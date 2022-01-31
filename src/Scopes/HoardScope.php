<?php

namespace Jaulz\Hoard\Scopes;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use Illuminate\Support\Str;

class HoardScope implements Scope
{
  protected ?Closure $implementation = null;
  protected ?string $alias = null;

  public function __construct(?Closure $implementation = null, ?string $alias = null)
  {
    $this->implementation = $implementation;
    $this->alias = $alias;
  }

  /**
   * Scope a query to include cache.
   *
   * @param  \Illuminate\Database\Eloquent\Builder  $builder
   * @param  \Illuminate\Database\Eloquent\Model  $model
   */
  public function apply(Builder $query, Model $model)
  {
    // Since we will use "addSelect" later on we need to ensure that we select all fields
    $query->select('*');

    // Prepare new select that will be used as a lateral cross join
    $keyName = $model->getKeyName();
    $tableName = $model->getTable();
    $cacheViewName = HoardSchema::getCacheViewName($tableName);
    $cachePrimaryKeyName = HoardSchema::getCachePrimaryKeyName($tableName, $keyName);
    $crossJoinQuery = DB::table(HoardSchema::$cacheSchema . '.' . $cacheViewName)->whereRaw(
      HoardSchema::$schema . '.' . $tableName . '.' . $keyName . ' = ' . HoardSchema::$cacheSchema . '.' . $cacheViewName . '.' . $cachePrimaryKeyName
    )->select('*');

    // Allow custom scopes to be applied
      $implementation = $this->implementation;
    if ($implementation instanceof Closure) {
      $implementation($crossJoinQuery);
    }

    // Eventually use the prepared select and extend the actual query
    $className = class_basename($model);
    $alias = $this->alias ?? 'cached_' . Str::snake($className);
    $query->addSelect($alias . '.*')->crossJoin(DB::raw('
      LATERAL (
        ' . $crossJoinQuery->toSql() . '
      ) ' . $alias . ' 
    '));

    return $query;
  }
}
