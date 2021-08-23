<?php

namespace Jaulz\Eloquence\Traits;

use Jaulz\Eloquence\Support\Cache;
use Jaulz\Eloquence\Support\CacheObserver;
use Jaulz\Eloquence\Support\FindIsCacheableTraitClasses;
use ReflectionClass;

trait IsCacheableTrait
{
    /**
     * @var object
     */
    private static $foreignCacheConfigs;

    /**
     * Boot the trait and its event bindings when a model is created.
     */
    public static function bootIsCacheableTrait()
    {
        static::observe(CacheObserver::class);
    }

    /**
     * Return the cache configuration for the model.
     *
     * @return array
     */
    public function caches() {
        return [];
    }

    /**
     * Rebuild cache for the model.
     *
     * @return array
     */
    public function rebuildCache()
    {
        if (!static::$foreignCacheConfigs) {
            // Get all other model classes
            $className = get_class($this);
            $reflector = new ReflectionClass($className);
            $directory = dirname($reflector->getFileName());
            $classNames = (new FindIsCacheableTraitClasses($directory))->getAllIsCacheableTraitClasses();

            // Go through all other classes and check if they reference the current class
            static::$foreignCacheConfigs = collect([]);
            collect($classNames)->each(function ($foreignClassName) use (
                $className
            ) {
                // Go through options and see where the model is referenced
                $foreignConfigs = collect([]);
                $foreignModel = new $foreignClassName();
                collect($foreignModel->caches())
                    ->filter(function ($config) use ($className) {
                        return $config['foreign_model'] === $className;
                    })
                    ->each(function ($config) use ($foreignConfigs) {
                        $foreignConfigs->push($config);
                    });

                // If there are no configurations that affect this model 
                if ($foreignConfigs->count() === 0) {
                    return true;
                }

                static::$foreignCacheConfigs->put($foreignClassName, $foreignConfigs->toArray());
            });
        }

        // Rebuild cache of instance
        $cache = new Cache($this);
        $cache->rebuild(static::$foreignCacheConfigs->toArray());

        return $this;
    }
}
