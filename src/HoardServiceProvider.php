<?php

namespace Jaulz\Hoard;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
      $valueName = $command->valueName ?? '';
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

      return array_filter([
        ...HoardSchema::createGenericHelperFunctions(),
        ...HoardSchema::createSpecificHelperFunctions(),
        ...HoardSchema::createAggregationFunctions(),
        ...HoardSchema::createRefreshFunctions(),
        ...HoardSchema::createProcessFunctions(),
        ...HoardSchema::createUpdateFunctions(),
        ...HoardSchema::createViewFunctions(),
        ...HoardSchema::createTriggerFunctions(),

        HoardSchema::execute(sprintf(
          <<<PLPGSQL
            BEGIN
              IF NOT %1\$s.exists_trigger('%1\$s', 'triggers', 'hoard_before') THEN
                CREATE TRIGGER hoard_before
                  BEFORE INSERT OR UPDATE OR DELETE ON %1\$s.triggers
                  FOR EACH ROW 
                  EXECUTE FUNCTION %1\$s.prepare();
              END IF;
  
              IF NOT %1\$s.exists_trigger('%1\$s', 'triggers', 'hoard_after') THEN
                CREATE TRIGGER hoard_after
                  AFTER INSERT OR UPDATE OR DELETE ON %1\$s.triggers
                  FOR EACH ROW 
                  EXECUTE FUNCTION %1\$s.initialize();
              END IF;
            END;
            PLPGSQL,
          HoardSchema::$cacheSchema
        )),

        sprintf(
          "
            INSERT INTO %1\$s.triggers (
              table_name, 
              key_name,
              aggregation_function, 
              value_name,
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
              asynchronous
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
              %21\$s
            ) ON CONFLICT (id) DO UPDATE SET 
              table_name = %2\$s, 
              key_name = %3\$s,
              aggregation_function = %4\$s,
              value_name = %5\$s, 
              options = %6\$s, 
              conditions = %7\$s,
              foreign_table_name = %8\$s, 
              foreign_key_name = %9\$s,
              foreign_aggregation_name = %10\$s,
              foreign_conditions = %11\$s,
              foreign_primary_key_name = %12\$s,
              lazy = %13\$s,
              primary_key_name = %14\$s,
              foreign_cache_table_name = %15\$s,
              foreign_cache_primary_key_name = %16\$s,
              hidden = %17\$s,
              manual = %18\$s,
              schema_name = %19\$s,
              foreign_schema_name = %20\$s,
              asynchronous = %21\$s;
          ",
          HoardSchema::$cacheSchema,
          $this->quoteString($tableName),
          $this->quoteString($keyName),
          $this->quoteString($aggregationFunction),
          $this->quoteString($valueName),
          $this->quoteString(json_encode($options, JSON_FORCE_OBJECT)),
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
        ),
      ]);
    });
  }
}
