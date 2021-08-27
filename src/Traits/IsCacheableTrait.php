<?php

namespace Jaulz\Hoard\Traits;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jaulz\Hoard\Support\Cache;
use Jaulz\Hoard\Support\CacheObserver;
use Jaulz\Hoard\Support\FindCacheableClasses;
use ReflectionClass;

trait IsCacheableTrait
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
   * Boot the trait and its event bindings when a model is created.
   */
  public static function bootIsCacheableTrait()
  {
    // Observe own model
    static::observe(CacheObserver::class);

    // In case we are dealing with a pivot model, we are attaching a cache config to that model as well
    foreach (static::hoard() as $configuration) {
      if (!isset($configuration['relationName'])) {
        continue;
      }

      // Get relation details
      $relationName = $configuration['relationName'];
      $relation = (new static())->{$relationName}();
      if (!is_a($relation, MorphToMany::class, true)) {
        continue;
      }

      // Append configuration
      $pivotClass = $relation->getPivotClass();
      $pivotClass::appendHoardConfiguration($configuration);
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
   * @return array
   */
  public static function getHoardConfigurations()
  {
    // Merge with static configurations (which were set from another model) and also expand foreignModelName key (which can be an array)
    return collect(static::$hoardConfigurations)->merge(static::hoard())->reduce(function ($cumulatedConfigurations, $configuration) {
      if (!isset($configuration['foreignModelName'])) {
        return $cumulatedConfigurations->push($configuration);
      }

      if (!is_array($configuration['foreignModelName'])) {
        return $cumulatedConfigurations->push($configuration);
      }

      collect($configuration['foreignModelName'])->each(function ($foreignModelName) use ($configuration, $cumulatedConfigurations) {
        $cumulatedConfigurations->push(array_merge($configuration, [
          'foreignModelName' => $foreignModelName,
        ]));
      });

      return $cumulatedConfigurations;
    }, collect());
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
   * @return array
   */
  public static function getForeignHoardConfigurations()
  {
    if (is_null(static::$foreignHoardConfigurations)) {
      // Get all other model classes
      $modelName = get_class();
      $reflector = new ReflectionClass($modelName);
      $directory = dirname($reflector->getFileName());
      $foreignModelNames = (new FindCacheableClasses(
        $directory
      ))->getAllIsCacheableTraitClasses();

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
            $foreignConfigurations->push(Cache::prepareConfiguration($foreignModelName, $foreignConfiguration, true, get_class()));
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
   * Rebuild cache for the model.
   *
   * @return array
   */
  public function rebuildCache()
  {
    // Rebuild cache
    $cache = new Cache($this);
    $cache->rebuild();

    return $this;
  }
}
