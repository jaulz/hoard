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
    foreach (self::caches() as $configuration) {
      if (!isset($configuration['relation'])) {
        continue;
      }

      // Get relation details
      $relationName = $configuration['relation'];
      $relation = (new static())->{$relationName}();
      if (!is_a($relation, MorphToMany::class, true)) {
        return;
      }

      // Append configuration with dynamic foreign key selector
      $relatedPivotKeyName = $relation->getRelatedPivotKeyName();
      $foreignPivotKeyName = $relation->getForeignPivotKeyName();
      $pivotClass = $relation->getPivotClass();
      $morphClass = $relation->getMorphClass();
      $morphType = $relation->getMorphType();
      $pivotClass::appendCacheConfiguration([
        'function' => $configuration['function'],
        'foreign_model' => $configuration['foreign_model'],
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
            $ids = self::whereHas($relationName, function ($query) use (
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
    array_push(static::$cacheConfigurations, $configuration);
  }

  /**
   * Return the cache configuration for the model.
   *
   * @return array
   */
  public function getCacheConfigurations()
  {
    return array_merge(static::$cacheConfigurations, static::caches());
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
      $className = get_class($this);
      $reflector = new ReflectionClass($className);
      $directory = dirname($reflector->getFileName());
      $classNames = (new FindCacheableClasses(
        $directory
      ))->getAllIsCacheableTraitClasses();

      // Go through all other classes and check if they reference the current class
      static::$foreignCacheConfigurations = collect([]);
      collect($classNames)->each(function ($foreignClassName) use ($className) {
        // Go through options and see where the model is referenced
        $foreignConfigs = collect([]);
        $foreignModel = new $foreignClassName();
        collect($foreignModel->getCacheConfigurations())
          ->filter(function ($configuration) use ($className) {
            return $configuration['foreign_model'] === $className;
          })
          ->each(function ($configuration) use ($foreignConfigs) {
            $foreignConfigs->push($configuration);
          });

        // If there are no configurations that affect this model
        if ($foreignConfigs->count() === 0) {
          return true;
        }

        static::$foreignCacheConfigurations->put(
          $foreignClassName,
          $foreignConfigs->toArray()
        );
      });
    }

    // Rebuild cache of instance
    $cache = new Cache($this);
    $cache->rebuild(static::$foreignCacheConfigurations->toArray());

    return $this;
  }
}
