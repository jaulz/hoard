<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionProperty;

class HoardSchema
{
  static private $cachePrimaryKeyNamePrefix = 'cacheable_';
  static private $cacheTableNameSuffix = '_cache';
  static private $cacheViewNameSuffix = '_with_cache';

  /**
   * Create all required tables, functions etc.
   */
  public static function init()
  {
    $statements = [
      sprintf(
        "
          CREATE TABLE IF NOT EXISTS hoard_triggers (
            id SERIAL PRIMARY KEY,
            table_name VARCHAR(255) NOT NULL,
            primary_key_name VARCHAR(255) NOT NULL,
            key_name VARCHAR(255) NOT NULL,
            aggregation_function VARCHAR(255) NOT NULL,
            value_name VARCHAR(255) NOT NULL,
            value_type VARCHAR(255),
            conditions VARCHAR(255) NOT NULL,
            foreign_table_name VARCHAR(255) NOT NULL,
            foreign_primary_key_name VARCHAR(255) NOT NULL,
            foreign_key_name VARCHAR(255) NOT NULL,
            foreign_aggregation_name VARCHAR(255) NOT NULL,
            foreign_conditions VARCHAR(255) NOT NULL,
            lazy BOOLEAN DEFAULT false,
            bubbled BOOLEAN DEFAULT false
          );
        "
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_get_cache_table_name(table_name text)
            RETURNS text
            AS $$
              BEGIN
                IF hoard_is_cache_table_name(table_name) THEN
                  RETURN table_name;
                END IF;

                RETURN format('%%s%1\$s', table_name);
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cacheTableNameSuffix
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_get_cache_primary_key_name(primary_key_name text)
            RETURNS text
            AS $$
              BEGIN
                RETURN format('%1\$s%%s', primary_key_name);
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cachePrimaryKeyNamePrefix
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_get_cache_view_name(table_name text)
            RETURNS text
            AS $$
              BEGIN
                IF hoard_is_cache_table_name(table_name) THEN
                  table_name := hoard_get_table_name(table_name);
                END IF;

                RETURN format('%%s%1\$s', table_name);
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cacheViewNameSuffix
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_get_table_name(cache_table_name text)
            RETURNS text
            AS $$
              BEGIN
                IF hoard_is_cache_table_name(cache_table_name) THEN
                  RETURN SUBSTRING(cache_table_name, 0, POSITION('%1\$s' in cache_table_name));
                END IF;

                RETURN cache_table_name;
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cacheTableNameSuffix
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_is_cache_table_name(table_name text)
            RETURNS boolean
            AS $$
              BEGIN
                RETURN POSITION('%1\$s' in table_name) > 0;
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cacheTableNameSuffix
      ),

      sprintf(
        "
          CREATE OR REPLACE FUNCTION hoard_get_join_table_name(table_name text)
            RETURNS text
            AS $$
              BEGIN
                IF hoard_is_cache_table_name(table_name) THEN
                  RETURN hoard_get_table_name(table_name);
                END IF;

                RETURN hoard_get_cache_table_name(table_name);
              END;
            $$ LANGUAGE PLPGSQL;
        ",
        static::$cacheTableNameSuffix
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
  public static function table(
    string $tableName,
    \Closure $callback,
    ?string $primaryKeyName = 'id',
    ?string $primaryKeyType = 'bigInteger'
  ) {
    $cacheTableName = static::getCacheTableName($tableName); // collect(DB::select('SELECT hoard_get_cache_table_name(?) as name', [$tableName]))->first()->name;
    $cachePrimaryKeyName = static::getCachePrimaryKeyName($primaryKeyName); // collect(DB::select('SELECT hoard_get_cache_primary_key_name(?) as name', [$primaryKeyName]))->first()->name;
    $cacheViewName = static::getCacheViewName($tableName); // collect(DB::select('SELECT hoard_get_cache_view_name(?) as name', [$tableName]))->first()->name;

    // Create cache table
    $method = Schema::hasTable($cacheTableName) ? 'table' : 'create';
    Schema::{$method}($cacheTableName, function (Blueprint $table) use ($tableName, $cacheTableName, $callback, $primaryKeyName, $primaryKeyType, $cachePrimaryKeyName) {
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
    });

    // Create view that combines both
    DB::statement(sprintf("
      CREATE OR REPLACE VIEW %1\$s AS
        SELECT * 
        FROM %2\$s
        JOIN %3\$s
          ON %4\$s = %5\$s;
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
    string $tableName,
    \Closure $callback,
    ?string $primaryKeyName = 'id'
  ) {
    // Create cache table
    Schema::table($tableName, function (Blueprint $table) use ($tableName, $primaryKeyName, $callback) {
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
}
