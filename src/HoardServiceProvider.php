<?php

namespace Jaulz\Hoard;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
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
        __DIR__ . '/../database/migrations/' => base_path(
          'database/migrations'
        ),
      ],
      'hoard-migrations'
    );

    $this->commands([]);

    $this->enhanceBlueprint();
  }

  public function enhanceBlueprint()
  {
    Blueprint::macro('hoard', function (string $foreignAggregationName, string $cacheGroupName = 'default') {
      /** @var \Illuminate\Database\Schema\Blueprint $this */
      $foreignTableName = $this->prefix . $this->table;

      $command = $this->addCommand(
        'hoard',
        compact(
          'foreignAggregationName',
          'foreignTableName',
          'cacheGroupName'
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
      $foreignSchemaName = Str::contains($command->foreignTableName, '.')
        ? Str::before($command->foreignTableName, '.')
        : HoardSchema::$schema;
      $foreignTableName = Str::after($command->foreignTableName, '.');
      $foreignKeyName =
        $command->foreignKeyName ?? null;
      $options = $command->options ?? [];
      $aggregationFunction = Str::upper($command->aggregationFunction) ?? null;
      $aggregationType = $command->aggregationType ?? null;
      $valueNames = $command->valueNames ?? [];
      $foreignConditions = HoardSchema::prepareConditions(
        $command->foreignConditions ?? []
      );
      $conditions = HoardSchema::prepareConditions($command->conditions ?? []);
      $cacheGroupName = $command->cacheGroupName;
      $schemaName = Str::contains($command->tableName, '.')
        ? Str::before($command->tableName, '.')
        : HoardSchema::$schema;
      $tableName = Str::after($command->tableName, '.');
      $keyName =
        $command->keyName ??
        ($schemaName === HoardSchema::$cacheSchema ? 
          null: Str::singular($foreignTableName) . '_id');
      $refreshConditions = HoardSchema::prepareConditions(
        $command->refreshConditions ?? []
      );
      $lazy = $command->lazy ?? false;
      $hidden = $command->hidden ?? false;
      $manual = $command->manual ?? false;
      $asynchronous = $command->asynchronous ?? false;

      return [
        sprintf(
          "
            INSERT INTO %1\$s.triggers (
              table_name, 
              key_name,
              aggregation_function, 
              value_names,
              options, 
              conditions,
              foreign_table_name,
              foreign_key_name, 
              cache_aggregation_name,
              foreign_conditions,
              lazy,
              hidden,
              manual,
              schema_name,
              foreign_schema_name,
              asynchronous,
              cache_group_name,
              aggregation_type
            ) VALUES (
              %2\$s,
              %3\$s,
              %4\$s,
              convert_from(decode(%5\$s, 'base64'), 'utf8')::jsonb,
              convert_from(decode(%6\$s, 'base64'), 'utf8')::jsonb,
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
              %19\$s
            ) ON CONFLICT (id) DO UPDATE SET 
              table_name = EXCLUDED.table_name, 
              key_name = EXCLUDED.key_name, 
              aggregation_function = EXCLUDED.aggregation_function, 
              value_names = EXCLUDED.value_names, 
              options = EXCLUDED.options, 
              conditions = EXCLUDED.conditions, 
              foreign_table_name = EXCLUDED.foreign_table_name, 
              foreign_key_name = EXCLUDED.foreign_key_name, 
              cache_aggregation_name = EXCLUDED.cache_aggregation_name, 
              foreign_conditions = EXCLUDED.foreign_conditions, 
              lazy = EXCLUDED.lazy, 
              hidden = EXCLUDED.hidden, 
              manual = EXCLUDED.manual, 
              schema_name = EXCLUDED.schema_name, 
              foreign_schema_name = EXCLUDED.foreign_schema_name, 
              asynchronous = EXCLUDED.asynchronous, 
              cache_group_name = EXCLUDED.cache_group_name,
              aggregation_type = EXCLUDED.aggregation_type;
          ",
          HoardSchema::$cacheSchema,
          $this->quoteString($tableName),
          $this->quoteString($keyName),
          $this->quoteString($aggregationFunction),
          $this->quoteString(base64_encode(json_encode($valueNames))),
          $this->quoteString(
            base64_encode(json_encode($options, JSON_FORCE_OBJECT))
          ),
          DB::getPdo()->quote($conditions),
          $this->quoteString($foreignTableName),
          $this->quoteString($foreignKeyName),
          $this->quoteString($foreignAggregationName),
          DB::getPdo()->quote($foreignConditions),
          $lazy ? 'true' : 'false',
          $hidden ? 'true' : 'false',
          $manual ? 'true' : 'false',
          $this->quoteString($schemaName),
          $this->quoteString($foreignSchemaName),
          $asynchronous ? 'true' : 'false',
          $this->quoteString($cacheGroupName),
          $this->quoteString($aggregationType),
        ),
      ];
    });
  }
}
