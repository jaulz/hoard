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
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::dropIfExists(HoardSchema::$cacheSchema . '.triggers');
  }
};
