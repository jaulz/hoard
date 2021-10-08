<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateHoardCheckConditionsFunction extends Migration
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
        CREATE OR REPLACE FUNCTION hoard_check_conditions(
            record record, 
            conditions text DEFAULT '1=1'::text
          )
            RETURNS boolean AS $$
            DECLARE
              filtered_record record;
              -- condition text[];
              -- key text;
              -- comparator text;
              -- value text;
              -- actual_value text;
            BEGIN
              -- FOREACH condition SLICE 1 IN ARRAY conditions LOOP
              --   key := condition[1];
              --   comparator := condition[2];
              --   value := condition[3];
              --
              --   EXECUTE format('SELECT ($1).%s;', key) INTO actual_value USING record;
              --
              --   RAISE NOTICE 'condition % % % %', condition, comparator, value, actual_value;
              --   CASE comparator
              --     WHEN '=' THEN
              --	     RAISE NOTICE '= % %', value, actual_value;
              --       IF value != actual_value THEN
              --         RETURN false;
              --       END IF;
              --     ELSE
              --       RETURN false;
              --   END CASE;
              -- END LOOP;
              --
              -- RETURN true;
          
              -- EXECUTE 'CREATE TEMPORARY TABLE hoard_check ON COMMIT DROP AS SELECT $1.*' USING record;
          
              EXECUTE format('SELECT 1 FROM (SELECT $1.*) record WHERE %s;', conditions) USING record INTO filtered_record;
          
              RETURN filtered_record IS NOT NULL;
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
    DB::unprepared(DB::raw('DROP FUNCTION hoard_check_conditions;'));
  }
}
