<?php

namespace Jaulz\Eloquence\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Jaulz\Eloquence\Exceptions\InvalidPivotModelException;
use Jaulz\Eloquence\Support\Cache;
use Jaulz\Eloquence\Support\CacheObserver;
use Jaulz\Eloquence\Support\FindCacheableClasses;
use ReflectionClass;

trait IsCacheableTrait
{
  /**
   * Keep track of foreign cache configurations in a static property so we don't need to find them again.
   *
   * @var object
   */
  private static $foreignCacheConfigurations;

  /**
   * Store dynamic configurations that were created at runtime.
   *
   * @var array
   */
  private static $cacheConfigurations = [];

  /**
   * Boot the trait and its event bindings when a model is created.
   */
  public static function bootIsCacheableTrait()
  {
    // Observe own model
    static::observe(CacheObserver::class);

    // In case we are dealing with a pivot model, we are attaching a cache config to that model as well
    foreach (static::caches() as $configuration) {
      if (!isset($configuration['relation'])) {
        continue;
      }

      // Get relation details
      $relationName = $configuration['relation'];
      $relation = (new static())->{$relationName}();
      if (!is_a($relation, MorphToMany::class, true)) {
        continue;
      }

      // Append configuration with dynamic foreign key selector
      $relatedPivotKeyName = $relation->getRelatedPivotKeyName();
      $foreignPivotKeyName = $relation->getForeignPivotKeyName();
      $pivotClass = $relation->getPivotClass();
      $morphClass = $relation->getMorphClass();
      $morphType = $relation->getMorphType();

      $pivotClass::appendCacheConfiguration([
        'function' => $configuration['function'],
        'foreign_model' => $relation->getModel(),
        'summary' => $configuration['summary'],
        'foreign_key' => [
          $relatedPivotKeyName,
          function ($key) {
            return $key;
          },
          function ($query, $foreignKey) use (
            $relationName,
            $relatedPivotKeyName,
            $foreignPivotKeyName
          ) {
            $ids = static::whereHas($relationName, function ($query) use (
              $relatedPivotKeyName,
              $foreignKey
            ) {
              return $query->where($relatedPivotKeyName, $foreignKey);
            })->pluck('id');

            $query->whereIn($foreignPivotKeyName, $ids);
          },
        ],
        'where' => [
          $morphType => $morphClass,
        ],
      ]);
    }
  }

  /**
   * Append a cache configuration at runtime.
   *
   */
  public static function appendCacheConfiguration($configuration)
  {
    // Avoid duplicates by creating an unique configuration key
    $configurationKey = $configuration['function'] . '_' . get_class($configuration['foreign_model']) . '_' . $configuration['summary'];

    // Append configuration
    static::$cacheConfigurations = array_merge(static::$cacheConfigurations, [
      $configurationKey => $configuration
    ]);
  }

  /**
   * Return the cache configuration for the model.
   *
   * @return array
   */
  public static function getCacheConfigurations()
  {
    return array_merge(array_values(static::$cacheConfigurations), static::caches());
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
   * Rebuild cache for the model.
   *
   * @return array
   */
  public function rebuildCache()
  {
    if (!static::$foreignCacheConfigurations) {
      // Get all other model classes
      $modelName = get_class($this);
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
        collect($foreignModelName::getCacheConfigurations())
          ->filter(function ($foreignConfiguration) use ($foreignModelName, $modelName) {
            $foreignForeignModelName = $foreignConfiguration['foreign_model'] ?? null;

            // Resolve model name via relation if necessary
            if (isset($foreignConfiguration['relation'])) {
              $relationName = $foreignConfiguration['relation'];

              if (!method_exists($foreignModelName, $relationName)) {
                return false;
              }

              $foreignModel = new $foreignModelName();
              $relation = $foreignModel->{$relationName}();
              $foreignForeignModelName = get_class($relation->getRelated());
            }

            return $foreignForeignModelName === $modelName;
          })
          ->each(function ($foreignConfiguration) use ($foreignConfigurations) {
            $foreignConfigurations->push($foreignConfiguration);
          });

        // If there are no configurations that affect this model
        if ($foreignConfigurations->count() === 0) {
          return true;
        }

        static::$foreignCacheConfigurations->put(
          $foreignModelName,
          $foreignConfigurations->toArray()
        );
      });
    }

    // Rebuild cache
    $cache = new Cache($this);
    $cache->rebuild(static::$foreignCacheConfigurations->toArray());

    return $this;
  }
}
