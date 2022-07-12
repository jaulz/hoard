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
    DB::statement('CREATE SCHEMA IF NOT EXISTS hoard;');
    
    Schema::create(HoardSchema::$cacheSchema . '.triggers', function (Blueprint $table) {
      $table->id()->generatedAs();

      $table->string('schema_name');
      $table->string('table_name');
      $table->string('primary_key_name');
      $table->string('key_name');
      $table->string('aggregation_function');
      $table->jsonb('value_names');
      $table->jsonb('options')->default('[]');
      $table->string('conditions');
      $table->jsonb('dependency_names');

      $table->string('foreign_schema_name');
      $table->string('foreign_table_name');
      $table->string('foreign_primary_key_name');
      $table->string('foreign_key_name');
      $table->string('foreign_aggregation_name');
      $table->string('foreign_conditions');
      $table->string('foreign_cache_table_name');
      $table->string('foreign_cache_primary_key_name');

      $table->boolean('manual')->default(false);
      $table->boolean('lazy')->default(false);
      $table->boolean('hidden')->default(false);
      $table->boolean('asynchronous')->default(false);
    });
    
    Schema::create(HoardSchema::$cacheSchema . '.logs', function (Blueprint $table) {
      $table->id()->generatedAs();

      $table->unsignedBigInteger('trigger_id');
      $table->foreign('trigger_id')
        ->references('id')->on(HoardSchema::$cacheSchema . '.triggers')
        ->cascadeOnDelete();

      $table->string('operation');
      $table->jsonb('old_values')->nullable();
      $table->text('old_foreign_key')->nullable();
      $table->boolean('old_relevant');
      $table->jsonb('new_values')->nullable();
      $table->text('new_foreign_key')->nullable();
      $table->boolean('new_relevant');

      $table->timestampTz('created_at')->default(DB::raw('NOW()'));
      $table->timestampTz('processed_at')->nullable();
      $table->timestampTz('canceled_at')->nullable();

      $table->index(['trigger_id', 'processed_at']);
    });

    DB::statement('ALTER TABLE ' . HoardSchema::$cacheSchema . '.logs' . ' SET UNLOGGED;');
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    DB::statement('DROP SCHEMA IF EXISTS hoard CASCADE;');
  }
};
