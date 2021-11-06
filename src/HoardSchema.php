<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionProperty;

class HoardSchema
{
  private static string $suffix = '_caches';

  /**
   * Create the cache table for a given table name.
   *
   * @param  string  $tableName
   * @param  \Closure  $callback
   * @param  ?string  $primaryKeyName
   * @param  ?string  $primaryKeyType
   */
  public static function table(
    string $tableName, \Closure $callback, ?string $primaryKeyName = 'id', ?string $primaryKeyType = 'id'
  ) {
    $cacheTableName = self::getCacheTableName($tableName);
    $cachePrimaryKeyName = self::getCachePrimaryKey($tableName, $primaryKeyName);
    $cacheViewName = self::getCacheViewName($tableName);

    // Create cache table
    $method = Schema::hasTable($cacheTableName) ? 'table' : 'create';
    Schema::{$method}($cacheTableName, function(Blueprint $table) use ($tableName, $cacheTableName, $callback, $primaryKeyName, $primaryKeyType, $cachePrimaryKeyName) {
      $table
        ->{$primaryKeyType}($cachePrimaryKeyName)
        ->foreign($cachePrimaryKeyName)
        ->references($primaryKeyName)
        ->on($tableName)
        ->constrained();

      $table->unique($cachePrimaryKeyName);

      $table->hoardContext($tableName, $primaryKeyName, $cacheTableName, $cachePrimaryKeyName);

      $callback($table);
    }); 

    // Create view that combines both
    DB::statement(sprintf("
      CREATE OR REPLACE VIEW %1\$s AS
        SELECT * 
        FROM %2\$s
        JOIN %3\$s ON %2\$s.%4\$s = %3\$s.%5\$s;
    ", $cacheViewName, $tableName, $cacheTableName, $primaryKeyName, $cachePrimaryKeyName));
  }

  /**
   * Return the cache table name for a given table name.
   *
   * @param  string  $tableName
   * @param  \Closure  $callback
   * @param  ?string  $primaryKeyName
   */
  public static function extend(
    string $tableName, \Closure $callback, ?string $primaryKeyName = 'id'
  ) {
    // Create cache table
    Schema::table($tableName, function(Blueprint $table) use ($tableName, $primaryKeyName, $callback) {
      $table->hoardContext($tableName, $primaryKeyName);

      $callback($table);
    }); 
  }

  /**
   * Return the cache table name for a given table name.
   *
   * @param  string  $tableName
   * @return string
   */
  public static function getCacheTableName(
    string $tableName
  ) {
    return $tableName . self::$suffix;
  }

  /**
   * Return the table name for a given cache table name.
   *
   * @param  string  $cacheTableName
   * @return string
   */
  public static function getTableName(
    string $cacheTableName
  ) {
    return Str::beforeLast($cacheTableName, self::$suffix);
  }

  /**
   * Return the cache primary key for a given table name.
   *
   * @param  string  $tableName
   * @param  string  $primaryKeyName
   * @return string
   */
  public static function getCachePrimaryKey(
    string $tableName, string $primaryKeyName
  ) {
    // NOTE: this should be something which is non clashing
    return '_' . $primaryKeyName;
  }

  /**
   * Return the cache view name for a given table name.
   *
   * @param  string  $tableName
   * @return string
   */
  public static function getCacheViewName(
    string $tableName
  ) {
    return $tableName . '_with_caches';
  }
}