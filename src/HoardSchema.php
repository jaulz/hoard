<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class HoardSchema
{
  static public $schema = 'public';
  static public $cacheSchema = 'hoard';

  static public $cachePrimaryKeyNamePrefix = 'cacheable_';

  static public $cacheTableNamePrefix = '';
  static public $cacheTableNameDelimiter = '__';
  static public $cacheTableNameSuffix = '';

  static public $cacheViewNamePrefix = '';
  static public $cacheViewNameSuffix = '';

  /**
   * Create all required tables, functions etc.
   */
  public static function init()
  {
    $statements = [
      sprintf(
        "CREATE SCHEMA IF NOT EXISTS %1\$s;",
        HoardSchema::$cacheSchema
      ),
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
    string $cacheTableGroup,
    \Closure $callback,
    ?string $primaryKeyName = 'id',
    ?string $primaryKeyType = 'bigInteger'
  ) {
    $cacheTableName = static::getCacheTableName($tableName, $cacheTableGroup);
    $cachePrimaryKeyName = static::getCachePrimaryKeyName($tableName, $primaryKeyName);
    $cacheUniqueIndexName = static::getCacheUniqueIndexName(static::getCacheTableName($tableName, $cacheTableGroup, false), $primaryKeyName, $cachePrimaryKeyName);

    // Create cache table
    Schema::create($cacheTableName, function (Blueprint $table) use ($tableName, $cacheTableGroup, $callback, $primaryKeyName, $primaryKeyType, $cachePrimaryKeyName, $cacheUniqueIndexName) {
      $table
        ->{$primaryKeyType}($cachePrimaryKeyName);

      $table->foreign($cachePrimaryKeyName)
        ->references($primaryKeyName)
        ->on($tableName)
        ->constrained()
        ->cascadeOnDelete();

      $table->unique($cachePrimaryKeyName, $cacheUniqueIndexName);

      $table->hoardContext([
        'tableName' => $tableName, 'cacheTableGroup' => $cacheTableGroup, 'primaryKeyName' => $primaryKeyName
      ]);

      $callback($table);

      $table->bigInteger('txid');
      $table->timestampTz('cached_at');
    });

    // Refresh table afterwards
    DB::raw(
      "
        DO $$
          BEGIN
            PERFORM :cache_schema.refresh_all(:schema, :table_name);
          END;
        $$ LANGUAGE PLPGSQL;
      ",
      [
        'cache_schema' => HoardSchema::$cacheSchema,
        'schema' => 'public',
        'table_name' => $tableName
      ]
    );
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
   * @param  string  $cacheTableGroup
   * @param  bool  $includeSchema
   * @return string
   */
  public static function getCacheTableName(
    string $tableName,
    string $cacheTableGroup,
    bool $includeSchema = true
  ) {
    return ($includeSchema ? HoardSchema::$cacheSchema . '.' : '') . static::$cacheTableNamePrefix . $tableName . static::$cacheTableNameDelimiter . $cacheTableGroup;
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
    return Str::afterLast(Str::beforeLast($cacheTableName, self::$cacheTableNameDelimiter), self::$cacheTableNamePrefix);
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
   * Return the cache unique index name for a given table name and key name.
   *
   * @param  string  $cacheTableName
   * @param  string  $primaryKeyName
   * @param  string  $cachePrimaryKeyName
   * @return string
   */
  public static function getCacheUniqueIndexName(
    string $cacheTableName,
    string $primaryKeyName,
    string $cachePrimaryKeyName
  ) {
    // NOTE: max length should be 63 characters
    $hashLength = 4;
    $hash = substr(md5($cacheTableName), 0, $hashLength);
    $suffix = '_' . $cachePrimaryKeyName . '_unique';

    $indexName = $cacheTableName . $suffix;
    if (strlen($indexName) <= 63) {
      return $indexName;
    }

    $suffix = '_' . $hash . '_' . $cachePrimaryKeyName . '_unique';

    return substr($cacheTableName, 0, 63 - strlen($suffix)) . $suffix;
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

  /**
   * Transform an array of conditions into a where condition as a simple string.
   *
   * @param  array $conditions
   * @return string
   */
  public static function prepareConditions(
    array $conditions
  ) {
    return collect($conditions)->mapWithKeys(function ($condition, $key) {
      $operator = '';
      $value = $condition;

      // In case a string is provided we just use it as it is
      if (is_numeric($key) && is_string($condition)) {
        return [$key, $condition];
      }

      // In case an array is provided, the second item is the operator and the third is the value
      if (is_array($condition)) {
        $key = $condition[0];
        $operator = $condition[1];
        $value = $condition[2];
      }
      if ($condition instanceof Collection) {
        $key = $condition->get(0);
        $operator = $condition->get(1);
        $value = $condition->get(2);
      }

      // Finally either use a quoted value or as it is
      if ($value instanceof Expression) {
        $value = $value->getValue();
      } else if (is_null($value)) {
        $operator = $operator ?: 'IS';
        $value = 'NULL';
      } else if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
      } else if (is_numeric($value)) {
        $value = $value;
      } else {
        $value = DB::getPdo()->quote($value);
      }

      if (!$value) {
        return [];
      }

      if (!$operator) {
        $operator = '=';
      }

      return  [$key => '"' . $key . '"' . ' ' . $operator . ' ' . $value];
    })->values()->filter()->implode(' AND ');
  }
}
