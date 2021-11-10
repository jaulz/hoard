<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HoardSchema
{
  static public $cachePrimaryKeyNamePrefix = 'cacheable_';
  static public $cacheTableNameSuffix = '_cache';
  static public $cacheViewNameSuffix = '_with_cache';

  /**
   * Create all required tables, functions etc.
   */
  public static function init()
  {
    $statements = [
    ];
    
    collect($statements)->each(function ($statement) {
      DB::statement($statement);
    });
  }

  /**
   * Create the cache table for a given table name.
   *
   * @param  string  $tableName
   * @param  \Closure  $callback
   * @param  ?string  $primaryKeyName
   * @param  ?string  $primaryKeyType
   */
  public static function create(
    string $tableName,
    \Closure $callback,
    ?string $primaryKeyName = 'id',
    ?string $primaryKeyType = 'bigInteger'
  ) {
    $cacheTableName = static::getCacheTableName($tableName); // collect(DB::select('SELECT hoard_get_cache_table_name(?) as name', [$tableName]))->first()->name;
    $cachePrimaryKeyName = static::getCachePrimaryKeyName($primaryKeyName); // collect(DB::select('SELECT hoard_get_cache_primary_key_name(?) as name', [$primaryKeyName]))->first()->name;
    $cacheViewName = static::getCacheViewName($tableName); // collect(DB::select('SELECT hoard_get_cache_view_name(?) as name', [$tableName]))->first()->name;

    // Create cache table
    Schema::create($cacheTableName, function (Blueprint $table) use ($tableName, $callback, $primaryKeyName, $primaryKeyType, $cachePrimaryKeyName) {
      $table
        ->{$primaryKeyType}($cachePrimaryKeyName);

        $table->foreign($cachePrimaryKeyName)
        ->references($primaryKeyName)
        ->on($tableName)
        ->constrained()
        ->cascadeOnDelete();

      $table->unique($cachePrimaryKeyName);

      $table->hoardContext($tableName, $primaryKeyName);

      $callback($table);

      $table->bigInteger('txid');
      $table->timestampTz('cached_at');
    });

    // Create view that combines both
    DB::statement(sprintf("
      CREATE OR REPLACE VIEW %1\$s AS
        SELECT * 
        FROM %2\$s
        JOIN %3\$s
          ON %4\$s = %5\$s;
    ", $cacheViewName, $tableName, $cacheTableName, $primaryKeyName, $cachePrimaryKeyName));

    // Create rule to insert into 
    /*DB::statement(sprintf("
      CREATE RULE \"_RETURN\" AS ON SELECT TO %1\$s DO INSTEAD
          SELECT * FROM %2\$s;
    ", $tableName, $cacheViewName));*/
    /*DB::statement(sprintf("
      CREATE RULE \"_RETURN\" AS ON SELECT TO %1\$s DO INSTEAD
        SELECT * 
        FROM %2\$s
        JOIN %3\$s
          ON %4\$s = %5\$s;
    ", $tableName, $tableName, $cacheTableName, $primaryKeyName, $cachePrimaryKeyName));*/
  }

  /**
   * Update the cache table for a given table name.
   *
   * @param  string  $tableName
   * @param  \Closure  $callback
   * @param  ?string  $primaryKeyName
   */
  public static function table(
    string $tableName,
    \Closure $callback,
    ?string $primaryKeyName = 'id'
  ) {
    $cacheTableName = static::getCacheTableName($tableName);

    return Schema::table($cacheTableName, function (Blueprint $table) use ($tableName, $primaryKeyName, $callback) {
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
    return $tableName . self::$cacheTableNameSuffix;
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
    return Str::beforeLast($cacheTableName, self::$cacheTableNameSuffix);
  }

  /**
   * Return the cache primary key for a given key name.
   *
   * @param  string  $primaryKeyName
   * @return string
   */
  public static function getCachePrimaryKeyName(
    string $primaryKeyName
  ) {
    // NOTE: this should be something which is non clashing
    return static::$cachePrimaryKeyNamePrefix . $primaryKeyName;
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
    return $tableName . static::$cacheViewNameSuffix;
  }

  /**
   * Return the cache view name for a given table name.
   *
   * @param  string  $tableName
   * @return bool
   */
  public static function isCacheViewName(
    string $tableName
  ) {
    return Str::endsWith($tableName, static::$cacheViewNameSuffix);
  }
}
