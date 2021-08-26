<?php

namespace Jaulz\Eloquence\Traits;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Jaulz\Eloquence\Support\Cache;
use Jaulz\Eloquence\Support\CacheObserver;
use Jaulz\Eloquence\Support\FindCacheableClasses;
use ReflectionClass;

trait IsCacheableTrait
{
  /**
   * Keep track of foreign cache configurations in a static property so we don't need to find them again.
   *
   * @var \Illuminate\Support\Collection
   */
  private static $foreignCacheConfigurations;

  /**
   * Store dynamic configurations that were created at runtime.
   *
   * @var array
   */
  private static array $cacheConfigurations = [];

  /**
   * Boot the trait and its event bindings when a model is created.
   */
  public static function bootIsCacheableTrait()
  {
    // Observe own model
    static::observe(CacheObserver::class);

    // In case we are dealing with a pivot model, we are attaching a cache config to that model as well
    foreach (static::caches() as $configuration) {
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
      $pivotClass::appendCacheConfiguration($configuration);
    }
  }

  /**
   * Append a cache configuration at runtime.
   *
   */
  public static function appendCacheConfiguration($configuration)
  {
    array_push(static::$cacheConfigurations, $configuration);
  }

  /**
   * Return the cache configuration for the model.
   *
   * @return array
   */
  public static function getCacheConfigurations()
  {
    return collect(static::$cacheConfigurations)->merge(static::caches())->reduce(function ($cumulatedConfigurations, $configuration) {
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
  protected static function caches()
  {
    return [];
  }

  /**
   * Gather all cache configurations
   *
   * @return array
   */
  public static function getForeignCacheConfigurations()
  {
    if (!static::$foreignCacheConfigurations) {
      // Get all other model classes
      $modelName = get_class();
      $reflector = new ReflectionClass($modelName);
      $directory = dirname($reflector->getFileName());
      $foreignModelNames = (new FindCacheableClasses(
        $directory
      ))->getAllIsCacheableTraitClasses();

      // Go through all other classes and check if they reference the current class
      static::$foreignCacheConfigurations = collect([]);
      collect($foreignModelNames)->each(function ($foreignModelName) use ($modelName) {
        // Go through options and see where the model is referenced
        $foreignConfigurations = collect([]);
        $foreignModelName::getCacheConfigurations()
          ->filter(function ($foreignConfiguration) use ($foreignModelName, $modelName) {
            $foreignForeignModelName = $foreignConfiguration['foreignModelName'] ?? null;

            // Resolve model name via relation if necessary
            if (isset($foreignConfiguration['relationName'])) {
              $relationName = $foreignConfiguration['relationName'];

              if (!method_exists($foreignModelName, $relationName)) {
                return false;
              }

              $foreignModel = new $foreignModelName();
              $relation = $foreignModel->{$relationName}();

              // In a morph to scenario any other model could be the target
              if ($relation instanceof MorphTo) {
                $foreignForeignModelName = $foreignConfiguration['foreignModelName'] ?? $modelName;
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

        static::$foreignCacheConfigurations->put(
          $foreignModelName,
          $foreignConfigurations->filter()->toArray()
        );
      });
    }

    return static::$foreignCacheConfigurations->toArray();
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
