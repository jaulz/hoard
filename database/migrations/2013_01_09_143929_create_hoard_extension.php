<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jaulz\Hoard\HoardSchema;

return new class extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    DB::statement('CREATE SCHEMA IF NOT EXISTS ' . HoardSchema::$cacheSchema);

    // Create functions that do not require the tables to exist
    HoardSchema::createGenericHelperFunctions();
    HoardSchema::createSpecificHelperFunctions();
    HoardSchema::createAggregationFunctions();
    HoardSchema::createUpdateFunctions();

    // Create tables
    Schema::create(HoardSchema::$cacheSchema . '.definitions', function (Blueprint $table) {
      $table->id()->generatedAs();

      $table->text('query')->storedAs("CASE WHEN (aggregation_function = '') IS NOT FALSE THEN NULL ELSE 'SELECT ' || foreign_primary_key_name  || ', (' || " . HoardSchema::$cacheSchema . ".get_refresh_query(primary_key_name, aggregation_function, value_names, options, table_schema, table_name, key_name, 'wrapper.' || foreign_primary_key_name, conditions) || ') AS ' || cache_aggregation_name || ' FROM ' || foreign_table_name || ' AS wrapper;' END")->nullable();

      $table->text('cache_table_name');
      $table->text('cache_aggregation_name');

      $table->text('table_schema')->nullable();
      $table->text('table_name')->nullable();

      $table->text('aggregation_function')->nullable();
      $table->jsonb('value_names');
      $table->text('conditions');
      $table->text('aggregation_type')->nullable();
      $table->jsonb('options')->default('[]');

      $table->text('key_name')->nullable();

      $table->text('foreign_table_schema');
      $table->text('foreign_table_name');
      $table->text('foreign_primary_key_name');

      $table->boolean('manual')->default(false);
      $table->boolean('lazy')->default(false);
      $table->boolean('hidden')->default(false);
      $table->boolean('asynchronous')->default(false);

      $table->text('cache_group_name');
      $table->text('cache_primary_key_name');

      $table->text('primary_key_name')->nullable();

      $table->text('foreign_key_name');
      $table->text('foreign_conditions');
    });

    Schema::create(HoardSchema::$cacheSchema . '.logs', function (Blueprint $table) {
      $table->id()->generatedAs();

      $table->unsignedBigInteger('definition_id');
      $table->foreign('definition_id')
        ->references('id')->on(HoardSchema::$cacheSchema . '.definitions')
        ->cascadeOnDelete();

      $table->text('operation');
      $table->jsonb('old_values')->nullable();
      $table->text('old_foreign_key')->nullable();
      $table->boolean('old_relevant');
      $table->jsonb('new_values')->nullable();
      $table->text('new_foreign_key')->nullable();
      $table->boolean('new_relevant');

      $table->timestampTz('created_at')->default(DB::raw('NOW()'));
      $table->timestampTz('processed_at')->nullable();
      $table->timestampTz('canceled_at')->nullable();

      $table->index(['definition_id', 'processed_at']);
    });

    // Create functions that require the tables to exist
    HoardSchema::createViewFunctions();
    HoardSchema::createTriggerFunctions();
    HoardSchema::createCacheTriggerFunctions();
    HoardSchema::createProcessFunctions();
    HoardSchema::createRefreshFunctions();

    // Optimize log table performance
    DB::statement(sprintf('ALTER TABLE %1$s.logs' . ' SET UNLOGGED;', HoardSchema::$cacheSchema));

    // Create triggers on definitions table
    HoardSchema::execute(sprintf(
      <<<PLPGSQL
        BEGIN
          IF NOT %1\$s.exists_trigger('%1\$s', 'definitions', '100_prepare_before') THEN
            CREATE TRIGGER "100_prepare_before"
              BEFORE INSERT OR UPDATE OR DELETE ON %1\$s.definitions
              FOR EACH ROW 
              EXECUTE FUNCTION %1\$s.definitions__before();
          END IF;
          IF NOT %1\$s.exists_trigger('%1\$s', 'definitions', '100_create_artifacts_after') THEN
            CREATE TRIGGER "100_create_artifacts_after"
              AFTER INSERT OR UPDATE OR DELETE ON %1\$s.definitions
              FOR EACH ROW 
              EXECUTE FUNCTION %1\$s.definitions__after();
          END IF;
        END;
        PLPGSQL,
      HoardSchema::$cacheSchema
    ));
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {    
    DB::statement('DROP SCHEMA IF EXISTS ' . HoardSchema::$cacheSchema . ' CASCADE;');
  }
};
