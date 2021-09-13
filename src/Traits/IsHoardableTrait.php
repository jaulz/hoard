<?php

namespace Jaulz\Hoard\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jaulz\Hoard\Support\Hoard;
use Jaulz\Hoard\Support\HoardObserver;
use Jaulz\Hoard\Support\FindHoardableClasses;
use ReflectionClass;

trait IsHoardableTrait
{
  /**
   * Keep track of foreign hoard configurations in a static property so we don't need to find them again.
   *
   * @var ?array
   */
  private static $foreignHoardConfigurations = null;

  /**
   * Store dynamic configurations that were created at runtime.
   *
   * @var array
   */
  private static array $hoardConfigurations = [];

  /**
   * @var array
   */
  static private array $hoardRelations = [];

  /**
   * Boot the trait and its event bindings when a model is created.
   */
  public static function bootIsHoardableTrait()
  {
    // Observe own model
    static::observe(HoardObserver::class);

    // In case we are dealing with a pivot model, we are attaching a cache config to that model as well
    foreach (static::getHoardConfigurations(false) as $configuration) {
      if (!isset($configuration['relationName'])) {
        continue;
      }

      // Get relation details
      $relationName = $configuration['relationName'];
      $relation = (new static())->{$relationName}();
      if ($relation instanceof BelongsToMany) {
        $pivotClass = $relation->getPivotClass();
        $pivotClass::appendHoardConfiguration(array_merge([
          'inverse' => $relation instanceof MorphToMany ? $relation->getInverse() : false,
        ], $configuration));
        $pivotClass::rememberHoardRelation(get_class(), $relation);
      }
    }
  }

  /**
   * Append a hoard configuration at runtime.
   *
   */
  public static function appendHoardConfiguration($configuration)
  {
    array_push(static::$hoardConfigurations, $configuration);
  }

  /**
   * Return the hoard configuration for the model.
   *
   * @param ?bool $local
   * @return array
   */
  public static function getHoardConfigurations($all = true)
  {
    // Merge with configurations which were set from another model if required
    $mergedConfigurations = collect($all ? static::$hoardConfigurations : []);
  
    // Expand foreignModelName and relationName key (which can both be arrays)
    $mergedConfigurations = $mergedConfigurations->merge(static::hoard())->reduce(function ($cumulatedConfigurations, $configuration) {
      if (!isset($configuration['foreignModelName']) ||!is_array($configuration['foreignModelName'])) {
        return $cumulatedConfigurations->push($configuration);
      }

      // Expand foreignModelName key (which can be an array)
      collect($configuration['foreignModelName'])->each(function ($foreignModelName) use ($configuration, $cumulatedConfigurations) {
        $cumulatedConfigurations->push(array_merge($configuration, [
          'foreignModelName' => $foreignModelName,
        ]));
      });

      return $cumulatedConfigurations;
    }, collect())->reduce(function ($cumulatedConfigurations, $configuration) {
      if (!isset($configuration['relationName']) ||!is_array($configuration['relationName'])) {
        return $cumulatedConfigurations->push($configuration);
      }

      // Expand relationName key (which can be an array)
      collect($configuration['relationName'])->each(function ($relationName) use ($configuration, $cumulatedConfigurations) {
        $cumulatedConfigurations->push(array_merge($configuration, [
          'relationName' => $relationName,
        ]));
      });

      return $cumulatedConfigurations;
    }, collect());

    return $mergedConfigurations;
  }

  /**
   * Return the cache configuration for the model.
   *
   * @return array
   */
  protected static function hoard()
  {
    return [];
  }

  /**
   * Gather all cache configurations
   *
   * @param ?bool $force
   * @return array
   */
  public static function getForeignHoardConfigurations(?bool $force = false)
  {
    // Check if we have a cached configuration file that we can use
    if (!$force) {
      $path = app()->bootstrapPath('cache/hoard.php');
      if (file_exists($path)) {
        $cache = require($path);

        return $cache[get_class()];
      }
    }

    // Otherwise we build the configuration tree from scratch if it hasn't been build yet
    if (is_null(static::$foreignHoardConfigurations)) {
      // Get all other model classes
      $modelName = get_class();
      $reflector = new ReflectionClass($modelName);
      $directory = dirname($reflector->getFileName());
      $foreignModelNames = (new FindHoardableClasses(
        $directory
      ))->getClassNames();

      // Go through all other classes and check if they reference the current class
      static::$foreignHoardConfigurations = [];
      collect($foreignModelNames)->each(function ($foreignModelName) use ($modelName) {
        // Go through options and see where the model is referenced
        $foreignConfigurations = collect([]);
        $foreignModelName::getHoardConfigurations()
          ->filter(function ($foreignConfiguration) use ($foreignModelName, $modelName) {
            $foreignForeignModelName = $foreignConfiguration['foreignModelName'] ?? null;

            // Resolve model name via relation if necessary
            if (!$foreignForeignModelName && isset($foreignConfiguration['relationName'])) {
              $relationName = $foreignConfiguration['relationName'];

              if (!method_exists($foreignModelName, $relationName)) {
                return false;
              }

              $foreignModel = new $foreignModelName();
              $relation = $foreignModel->{$relationName}();

              // In a morph to scenario any other model could be the target
              if ($relation instanceof MorphTo) {
                $foreignForeignModelName = $modelName;
              } else {
                $foreignForeignModelName = get_class($relation->getRelated());
              }
            }

            return $foreignForeignModelName === $modelName;
          })
          ->each(function ($foreignConfiguration) use ($foreignModelName, $foreignConfigurations) {
            $foreignConfigurations->push(Hoard::prepareConfiguration($foreignModelName, null, $foreignConfiguration, get_class(), true));
          });

        // If there are no configurations that affect this model
        if ($foreignConfigurations->count() === 0) {
          return true;
        }

        // Eventually add the configuration to our static collection
        static::$foreignHoardConfigurations[$foreignModelName] = $foreignConfigurations->filter()->toArray();
      });
    }

    return static::$foreignHoardConfigurations;
  }

  /**
   * Refresh cache for the model.
   *
   * @return array
   */
  public function refreshHoard()
  {
    $hoard = new Hoard($this);
    $hoard->run();

    return $this;
  }

  /**
   * Return the hoard relations for the model.
   *
   * @return array
   */
  public static function getHoardRelations()
  {
    return static::$hoardRelations;
  }

  /**
   * Get protected "morphClass" attribute of model.
   *
   * @param string     $modelName
   * @param Relation     $morphType
   */
  protected static function rememberHoardRelation(
    string $modelName,
    Relation $morphType
  ) {
    static::$hoardRelations[$modelName] = $morphType;
  }
}
