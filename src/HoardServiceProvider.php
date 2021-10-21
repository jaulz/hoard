<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\ServiceProvider;
use Jaulz\Hoard\Commands\CacheCommand;
use Jaulz\Hoard\Commands\ClearCommand;
use Jaulz\Hoard\Commands\RefreshCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;

class HoardServiceProvider extends ServiceProvider
{
  /**
   * Boots the service provider.
   */
  public function boot()
  {
    $this->commands([
      ClearCommand::class,
      CacheCommand::class,
      RefreshCommand::class,
    ]);

    $this->enhanceBlueprint();
  }

  public function enhanceBlueprint()
  {
    Blueprint::macro('hoard', function (
      string $keyName,
      string $foreignTableName,
      string $foreignKeyName,
      string $foreignAggregationName,
      string $aggregationFunction,
      string $valueName,
      string|array $conditions = '',
      string|array $foreignConditions = ''
    ) {
      /** @var \Illuminate\Database\Schema\Blueprint $this */
      $tableName = $this->prefix . $this->table;
      $conditions = is_string($conditions) ? [$conditions] : $conditions; 
      $foreignConditions = is_string($foreignConditions) ? [$foreignConditions] : $foreignConditions; 

      $command = $this->addCommand(
        'hoard',
        compact(
          'foreignAggregationName',
          'keyName',
          'foreignTableName',
          'foreignKeyName',
          'aggregationFunction',
          'valueName',
          'conditions',
          'foreignConditions',
          'tableName'
        )
      );

      return new HoardDefinition($this, $command);
    });

    PostgresGrammar::macro('compileHoard', function (
      Blueprint $blueprint,
      Fluent $command
    ) {
      /** @var \Illuminate\Database\Schema\Grammars\PostgresGrammar $this */
       $prepareConditions = function (array $conditions) {
        return collect($conditions)->mapWithKeys(function ($condition, $key) {
          $operator = '=';
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
              $operator = 'IS';
              $value = 'NULL';
            } else {
              $value = DB::getPdo()->quote($value);
            }
  
            if (!$value) {
              return [];
            }
  
            return  [$key => $key . ' ' . $operator . ' ' . $value];
          })->values()->filter()->implode(' AND ');
      };
      $foreignAggregationName = $command->foreignAggregationName;
      $keyName = $command->keyName;
      $foreignTableName = $command->foreignTableName;
      $foreignKeyName = $command->foreignKeyName;
      $valueType = $command->valueType ?? 'text';
      $aggregationFunction = Str::upper($command->aggregationFunction);
      $valueName = $command->valueName;
      $foreignConditions = $prepareConditions($command->foreignConditions);
      $conditions = $prepareConditions($command->conditions);
      $tableName = $command->tableName;
      $refreshKeyName = $command->refreshKeyName;

      return array_filter([
        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_exists_trigger(table_name text, _trigger_name text)
              RETURNS bool
              AS $$
                BEGIN
                  IF EXISTS(SELECT * FROM information_schema.triggers WHERE triggers.event_object_table = table_name AND trigger_name = _trigger_name) THEN
                    RETURN true;
                  ELSE
                    RETURN false;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          "
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_set_row_value(record anyelement, key text, value text)
              RETURNS anyelement
              AS $$
                SELECT json_populate_record(record, json_build_object(key, value));
              $$ LANGUAGE SQL;
          "
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_set_row_value(record anyelement, key text, value jsonb)
              RETURNS anyelement
              AS $$
                SELECT json_populate_record(record, json_build_object(key, value), true);
              $$ LANGUAGE SQL;
          "
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_get_row_value(record anyelement, key text)
              RETURNS text
              AS $$
                BEGIN
                  RETURN row_to_json(record) ->> key;
                END;
              $$ LANGUAGE PLPGSQL;
          "
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_get_refresh_query(aggregation_function text, value_name text, value_type text, table_name text, key_name text, foreign_key text, conditions text DEFAULT '')
              RETURNS text
              AS $$
                DECLARE
                  refresh_query text;
                BEGIN
                  -- Ensure that we have any conditions
                  IF conditions = '' THEN
                    conditions := '1 = 1';
                  END IF;

                  -- Prepare refresh query
                  refresh_query := format('SELECT %%s(%%s) FROM %%s WHERE %%s = ''%%s'' AND (%%s)', aggregation_function, value_name, table_name, key_name, foreign_key, conditions);

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
          "
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_refresh(
                foreign_table_name text,
                foreign_key_name text,
                foreign_row record
              )
              RETURNS void
              AS $$
                DECLARE
                  trigger hoard_triggers%%rowtype;
                  foreign_key text;
                  updates jsonb DEFAULT '{}';
                  refresh_query text;
                  existing_refresh_query text;
                  concatenated_updates text;
                  update_query text;

                  table_name text;
                  foreign_aggregation_name text;
                  aggregation_function text;
                  value_name text;
                  value_type text;
                  key_name text;
                  conditions text;
                  foreign_conditions text;
                BEGIN
                  RAISE NOTICE 'hoard_refresh: start (foreign_table_name=%%, foreign_row=%%)', foreign_table_name, foreign_row;

                  -- Get key
                  foreign_key := hoard_get_row_value(foreign_row, foreign_key_name);

                  -- Collect all updates in a JSON map
                  FOR trigger IN
                    EXECUTE format('SELECT * FROM hoard_triggers WHERE hoard_triggers.foreign_table_name = ''%%s''', foreign_table_name)
                  LOOP
                    table_name := trigger.table_name;
                    foreign_aggregation_name := trigger.foreign_aggregation_name;
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

                    -- Prepare refresh query
                    refresh_query := hoard_get_refresh_query(aggregation_function, value_name, value_type, table_name, key_name, hoard_get_row_value(foreign_row, trigger.foreign_key_name), conditions);

                    -- Append query if necessary
                    existing_refresh_query := updates ->> foreign_aggregation_name;
                    IF existing_refresh_query != '' THEN
                      CASE aggregation_function 
                        WHEN 'COUNT' THEN
                          refresh_query := format('SUM(%%s, %%s)', existing_refresh_query, refresh_query);
                        WHEN 'SUM' THEN
                          refresh_query := format('SUM(%%s, %%s)', existing_refresh_query, refresh_query);
                        WHEN 'MAX' THEN
                          refresh_query := format('GREATEST(%%s, %%s)', existing_refresh_query, refresh_query);
                        WHEN 'MIN' THEN
                          refresh_query := format('LEAST(%%s, %%s)', existing_refresh_query, refresh_query);
                        WHEN 'JSONB_AGG' THEN
                          refresh_query := format('((%%s) || (%%s))', existing_refresh_query, refresh_query);
                        ELSE
                          refresh_query := format('%%s(%%s, %%s)', aggregation_function, existing_refresh_query, refresh_query);
                      END CASE;
                    END IF;

                    -- Set new refresh query in updates map
                    updates :=  updates || jsonb_build_object(foreign_aggregation_name, refresh_query);
                  END LOOP;

                  -- Concatenate update
                  FOR foreign_aggregation_name, refresh_query IN 
                    SELECT * FROM jsonb_each_text(updates)
                  LOOP
                      IF concatenated_updates IS NULL THEN
                        concatenated_updates := format('%%s = (%%s)', foreign_aggregation_name, refresh_query);
                      ELSE
                        concatenated_updates := format('%%s, %%s = (%%s)', concatenated_updates, foreign_aggregation_name, refresh_query);
                      END IF;
                  END LOOP;
                  
                  -- Run update if required
                  IF concatenated_updates IS NOT NULL THEN
                    update_query := format('UPDATE %%s SET %%s WHERE %%s = %%s', foreign_table_name, concatenated_updates, foreign_key_name, foreign_key);
                    RAISE NOTICE 'hoard_refresh: update (update_query=%%)', update_query;
                    EXECUTE update_query;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_refresh_all(
                foreign_table_name text,
                foreign_key_name text,
                foreign_table_conditions text DEFAULT '1 = 1'
              )
              RETURNS void
                AS $$
                DECLARE
                  foreign_row record;
                BEGIN
                  RAISE NOTICE 'hoard_refresh_all: start (foreign_table_name=%%, foreign_key_name=%%, foreign_table_conditions=%%)', foreign_table_name, foreign_key_name, foreign_table_conditions;

                  -- Ensure that we have any conditions
                  IF foreign_table_conditions = '' THEN
                    foreign_table_conditions := '1 = 1';
                  END IF;

                  -- Run updates
                  FOR foreign_row IN
                    EXECUTE format('SELECT * FROM %%s WHERE %%s', foreign_table_name, foreign_table_conditions)
                  LOOP
                    PERFORM hoard_refresh(foreign_table_name, foreign_key_name, foreign_row);
                  END LOOP;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_update(
                table_name text,
                key_name text,
                foreign_table_name text,
                foreign_key_name text,
                foreign_aggregation_name text,
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

                  RAISE NOTICE 'hoard_update: start (table_name=%%, key_name=%%, foreign_table_name=%%, foreign_key_name=%%, foreign_aggregation_name=%%, aggregation_function=%%, value_name=%%, value_type=%%, conditions=%%, foreign_conditions=%%, operation=%%, old_value=%%, old_foreign_key=%%, old_relevant=%%, new_value=%%, new_foreign_key=%%, new_relevant=%%, changed_foreign_key=%%, changed_value=%%)',
                    table_name,
                    key_name,
                    foreign_table_name,
                    foreign_key_name,
                    foreign_aggregation_name,
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

                  -- Prepare conditions
                  old_condition := format('%%s = ''%%s'' AND ( %%s )', foreign_key_name, old_foreign_key, foreign_conditions);
                  new_condition := format('%%s = ''%%s'' AND ( %%s )', foreign_key_name, new_foreign_key, foreign_conditions);

                  -- Prepare refresh query that can be used to get the aggregated value
                  old_refresh_query := hoard_get_refresh_query(aggregation_function, value_name, value_type, table_name, key_name, old_foreign_key, conditions);
                  new_refresh_query := hoard_get_refresh_query(aggregation_function, value_name, value_type, table_name, key_name, new_foreign_key, conditions);

                  -- Update row if
                  -- 1. Foreign row with matching conditions is deleted
                  -- 2. Foreign row was updated and conditions are not matching anymore
                  IF (operation = 'DELETE' AND old_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = true and new_relevant = false) OR (old_relevant = true AND (changed_value = true OR changed_foreign_key = true))) THEN
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        old_query := format('UPDATE %%s SET %%s = %%s - 1 WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, old_condition);
                      WHEN 'SUM' THEN
                        old_query := format('UPDATE %%s SET %%s = %%s - %%s WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, old_value, old_condition);
                      WHEN 'MAX' THEN
                        IF old_value != '' THEN
                          old_query := format('UPDATE %%s SET %%s = CASE WHEN ''%%s'' <> %%s THEN %%s ELSE (%%s) END WHERE %%s', foreign_table_name, foreign_aggregation_name, old_value, foreign_aggregation_name, foreign_aggregation_name, old_refresh_query, old_condition);
                        END IF;
                      WHEN 'MIN' THEN
                        IF old_value != '' THEN
                          old_query := format('UPDATE %%s SET %%s = CASE WHEN ''%%s'' <> %%s THEN %%s ELSE (%%s) END WHERE %%s', foreign_table_name, foreign_aggregation_name, old_value, foreign_aggregation_name, foreign_aggregation_name, old_refresh_query, old_condition);
                        END IF;
                      WHEN 'JSONB_AGG' THEN
                        IF old_value != '' THEN
                          IF value_type = 'text' THEN
                            old_update := format('%%s - ''%%s''', foreign_aggregation_name, old_value);
                          ELSIF value_type = 'numeric' THEN
                            old_update := format('array_to_json(array_remove(array(select jsonb_array_elements_text(%%s)), %%s::text)::int[])', foreign_aggregation_name, old_value);
                          ELSE  
                            old_update := format('%%s - %%s', foreign_aggregation_name, old_value);
                          END IF;

                          old_query := format('UPDATE %%s SET %%s = %%s WHERE %%s', foreign_table_name, foreign_aggregation_name, old_update, old_condition);
                        END IF;
                      ELSE
                        old_query := format('UPDATE %%s SET %%s = (%%s) WHERE %%s', foreign_table_name, foreign_aggregation_name, old_refresh_query, old_condition);
                    END CASE;

                    IF old_query != '' THEN
                      RAISE NOTICE 'hoard_update: delete or update old (old_query=%%)', old_query;
                      EXECUTE old_query;
                    END IF;
                  END IF;
              
                  -- Update row if
                  -- 1. Foreign row with matching conditions is created
                  -- 2. Foreign row was updated and conditions are now matching
                  IF (operation = 'INSERT' AND new_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = false AND new_relevant = true) OR (new_relevant = true AND (changed_value = true OR changed_foreign_key = true))) THEN
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        new_query := format('UPDATE %%s SET %%s = %%s + 1 WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, new_condition);
                      WHEN 'SUM' THEN
                        new_query := format('UPDATE %%s SET %%s = %%s + %%s WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, new_condition);
                      WHEN 'MAX' THEN
                        IF new_value != '' THEN
                          new_query := format('UPDATE %%s SET %%s = CASE WHEN %%s IS NULL OR ''%%s'' > %%s THEN ''%%s'' ELSE (%%s) END WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, foreign_aggregation_name, new_value, foreign_aggregation_name, new_condition);
                        END IF;
                      WHEN 'MIN' THEN
                        IF new_value != '' THEN
                          new_query := format('UPDATE %%s SET %%s = CASE WHEN %%s IS NULL OR ''%%s'' < %%s THEN ''%%s'' ELSE (%%s) END WHERE %%s', foreign_table_name, foreign_aggregation_name, foreign_aggregation_name, new_value, foreign_aggregation_name, new_value, foreign_aggregation_name, new_condition);
                        END IF;
                      WHEN 'JSONB_AGG' THEN
                        IF new_value != '' THEN
                          IF value_type = 'text' THEN
                            new_update := format('%%s::jsonb || ''[\"%%s\"]''::jsonb', foreign_aggregation_name, new_value);
                          ELSE  
                            new_update := format('%%s::jsonb || ''[%%s]''::jsonb', foreign_aggregation_name, new_value);
                          END IF;

                          new_query := format('UPDATE %%s SET %%s = %%s WHERE %%s', foreign_table_name, foreign_aggregation_name, new_update, new_condition);
                        END IF;  
                      ELSE
                        new_query := format('UPDATE %%s SET %%s = (%%s) WHERE %%s', foreign_table_name, foreign_aggregation_name, new_refresh_query, new_condition);
                    END CASE;

                    IF new_query != '' THEN
                      RAISE NOTICE 'hoard_update: create or update new (new_query=%%)', new_query;
                      EXECUTE new_query;
                    END IF;
                  END IF;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_after_trigger()
              RETURNS trigger
              AS $$
                DECLARE
                  trigger hoard_triggers%%rowtype;

                  table_name text;
                  foreign_table_name text;
                  foreign_aggregation_name text;
                  foreign_key_name text;
                  aggregation_function text;
                  value_name text;
                  value_type text;
                  key_name text;
                  conditions text;
                  foreign_conditions text;
            
                  old_value text;
                  old_foreign_key text;
                  old_condition text;
                  old_relevant boolean DEFAULT false;
                  new_value text;
                  new_foreign_key text;
                  new_condition text;
                  new_relevant boolean DEFAULT false;
                  changed_foreign_key boolean;
                  changed_value boolean;
                BEGIN
                  RAISE NOTICE 'hoard_after_trigger: start (TG_OP=%%, TG_TABLE_NAME=%%, OLD=%%, NEW=%%)', TG_OP, TG_TABLE_NAME, OLD::text, NEW::text;

                  -- Get all triggers
                  FOR trigger IN
                    SELECT * FROM hoard_triggers WHERE hoard_triggers.table_name = TG_TABLE_NAME
                  LOOP
                    table_name := trigger.table_name;
                    foreign_table_name := trigger.foreign_table_name;
                    foreign_aggregation_name := trigger.foreign_aggregation_name;
                    foreign_key_name := trigger.foreign_key_name;
                    aggregation_function := trigger.aggregation_function;
                    value_name := trigger.value_name;
                    value_type := trigger.value_type;
                    key_name := trigger.key_name;
                    conditions := trigger.conditions;
                    foreign_conditions := trigger.foreign_conditions;
                    RAISE NOTICE 'hoard_after_trigger: trigger (foreign_table_name=%%, foreign_aggregation_name=%%, foreign_key_name=%%, aggregation_function=%%, value_name=%%, key_name=%%, conditions=%%, foreign_conditions=%%)', foreign_table_name, foreign_aggregation_name, foreign_key_name, aggregation_function, value_name, key_name, conditions, foreign_conditions;

                    -- Ensure that we have any conditions
                    IF conditions = '' THEN
                      conditions := '1 = 1';
                    END IF;
                    IF foreign_conditions = '' THEN
                      foreign_conditions := '1 = 1';
                    END IF;

                    -- Get foreign key and value from old record
                    IF OLD IS NULL THEN
                      -- Nothing to do but for some reason IS NOT NULL did not work
                    ELSE
                      old_value := hoard_get_row_value(OLD, value_name);
                      old_foreign_key := hoard_get_row_value(OLD, key_name);
                      -- EXECUTE format('SELECT ($1).%%s;', value_name) INTO old_value USING OLD;
                      -- EXECUTE format('SELECT ($1).%%s;', key_name) INTO old_foreign_key USING OLD;
                      EXECUTE format('SELECT true FROM (SELECT $1.*) record WHERE %%s;', conditions) USING OLD INTO old_relevant;

                      -- Set old_relevant explicitly to false to allow proper checks
                      IF old_relevant IS NULL THEN
                        old_relevant := false;
                      END IF;

                      RAISE NOTICE 'hoard_after_trigger: old (old_value=%%, old_foreign_key=%%, old_relevant=%%)', old_value, old_foreign_key, old_relevant;
                    END IF;
                
                    -- Get foreign key and value from new record
                    IF NEW IS NULL THEN
                      -- Nothing to do but for some reason IS NOT NULL did not work
                    ELSE
                      new_value := hoard_get_row_value(NEW, value_name);
                      new_foreign_key := hoard_get_row_value(NEW, key_name);
                      -- EXECUTE format('SELECT ($1).%%s;', value_name) INTO new_value USING NEW;
                      -- EXECUTE format('SELECT ($1).%%s;', key_name) INTO new_foreign_key USING NEW;
                      EXECUTE format('SELECT true FROM (SELECT $1.*) record WHERE %%s;', conditions) USING NEW INTO new_relevant;

                      -- Set new_relevant explicitly to false to allow proper checks
                      IF new_relevant IS NULL THEN
                        new_relevant := false;
                      END IF;

                      RAISE NOTICE 'hoard_after_trigger: new (new_value=%%, new_foreign_key=%%, new_relevant=%%, changed_value=%%, changed_foreign_key=%%)', new_value, new_foreign_key, new_relevant, changed_value, changed_foreign_key;
                    END IF;
                
                    -- Run update
                    PERFORM hoard_update(
                      table_name,
                      key_name,
                      foreign_table_name,
                      foreign_key_name,
                      foreign_aggregation_name,
                      aggregation_function,
                      value_name,
                      value_type,
                      conditions,
                      foreign_conditions,

                      TG_OP, 
                      old_value,
                      old_foreign_key,
                      old_relevant,
                      new_value,
                      new_foreign_key,
                      new_relevant
                    );
                  END LOOP;
              
                  RETURN NEW;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION hoard_before_trigger()
              RETURNS trigger
                AS $$
                DECLARE
                  trigger hoard_triggers%%rowtype;

                  table_name text;
                  foreign_table_name text;
                  foreign_aggregation_name text;
                  foreign_key_name text;
                  aggregation_function text;
                  value_name text;
                  value_type text;
                  key_name text;
                  conditions text;
            
                  refresh_query text;
                  value text;
                  foreign_key text;
                BEGIN
                  RAISE NOTICE 'hoard_before_trigger: start (TG_OP=%%, TG_TABLE_NAME=%%, OLD=%%, NEW=%%)', TG_OP, TG_TABLE_NAME, OLD::text, NEW::text;

                  -- Nothing to do on DELETE
                  IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                  END IF;

                  -- Nothing to do on UPDATE
                  IF TG_OP = 'UPDATE' THEN
                  END IF;

                  -- Refresh on INSERT
                  IF TG_OP = 'INSERT' THEN
                    -- Get all foreign triggers
                    FOR trigger IN
                      SELECT * FROM hoard_triggers WHERE hoard_triggers.foreign_table_name = TG_TABLE_NAME
                    LOOP
                      table_name := trigger.table_name;
                      foreign_table_name := trigger.foreign_table_name;
                      foreign_aggregation_name := trigger.foreign_aggregation_name;
                      foreign_key_name := trigger.foreign_key_name;
                      aggregation_function := trigger.aggregation_function;
                      value_name := trigger.value_name;
                      value_type := trigger.value_type;
                      key_name := trigger.key_name;
                      conditions := trigger.conditions;

                      -- Get key of new record
                      foreign_key := hoard_get_row_value(NEW, foreign_key_name);

                      -- Get actual value for aggregation column
                      EXECUTE hoard_get_refresh_query(aggregation_function, value_name, value_type, table_name, key_name, foreign_key, conditions) INTO value;

                      -- Assign value to new record
                      IF aggregation_function = 'JSONB_AGG' THEN
                        NEW := hoard_set_row_value(NEW, foreign_aggregation_name, value::jsonb);
                      ELSE
                        NEW := hoard_set_row_value(NEW, foreign_aggregation_name, value);
                      END IF;
                    END LOOP;
                  END IF;
              
                  RETURN NEW;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
        ),

        sprintf(
          "
            DO $$
              BEGIN
                IF NOT hoard_exists_trigger(%s, 'hoard_before_trigger') THEN
                  CREATE TRIGGER hoard_before_trigger
                    BEFORE INSERT OR UPDATE OR DELETE ON %s
                    FOR EACH ROW 
                    EXECUTE FUNCTION hoard_before_trigger();
                END IF;
              END;
            $$ LANGUAGE PLPGSQL;
          ",
          $this->quoteString($tableName),
          $tableName
        ),

        sprintf(
          "
            DO $$
              BEGIN
                IF NOT hoard_exists_trigger(%s, 'hoard_after_trigger') THEN
                  CREATE TRIGGER hoard_after_trigger
                    AFTER INSERT OR UPDATE OR DELETE ON %s
                    FOR EACH ROW 
                    EXECUTE FUNCTION hoard_after_trigger();
                END IF;
              END;
            $$ LANGUAGE PLPGSQL;
          ",
          $this->quoteString($tableName),
          $tableName
        ),

        sprintf(
          "
            INSERT INTO hoard_triggers (
              table_name, 
              key_name,
              aggregation_function, 
              value_name,
              value_type, 
              conditions,
              foreign_table_name, 
              foreign_key_name, 
              foreign_aggregation_name,
              foreign_conditions
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
              %11\$s
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
              foreign_conditions = %11\$s;
          ",
          '',
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
        ),

        $refreshKeyName ? sprintf(
          "
            DO $$
              BEGIN
                PERFORM hoard_refresh_all(%s, %s);
              END;
            $$ LANGUAGE PLPGSQL;
          ",
          $this->quoteString($tableName),
          $this->quoteString($refreshKeyName)
        ) : null,
      ]);
    });
  }
}
