<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HoardSchema
{
  static public $cachePrimaryKeyNamePrefix = 'cacheable_';

  static public $cacheTableNamePrefix = 'hoard_cached_';
  static public $cacheTableNameDelimiter = '__';
  static public $cacheTableNameSuffix = '';

  static public $cacheViewNamePrefix = 'hoard_cached_';
  static public $cacheViewNameSuffix = '';

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
    string $groupName,
    \Closure $callback,
    ?string $primaryKeyName = 'id',
    ?string $primaryKeyType = 'bigInteger'
  ) {
    $cacheTableName = static::getCacheTableName($tableName, $groupName); // collect(DB::select('SELECT hoard_get_cache_table_name(?) as name', [$tableName]))->first()->name;
    $cachePrimaryKeyName = static::getCachePrimaryKeyName($tableName, $primaryKeyName); // collect(DB::select('SELECT hoard_get_cache_primary_key_name(?) as name', [$primaryKeyName]))->first()->name;

    // Create cache table
    Schema::create($cacheTableName, function (Blueprint $table) use ($tableName, $groupName, $callback, $primaryKeyName, $primaryKeyType, $cachePrimaryKeyName) {
      $table
        ->{$primaryKeyType}($cachePrimaryKeyName);

        $table->foreign($cachePrimaryKeyName)
        ->references($primaryKeyName)
        ->on($tableName)
        ->constrained()
        ->cascadeOnDelete();

      $table->unique($cachePrimaryKeyName);

      $table->hoardContext([
        'tableName' => $tableName, 'groupName' => $groupName, 'primaryKeyName' => $primaryKeyName
      ]);

      $callback($table);

      $table->bigInteger('txid');
      $table->timestampTz('cached_at');
    });

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
   * @param  string  $groupName
   * @param  \Closure  $callback
   * @param  ?string  $primaryKeyName
   */
  public static function table(
    string $tableName,
    string $groupName,
    \Closure $callback,
    ?string $primaryKeyName = 'id'
  ) {
    $cacheTableName = static::getCacheTableName($tableName, $groupName);

    return Schema::table($cacheTableName, function (Blueprint $table) use ($tableName, $groupName, $primaryKeyName, $callback) {
      $table->hoardContext([
        'tableName' => $tableName, 'groupName' => $groupName, 'primaryKeyName' => $primaryKeyName
      ]);

      $callback($table);
    });
  }

  /**
   * Return the cache table name for a given table name.
   *
   * @param  string  $tableName
   * @param  string  $groupName
   * @return string
   */
  public static function getCacheTableName(
    string $tableName,
    string $groupName
  ) {
    return static::$cacheTableNamePrefix . $tableName . static::$cacheTableNameDelimiter . $groupName;
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
    return Str::afterLast(Str::beforeLast($cacheTableName, self::$cacheTableNameSuffix), self::$cacheTableNamePrefix);
  }

  /**
   * Return the foreign primary key for a given key name.
   *
   * @param  string  $tableName
   * @param  string  $primaryKeyName
   * @return string
   */
  public static function getForeignPrimaryKeyName(
    string $tableName,
    string $primaryKeyName
  ) {
    return Str::singular($tableName) . '_' . $primaryKeyName;
  }

  /**
   * Return the cache primary key for a given key name.
   *
   * @param  string  $tableName
   * @param  string  $primaryKeyName
   * @return string
   */
  public static function getCachePrimaryKeyName(
    string $tableName,
    string $primaryKeyName
  ) {
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
    return static::$cacheViewNamePrefix . $tableName . static::$cacheViewNameSuffix;
  }
}
