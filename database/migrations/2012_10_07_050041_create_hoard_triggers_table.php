<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::create('hoard_triggers', function (Blueprint $table) {
      $table->id();
      $table->string('table_name');
      $table->string('key_name');
      $table->string('aggregation_function');
      $table->string('value_name');
      $table->string('value_type')->nullable();
      $table->string('conditions');
      $table->string('foreign_table_name');
      $table->string('foreign_cache_table_name');
      $table->string('foreign_primary_key_name');
      $table->string('foreign_key_name');
      $table->string('foreign_aggregation_name');
      $table->string('foreign_conditions');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::dropIfExists('hoard_triggers');
  }
};
