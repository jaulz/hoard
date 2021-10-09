<?php

namespace Jaulz\Hoard;

use Illuminate\Support\ServiceProvider;
use Jaulz\Hoard\Commands\CacheCommand;
use Jaulz\Hoard\Commands\ClearCommand;
use Jaulz\Hoard\Commands\RefreshCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
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
    $this->publishes(
      [
        __DIR__ . '/../database/migrations/' => database_path('migrations'),
      ],
      'hoard-migrations'
    );
    
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
      string $foreignTableName,
      string $aggregationName,
      string $foreignKeyName,
      string $aggregationFunction,
      string $valueExpression,
      string $keyName = 'id',
      string $conditions = '1=1'
    ) {
      /** @var \Illuminate\Database\Schema\Blueprint $this */
      $tableName = $this->prefix . $this->table;

      return $this->addCommand(
        'hoard',
        compact(
          'aggregationName',
          'keyName',
          'foreignTableName',
          'foreignKeyName',
          'aggregationFunction',
          'valueExpression',
          'conditions',
          'tableName'
        )
      );
    });

    PostgresGrammar::macro('compileHoard', function (
      Blueprint $blueprint,
      Fluent $command
    ) {
      /** @var \Illuminate\Database\Schema\Grammars\PostgresGrammar $this */
      $aggregationName = $command->aggregationName;
      $keyName = $command->keyName;
      $foreignTableName = $command->foreignTableName;
      $foreignKeyName = $command->foreignKeyName;
      $aggregationFunction = Str::upper($command->aggregationFunction);
      $valueExpression = $command->valueExpression;
      $conditions = $command->conditions;
      $tableName = $command->tableName;
      $functionName = sprintf(
        'hoard_%s_in_%s_table',
        $aggregationName,
        $foreignTableName
      );
      $triggerName = $functionName . '_trigger';

      return [
        sprintf(
          "DROP TRIGGER IF EXISTS %s ON %s;",
          $triggerName,
          $tableName
        ),

        sprintf(
          "DROP FUNCTION IF EXISTS %s;",
          $functionName
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %s(operation text, old_record %2\$s, new_record %2\$s)
              RETURNS %2\$s
                AS $$
                DECLARE
                  table_name text DEFAULT '%s';
                  foreign_table_name text DEFAULT '%s';
                  aggregation_name text DEFAULT '%s';
                  foreign_key_name text DEFAULT '%s';
                  aggregation_function text DEFAULT '%s';
                  value_expression text DEFAULT '%s';
                  key_name text DEFAULT '%s';
                  conditions text DEFAULT '%s';
            
                  old_value text;
                  old_foreign_key text;
                  old_condition text;
                  old_relevant boolean;
                  new_value text;
                  new_foreign_key text;
                  new_condition text;
                  new_relevant boolean;
                  changed_foreign_key boolean;
                BEGIN
                  RAISE NOTICE 'hoard: start (operation=%%, old_record=%%, new_record=%%, aggregation_name=%%, key_name=%%, table_name=%%, foreign_table_name=%%, foreign_key_name=%%, aggregation_function=%%, value_expression=%%, conditions=%%)', operation, old_record::text, new_record::text, aggregation_name, key_name, table_name, foreign_table_name, foreign_key_name, aggregation_function, value_expression, conditions;
              
                  -- Get foreign key and value from old record
                  IF old_record IS NULL THEN
    
                  ELSE
                    EXECUTE format('SELECT ($1).%%s;', value_expression) INTO old_value USING old_record;
                    EXECUTE format('SELECT ($1).%%s;', foreign_key_name) INTO old_foreign_key USING old_record;
                    EXECUTE format('SELECT true FROM (SELECT $1.*) record WHERE %%s;', conditions) USING old_record INTO old_relevant;
                    old_condition := format('%%s = ''%%s''', key_name, old_foreign_key);
                  END IF;
              
                  -- Get foreign key and value from new record
                  IF new_record IS NULL THEN
                  ELSE
                    EXECUTE format('SELECT ($1).%%s;', value_expression) INTO new_value USING new_record;
                    EXECUTE format('SELECT ($1).%%s;', foreign_key_name) INTO new_foreign_key USING new_record;
                    EXECUTE format('SELECT true FROM (SELECT $1.*) record WHERE %%s;', conditions) USING new_record INTO new_relevant;
                    new_condition := format('%%s = ''%%s''', key_name, new_foreign_key);
                    changed_foreign_key := new_foreign_key != old_foreign_key;
                  END IF;
              
                  -- Debug
                  RAISE NOTICE 'hoard: before updates (old_condition=%%, old_relevant=%%, new_condition=%%, new_relevant=%%, changed_foreign_key=%%)', old_condition, old_relevant, new_condition, new_relevant, changed_foreign_key;
              
                  -- Update row if
                  -- 1. Foreign row with matching conditions is deleted
                  -- 2. Foreign row was updated and conditions are not matching anymore
                  IF (operation = 'DELETE' AND old_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = true and new_relevant = false) OR (old_relevant = true and changed_foreign_key = true)) THEN
                    RAISE NOTICE 'hoard: delete or update old';
                
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        EXECUTE format('UPDATE %%s SET %%s = %%s -1 WHERE %%s', foreign_table_name, aggregation_name, aggregation_name, old_condition);
                      WHEN 'SUM' THEN
                        EXECUTE format('UPDATE %%s SET %%s = %%s - %%s WHERE %%s', foreign_table_name, aggregation_name, aggregation_name, value_expression, old_condition);
                      WHEN 'MAX' THEN
                      -- EXECUTE format('UPDATE %%s SET %%s = %%s -1 WHERE %%s', foreign_table_name, summaryName, summaryName, oldCondition);
                      -- UPDATE {tableName} SET {summaryName} = IF(valueName = old.{valueName}, SELECT max(valueName) from {table}, valueName) WHERE {newCondition};
                    END CASE;
                  END IF;
              
                  -- Update row if
                  -- 1. Foreign row with matching conditions is created
                  -- 2. Foreign row was updated and conditions are now matching
                  IF (operation = 'INSERT' AND new_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = false AND new_relevant = true) OR (new_relevant = true AND changed_foreign_key = true)) THEN
                    RAISE NOTICE 'hoard: create or update new';
                
                    CASE aggregation_function 
                      WHEN 'COUNT' THEN
                        EXECUTE format('UPDATE %%s SET %%s = %%s +1 WHERE %%s', foreign_table_name, aggregation_name, aggregation_name, new_condition);
                      WHEN 'SUM' THEN
                        EXECUTE format('UPDATE %%s SET %%s = %%s + %%s WHERE %%s', foreign_table_name, aggregation_name, aggregation_name, value_expression, new_condition);
                      WHEN 'MAX' THEN
                        -- UPDATE {tableName} SET {summaryName} = IF(valueName < new.{valueName}, new.{valueName}, valueName) WHERE {newCondition};
                    END CASE;
                  END IF;
              
                  RETURN new_record;
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          $functionName,
          $tableName,
          $foreignTableName,
          $aggregationName,
          $foreignKeyName,
          $aggregationFunction,
          $valueExpression,
          $keyName,
          $conditions
        ),

        sprintf(
          "
            CREATE OR REPLACE FUNCTION %s()
              RETURNS trigger
              AS $$
                BEGIN
                  RETURN %s(TG_OP, OLD, NEW);
                END;
              $$ LANGUAGE PLPGSQL;
          ",
          $triggerName,
          $functionName,
          $tableName,
          $foreignTableName,
          $aggregationName,
          $foreignKeyName,
          $aggregationFunction,
          $valueExpression,
          $keyName,
          $conditions
        ),

        sprintf(
          "
            CREATE TRIGGER %s
              AFTER INSERT OR UPDATE OR DELETE ON %s
              FOR EACH ROW 
              EXECUTE FUNCTION %s();
          ",
          $triggerName,
          $tableName,
          $triggerName
        ),
      ];
    });
  }
}
