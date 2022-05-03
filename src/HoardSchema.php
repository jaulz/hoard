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
    DB::statement(
      sprintf(
        "
        DO $$
          BEGIN
            PERFORM %1\$s.refresh(%2\$s, %3\$s);
          END;
        $$ LANGUAGE PLPGSQL;
      ",
        HoardSchema::$cacheSchema,
        DB::getPdo()->quote('public'),
        DB::getPdo()->quote($tableName)
      )
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

  /**
   * Execute script.
   *
   * @param  string  $body
   * @param  string  $language
   * @return string
   */
  public static function execute(string $body, string $language = 'PLPGSQL')
  {
    return sprintf(
      "
        DO $$
          %1\$s
        $$ LANGUAGE %2\$s;
      ",
      $body,
      $language,
    );
  }

  /**
   * Create function.
   *
   * @param  string  $name
   * @param  string|array  $parameters
   * @param  string  $return
   * @param  string  $body
   * @param  string  $language
   * @param  string  $volatility
   * @return string
   */
  public static function createFunction(string $name, string|array $parameters, string $return, string $body, string $language = 'PLPGSQL', string $volatility = '')
  {
    $parameters = is_string($parameters) ? $parameters : implode(', ', array_map(function (string $key, string $value) {
      return "$key $value";
    }, array_keys($parameters), array_values($parameters)));

    return sprintf(
      "
        CREATE OR REPLACE FUNCTION %1\$s.%2\$s(%3\$s)
          RETURNS %4\$s
          AS $$
            %5\$s
          $$ LANGUAGE %6\$s %7\$s;
      ",
      HoardSchema::$cacheSchema,
      $name,
      $parameters,
      $return,
      $body,
      $language,
      $volatility
    );
  }

  /**
   * Create generic helper functions.
   *
   * @return array
   */
  public static function createGenericHelperFunctions()
  {
    return [
      HoardSchema::createFunction('array_diff', [
        'p_array1' =>  'anyarray', 'p_array2' =>  'anyarray'
      ], 'anyarray', sprintf(<<<SQL
        SELECT coalesce(array_agg(element), '{}')
          FROM unnest(p_array1) element
          WHERE element <> all(p_array2)
      SQL), 'SQL', 'IMMUTABLE'),

      HoardSchema::createFunction('exists_trigger', [
        'p_schema_name' =>  'text', 'p_table_name' =>  'text', 'p_trigger_name' =>  'text'
      ], 'bool', sprintf(<<<PLPGSQL
        DECLARE
        BEGIN
          IF EXISTS(
            SELECT 
                true 
              FROM information_schema.triggers 
              WHERE 
                  trigger_schema = p_schema_name 
                AND 
                  triggers.event_object_table = p_table_name 
                AND 
                  trigger_name = p_trigger_name
            ) 
          THEN
            RETURN true;
          ELSE
            RETURN false;
          END IF;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('exists_table', [
        'p_schema_name' =>  'text', 'p_table_name' =>  'text'
      ], 'bool', sprintf(<<<PLPGSQL
        DECLARE
        BEGIN
          IF EXISTS(
            SELECT 
                true 
              FROM information_schema.tables 
              WHERE 
                  table_schema = p_schema_name 
                AND 
                  table_name = p_table_name
            ) 
          THEN
            RETURN true;
          ELSE
            RETURN false;
          END IF;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('exists_view', [
        'p_schema_name' =>  'text', 'p_view_name' =>  'text'
      ], 'bool', sprintf(<<<PLPGSQL
        DECLARE
        BEGIN
          IF EXISTS(
            SELECT 
                true 
              FROM 
                information_schema.views 
              WHERE 
                  table_schema = p_schema_name 
                AND 
                  table_name = p_view_name
            ) 
          THEN
            RETURN true;
          ELSE
            RETURN false;
          END IF;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('exists_function', [
        'p_schema_name' =>  'text', 'p_function_name' =>  'text'
      ], 'bool', sprintf(<<<PLPGSQL
        DECLARE
        BEGIN
          IF EXISTS(
            SELECT
                true 
              FROM 
                pg_proc 
              WHERE 
                  pronamespace = (SELECT oid FROM pg_namespace WHERE nspname = p_schema_name) 
                AND 
                  proname = p_function_name
            ) 
          THEN
            RETURN true;
          ELSE
            RETURN false;
          END IF;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('set_row_value', [
        'p_element' =>  'anyelement', 'p_key' =>  'text', 'p_value' => 'text'
      ], 'anyelement', sprintf(<<<SQL
        SELECT json_populate_record(p_element, json_build_object(p_key, p_value));
      SQL), 'SQL'),

      HoardSchema::createFunction('set_row_value', [
        'p_element'  => 'anyelement',
        'p_key' => 'text',
        'p_value' => 'jsonb'
      ], 'anyelement', sprintf(<<<SQL
        SELECT json_populate_record(p_element, json_build_object(p_key, p_value), true);
      SQL), 'SQL'),

      HoardSchema::createFunction('get_row_value', [
        'p_element' =>  'json', 'p_key' =>  'text'
      ], 'text', sprintf(<<<PLPGSQL
        BEGIN
          RETURN p_element ->> p_key;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('get_row_value', [
        'p_element' =>  'jsonb', 'p_key' =>  'text'
      ], 'text', sprintf(<<<PLPGSQL
        BEGIN
          RETURN p_element ->> p_key;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('get_row_value', [
        'p_element' =>  'anyelement', 'p_key' =>  'text'
      ], 'text', sprintf(<<<PLPGSQL
        BEGIN
          RETURN row_to_json(p_element) ->> p_key;
        END;
      PLPGSQL), 'PLPGSQL'),

      HoardSchema::createFunction('jsonb_array_to_text_array', [
        'p_array' =>  'jsonb'
      ], 'text[]', sprintf(<<<SQL
        SELECT array(SELECT jsonb_array_elements_text(p_array))
      SQL), 'SQL', 'IMMUTABLE PARALLEL SAFE STRICT'),

      HoardSchema::createFunction('row_values_to_json', [
        'p_object' =>  'anyelement'
      ], 'jsonb', sprintf(<<<SQL
        SELECT json_agg(base.value) FROM json_each(row_to_json(p_object)) base
      SQL), 'SQL', 'IMMUTABLE PARALLEL SAFE STRICT'),

      HoardSchema::createFunction('jsonb_object_to_value_array', [
        'p_object' =>  'jsonb'
      ], 'jsonb', sprintf(<<<SQL
        SELECT jsonb_agg(base.value) FROM jsonb_each(p_object) base
      SQL), 'SQL', 'IMMUTABLE PARALLEL SAFE STRICT'),

      HoardSchema::createFunction('filter_jsonb_object', [
        'p_object' =>  'jsonb',
        'p_condition' => "text DEFAULT ''"
      ], 'jsonb', sprintf(<<<PLPGSQL
        BEGIN
          IF p_condition = '' THEN
            p_condition = '1 = 1';
          END IF;

          EXECUTE format(
            'SELECT jsonb_object_agg(key, value) FROM jsonb_each($1) WHERE %%s',
            p_condition
          ) USING p_object INTO p_object;

          RETURN p_object;
        END;
      PLPGSQL), 'PLPGSQL', 'IMMUTABLE PARALLEL SAFE STRICT'),
    ];
  }

  /**
   * Create hoard-specific helper functions.
   *
   * @return array
   */
  public static function createSpecificHelperFunctions()
  {
    return [
      HoardSchema::createFunction('get_primary_key_name', [
        'p_cache_primary_key_name' =>   'text'
      ], 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF NOT %1\$s.is_cache_primary_key_name(p_cache_primary_key_name) THEN
            RETURN p_cache_primary_key_name;
          END IF;

          RETURN SUBSTRING(p_cache_primary_key_name, LENGTH('%2\$s') + 1);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cachePrimaryKeyNamePrefix
      ), 'PLPGSQL'),

      HoardSchema::createFunction('is_cache_primary_key_name', [
        'p_primary_key_name' =>   'text'
      ], 'boolean', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN POSITION('%2\$s' in p_primary_key_name) > 0;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cachePrimaryKeyNamePrefix
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_cache_primary_key_name', [
        'p_primary_key_name' =>   'text'
      ], 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF %1\$s.is_cache_primary_key_name(p_primary_key_name) THEN
            RETURN p_primary_key_name;
          END IF;

          RETURN format('%2\$s%%s', p_primary_key_name);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cachePrimaryKeyNamePrefix
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_cache_view_name', [
        'p_table_name' =>   'text'
      ], 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF %1\$s.is_cache_table_name(p_table_name) THEN
            p_table_name := %1\$s.get_table_name(p_table_name);
          END IF;

          RETURN format('%2\$s%%s%3\$s', p_table_name);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cacheViewNamePrefix,
        HoardSchema::$cacheViewNameSuffix
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_table_name', [
        'p_cache_table_name' =>   'text'
      ], 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          table_name text;
        BEGIN
          IF %1\$s.is_cache_table_name(p_cache_table_name) THEN
            table_name := SUBSTRING(p_cache_table_name, 1, POSITION('%3\$s' in p_cache_table_name) - 1);
            table_name := SUBSTRING(table_name, LENGTH('%2\$s') + 1);

            RETURN table_name;
          END IF;

          RETURN p_cache_table_name;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cacheTableNamePrefix,
        HoardSchema::$cacheTableNameDelimiter
      ), 'PLPGSQL'),

      HoardSchema::createFunction('is_cache_table_name', [
        'p_table_name' =>   'text'
      ], 'boolean', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN (
              POSITION('%2\$s' in p_table_name) = 1 
            AND 
              POSITION('%3\$s' in p_table_name) > 0 
            AND 
              POSITION('%4\$s' in p_table_name) > 0
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
        HoardSchema::$cacheTableNamePrefix,
        HoardSchema::$cacheTableNameDelimiter,
        HoardSchema::$cacheTableNameSuffix,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_join_statement', [
        'p_join_schema_name' =>   'text',
        'p_join_table_name' =>   'text',
        'p_primary_key_name' =>   'text',
        'p_alias' =>   'text',
      ], 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          cache_view_name text;
          cache_table_name text;
          table_name text;
        BEGIN
          -- In case the provided table name is a cache table name we will join the principal table
          IF %1\$s.is_cache_table_name(p_join_table_name) THEN
            cache_table_name := p_join_table_name;
            table_name := %1\$s.get_table_name(p_join_table_name);

            RETURN format(
              'LEFT JOIN "%%s" ON "%%s"."%%s" = "%%s"."%%s"', 
              table_name, 
              table_name, 
              %1\$s.get_primary_key_name(p_primary_key_name), 
              p_alias, 
              %1\$s.get_cache_primary_key_name(p_primary_key_name)
            );
          END IF;

          -- In case the provided table name is a principal table, we will check if there is a cache view and join that 
          cache_view_name := %1\$s.get_cache_view_name(p_join_table_name);
          IF NOT %1\$s.exists_view('%1\$s', cache_view_name) THEN
            RETURN '';
          END IF;

          -- In case an record is provided we cannot use the schema
          IF p_alias = 'record' THEN
            RETURN format(
              'LEFT JOIN %1\$s."%%s" ON %1\$s."%%s"."%%s" = "%%s"."%%s"', 
              cache_view_name, 
              cache_view_name, 
              %1\$s.get_cache_primary_key_name(p_primary_key_name), 
              p_alias,
              %1\$s.get_primary_key_name(p_primary_key_name)
            );
          END IF;

          RETURN format(
            'LEFT JOIN %1\$s."%%s" ON %1\$s."%%s"."%%s" = %%s."%%s"."%%s"', 
            cache_view_name, 
            cache_view_name, 
            %1\$s.get_cache_primary_key_name(p_primary_key_name), 
            p_join_schema_name, 
            p_alias, 
            %1\$s.get_primary_key_name(p_primary_key_name)
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('value_names_to_columns', [
        'p_value_names' =>  'jsonb'
      ], 'text', sprintf(
        <<<PLPGSQL
          DECLARE
            value_name text;
            columns text[] DEFAULT array[]::text[];
            index integer DEFAULT 0;
          BEGIN
            FOR value_name IN SELECT * FROM jsonb_array_elements_text(p_value_names)
              LOOP
                columns := array_append(columns, format('(%%s) as value_%%s', value_name, index));

                index := index + 1;
              END LOOP;

            RETURN array_to_string(columns, ', ', '*');
          END;
        PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL', 'IMMUTABLE PARALLEL SAFE STRICT'),
    ];
  }

  /**
   * Create predefined aggregations functions.
   *
   * @return array
   */
  public static function createAggregationFunctions()
  {
    $getRefreshQueryParameters = [
      'p_schema_name' =>   'text',
      'p_table_name' =>   'text',
      'p_primary_key_name' =>   'text',
      'p_key_name' =>   'text',
      'p_value_names' =>   'jsonb',
      'p_options' =>   'jsonb',
      'p_foreign_key' =>   'text',
      'p_conditions' =>   "text DEFAULT ''",
    ];
    $concatRefreshQueriesParameters = [
      'p_existing_refresh_query' => 'text',
      'p_refresh_query' => 'text',
    ];
    $updateParameters = [
      'p_schema_name' => 'text',
      'p_table_name' => 'text',
      'p_primary_key_name' => 'text',
      'p_key_name' => 'text',
      'p_foreign_schema_name' => 'text',
      'p_foreign_table_name' => 'text',
      'p_foreign_primary_key_name' => 'text',
      'p_foreign_key_name' => 'text',
      'p_foreign_aggregation_name' => 'text',
      'p_foreign_cache_table_name' => 'text',
      'p_foreign_cache_primary_key_name' => 'text',
      'p_aggregation_function' => 'text',
      'p_value_names' => 'jsonb',
      'p_options' => 'jsonb',
      'p_conditions' => 'text',
      'p_foreign_conditions' => 'text',

      'p_operation' => 'text',
      'p_old_values' => 'jsonb',
      'p_old_foreign_key' => 'text',
      'p_old_relevant' => 'boolean',
      'p_old_condition' => 'text',
      'p_old_refresh_query' => 'text',
      'p_new_values' => 'jsonb',
      'p_new_foreign_key' => 'text',
      'p_new_relevant' => 'boolean',
      'p_new_condition' => 'text',
      'p_new_refresh_query' => 'text',

      'p_action' => 'text'
    ];

    return [
      /**
       * Sum
       */
      HoardSchema::createFunction('get_sum_refresh_query', $getRefreshQueryParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format(
            'SELECT coalesce((SELECT sum(%%I) FROM %%I %%s WHERE %%I = %%L AND (%%s)), 0)', 
            p_value_names->>0, 
            p_table_name, 
            %1\$s.get_join_statement(p_schema_name, p_table_name, p_primary_key_name, p_table_name), 
            p_key_name, 
            p_foreign_key, 
            p_conditions
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('concat_sum_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('((%%s) + (%%s))', p_existing_refresh_query, p_refresh_query);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_sum_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          value text;
          sign text;
        BEGIN
          IF p_action = 'REMOVE' THEN
            value := p_old_values->>0;
            sign := '-';
          ELSEIF p_action = 'ADD' THEN
            value := p_new_values->>0;
            sign := '+';
          END IF;

          RETURN format(
            '%%s %%s %%s', 
            p_foreign_aggregation_name, 
            sign,
            value
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Count
       */
      HoardSchema::createFunction('get_count_refresh_query', $getRefreshQueryParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format(
            'SELECT coalesce((SELECT COUNT(%%I) FROM %%I %%s WHERE %%I = %%L AND (%%s)), 0)', 
            p_value_names->>0, 
            p_table_name, 
            %1\$s.get_join_statement(p_schema_name, p_table_name, p_primary_key_name, p_table_name), 
            p_key_name, 
            p_foreign_key, 
            p_conditions
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('concat_count_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('((%%s) + (%%s))', p_existing_refresh_query, p_refresh_query);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_count_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          sign text;
        BEGIN
          IF p_action = 'REMOVE' THEN
            sign := '-';
          ELSEIF p_action = 'ADD' THEN
            sign := '+';
          END IF;

          RETURN format(
            '%%s %%s 1', 
            p_foreign_aggregation_name,
            sign
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Max
       */
      HoardSchema::createFunction('concat_max_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('greatest((%%s), (%%s))', p_existing_refresh_query, p_refresh_query);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_max_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF p_action = 'REMOVE' THEN
            IF p_old_values->>0 IS NOT NULL THEN
              RETURN format(
                'CASE WHEN %%L <> %%s THEN %%s ELSE (%%s) END',
                p_old_values->>0, 
                p_foreign_aggregation_name, 
                p_foreign_aggregation_name, 
                p_old_refresh_query
              );
            END IF;
          END IF;

          IF p_action = 'ADD' THEN
            IF p_new_values->>0 != '' THEN
              RETURN format(
                'CASE WHEN %%I IS NULL OR %%L > %%I THEN %%L ELSE (%%I) END', 
                p_foreign_aggregation_name, 
                p_new_values->>0, 
                p_foreign_aggregation_name, 
                p_new_values->>0, 
                p_foreign_aggregation_name
              );
            END IF;
          END IF;

          RETURN '';
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Min
       */
      HoardSchema::createFunction('concat_min_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('least((%%s), (%%s))', p_existing_refresh_query, p_refresh_query);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_min_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF p_action = 'REMOVE' THEN
            IF p_old_values->>0 IS NOT NULL THEN
              RETURN format(
                'CASE WHEN %%L <> %%I THEN %%I ELSE (%%s) END', 
                p_old_values->>0, 
                p_foreign_aggregation_name, 
                p_foreign_aggregation_name,
                p_old_refresh_query
              );
            END IF;
          END IF;

          IF p_action = 'ADD' THEN
            IF p_new_values->>0 != '' THEN
              RETURN format(
                'CASE WHEN %%I IS NULL OR %%L < %%I THEN %%L ELSE (%%s) END', 
                p_foreign_aggregation_name, 
                p_new_values->>0, 
                p_foreign_aggregation_name, 
                p_new_values->>0, 
                p_foreign_aggregation_name
              );
            END IF;
          END IF;

          RETURN '';
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Copy
       */
      HoardSchema::createFunction('get_copy_refresh_query', $getRefreshQueryParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format(
            'SELECT %%s FROM %%I %%s WHERE %%I = %%L AND (%%s) %%s LIMIT 1', 
            p_value_names->>0, 
            p_table_name, 
            %1\$s.get_join_statement(p_schema_name, p_table_name, p_primary_key_name, p_table_name), 
            p_key_name, 
            p_foreign_key, 
            p_conditions,
            p_options->>'order_by'
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('concat_copy_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RAISE EXCEPTION 'Concatenation of COPY aggregations is not yet possible';
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_copy_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
          BEGIN
            IF p_action = 'REMOVE' THEN
              RETURN p_old_refresh_query;
            END IF;

            IF p_action = 'ADD' THEN
              RETURN p_new_refresh_query;
            END IF;

            RETURN '';
          END;
        PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Push
       */
      HoardSchema::createFunction('get_push_refresh_query', $getRefreshQueryParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format(
            'SELECT coalesce((SELECT jsonb_agg(%%I) FROM %%I %%s WHERE %%I = %%L AND (%%s)), ''[]''::jsonb)', 
            p_value_names->>0, 
            p_table_name, 
            %1\$s.get_join_statement(p_schema_name, p_table_name, p_primary_key_name, p_table_name), 
            p_key_name, 
            p_foreign_key, 
            p_conditions
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('concat_push_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('((%%s) || (%%s))', p_existing_refresh_query, p_refresh_query);
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_push_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          type text;
        BEGIN
          type := p_options->>'type';

          IF p_action = 'REMOVE' THEN
            IF p_old_values->>0 IS NOT NULL THEN
              IF type = 'string' THEN
                RETURN format(
                  '%%s - %%L', 
                  p_foreign_aggregation_name, 
                  p_old_values->>0
                );
              ELSIF type = 'number' THEN
                RETURN format(
                  'array_to_json(array_remove(array(select jsonb_array_elements_text(%%s)), %%s::text)::int[])', 
                  p_foreign_aggregation_name, 
                  p_old_values->>0
                );
              ELSIF type = 'json' THEN
                RETURN format(
                  'array_to_json(%1\$s.array_diff(array(select jsonb_array_elements_text(%%s)), array(select jsonb_array_elements_text(''%%s''))))', 
                  p_foreign_aggregation_name, 
                  p_old_values->>0
                );
              ELSE  
                RETURN format(
                  '%%s - %%s', 
                  p_foreign_aggregation_name, 
                  p_old_values->>0
                );
              END IF;
            END IF;
          END IF;

          IF p_action = 'ADD' THEN
            IF p_new_values->>0 != '' THEN
              IF type = 'string' THEN
                RETURN format(
                  '%%s::jsonb || ''["%%s"]''::jsonb', 
                  p_foreign_aggregation_name, 
                  p_new_values->>0
                );
              ELSIF type = 'json' THEN
                RETURN format(
                  '%%s::jsonb || ''%%s''::jsonb', 
                  p_foreign_aggregation_name, 
                  p_new_values->>0
                );
              ELSE
                RETURN format(
                  '%%s::jsonb || ''[%%s]''::jsonb', 
                  p_foreign_aggregation_name, 
                  p_new_values->>0
                );
              END IF;
            END IF;  
          END IF;

          RETURN '';
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      /**
       * Group
       */
      HoardSchema::createFunction('get_group_refresh_query', $getRefreshQueryParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          IF p_options->>'aggregation_function' IS NULL THEN
            RAISE EXCEPTION '"aggregation_function" key is missing in options.';
          END IF;

          RETURN format(
            'SELECT coalesce(
              %1\$s.filter_jsonb_object(
                (
                  SELECT 
                      jsonb_object_agg(base.key, base.value) 
                    FROM (
                      SELECT 
                          %%s as key, 
                          %%s(%%I) as value
                        FROM %%s %%s
                        WHERE %%s
                        GROUP BY (%%s)
                    ) AS base
                ), 
                %%L
              ), 
              ''{}''::jsonb
            )',
            p_value_names->>0,
            p_options->>'aggregation_function',
            p_value_names->>1,
            p_table_name,
            %1\$s.get_join_statement(p_schema_name, p_table_name, p_primary_key_name, p_table_name),
            p_conditions,
            p_value_names->>0,
            coalesce(p_options->>'condition', '')
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('concat_group_refresh_queries', $concatRefreshQueriesParameters, 'text', sprintf(
        <<<PLPGSQL
        BEGIN
          RETURN format('
            SELECT 
                jsonb_object_agg(base.key, base.value) 
              FROM (
                SELECT key, sum(value::float)
                  FROM ((SELECT * FROM jsonb_each_text(%%s)) UNION ALL (SELECT * FROM jsonb_each_text(%%s))) base
                  GROUP BY key
              ) base
            ', 
            p_existing_refresh_query, 
            p_refresh_query
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),

      HoardSchema::createFunction('get_update_group_statement', $updateParameters, 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          value text;
          update text;
        BEGIN
          IF p_options->>'aggregation_function' IS NULL THEN
            RAISE EXCEPTION '"aggregation_function" key is missing in options.';
          END IF;

          -- Value depends on action
          IF p_action = 'REMOVE' THEN
            value := p_old_values->>0;
          ELSEIF p_action = 'ADD' THEN
            value := p_new_values->>0;
          END IF;

          -- Use existing get_update_*_statement functions to get the update statement
          EXECUTE format(
            'SELECT %1\$s.%%s(%%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L::jsonb, %%L, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L)',
            lower(format('get_update_%%s_statement', p_options->>'aggregation_function')),
            p_schema_name,
            p_table_name,
            p_primary_key_name,
            p_key_name,
            p_foreign_schema_name,
            p_foreign_table_name,
            p_foreign_primary_key_name,
            p_foreign_key_name,

            -- Adjust foreign_aggregation_name and pass the deep path instead
            format(
              'coalesce(%%I->>''%%s'', %%L)::float',
              p_foreign_aggregation_name,
              value,
              0
            ),
            p_foreign_cache_table_name,
            p_foreign_cache_primary_key_name,
            p_aggregation_function,
            p_value_names,
            p_options,
            p_conditions,
            p_foreign_conditions,

            p_operation,
            (format('[%%s]', p_old_values->>1))::jsonb,
            p_old_foreign_key,
            p_old_relevant,
            p_old_condition,
            p_old_refresh_query,
            (format('[%%s]', p_new_values->>1))::jsonb,
            p_new_foreign_key,
            p_new_relevant,
            p_new_condition,
            p_new_refresh_query,
            
            p_action
          ) INTO update;

          RETURN format(
            '%1\$s.filter_jsonb_object(jsonb_set(%%I, (''{'' || (%%L) || ''}'')::text[], (%%s)::text::jsonb), %%L)', 
            p_foreign_aggregation_name,
            value,
            update,
            coalesce(p_options->>'condition', '')
          );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),
    ];
  }

  /**
   * Create refresh functions.
   *
   * @return array
   */
  public static function createRefreshFunctions()
  {
    return [
      HoardSchema::createFunction('get_refresh_query', [
        'primary_key_name' =>   'text',
        'aggregation_function' =>   'text',
        'value_names' =>   'jsonb',
        'options' =>   'jsonb',
        'schema_name' =>   'text',
        'table_name' =>   'text',
        'key_name' =>   'text',
        'foreign_key' =>   'text',
        'conditions' =>   "text DEFAULT ''",
      ], 'text', sprintf(
        <<<PLPGSQL
        DECLARE
          refresh_query text;
          refresh_query_function_name text;
          principal_schema_name text;
          principal_table_name text;
          principal_primary_key_name text;
        BEGIN
          -- Ensure that we have any conditions
          IF conditions = '' THEN
            conditions := '1 = 1';
          END IF;

          -- We always resolve to the principal table even if the table_name is a cached table (e.g. cached_users__default -> users)
          principal_schema_name = 'public'; -- TODO: remove hard coded schema
          principal_table_name = %1\$s.get_table_name(table_name);
          principal_primary_key_name = %1\$s.get_primary_key_name(primary_key_name);

          -- Prepare refresh query
          refresh_query_function_name = lower(format('get_%%s_refresh_query', aggregation_function));
          IF %1\$s.exists_function('%1\$s', refresh_query_function_name) THEN
            EXECUTE format(
              'SELECT %1\$s.%%s(%%L, %%L, %%L, %%L, %%L, %%L::jsonb, %%L, %%L)',
              refresh_query_function_name,
              principal_schema_name,
              principal_table_name,
              primary_key_name,
              key_name,
              value_names,
              options,
              foreign_key,
              conditions
            ) INTO refresh_query;
          ELSE
            refresh_query := format(
              'SELECT %%s(%%s) FROM %%I %%s WHERE %%I = %%L AND (%%s)', 
              aggregation_function, 
              value_names->>0, 
              principal_table_name, 
              %1\$s.get_join_statement(principal_schema_name, principal_table_name, principal_primary_key_name, principal_table_name), 
              key_name, 
              foreign_key, 
              conditions
            );
          END IF;

          RETURN refresh_query;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('upsert_cache', [
        'table_name' => 'text', 'primary_key_name' => 'text', 'primary_key' => 'text', 'updates' => 'jsonb'
      ], 'void', sprintf(
        <<<PLPGSQL
        DECLARE 
          query text;
          concatenated_keys text;
          concatenated_values text;
          concatenated_updates text;
          key text;
          value text;
        BEGIN
          RAISE NOTICE '%1\$s.upsert_cache: start (table_name=%%, primary_key_name=%%, primary_key=%%, updates=%%)', table_name, primary_key_name, primary_key, updates;

          -- Concatenate updates
          FOR key, value IN 
            SELECT * FROM jsonb_each_text(updates)
          LOOP
            concatenated_keys := format('%%s, %%s', concatenated_keys, key);
            concatenated_values := format('%%s, (%%s)', concatenated_values, value);
            concatenated_updates := format('%%s, %%s = excluded.%%s', concatenated_updates, key, key);
          END LOOP;
          
          -- Run update if required
          query := format('INSERT INTO %1\$s.%%s (%%s %%s, txid, cached_at) VALUES (''%%s'' %%s, txid_current(), NOW()) ON CONFLICT (%%s) DO UPDATE SET txid=txid_current(), cached_at=NOW() %%s', table_name, primary_key_name, concatenated_keys, primary_key, concatenated_values, primary_key_name, concatenated_updates);
          RAISE NOTICE '%1\$s.upsert_cache: execute (query=%%)', query;
          EXECUTE query;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('refresh_row', ['p_foreign_schema_name' => 'text', 'p_foreign_table_name' => 'text', 'p_foreign_row' => 'json'], 'void', sprintf(
        <<<PLPGSQL
        DECLARE
          trigger %1\$s.triggers%%rowtype;
          foreign_primary_key text;
          updates jsonb DEFAULT '{}';
          refresh_query text;
          existing_refresh_query text;
          concat_query_function_name text;

          schema_name text;
          table_name text;
          primary_key_name text;
          foreign_schema_name text;
          foreign_cache_table_name text;
          foreign_cache_primary_key_name text;
          foreign_primary_key_name text;
          foreign_aggregation_name text;
          aggregation_function text;
          value_names jsonb;
          options jsonb;
          key_name text;
          conditions text;
          foreign_conditions text;
          
          relevant boolean;
        BEGIN
          RAISE NOTICE '%1\$s.refresh_row: start (p_foreign_schema_name=%%, p_foreign_table_name=%%, p_foreign_row=%%)', p_foreign_schema_name, p_foreign_table_name, p_foreign_row;

          -- Collect all updates in a JSON map
          FOR trigger IN
            EXECUTE format(
              'SELECT * FROM %1\$s.triggers WHERE %1\$s.triggers.foreign_schema_name = ''%%s'' AND %1\$s.triggers.foreign_table_name = ''%%s'' AND %1\$s.triggers.table_name <> '''' ORDER BY foreign_cache_table_name', 
              p_foreign_schema_name, 
              p_foreign_table_name
            )
          LOOP
            -- Execute updates whenever the foreign cache table name changes
            IF foreign_cache_table_name IS NOT NULL AND foreign_cache_table_name <> trigger.foreign_cache_table_name THEN
              PERFORM %1\$s.upsert_cache(foreign_cache_table_name, foreign_cache_primary_key_name, foreign_primary_key, updates);
              updates := '{}';
            END IF;

            table_name := trigger.table_name;
            schema_name := trigger.schema_name;
            primary_key_name := trigger.primary_key_name;
            foreign_cache_table_name := trigger.foreign_cache_table_name;
            foreign_cache_primary_key_name := trigger.foreign_cache_primary_key_name;
            foreign_aggregation_name := trigger.foreign_aggregation_name;
            foreign_primary_key_name := trigger.foreign_primary_key_name;
            foreign_schema_name := trigger.foreign_schema_name;
            aggregation_function := trigger.aggregation_function;
            value_names := trigger.value_names;
            options := trigger.options;
            key_name := trigger.key_name;
            conditions := trigger.conditions;
            foreign_conditions := trigger.foreign_conditions;

            -- Ensure that we have any conditions
            IF conditions = '' THEN
              conditions := '1 = 1';
            END IF;

            -- Ensure that we have any conditions
            IF foreign_conditions = '' THEN
              foreign_conditions := '1 = 1';
            END IF;

            -- Get foreign key 
            foreign_primary_key := %1\$s.get_row_value(p_foreign_row, foreign_primary_key_name);

            -- Check if foreign conditions are met
            EXECUTE format(
              'SELECT true FROM %%s.%%s WHERE %%s = ''%%s'' AND %%s;',
              p_foreign_schema_name, 
              %1\$s.get_table_name(p_foreign_table_name), 
              foreign_primary_key_name, 
              foreign_primary_key, 
              foreign_conditions
            ) USING p_foreign_row INTO relevant;

            IF relevant IS NOT NULL THEN
              -- Prepare refresh query
              refresh_query := %1\$s.get_refresh_query(
                primary_key_name, 
                aggregation_function, 
                value_names, 
                options, 
                schema_name, 
                table_name, 
                key_name,
                %1\$s.get_row_value(p_foreign_row, trigger.foreign_key_name), 
                conditions
              );

              -- Concatenate query if necessary
              existing_refresh_query := updates ->> foreign_aggregation_name;
              IF existing_refresh_query != '' THEN
                concat_query_function_name = lower(format('concat_%%s_refresh_queries', aggregation_function));
                IF %1\$s.exists_function('%1\$s', concat_query_function_name) THEN
                  EXECUTE format(
                    'SELECT %1\$s.%%s(%%L, %%L, %%L, %%L, %%L, %%L::jsonb, %%L, %%L)',
                    refresh_query_function_name,
                    principal_schema_name,
                    principal_table_name,
                    primary_key_name,
                    key_name,
                    value_names,
                    options,
                    foreign_key,
                    conditions
                  ) INTO refresh_query;
                ELSE
                  refresh_query := format('%%s((%%s), (%%s))', aggregation_function, existing_refresh_query, refresh_query);
                END IF;
              END IF;

              -- Set new refresh query in updates map
              updates := updates || jsonb_build_object(foreign_aggregation_name, refresh_query);
            END IF;
          END LOOP;

          -- Run updates that were not yet executed within the loop
          IF foreign_cache_table_name IS NOT NULL AND foreign_cache_primary_key_name IS NOT NULL THEN
            PERFORM %1\$s.upsert_cache(foreign_cache_table_name, foreign_cache_primary_key_name, foreign_primary_key, updates);
          END IF;

          -- Clear logs table
          UPDATE %1\$s.logs 
            SET 
              canceled_at = NOW() 
            WHERE 
                trigger_id = trigger.id
              AND
                ( 
                    new_foreign_key = %1\$s.get_row_value(p_foreign_row, trigger.foreign_key_name)
                  OR
                    old_foreign_key = %1\$s.get_row_value(p_foreign_row, trigger.foreign_key_name)
                );
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('refresh', ['foreign_schema_name' => 'text', 'foreign_table_name' => 'text', 'foreign_table_conditions' => "text DEFAULT '1 = 1'"], 'void', sprintf(
        <<<PLPGSQL
        DECLARE
          foreign_row record;
        BEGIN
          RAISE NOTICE '%1\$s.refresh: start (foreign_schema_name=%%, foreign_table_name=%%, foreign_table_conditions=%%)', foreign_schema_name, foreign_table_name, foreign_table_conditions;

          -- Ensure that we have any conditions
          IF foreign_table_conditions = '' THEN
            foreign_table_conditions := '1 = 1';
          END IF;

          -- Run updates
          FOR foreign_row IN
            EXECUTE format('SELECT * FROM %%s.%%s WHERE %%s', foreign_schema_name, foreign_table_name, foreign_table_conditions)
          LOOP
            PERFORM %1\$s.refresh_row(foreign_schema_name, foreign_table_name, row_to_json(foreign_row));
          END LOOP;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),
    ];
  }

  /**
   * Create process functions.
   *
   * @return array
   */
  public static function createProcessFunctions()
  {
    return [
      HoardSchema::createFunction('process', [
        'p_foreign_schema_name' => 'text',
        'p_foreign_table_name' => 'text',
        'p_foreign_aggregation_name' =>  "text DEFAULT '%%'",
      ], sprintf('%1$s.logs[]', HoardSchema::$cacheSchema), sprintf(
        <<<PLPGSQL
        DECLARE
          trigger %1\$s.triggers%%rowtype;
          log %1\$s.logs%%rowtype;
          logs %1\$s.logs[];
        BEGIN
          RAISE NOTICE '%1\$s.process: start (p_foreign_schema_name=%%, p_foreign_table_name=%%)', p_foreign_schema_name, p_foreign_table_name;

          -- Set global variable for the transaction (can be read via current_setting('hoard.processing')::bool)
          SET hoard.processing = 'true';

          -- Find triggers for the table and run updates
          FOR trigger IN 
            SELECT 
                * 
              FROM 
                %1\$s.triggers
              WHERE 
                  foreign_schema_name = p_foreign_schema_name 
                AND 
                  foreign_table_name = p_foreign_table_name 
                AND
                  foreign_aggregation_name LIKE p_foreign_aggregation_name
          LOOP
            FOR log IN 
              SELECT 
                  * 
                FROM 
                  %1\$s.logs
                WHERE 
                    trigger_id = trigger.id
                  AND
                    (processed_at IS NULL AND canceled_at IS NULL)
                ORDER BY 
                  created_at ASC
                FOR UPDATE SKIP LOCKED
            LOOP
              PERFORM %1\$s.update(
                trigger.schema_name, 
                trigger.table_name, 
                trigger.primary_key_name, 
                trigger.key_name, 
                trigger.foreign_schema_name, 
                trigger.foreign_table_name, 
                trigger.foreign_primary_key_name, 
                trigger.foreign_key_name, 
                trigger.foreign_aggregation_name, 
                trigger.foreign_cache_table_name, 
                trigger.foreign_cache_primary_key_name, 
                trigger.aggregation_function, 
                trigger.value_names, 
                trigger.options, 
                trigger.conditions, 
                trigger.foreign_conditions, 
                log.operation, 
                log.old_values, 
                log.old_foreign_key, 
                log.old_relevant, 
                log.new_values, 
                log.new_foreign_key, 
                log.new_relevant
              );

              UPDATE %1\$s.logs SET processed_at = NOW() WHERE id = log.id;
              logs := array_append(logs, log);
            END LOOP;
          END LOOP;

          RETURN logs;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),
    ];
  }

  /**
   * Create update functions.
   *
   * @return array
   */
  public static function createUpdateFunctions()
  {
    return [
      HoardSchema::createFunction('update', [
        'p_schema_name' => 'text',
        'p_table_name' => 'text',
        'p_primary_key_name' => 'text',
        'p_key_name' => 'text',
        'p_foreign_schema_name' => 'text',
        'p_foreign_table_name' => 'text',
        'p_foreign_primary_key_name' => 'text',
        'p_foreign_key_name' => 'text',
        'p_foreign_aggregation_name' => 'text',
        'p_foreign_cache_table_name' => 'text',
        'p_foreign_cache_primary_key_name' => 'text',
        'p_aggregation_function' => 'text',
        'p_value_names' => 'jsonb',
        'p_options' => 'jsonb',
        'p_conditions' => 'text',
        'p_foreign_conditions' => 'text',

        'p_operation' => 'text',
        'p_old_values' => 'jsonb',
        'p_old_foreign_key' => 'text',
        'p_old_relevant' => 'boolean',
        'p_new_values' => 'jsonb',
        'p_new_foreign_key' => 'text',
        'p_new_relevant' => 'boolean'
      ], 'void', sprintf(
        <<<PLPGSQL
        DECLARE
          changed_foreign_key boolean;
          changed_value boolean;
          old_update text;
          old_refresh_query text;
          old_condition text;
          new_update text;
          new_refresh_query text;
          new_condition text;

          update_function_name text;

          key text;
          value text;
          index int DEFAULT 0;
        BEGIN
          update_function_name = lower(format('get_update_%%s_statement', p_aggregation_function));

          -- Check what has changed
          IF p_new_foreign_key <> '' THEN
            changed_foreign_key := p_new_foreign_key IS DISTINCT FROM p_old_foreign_key;

            FOR value IN
              SELECT * FROM jsonb_array_elements(p_new_values)
            LOOP
              IF value IS DISTINCT FROM p_old_values->>index THEN
                changed_value := true;
              END IF;

              index := index + 1;
            END LOOP;
          END IF;

          RAISE NOTICE '
            %1\$s.update: start (
              p_schema_name=%%, 
              p_table_name=%%, 
              p_key_name=%%, 
              p_foreign_table_name=%%,
              p_foreign_primary_key_name=%%, 
              p_foreign_key_name=%%, 
              p_foreign_aggregation_name=%%, 
              p_foreign_cache_table_name=%%, 
              p_foreign_cache_primary_key_name=%%, 
              p_aggregation_function=%%, 
              p_value_names=%%, 
              p_options=%%, 
              p_conditions=%%, 
              p_foreign_conditions=%%, 
              p_operation=%%,
              p_old_values=%%, 
              p_old_foreign_key=%%, 
              p_old_relevant=%%, 
              p_new_values=%%, 
              p_new_foreign_key=%%, 
              p_new_relevant=%%, 
              changed_foreign_key=%%, 
              changed_value=%%
            )',
            p_schema_name,  
            p_table_name,
            p_key_name,
            p_foreign_table_name,
            p_foreign_primary_key_name,
            p_foreign_key_name,
            p_foreign_aggregation_name,
            p_foreign_cache_table_name,
            p_foreign_cache_primary_key_name,
            p_aggregation_function,
            p_value_names,
            p_options,
            p_conditions,
            p_foreign_conditions,

            p_operation, 
            p_old_values,
            p_old_foreign_key,
            p_old_relevant,
            p_new_values,
            p_new_foreign_key,
            p_new_relevant,
            changed_foreign_key,
            changed_value
          ;

          -- Ensure that we have any condition
          IF p_foreign_conditions = '' THEN
            p_foreign_conditions := '1 = 1';
          END IF;

          -- Prepare conditions
          IF p_foreign_table_name = p_foreign_cache_table_name THEN
            old_condition := format('%%s = ''%%s'' AND ( %%s )', p_foreign_key_name, p_old_foreign_key, p_foreign_conditions);
            new_condition := format('%%s = ''%%s'' AND ( %%s )', p_foreign_key_name, p_new_foreign_key, p_foreign_conditions);
          ELSE 
            old_condition := format(
              '%%s IN (SELECT %%s FROM %%s WHERE %%s = ''%%s'' AND ( %%s ))', 
              p_foreign_cache_primary_key_name, 
              p_foreign_primary_key_name, 
              p_foreign_table_name, 
              p_foreign_key_name, 
              p_old_foreign_key, 
              p_foreign_conditions
            );
            new_condition := format(
              '%%s IN (SELECT %%s FROM %%s WHERE %%s = ''%%s'' AND ( %%s ))', 
              p_foreign_cache_primary_key_name, 
              p_foreign_primary_key_name, 
              p_foreign_table_name, 
              p_foreign_key_name, 
              p_new_foreign_key, 
              p_foreign_conditions
            );
          END IF;

          -- Prepare refresh query that can be used to get the aggregated value
          old_refresh_query := %1\$s.get_refresh_query(
            p_primary_key_name, 
            p_aggregation_function, 
            p_value_names, 
            p_options, 
            p_schema_name, 
            p_table_name, 
            p_key_name, 
            p_old_foreign_key, 
            p_conditions
          );
          new_refresh_query := %1\$s.get_refresh_query(
            p_primary_key_name, 
            p_aggregation_function, 
            p_value_names, 
            p_options,
            p_schema_name, 
            p_table_name, 
            p_key_name, 
            p_new_foreign_key, 
            p_conditions
          );

          -- Update row if
          -- 1. Foreign row with matching conditions is deleted
          -- 2. Foreign row was updated and conditions are not matching anymore
          IF 
            (
                p_old_relevant = true 
              AND 
                p_old_foreign_key IS NOT NULL
              AND 
                (
                    p_operation = 'DELETE' 
                  OR 
                    (
                        p_operation = 'UPDATE' 
                      AND 
                        (
                            p_new_relevant = false 
                          OR 
                            (
                                changed_value = true 
                              OR 
                                changed_foreign_key = true
                            )
                        )
                    )
                ) 
            ) 
          THEN
            EXECUTE format(
              'SELECT %1\$s.%%s(%%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L::jsonb, %%L, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L)',
              update_function_name,
              p_schema_name,
              p_table_name,
              p_primary_key_name,
              p_key_name,
              p_foreign_schema_name,
              p_foreign_table_name,
              p_foreign_primary_key_name,
              p_foreign_key_name,
              p_foreign_aggregation_name,
              p_foreign_cache_table_name,
              p_foreign_cache_primary_key_name,
              p_aggregation_function,
              p_value_names,
              p_options,
              p_conditions,
              p_foreign_conditions,

              p_operation,
              p_old_values,
              p_old_foreign_key,
              p_old_relevant,
              old_condition,
              old_refresh_query,
              p_new_values,
              p_new_foreign_key,
              p_new_relevant,
              new_condition,
              new_refresh_query,
              
              'REMOVE'
            ) INTO old_update;

            IF old_update != '' THEN
              RAISE NOTICE '%1\$s.update: delete or update old (old_update=%%)', old_update;

              EXECUTE format(
                'UPDATE %1\$s.%%s SET %%s = (%%s) WHERE %%s', 
                p_foreign_cache_table_name, 
                p_foreign_aggregation_name, 
                old_update, 
                old_condition
              );
            END IF;
          END IF;
      
          -- Update row if
          -- 1. Foreign row with matching conditions is created
          -- 2. Foreign row was updated and conditions are now matching
          IF 
            (
                p_new_relevant = true 
              AND 
                p_new_foreign_key IS NOT NULL 
              AND 
                (
                    p_operation = 'INSERT' 
                  OR 
                    (
                        p_operation = 'UPDATE' 
                      AND 
                        (
                            p_old_relevant = false 
                          OR 
                            (
                                changed_value = true 
                              OR 
                                changed_foreign_key = true
                            )
                        )
                    )
                ) 
            )
          THEN
            EXECUTE format(
              'SELECT %1\$s.%%s(%%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L, %%L, %%L::bool, %%L, %%L, %%L)',
              update_function_name,
              p_schema_name,
              p_table_name,
              p_primary_key_name,
              p_key_name,
              p_foreign_schema_name,
              p_foreign_table_name,
              p_foreign_primary_key_name,
              p_foreign_key_name,
              p_foreign_aggregation_name,
              p_foreign_cache_table_name,
              p_foreign_cache_primary_key_name,
              p_aggregation_function,
              p_value_names,
              p_options,
              p_conditions,
              p_foreign_conditions,

              p_operation,
              p_old_values,
              p_old_foreign_key,
              p_old_relevant,
              old_condition,
              old_refresh_query,
              p_new_values,
              p_new_foreign_key,
              p_new_relevant,
              new_condition,
              new_refresh_query,

              'ADD'
            ) INTO new_update;

            IF new_update != '' THEN
              RAISE NOTICE '%1\$s.update: create or update new (new_update=%%)', new_update;

              EXECUTE format(
                'UPDATE %1\$s.%%s SET %%s = (%%s) WHERE %%s', 
                p_foreign_cache_table_name, 
                p_foreign_aggregation_name, 
                new_update, 
                new_condition
              );
            END IF;
          END IF;
        END;
      PLPGSQL,
        HoardSchema::$cacheSchema
      ), 'PLPGSQL'),
    ];
  }

  /**
   * Create trigger functions.
   *
   * @return array
   */
  public static function createTriggerFunctions()
  {
    return [

      HoardSchema::createFunction('after_trigger', [], 'trigger', sprintf(
        <<<PLPGSQL
        DECLARE
          trigger %1\$s.triggers%%rowtype;

          trigger_table_name text;
          cached_trigger_table_name boolean DEFAULT false;

          trigger_id int;
          schema_name text;
          table_name text;
          primary_key_name text;
          foreign_schema_name text;
          foreign_table_name text;
          foreign_cache_table_name text;
          foreign_aggregation_name text;
          foreign_primary_key_name text;
          foreign_cache_primary_key_name text;
          foreign_key_name text;
          aggregation_function text;
          value_names jsonb;
          options jsonb;
          key_name text;
          conditions text;
          foreign_conditions text;
    
          new_values jsonb;
          new_foreign_key text;
          new_condition text;
          new_relevant boolean DEFAULT false;

          asynchronous boolean;
          processed_at timestamp with time zone DEFAULT null; 
        BEGIN
          RAISE NOTICE '-- %1\$s.after_trigger START';

          -- Log
          RAISE NOTICE '%1\$s.after_trigger: start (TG_OP=%%, TG_TABLE_NAME=%%, trigger_table_name=%%, OLD=%%, NEW=%%)', TG_OP, TG_TABLE_NAME, trigger_table_name, OLD::text, NEW::text;

          -- If this is the first row we need to create an entry for the new row in the cache table
          IF TG_OP = 'INSERT' AND NOT %1\$s.is_cache_table_name(TG_TABLE_NAME) THEN
            PERFORM %1\$s.refresh_row(TG_TABLE_SCHEMA, TG_TABLE_NAME, row_to_json(NEW));
          END IF;

          -- Get all triggers that affect OTHER tables
          FOR trigger IN
            SELECT * FROM %1\$s.triggers
            WHERE 
                %1\$s.triggers.schema_name = TG_TABLE_SCHEMA
              AND 
                %1\$s.triggers.table_name = TG_TABLE_NAME
              AND 
                %1\$s.triggers.manual = false
          LOOP
            trigger_id := trigger.id;
            schema_name := trigger.schema_name;
            table_name := trigger.table_name;
            primary_key_name := trigger.primary_key_name;
            foreign_schema_name := trigger.foreign_schema_name;
            foreign_table_name := trigger.foreign_table_name;
            foreign_cache_table_name := trigger.foreign_cache_table_name;
            foreign_aggregation_name := trigger.foreign_aggregation_name;
            foreign_primary_key_name := trigger.foreign_primary_key_name;
            foreign_cache_primary_key_name := trigger.foreign_cache_primary_key_name;
            foreign_key_name := trigger.foreign_key_name;
            aggregation_function := trigger.aggregation_function;
            value_names := trigger.value_names;
            options := trigger.options;
            key_name := trigger.key_name;
            conditions := trigger.conditions;
            foreign_conditions := trigger.foreign_conditions;
            asynchronous := trigger.asynchronous;
            RAISE NOTICE '%1\$s.after_trigger: trigger (schema_name=%%, table_name=%%, primary_key_name=%%, foreign_schema_name=%%, foreign_table_name=%%, foreign_cache_table_name=%%, foreign_aggregation_name=%%, foreign_key_name=%%, aggregation_function=%%, value_names=%%, key_name=%%, conditions=%%, foreign_conditions=%%, asynchronous=%%)', schema_name, table_name, primary_key_name, foreign_schema_name, foreign_table_name, foreign_cache_table_name, foreign_aggregation_name, foreign_key_name, aggregation_function, value_names, key_name, conditions, foreign_conditions, asynchronous;
            
            -- Reset processed time
            processed_at := NULL;

            -- Ensure that we have any conditions
            IF conditions = '' THEN
              conditions := '1 = 1';
            END IF;

            -- Get foreign key and value from new record
            EXECUTE format(
              'SELECT %1\$s.jsonb_object_to_value_array((SELECT row_to_json(values.*) FROM (SELECT %%s FROM (SELECT $1.*) record %%s) AS values)::jsonb)', 
              %1\$s.value_names_to_columns(value_names), 
              %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) 
              USING NEW 
              INTO new_values;
            EXECUTE format(
              'SELECT %%s FROM (SELECT $1.*) record %%s;', 
              key_name, 
              %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) 
              USING NEW 
              INTO new_foreign_key;
            EXECUTE format(
              'SELECT true FROM (SELECT $1.*) record %%s WHERE %%s;', 
              %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record'), conditions) 
              USING NEW 
              INTO new_relevant;

            -- Set new_relevant explicitly to false to allow proper checks
            IF new_relevant IS NULL THEN
              new_relevant := false;
            END IF;

            RAISE NOTICE '%1\$s.after_trigger: new (new_values=%%, new_foreign_key=%%, new_relevant=%%)', new_values, new_foreign_key, new_relevant;
      
            -- Run update if required
            IF asynchronous = false THEN
              PERFORM %1\$s.update(
                schema_name,
                table_name,
                primary_key_name,
                key_name,
                foreign_schema_name,
                foreign_table_name,
                foreign_primary_key_name,
                foreign_key_name,
                foreign_aggregation_name,
                foreign_cache_table_name,
                foreign_cache_primary_key_name,
                aggregation_function,
                value_names,
                options,
                conditions,
                foreign_conditions,

                TG_OP, 
                null,
                null,
                false,
                new_values,
                new_foreign_key,
                new_relevant
              );
              processed_at := NOW();
            ELSE
              RAISE NOTICE '%1\$s.after_trigger: skip update because of asynchronous mode';
            END IF;
            RAISE NOTICE '';

            -- Store update in logs
            EXECUTE format('INSERT INTO %1\$s.logs (trigger_id, operation, old_relevant, new_values, new_foreign_key, new_relevant, processed_at) VALUES($1, $2, false, $3, $4, $5, $6)') 
              USING trigger_id, TG_OP, new_values, new_foreign_key, new_relevant, processed_at;
          END LOOP;

          RAISE NOTICE '-- %1\$s.after_trigger END';
      
          IF TG_OP = 'DELETE' THEN
            RETURN OLD;
          END IF;

          RETURN NEW;
        END;
        PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('before_trigger', [], 'trigger', sprintf(
        <<<PLPGSQL
          DECLARE
            trigger %1\$s.triggers%%rowtype;
            
            trigger_id int;
            table_name text;
            primary_key_name text;
            foreign_schema_name text;
            foreign_table_name text;
            foreign_cache_table_name text;
            foreign_aggregation_name text;
            foreign_key_name text;
            aggregation_function text;
            value_names jsonb;
            options jsonb;
            key_name text;
            conditions text;
            foreign_conditions text;
            foreign_key text;
            foreign_primary_key text;
            foreign_primary_key_name text;
            foreign_cache_primary_key_name text;
            foreign_cache_primary_key text;
            primary_key text;

            old_values jsonb;
            old_foreign_key text;
            old_condition text;
            old_relevant boolean DEFAULT false;

            asynchronous boolean;
            processed_at timestamp with time zone DEFAULT null; 
          BEGIN
            RAISE NOTICE '-- %1\$s.before_trigger START';
            RAISE NOTICE '%1\$s.before_trigger: start (TG_OP=%%, TG_TABLE_NAME=%%, OLD=%%, NEW=%%)', TG_OP, TG_TABLE_NAME, OLD::text, NEW::text;

            -- On DELETE we need to check the triggers before because otherwise the join table will be deleted and we cannot check the conditions anymore
            IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
              -- Get all triggers that affect OTHER tables
              FOR trigger IN
                SELECT * FROM %1\$s.triggers 
                WHERE 
                    (
                        (
                              %1\$s.is_cache_table_name(TG_TABLE_NAME) = true
                            AND 
                              %1\$s.triggers.table_name = TG_TABLE_NAME
                        )
                      OR
                        (
                            %1\$s.is_cache_table_name(TG_TABLE_NAME) = false
                          AND 
                            %1\$s.get_table_name(%1\$s.triggers.table_name) = %1\$s.get_table_name(TG_TABLE_NAME)
                        )
                    )
                  AND 
                    %1\$s.triggers.manual = false
              LOOP
                CONTINUE WHEN TG_OP = 'DELETE' AND trigger.lazy = true;

                trigger_id := trigger.id;
                table_name := trigger.table_name;
                primary_key_name := trigger.primary_key_name;
                foreign_schema_name := trigger.foreign_schema_name;
                foreign_table_name := trigger.foreign_table_name;
                foreign_cache_table_name := trigger.foreign_cache_table_name;
                foreign_aggregation_name := trigger.foreign_aggregation_name;
                foreign_primary_key_name := trigger.foreign_primary_key_name;
                foreign_cache_primary_key_name := trigger.foreign_cache_primary_key_name;
                foreign_key_name := trigger.foreign_key_name;
                aggregation_function := trigger.aggregation_function;
                value_names:= trigger.value_names;
                options := trigger.options;
                key_name := trigger.key_name;
                conditions := trigger.conditions;
                foreign_conditions := trigger.foreign_conditions;
                asynchronous := trigger.asynchronous;
                RAISE NOTICE '%1\$s.before_trigger: trigger (TG_TABLE_NAME=%%, table_name=%%, primary_key_name=%%, foreign_table_name=%%, foreign_cache_table_name=%%, foreign_aggregation_name=%%, foreign_key_name=%%, aggregation_function=%%, value_names=%%, key_name=%%, conditions=%%, foreign_conditions=%%, asynchronous=%%)', TG_TABLE_NAME, table_name, primary_key_name, foreign_table_name, foreign_cache_table_name, foreign_aggregation_name, foreign_key_name, aggregation_function, value_names, key_name, conditions, foreign_conditions, asynchronous;

                -- Reset processed time
                processed_at := NULL;

                -- Ensure that we have any conditions
                IF conditions = '' THEN
                  conditions := '1 = 1';
                END IF;
                IF foreign_conditions = '' THEN
                  foreign_conditions := '1 = 1';
                END IF;

                -- Get foreign key and value from old record
                EXECUTE format(
                  'SELECT %1\$s.jsonb_object_to_value_array((SELECT row_to_json(values.*) FROM (SELECT %%s FROM (SELECT $1.*) record %%s) AS values)::jsonb)', 
                  %1\$s.value_names_to_columns(value_names), 
                  %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) 
                  USING OLD 
                  INTO old_values;
                EXECUTE format(
                  'SELECT %%s FROM (SELECT $1.*) record %%s;', 
                  key_name, 
                  %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) 
                  USING OLD INTO old_foreign_key;
                EXECUTE format(
                  'SELECT true FROM (SELECT $1.*) record %%s WHERE %%s;', 
                  %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record'), conditions) 
                  USING OLD 
                  INTO old_relevant;

                -- Set old_relevant explicitly to false to allow proper checks
                IF old_relevant IS NULL THEN
                  old_relevant := false;
                END IF;

                RAISE NOTICE '%1\$s.before_trigger: old (old_values=%%, old_foreign_key=%%, old_relevant=%%)', old_values, old_foreign_key, old_relevant;

                -- During deletion we exclude ourself from the update conditions
                EXECUTE format('SELECT %%s FROM (SELECT $1.*) record %%s WHERE %%s;', primary_key_name, %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record'), conditions) USING OLD INTO primary_key;
                conditions := format('%%s AND %%s <> ''%%s''', conditions, primary_key_name, primary_key);
            
                -- Run update if required
                IF asynchronous = false THEN
                  PERFORM %1\$s.update(
                    TG_TABLE_SCHEMA,
                    TG_TABLE_NAME,
                    primary_key_name,
                    key_name,
                    foreign_schema_name,
                    foreign_table_name,
                    foreign_primary_key_name,
                    foreign_key_name,
                    foreign_aggregation_name,
                    foreign_cache_table_name,
                    foreign_cache_primary_key_name,
                    aggregation_function,
                    value_names,
                    options,
                    conditions,
                    foreign_conditions,

                    TG_OP, 
                    old_values,
                    old_foreign_key,
                    old_relevant,
                    null,
                    null,
                    false
                  );
                  processed_at := NOW();
                ELSE
                  RAISE NOTICE '%1\$s.before_trigger: skip update because of asynchronous mode';
                END IF;
                RAISE NOTICE '';

                -- Store update in logs
                EXECUTE format('INSERT INTO %1\$s.logs (trigger_id, operation, new_relevant, old_values, old_foreign_key, old_relevant, processed_at) VALUES($1, $2, false, $3, $4, $5, $6)') USING trigger_id, TG_OP, old_values, old_foreign_key, old_relevant, processed_at;
              END LOOP;
            END IF;

            RAISE NOTICE '-- %1\$s.before_trigger END';
        
            IF TG_OP = 'DELETE' THEN
              RETURN OLD;
            END IF;
        
            RETURN NEW;
          END;
          PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('create_triggers', [
        'schema_name' => 'text',
        'table_name' => 'text',
      ], 'void', sprintf(
        <<<PLPGSQL
          BEGIN
            RAISE NOTICE '%1\$s.create_triggers: start (schema_name=%%, table_name=%%)', schema_name, table_name;

            -- Create triggers for table
            IF table_name <> '' AND NOT %1\$s.exists_trigger(schema_name, table_name, 'hoard_before') THEN
              EXECUTE format('
                  CREATE TRIGGER hoard_before
                  BEFORE INSERT OR UPDATE OR DELETE ON %%s.%%s
                  FOR EACH ROW 
                  EXECUTE FUNCTION %1\$s.before_trigger()
                ', schema_name, table_name);
            END IF;

            IF table_name <> '' AND NOT %1\$s.exists_trigger(schema_name, table_name, 'hoard_after') THEN
              EXECUTE format('
                CREATE TRIGGER hoard_after
                  AFTER INSERT OR UPDATE OR DELETE ON %%s.%%s
                  FOR EACH ROW 
                  EXECUTE FUNCTION %1\$s.after_trigger()
                ', schema_name, table_name);
            END IF;
          END;
          PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('prepare', [], 'trigger', sprintf(
        <<<PLPGSQL
          BEGIN
            RAISE NOTICE '%1\$s.prepare: start (TG_OP=%%, OLD=%%, NEW=%%)', TG_OP, OLD, NEW;

            IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
              EXECUTE format('DROP VIEW IF EXISTS %1\$s.%%s', %1\$s.get_cache_view_name(OLD.foreign_table_name));
            END IF;

            IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
              EXECUTE format('DROP VIEW IF EXISTS %1\$s.%%s', %1\$s.get_cache_view_name(NEW.foreign_table_name));
            END IF;

            RETURN NEW;
          END;
        PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),

      HoardSchema::createFunction('initialize', [], 'trigger', sprintf(
        <<<PLPGSQL
            BEGIN
              RAISE NOTICE '%1\$s.initialize: start (TG_OP=%%, OLD=%%, NEW=%%)', TG_OP, OLD, NEW;

              IF TG_OP = 'DELETE' THEN
                PERFORM %1\$s.create_views(OLD.foreign_table_name);
              END IF;

              IF TG_OP = 'INSERT' THEN
                PERFORM %1\$s.create_views(NEW.foreign_table_name);
              END IF;

              IF TG_OP = 'UPDATE' THEN
                PERFORM %1\$s.create_views(NEW.foreign_table_name);
              END IF;

              IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
                PERFORM %1\$s.create_triggers(NEW.schema_name, NEW.table_name);
                PERFORM %1\$s.create_triggers(NEW.foreign_schema_name, NEW.foreign_table_name);
                PERFORM %1\$s.create_triggers('hoard', NEW.foreign_cache_table_name);
              END IF;

              RETURN NEW;
            END;
          PLPGSQL,
        HoardSchema::$cacheSchema,
      ), 'PLPGSQL'),
    ];
  }

  /**
   * Create view functions.
   *
   * @return array
   */
  public static function createViewFunctions()
  {
    return [

      HoardSchema::createFunction('create_views', [
        'p_foreign_table_name' => 'text'
      ], 'void', sprintf(
        <<<PLPGSQL
            DECLARE
              trigger %1\$s.triggers%%rowtype;
              
              foreign_schema_name text;
              foreign_table_name text;
              foreign_cache_table_name text;
              foreign_aggregation_name text;
              foreign_primary_key_name text;
              foreign_cache_primary_key_name text;

              joins jsonb DEFAULT '{}';
              concatenated_joins text;
              foreign_aggregation_names jsonb DEFAULT '{}';
              concatenated_foreign_aggregation_names text;
              cache_view_name text;
              key text;
              value text;
            BEGIN
              RAISE NOTICE '%1\$s.create_views: start (p_foreign_table_name=%%)', p_foreign_table_name;

              -- Get all visible cached fields
              FOR trigger IN
                SELECT * FROM %1\$s.triggers
                WHERE 
                    %1\$s.triggers.foreign_table_name = p_foreign_table_name
                  AND
                    hidden = false
              LOOP
                foreign_schema_name := trigger.foreign_schema_name;
                foreign_table_name := trigger.foreign_table_name;
                foreign_primary_key_name := trigger.foreign_primary_key_name;
                foreign_cache_table_name := trigger.foreign_cache_table_name;
                foreign_cache_primary_key_name := trigger.foreign_cache_primary_key_name;
                foreign_aggregation_name := trigger.foreign_aggregation_name;
              
                joins := joins || jsonb_build_object(foreign_cache_table_name, format('%%s.%%s.%%s = %1\$s.%%s.%%s', foreign_schema_name, foreign_table_name, foreign_primary_key_name, foreign_cache_table_name, foreign_cache_primary_key_name));
                foreign_aggregation_names := foreign_aggregation_names || jsonb_build_object(foreign_aggregation_name, foreign_cache_table_name);
              END LOOP;

              -- Concatenate joins
              FOR key, value IN 
                SELECT * FROM jsonb_each_text(joins)
              LOOP
                concatenated_joins := format('%%s LEFT JOIN %1\$s.%%s ON %%s', concatenated_joins, key, value);
              END LOOP;

              -- Concatenate aggregation names
              FOR key, value IN 
                SELECT * FROM jsonb_each_text(foreign_aggregation_names)
              LOOP
                concatenated_foreign_aggregation_names := format('%%s, %%s.%%s', concatenated_foreign_aggregation_names, value, key);
              END LOOP;

              -- Create view
              IF foreign_primary_key_name IS NOT NULL THEN
                cache_view_name := %1\$s.get_cache_view_name(p_foreign_table_name);
                EXECUTE format(
                  'CREATE VIEW %1\$s.%%s AS SELECT %%s.%%s %%s FROM %%s %%s', 
                  cache_view_name, 
                  foreign_cache_table_name, 
                  %1\$s.get_cache_primary_key_name(foreign_cache_primary_key_name), 
                  concatenated_foreign_aggregation_names, 
                  p_foreign_table_name, 
                  concatenated_joins
                );
              END IF;
            END;
          PLPGSQL,
        HoardSchema::$cacheSchema
      )),
    ];
  }
}
