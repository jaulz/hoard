<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;

class HoardServiceProvider extends ServiceProvider
{
  /**
   * Boots the service provider.
   */
  public function boot()
  {
    $this->publishes(
      [
        __DIR__ . '/../database/migrations/' => base_path('migrations'),
      ],
      'hoard-migrations'
    );

    $this->commands([]);

    $this->enhanceBlueprint();
  }

  public function enhanceBlueprint()
  {
    Blueprint::macro('hoardContext', function (
      array $context
    ) {
      /** @var \Illuminate\Database\Schema\Blueprint $this */
      $this->addCommand(
        'hoardContext',
        $context
      );
    });

    Blueprint::macro('hoard', function (
      string $foreignAggregationName
    ) {
      /** @var \Illuminate\Database\Schema\Blueprint $this */
      $hoardContext = collect($this->getCommands())->first(function ($command) {
        return $command->get('name') === 'hoardContext';
      });
      $foreignTableName = $this->prefix . $hoardContext->get('tableName');
      $foreignPrimaryKeyName = $hoardContext->get('primaryKeyName');
      $cacheTableGroup = $hoardContext->get('cacheTableGroup');

      $command = $this->addCommand(
        'hoard',
        compact(
          'foreignAggregationName',
          'foreignTableName',
          'foreignPrimaryKeyName',
          'cacheTableGroup'
        )
      );

      return new HoardDefinition($this, $command);
    });

    PostgresGrammar::macro('compileHoard', function (
      Blueprint $blueprint,
      Fluent $command
    ) {
      /** @var \Illuminate\Database\Schema\Grammars\PostgresGrammar $this */
      $foreignAggregationName = $command->foreignAggregationName;
      $foreignSchemaName = Str::contains($command->foreignTableName, '.') ? Str::before($command->foreignTableName, '.') : HoardSchema::$schema;
      $foreignTableName = Str::after($command->foreignTableName, '.');
      $foreignKeyName = $command->foreignKeyName ?? $command->foreignPrimaryKeyName ??  'id';
      $keyName = $command->keyName ?? Str::singular($foreignTableName) . '_' . $foreignKeyName;
      $valueType = $command->valueType ?? 'text';
      $aggregationFunction = Str::upper($command->aggregationFunction) ?? '';
      $valueName = $command->valueName ?? '';
      $foreignConditions = HoardSchema::prepareConditions($command->foreignConditions ?? []);
      $conditions = HoardSchema::prepareConditions($command->conditions ?? []);
      $groupName = $command->groupName;
      $tableName = $command->tableName ?? '';
      $schemaName = $groupName ? HoardSchema::$cacheSchema : HoardSchema::$schema;
      $tableName = $groupName ? HoardSchema::getCacheTableName($tableName, $groupName, false) : $tableName;
      $primaryKeyName = $command->primaryKeyName ?? 'id';
      $primaryKeyName = $groupName ? HoardSchema::getCachePrimaryKeyName($tableName, $primaryKeyName) : $primaryKeyName;
      $foreignPrimaryKeyName = $command->foreignPrimaryKeyName ?? $foreignKeyName;
      $refreshConditions = HoardSchema::prepareConditions($command->refreshConditions ?? []);
      $lazy = $command->lazy ?? false;
      $hidden = $command->hidden ?? false;
      $manual = $command->manual ?? false;
      $asynchronous = $command->asynchronous ?? false;
      $cacheTableGroup = $command->cacheTableGroup;
      $foreignCacheTableName = HoardSchema::getCacheTableName($foreignTableName, $cacheTableGroup, false);
      $foreignCachePrimaryKeyName = HoardSchema::getCachePrimaryKeyName($foreignTableName, $foreignPrimaryKeyName);

      return array_filter([
        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.array_diff(array1 anyarray, array2 anyarray)
              RETURNS anyarray
              AS $$
                SELECT 
                    COALESCE(ARRAY_AGG(element), '{}')
                  FROM UNNEST(array1) element
                  WHERE 
                    element <> all(array2)
              $$ LANGUAGE SQL IMMUTABLE;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_primary_key_name(cache_primary_key_name text)
              RETURNS text
              AS $$
                BEGIN
                  IF NOT %1\$s.is_cache_primary_key_name(cache_primary_key_name) THEN
                    RETURN cache_primary_key_name;
                  END IF;

                  RETURN SUBSTRING(cache_primary_key_name, LENGTH('%2\$s') + 1);
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cachePrimaryKeyNamePrefix
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.is_cache_primary_key_name(primary_key_name text)
              RETURNS boolean
              AS $$
                BEGIN
                  RETURN POSITION('%2\$s' in primary_key_name) > 0;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cachePrimaryKeyNamePrefix
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_cache_primary_key_name(primary_key_name text)
              RETURNS text
              AS $$
                BEGIN
                  IF %1\$s.is_cache_primary_key_name(primary_key_name) THEN
                    RETURN primary_key_name;
                  END IF;

                  RETURN format('%2\$s%%s', primary_key_name);
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cachePrimaryKeyNamePrefix
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_cache_view_name(table_name text)
              RETURNS text
              AS $$
                BEGIN
                  IF %1\$s.is_cache_table_name(table_name) THEN
                    table_name := %1\$s.get_table_name(table_name);
                  END IF;
  
                  RETURN format('%2\$s%%s%3\$s', table_name);
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cacheViewNamePrefix,
          HoardSchema::$cacheViewNameSuffix
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_table_name(cache_table_name text)
              RETURNS text
              AS $$
                DECLARE
                  table_name text;
                BEGIN
                  IF %1\$s.is_cache_table_name(cache_table_name) THEN
                    table_name := SUBSTRING(cache_table_name, 1, POSITION('%3\$s' in cache_table_name) - 1);
                    table_name := SUBSTRING(table_name, LENGTH('%2\$s') + 1);

                    RETURN table_name;
                  END IF;
  
                  RETURN cache_table_name;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cacheTableNamePrefix,
          HoardSchema::$cacheTableNameDelimiter
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.is_cache_table_name(table_name text)
              RETURNS boolean
              AS $$
                BEGIN
                  RETURN POSITION('%2\$s' in table_name) = 1 AND POSITION('%3\$s' in table_name) > 0 AND POSITION('%4\$s' in table_name) > 0;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema,
          HoardSchema::$cacheTableNamePrefix,
          HoardSchema::$cacheTableNameDelimiter,
          HoardSchema::$cacheTableNameSuffix,
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.exists_trigger(schema_name text, table_name text, _trigger_name text)
              RETURNS bool
              AS $$
                DECLARE
                BEGIN
                  IF EXISTS(SELECT true FROM information_schema.triggers WHERE trigger_schema = schema_name AND triggers.event_object_table = table_name AND trigger_name = _trigger_name) THEN
                    RETURN true;
                  ELSE
                    RETURN false;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.exists_table(schema_name text, _table_name text)
              RETURNS bool
              AS $$
                BEGIN
                  IF EXISTS(SELECT true FROM information_schema.tables WHERE table_schema = schema_name AND table_name = _table_name) THEN
                    RETURN true;
                  ELSE
                    RETURN false;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.exists_view(schema_name text, view_name text)
              RETURNS bool
              AS $$
                BEGIN
                  IF EXISTS(SELECT true FROM information_schema.views WHERE table_schema = schema_name AND table_name = view_name) THEN
                    RETURN true;
                  ELSE
                    RETURN false;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.set_row_value(record anyelement, key text, value text)
              RETURNS anyelement
              AS $$
                SELECT json_populate_record(record, json_build_object(key, value));
              $$ LANGUAGE SQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.set_row_value(record anyelement, key text, value jsonb)
              RETURNS anyelement
              AS $$
                SELECT json_populate_record(record, json_build_object(key, value), true);
              $$ LANGUAGE SQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_row_value(record json, key text)
              RETURNS text
              AS $$
                BEGIN
                  RETURN record ->> key;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_row_value(record anyelement, key text)
              RETURNS text
              AS $$
                BEGIN
                  RETURN row_to_json(record) ->> key;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_join_statement(join_schema_name text, join_table_name text, primary_key_name text, alias text)
              RETURNS text
              AS $$
              DECLARE
                cache_view_name text;
                cache_table_name text;
                table_name text;
              BEGIN
                -- In case the provided table name is a cache table name we will join the principal table
                IF %1\$s.is_cache_table_name(join_table_name) THEN
                  cache_table_name := join_table_name;
                  table_name := %1\$s.get_table_name(join_table_name);

                  RETURN format('LEFT JOIN \"%%s\" ON \"%%s\".\"%%s\" = \"%%s\".\"%%s\"', table_name, table_name, %1\$s.get_primary_key_name(primary_key_name), alias, %1\$s.get_cache_primary_key_name(primary_key_name));
                END IF;

                -- In case the provided table name is a principal table, we will check if there is a cache view and join that 
                cache_view_name := %1\$s.get_cache_view_name(join_table_name);
                IF NOT %1\$s.exists_view('%1\$s', cache_view_name) THEN
                  RETURN '';
                END IF;

                -- In case an record is provided we cannot use the schema
                IF alias = 'record' THEN
                  RETURN format('LEFT JOIN %1\$s.\"%%s\" ON %1\$s.\"%%s\".\"%%s\" = \"%%s\".\"%%s\"', cache_view_name, cache_view_name, %1\$s.get_cache_primary_key_name(primary_key_name), alias, %1\$s.get_primary_key_name(primary_key_name));
                END IF;

                RETURN format('LEFT JOIN %1\$s.\"%%s\" ON %1\$s.\"%%s\".\"%%s\" = %%s.\"%%s\".\"%%s\"', cache_view_name, cache_view_name, %1\$s.get_cache_primary_key_name(primary_key_name), join_schema_name, alias, %1\$s.get_primary_key_name(primary_key_name));
              END;
            $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.get_refresh_query(primary_key_name text, aggregation_function text, value_name text, value_type text, schema_name text, table_name text, key_name text, foreign_key text, conditions text DEFAULT '')
              RETURNS text
              AS $$
                DECLARE
                  refresh_query text;
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
                  CASE aggregation_function 
                    WHEN 'X' THEN
                    ELSE
                      refresh_query := format('SELECT %%s(%%s) FROM \"%%s\" %%s WHERE \"%%s\" = ''%%s'' AND (%%s)', aggregation_function, value_name, principal_table_name, %1\$s.get_join_statement(principal_schema_name, principal_table_name, principal_primary_key_name, principal_table_name), key_name, foreign_key, conditions);
                  END CASE;

                  -- Coalesce certain aggregation functions to prevent null values
                  CASE aggregation_function 
                    WHEN 'COUNT' THEN
                      refresh_query := format('SELECT COALESCE((%%s), 0)', refresh_query);
                    WHEN 'SUM' THEN
                      refresh_query := format('SELECT COALESCE((%%s), 0)', refresh_query);
                    WHEN 'JSONB_AGG' THEN
                      refresh_query := format('SELECT COALESCE((%%s), ''[]''::jsonb)', refresh_query);
                    ELSE
                  END CASE;

                  RETURN refresh_query;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.upsert_cache(
                table_name text,
                primary_key_name text,
                primary_key text,
                updates jsonb
              )
              RETURNS void
              AS $$
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.refresh_row(
                p_foreign_schema_name text,
                p_foreign_table_name text,
                p_foreign_row json
              )
              RETURNS void
              AS $$
                DECLARE
                  trigger %1\$s.triggers%%rowtype;
                  foreign_primary_key text;
                  updates jsonb DEFAULT '{}';
                  refresh_query text;
                  existing_refresh_query text;

                  schema_name text;
                  table_name text;
                  primary_key_name text;
                  foreign_schema_name text;
                  foreign_cache_table_name text;
                  foreign_cache_primary_key_name text;
                  foreign_primary_key_name text;
                  foreign_aggregation_name text;
                  aggregation_function text;
                  value_name text;
                  value_type text;
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
                    value_name := trigger.value_name;
                    value_type := trigger.value_type;
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
                        value_name, 
                        value_type, 
                        schema_name, 
                        table_name, 
                        key_name,
                        %1\$s.get_row_value(p_foreign_row, trigger.foreign_key_name), 
                        conditions
                      );

                      -- Append query if necessary
                      existing_refresh_query := updates ->> foreign_aggregation_name;
                      IF existing_refresh_query != '' THEN
                        CASE aggregation_function 
                          WHEN 'COUNT' THEN
                            refresh_query := format('((%%s) + (%%s))', existing_refresh_query, refresh_query);
                          WHEN 'SUM' THEN
                            refresh_query := format('((%%s) + (%%s))', existing_refresh_query, refresh_query);
                          WHEN 'MAX' THEN
                            refresh_query := format('GREATEST((%%s), (%%s))', existing_refresh_query, refresh_query);
                          WHEN 'MIN' THEN
                            refresh_query := format('LEAST((%%s), (%%s))', existing_refresh_query, refresh_query);
                          WHEN 'JSONB_AGG' THEN
                            refresh_query := format('((%%s) || (%%s))', existing_refresh_query, refresh_query);
                          WHEN 'COPY' THEN
                            refresh_query := format('((%%s) || (%%s))', existing_refresh_query, refresh_query);
                          ELSE
                            refresh_query := format('%%s((%%s), (%%s))', aggregation_function, existing_refresh_query, refresh_query);
                        END CASE;
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.refresh(
                foreign_schema_name text,
                foreign_table_name text,
                foreign_table_conditions text DEFAULT '1 = 1'
              )
              RETURNS void
                AS $$
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.process(
                p_foreign_schema_name text,
                p_foreign_table_name text,
                p_foreign_aggregation_name text DEFAULT '%%'
              )
              RETURNS void
                AS $$
                DECLARE
                  trigger %1\$s.triggers%%rowtype;
                  log %1\$s.logs%%rowtype;
                BEGIN
                  RAISE NOTICE '%1\$s.process: start (p_foreign_schema_name=%%, p_foreign_table_name=%%)', p_foreign_schema_name, p_foreign_table_name;

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
                      FOR UPDATE SKIP LOCKED
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
                        trigger.value_name, 
                        trigger.value_type, 
                        trigger.conditions, 
                        trigger.foreign_conditions, 
                        log.operation, 
                        log.old_value, 
                        log.old_foreign_key, 
                        log.old_relevant, 
                        log.new_value, 
                        log.new_foreign_key, 
                        log.new_relevant
                      );

                      UPDATE %1\$s.logs SET processed_at = NOW() WHERE id = log.id;
                    END LOOP;
                  END LOOP;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.update(
                schema_name text,
                table_name text,
                primary_key_name text,
                key_name text,
                foreign_schema_name text,
                foreign_table_name text,
                foreign_primary_key_name text,
                foreign_key_name text,
                foreign_aggregation_name text,
                foreign_cache_table_name text,
                foreign_cache_primary_key_name text,
                aggregation_function text,
                value_name text,
                value_type text,
                conditions text,
                foreign_conditions text,

                operation text, 
                old_value text,
                old_foreign_key text,
                old_relevant boolean,
                new_value text,
                new_foreign_key text,
                new_relevant boolean
              )
              RETURNS void
              AS $$
                DECLARE
                  changed_foreign_key boolean;
                  changed_value boolean;
                  old_query text;
                  old_update text;
                  old_refresh_query text;
                  old_condition text;
                  new_query text;
                  new_update text;
                  new_refresh_query text;
                  new_condition text;
                BEGIN
                  -- Check what has changed
                  IF new_foreign_key <> '' THEN
                    changed_foreign_key := new_foreign_key IS DISTINCT FROM old_foreign_key;
                    changed_value := new_value IS DISTINCT FROM old_value;
                  END IF;

                  RAISE NOTICE '%1\$s.update: start (schema_name=%%, table_name=%%, key_name=%%, foreign_table_name=%%, foreign_primary_key_name=%%, foreign_key_name=%%, foreign_aggregation_name=%%, foreign_cache_table_name=%%, foreign_cache_primary_key_name=%%, aggregation_function=%%, value_name=%%, value_type=%%, conditions=%%, foreign_conditions=%%, operation=%%, old_value=%%, old_foreign_key=%%, old_relevant=%%, new_value=%%, new_foreign_key=%%, new_relevant=%%, changed_foreign_key=%%, changed_value=%%)',
                    schema_name,  
                    table_name,
                    key_name,
                    foreign_table_name,
                    foreign_primary_key_name,
                    foreign_key_name,
                    foreign_aggregation_name,
                    foreign_cache_table_name,
                    foreign_cache_primary_key_name,
                    aggregation_function,
                    value_name,
                    value_type,
                    conditions,
                    foreign_conditions,

                    operation, 
                    old_value,
                    old_foreign_key,
                    old_relevant,
                    new_value,
                    new_foreign_key,
                    new_relevant,
                    changed_foreign_key,
                    changed_value
                  ;

                  -- Ensure that we have any condition
                  IF foreign_conditions = '' THEN
                    foreign_conditions := '1 = 1';
                  END IF;

                  -- Prepare conditions
                  IF foreign_table_name = foreign_cache_table_name THEN
                    old_condition := format('%%s = ''%%s'' AND ( %%s )', foreign_key_name, old_foreign_key, foreign_conditions);
                    new_condition := format('%%s = ''%%s'' AND ( %%s )', foreign_key_name, new_foreign_key, foreign_conditions);
                  ELSE 
                    old_condition := format('%%s IN (SELECT %%s FROM %%s WHERE %%s = ''%%s'' AND ( %%s ))', foreign_cache_primary_key_name, foreign_primary_key_name, foreign_table_name, foreign_key_name, old_foreign_key, foreign_conditions);
                    new_condition := format('%%s IN (SELECT %%s FROM %%s WHERE %%s = ''%%s'' AND ( %%s ))', foreign_cache_primary_key_name, foreign_primary_key_name, foreign_table_name, foreign_key_name, new_foreign_key, foreign_conditions);
                  END IF;

                  -- Prepare refresh query that can be used to get the aggregated value
                  old_refresh_query := %1\$s.get_refresh_query(primary_key_name, aggregation_function, value_name, value_type, schema_name, table_name, key_name, old_foreign_key, conditions);
                  new_refresh_query := %1\$s.get_refresh_query(primary_key_name, aggregation_function, value_name, value_type, schema_name, table_name, key_name, new_foreign_key, conditions);

                  -- Update row if
                  -- 1. Foreign row with matching conditions is deleted
                  -- 2. Foreign row was updated and conditions are not matching anymore
                  IF old_foreign_key IS NOT NULL AND (operation = 'DELETE' AND old_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = true and new_relevant = false) OR (old_relevant = true AND (changed_value = true OR changed_foreign_key = true))) THEN
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        old_query := format('UPDATE %1\$s.%%s SET %%s = %%s - 1 WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, old_condition);
                      WHEN 'SUM' THEN
                        -- NOTE: the value will be added later so we don't need to calculate the difference
                        old_query := format('UPDATE %1\$s.%%s SET %%s = %%s - %%s WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, old_value, old_condition);
                      WHEN 'MAX' THEN
                        IF old_value IS NOT NULL THEN
                          old_query := format('UPDATE %1\$s.%%s SET %%s = CASE WHEN ''%%s'' <> %%s THEN %%s ELSE (%%s) END WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, old_value, foreign_aggregation_name, foreign_aggregation_name, old_refresh_query, old_condition);
                        END IF;
                      WHEN 'MIN' THEN
                        IF old_value IS NOT NULL THEN
                          old_query := format('UPDATE %1\$s.%%s SET %%s = CASE WHEN ''%%s'' <> %%s THEN %%s ELSE (%%s) END WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, old_value, foreign_aggregation_name, foreign_aggregation_name, old_refresh_query, old_condition);
                        END IF;
                      WHEN 'JSONB_AGG' THEN
                        IF old_value IS NOT NULL THEN
                          IF value_type = 'text' THEN
                            old_update := format('%%s - ''%%s''', foreign_aggregation_name, old_value);
                          ELSIF value_type = 'numeric' THEN
                            old_update := format('array_to_json(array_remove(array(select jsonb_array_elements_text(%%s)), %%s::text)::int[])', foreign_aggregation_name, old_value);
                          ELSIF value_type = 'json' THEN
                            old_update := format('array_to_json(%1\$s.array_diff(array(select jsonb_array_elements_text(%%s)), array(select jsonb_array_elements_text(''%%s''))))', foreign_aggregation_name, old_value);
                          ELSE  
                            old_update := format('%%s - %%s', foreign_aggregation_name, old_value);
                          END IF;

                          old_query := format('UPDATE %1\$s.%%s SET %%s = %%s WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, old_update, old_condition);
                        END IF;
                      ELSE
                        old_query := format('UPDATE %1\$s.%%s SET %%s = (%%s) WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, old_refresh_query, old_condition);
                    END CASE;

                    IF old_query != '' THEN
                      RAISE NOTICE '%1\$s.update: delete or update old (old_query=%%)', old_query;
                      EXECUTE old_query;
                    END IF;
                  END IF;
              
                  -- Update row if
                  -- 1. Foreign row with matching conditions is created
                  -- 2. Foreign row was updated and conditions are now matching
                  IF new_foreign_key IS NOT NULL AND (operation = 'INSERT' AND new_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = false AND new_relevant = true) OR (new_relevant = true AND (changed_value = true OR changed_foreign_key = true))) THEN
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        new_query := format('UPDATE %1\$s.%%s SET %%s = %%s + 1 WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, new_condition);
                      WHEN 'SUM' THEN
                        -- NOTE: the value was deducted before so we don't need to calculate the difference here
                        new_query := format('UPDATE %1\$s.%%s SET %%s = %%s + %%s WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, new_condition);
                      WHEN 'MAX' THEN
                        IF new_value != '' THEN
                          new_query := format('UPDATE %1\$s.%%s SET %%s = CASE WHEN %%s IS NULL OR ''%%s'' > %%s THEN ''%%s'' ELSE (%%s) END WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, foreign_aggregation_name, new_value, foreign_aggregation_name, new_condition);
                        END IF;
                      WHEN 'MIN' THEN
                        IF new_value != '' THEN
                          new_query := format('UPDATE %1\$s.%%s SET %%s = CASE WHEN %%s IS NULL OR ''%%s'' < %%s THEN ''%%s'' ELSE (%%s) END WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, foreign_aggregation_name, new_value, foreign_aggregation_name, new_condition);
                        END IF;
                      WHEN 'JSONB_AGG' THEN
                        IF new_value != '' THEN
                          IF value_type = 'text' THEN
                            new_update := format('%%s::jsonb || ''[\"%%s\"]''::jsonb', foreign_aggregation_name, new_value);
                          ELSIF value_type = 'json' THEN
                            new_update := format('%%s::jsonb || ''%%s''::jsonb', foreign_aggregation_name, new_value);
                          ELSE  
                            new_update := format('%%s::jsonb || ''[%%s]''::jsonb', foreign_aggregation_name, new_value);
                          END IF;

                          new_query := format('UPDATE %1\$s.%%s SET %%s = %%s WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, new_update, new_condition);
                        END IF;  
                      ELSE
                        new_query := format('UPDATE %1\$s.%%s SET %%s = (%%s) WHERE %%s', foreign_cache_table_name, foreign_aggregation_name, new_refresh_query, new_condition);
                    END CASE;

                    IF new_query != '' THEN
                      RAISE NOTICE '%1\$s.update: create or update new (new_query=%%)', new_query;
                      EXECUTE new_query;
                    END IF;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.after_trigger()
              RETURNS trigger
              AS $$
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
                  value_name text;
                  value_type text;
                  key_name text;
                  conditions text;
                  foreign_conditions text;
            
                  new_value text;
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
                    value_name := trigger.value_name;
                    value_type := trigger.value_type;
                    key_name := trigger.key_name;
                    conditions := trigger.conditions;
                    foreign_conditions := trigger.foreign_conditions;
                    asynchronous := trigger.asynchronous;
                    RAISE NOTICE '%1\$s.after_trigger: trigger (schema_name=%%, table_name=%%, primary_key_name=%%, foreign_schema_name=%%, foreign_table_name=%%, foreign_cache_table_name=%%, foreign_aggregation_name=%%, foreign_key_name=%%, aggregation_function=%%, value_name=%%, key_name=%%, conditions=%%, foreign_conditions=%%, asynchronous=%%)', schema_name, table_name, primary_key_name, foreign_schema_name, foreign_table_name, foreign_cache_table_name, foreign_aggregation_name, foreign_key_name, aggregation_function, value_name, key_name, conditions, foreign_conditions, asynchronous;
                    
                    -- Reset processed time
                    processed_at := NULL;

                    -- Ensure that we have any conditions
                    IF conditions = '' THEN
                      conditions := '1 = 1';
                    END IF;

                    -- Get foreign key and value from new record
                    EXECUTE format('SELECT %%s FROM (SELECT $1.*) record %%s;', value_name, %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) USING NEW INTO new_value;
                    EXECUTE format('SELECT %%s FROM (SELECT $1.*) record %%s;', key_name, %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) USING NEW INTO new_foreign_key;
                    EXECUTE format('SELECT true FROM (SELECT $1.*) record %%s WHERE %%s;', %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record'), conditions) USING NEW INTO new_relevant;

                    -- Set new_relevant explicitly to false to allow proper checks
                    IF new_relevant IS NULL THEN
                      new_relevant := false;
                    END IF;

                    RAISE NOTICE '%1\$s.after_trigger: new (new_value=%%, new_foreign_key=%%, new_relevant=%%)', new_value, new_foreign_key, new_relevant;
              
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
                        value_name,
                        value_type,
                        conditions,
                        foreign_conditions,

                        TG_OP, 
                        null,
                        null,
                        false,
                        new_value,
                        new_foreign_key,
                        new_relevant
                      );
                      processed_at := NOW();
                    ELSE
                      RAISE NOTICE '%1\$s.after_trigger: skip update because of asynchronous mode';
                    END IF;
                    RAISE NOTICE '';

                    -- Store update in logs
                    EXECUTE format('INSERT INTO %1\$s.logs (trigger_id, operation, old_relevant, new_value, new_foreign_key, new_relevant, processed_at) VALUES($1, $2, false, $3, $4, $5, $6)') USING trigger_id, TG_OP, new_value, new_foreign_key, new_relevant, processed_at;
                  END LOOP;

                  RAISE NOTICE '-- %1\$s.after_trigger END';
              
                  IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                  END IF;

                  RETURN NEW;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.before_trigger()
              RETURNS trigger
                AS $$
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
                  value_name text;
                  value_type text;
                  key_name text;
                  conditions text;
                  foreign_conditions text;
                  foreign_key text;
                  foreign_primary_key text;
                  foreign_primary_key_name text;
                  foreign_cache_primary_key_name text;
                  foreign_cache_primary_key text;
                  primary_key text;

                  old_value text;
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
                      value_name := trigger.value_name;
                      value_type := trigger.value_type;
                      key_name := trigger.key_name;
                      conditions := trigger.conditions;
                      foreign_conditions := trigger.foreign_conditions;
                      asynchronous := trigger.asynchronous;
                      RAISE NOTICE '%1\$s.before_trigger: trigger (TG_TABLE_NAME=%%, table_name=%%, primary_key_name=%%, foreign_table_name=%%, foreign_cache_table_name=%%, foreign_aggregation_name=%%, foreign_key_name=%%, aggregation_function=%%, value_name=%%, key_name=%%, conditions=%%, foreign_conditions=%%, asynchronous=%%)', TG_TABLE_NAME, table_name, primary_key_name, foreign_table_name, foreign_cache_table_name, foreign_aggregation_name, foreign_key_name, aggregation_function, value_name, key_name, conditions, foreign_conditions, asynchronous;

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
                      EXECUTE format('SELECT %%s FROM (SELECT $1.*) record %%s;', value_name, %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) USING OLD INTO old_value;
                      EXECUTE format('SELECT %%s FROM (SELECT $1.*) record %%s;', key_name, %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record')) USING OLD INTO old_foreign_key;
                      EXECUTE format('SELECT true FROM (SELECT $1.*) record %%s WHERE %%s;', %1\$s.get_join_statement(TG_TABLE_SCHEMA, TG_TABLE_NAME, primary_key_name, 'record'), conditions) USING OLD INTO old_relevant;

                      -- Set old_relevant explicitly to false to allow proper checks
                      IF old_relevant IS NULL THEN
                        old_relevant := false;
                      END IF;

                      RAISE NOTICE '%1\$s.before_trigger: old (old_value=%%, old_foreign_key=%%, old_relevant=%%)', old_value, old_foreign_key, old_relevant;

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
                          value_name,
                          value_type,
                          conditions,
                          foreign_conditions,

                          TG_OP, 
                          old_value,
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
                      EXECUTE format('INSERT INTO %1\$s.logs (trigger_id, operation, new_relevant, old_value, old_foreign_key, old_relevant, processed_at) VALUES($1, $2, false, $3, $4, $5, $6)') USING trigger_id, TG_OP, old_value, old_foreign_key, old_relevant, processed_at;
                    END LOOP;
                  END IF;

                  RAISE NOTICE '-- %1\$s.before_trigger END';
              
                  IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                  END IF;
              
                  RETURN NEW;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.create_triggers(schema_name text, table_name text)
              RETURNS void
                AS $$
                DECLARE
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.create_views(_foreign_table_name text)
              RETURNS void
                AS $$
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
                  RAISE NOTICE '%1\$s.create_views: start (_foreign_table_name=%%)', _foreign_table_name;

                  -- Get all visible cached fields
                  FOR trigger IN
                    SELECT * FROM %1\$s.triggers
                    WHERE 
                        %1\$s.triggers.foreign_table_name = _foreign_table_name
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
                    concatenated_joins := format('%%s JOIN %1\$s.%%s ON %%s', concatenated_joins, key, value);
                  END LOOP;

                  -- Concatenate aggregation names
                  FOR key, value IN 
                    SELECT * FROM jsonb_each_text(foreign_aggregation_names)
                  LOOP
                    concatenated_foreign_aggregation_names := format('%%s, %%s.%%s', concatenated_foreign_aggregation_names, value, key);
                  END LOOP;

                  -- Create view
                  IF foreign_primary_key_name IS NOT NULL THEN
                    cache_view_name := %1\$s.get_cache_view_name(_foreign_table_name);
                    EXECUTE format('CREATE VIEW %1\$s.%%s AS SELECT %%s.%%s %%s FROM %%s %%s', cache_view_name, foreign_cache_table_name, %1\$s.get_cache_primary_key_name(foreign_cache_primary_key_name), concatenated_foreign_aggregation_names, _foreign_table_name, concatenated_joins);
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.prepare()
              RETURNS trigger
                AS $$
                DECLARE
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %1\$s.initialize()
              RETURNS trigger
                AS $$
                DECLARE
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
              $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            DO $$
              BEGIN
                IF NOT %1\$s.exists_trigger('%1\$s', 'triggers', 'hoard_before') THEN
                  CREATE TRIGGER hoard_before
                    BEFORE INSERT OR UPDATE OR DELETE ON %1\$s.triggers
                    FOR EACH ROW 
                    EXECUTE FUNCTION %1\$s.prepare();
                END IF;

                IF NOT %1\$s.exists_trigger('%1\$s', 'triggers', 'hoard_after') THEN
                  CREATE TRIGGER hoard_after
                    AFTER INSERT OR UPDATE OR DELETE ON %1\$s.triggers
                    FOR EACH ROW 
                    EXECUTE FUNCTION %1\$s.initialize();
                END IF;
              END;
            $$ LANGUAGE PLPGSQL;
          ",
          HoardSchema::$cacheSchema
        ),

        sprintf(
          "
            INSERT INTO %1\$s.triggers (
              table_name, 
              key_name,
              aggregation_function, 
              value_name,
              value_type, 
              conditions,
              foreign_table_name,
              foreign_key_name, 
              foreign_aggregation_name,
              foreign_conditions,
              foreign_primary_key_name,
              lazy,
              primary_key_name,
              foreign_cache_table_name,
              foreign_cache_primary_key_name,
              hidden,
              manual,
              schema_name,
              foreign_schema_name,
              asynchronous
            ) VALUES (
              %2\$s,
              %3\$s,
              %4\$s,
              %5\$s,
              %6\$s,
              %7\$s,
              %8\$s,
              %9\$s,
              %10\$s,
              %11\$s,
              %12\$s,
              %13\$s,
              %14\$s,
              %15\$s,
              %16\$s,
              %17\$s,
              %18\$s,
              %19\$s,
              %20\$s,
              %21\$s
            ) ON CONFLICT (id) DO UPDATE SET 
              table_name = %2\$s, 
              key_name = %3\$s,
              aggregation_function = %4\$s,
              value_name = %5\$s, 
              value_type = %6\$s, 
              conditions = %7\$s,
              foreign_table_name = %8\$s, 
              foreign_key_name = %9\$s,
              foreign_aggregation_name = %10\$s,
              foreign_conditions = %11\$s,
              foreign_primary_key_name = %12\$s,
              lazy = %13\$s,
              primary_key_name = %14\$s,
              foreign_cache_table_name = %15\$s,
              foreign_cache_primary_key_name = %16\$s,
              hidden = %17\$s,
              manual = %18\$s,
              schema_name = %19\$s,
              foreign_schema_name = %20\$s,
              asynchronous = %21\$s;
          ",
          HoardSchema::$cacheSchema,
          $this->quoteString($tableName),
          $this->quoteString($keyName),
          $this->quoteString($aggregationFunction),
          $this->quoteString($valueName),
          $this->quoteString($valueType),
          DB::getPdo()->quote($conditions),
          $this->quoteString($foreignTableName),
          $this->quoteString($foreignKeyName),
          $this->quoteString($foreignAggregationName),
          DB::getPdo()->quote($foreignConditions),
          $this->quoteString($foreignPrimaryKeyName),
          $lazy ? 'true' : 'false',
          $this->quoteString($primaryKeyName),
          $this->quoteString($foreignCacheTableName),
          $this->quoteString($foreignCachePrimaryKeyName),
          $hidden ? 'true' : 'false',
          $manual ? 'true' : 'false',
          $this->quoteString($schemaName),
          $this->quoteString($foreignSchemaName),
          $asynchronous ? 'true' : 'false',
        ),
      ]);
    });
  }
}
