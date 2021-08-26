<?php

namespace Jaulz\Eloquence\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
      /*$relatedPivotKeyName = $relation->getRelatedPivotKeyName();
      $foreignPivotKeyName = $relation->getForeignPivotKeyName();
      $morphClass = $relation->getMorphClass();
      $morphType = $relation->getMorphType();*/

      $pivotClass::appendCacheConfiguration($configuration);
      /*$pivotClass::appendCacheConfiguration([
        'function' => $configuration['function'],
        'foreignModelName' => $relation->getModel(),
        'summaryName' => $configuration['summaryName'],
        'valueName' => $configuration['valueName'] ?? null,
        'foreignKeyName' => [
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
        'attributes' => function (Model $model) {
          return $model->pivotParent->getAttributes();
        },
        'original_attributes' => function (Model $model) {
          return $model->pivotParent->getRawOriginal();
        },
      ]);*/
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
        collect($foreignModelName::getCacheConfigurations())
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
