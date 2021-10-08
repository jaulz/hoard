<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateHoardUpdateFunction extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    DB::unprepared(
      DB::raw("
        CREATE OR REPLACE FUNCTION hoard_update(
            operation text, 
            old_record record, 
            summary_name text, 
            key_name text, 
            table_name text, 
            foreign_table_name text, 
            foreign_key_name text, 
            aggregation_name text, 
            value_name text, 
            conditions text DEFAULT '1=1'::text, 
            new_record record DEFAULT NULL::record
          )
            RETURNS record AS $$
            DECLARE
              condition text;
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
              -- NOTE: variable names are matching the PHP function call. Hence, tableName is actually the name of the foreign table (e.g. discussions) and foreignTableName is the name of the table of the affected row (e.g. comments)
              RAISE NOTICE 'hoard_update: start (operation=%, old_record=%, summary_name=%, key_name=%, table_name=%, foreign_table_name=%, foreign_key_name=%, aggregation_name=%, value_name=%, conditions=%, new_record=%)', operation, old_record, summary_name, key_name, table_name, foreign_table_name, foreign_key_name, aggregation_name, value_name, conditions, new_record;
          
              -- Get foreign key and value from old record
              EXECUTE format('SELECT ($1).%s;', value_name) INTO old_value USING old_record;
              EXECUTE format('SELECT ($1).%s;', foreign_key_name) INTO old_foreign_key USING old_record;
              old_condition := format('%s = ''%s''', key_name, old_foreign_key);
          
              -- Get foreign key and value from new record
              IF new_record IS NULL THEN
                -- new_condition := old_condition;
              ELSE
                EXECUTE format('SELECT ($1).%s;', value_name) INTO new_value USING new_record;
                EXECUTE format('SELECT ($1).%s;', foreign_key_name) INTO new_foreign_key USING new_record;
                new_condition := format('%s = ''%s''', key_name, new_foreign_key);
                changed_foreign_key := new_foreign_key != old_foreign_key;
              END IF;
          
              -- Check if the old and the new record are matching the conditions
              old_relevant := hoard_check_conditions(old_record, conditions);
              new_relevant := hoard_check_conditions(new_record, conditions);
          
              -- Debug
              RAISE NOTICE 'hoard_update: before updates (old_condition=%, old_relevant=%, new_condition=%, new_relevant=%, changed_foreign_key=%)', old_condition, old_relevant, new_condition, new_relevant, changed_foreign_key;
          
              -- Update row if
              -- 1. Foreign row with matching conditions is deleted
              -- 2. Foreign row was updated and conditions are not matching anymore
              IF (operation = 'DELETE' AND old_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = true and new_relevant = false) OR (old_relevant = true and changed_foreign_key = true)) THEN
                RAISE NOTICE 'hoard_update: delete or update old';
            
                CASE aggregation_name 
                  WHEN 'COUNT' THEN
                    EXECUTE format('UPDATE %s SET %s = %s -1 WHERE %s', table_name, summary_name, summary_name, old_condition);
                  WHEN 'SUM' THEN
                    EXECUTE format('UPDATE %s SET %s = %s - %s WHERE %s', table_name, summary_name, summary_name, value_name, old_condition);
                  WHEN 'MAX' THEN
                  -- EXECUTE format('UPDATE %s SET %s = %s -1 WHERE %s', tableName, summaryName, summaryName, oldCondition);
                  -- UPDATE {tableName} SET {summaryName} = IF(valueName = old.{valueName}, SELECT max(valueName) from {table}, valueName) WHERE {newCondition};
                END CASE;
              END IF;
          
              -- Update row if
              -- 1. Foreign row with matching conditions is created
              -- 2. Foreign row was updated and conditions are now matching
              IF (operation = 'INSERT' AND new_relevant = true) OR (operation = 'UPDATE' AND (old_relevant = false AND new_relevant = true) OR (new_relevant = true AND changed_foreign_key = true)) THEN
                RAISE NOTICE 'hoard_update: create or update new';
            
                CASE aggregation_name 
                  WHEN 'COUNT' THEN
                    EXECUTE format('UPDATE %s SET %s = %s +1 WHERE %s', table_name, summary_name, summary_name, new_condition);
                  WHEN 'SUM' THEN
                    EXECUTE format('UPDATE %s SET %s = %s + %s WHERE %s', table_name, summary_name, summary_name, value_name, new_condition);
                  WHEN 'MAX' THEN
                    -- UPDATE {tableName} SET {summaryName} = IF(valueName < new.{valueName}, new.{valueName}, valueName) WHERE {newCondition};
                END CASE;
              END IF;
          
              RETURN new_record;
            END;
          $$ LANGUAGE PLPGSQL;
      ")
    );
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    DB::unprepared(DB::raw('DROP FUNCTION hoard_update;'));
  }
}
