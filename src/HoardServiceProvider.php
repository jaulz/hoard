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
        __DIR__ . '/../database/migrations/' => base_path('database/migrations'),
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
      $options = $command->options ?? [];
      $aggregationFunction = Str::upper($command->aggregationFunction) ?? '';
      $valueNames = $command->valueNames ?? [];
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

      // Extract dependencies from key, values and conditions
      // [^\\\\]((\")|('))(?(2)([^\"]|\\\")*|([^']|\\')*)[^\\\\]\\1|([a-zA-Z_$][a-zA-Z_$0-9]*)
      preg_match_all("/'[^']*'|\b([a-zA-Z_$][a-zA-Z_$0-9]*)\b/m", 
        implode(' ', [$keyName, ...$valueNames, $conditions])
      , $matches);
      $dependencyNames = collect([...$matches[1]])
      ->map(fn($dependencyName) => Str::lower($dependencyName))
      ->unique()
      ->filter(fn ($dependencyName) => !in_array($dependencyName, [
        'is',
        'not',
        'null',
        'true',
        'false'
      ]))
      ->filter()
      ->values()->all();

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
              asynchronous,
              dependency_names
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
              %19\$s,
              %20\$s,
              %21\$s,
              convert_from(decode(%22\$s, 'base64'), 'utf8')::jsonb
            ) ON CONFLICT (id) DO UPDATE SET 
              table_name = EXCLUDED.table_name, 
              key_name = EXCLUDED.key_name, 
              aggregation_function = EXCLUDED.aggregation_function, 
              value_names = EXCLUDED.value_names, 
              options = EXCLUDED.options, 
              conditions = EXCLUDED.conditions, 
              foreign_table_name = EXCLUDED.foreign_table_name, 
              foreign_key_name = EXCLUDED.foreign_key_name, 
              foreign_aggregation_name = EXCLUDED.foreign_aggregation_name, 
              foreign_conditions = EXCLUDED.foreign_conditions, 
              foreign_primary_key_name = EXCLUDED.foreign_primary_key_name, 
              lazy = EXCLUDED.lazy, 
              primary_key_name = EXCLUDED.primary_key_name, 
              foreign_cache_table_name = EXCLUDED.foreign_cache_table_name, 
              foreign_cache_primary_key_name = EXCLUDED.foreign_cache_primary_key_name, 
              hidden = EXCLUDED.hidden, 
              manual = EXCLUDED.manual, 
              schema_name = EXCLUDED.schema_name, 
              foreign_schema_name = EXCLUDED.foreign_schema_name, 
              asynchronous = EXCLUDED.asynchronous, 
              dependency_names = EXCLUDED.dependency_names;
          ",
          HoardSchema::$cacheSchema,
          $this->quoteString($tableName),
          $this->quoteString($keyName),
          $this->quoteString($aggregationFunction),
          $this->quoteString(base64_encode(json_encode($valueNames))),
          $this->quoteString(base64_encode(json_encode($options, JSON_FORCE_OBJECT))),
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
          $this->quoteString(base64_encode(json_encode($dependencyNames))),
        ),
      ];
    });
  }
}
