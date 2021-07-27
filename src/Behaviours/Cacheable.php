<?php

namespace Jaulz\Eloquence\Behaviours;

use Jaulz\Eloquence\Behaviours\Cacheable\Cache;
use Jaulz\Eloquence\Behaviours\Cacheable\CacheObserver;
use Jaulz\Eloquence\Support\FindCacheableClasses;
use ReflectionClass;

trait Cacheable
{
    /**
     * Boot the trait and its event bindings when a model is created.
     */
    public static function bootCacheable()
    {
        static::observe(CacheObserver::class);
    }

    /**
     * Return the cache configuration for the model.
     *
     * @return array
     */
    abstract public function caches();

    /**
     * Rebuild cache for the model.
     *
     * @return array
     */
    public function cache()
    {
        $className = get_class($this);

        // Get all other model classes
        $reflector = new ReflectionClass($className);
        $directory = dirname($reflector->getFileName());
        $classNames = (new FindCacheableClasses($directory))->getAllCacheableClasses();

        // Go through all other classes and check if they reference the current class
        $configs = collect([]);
        collect($classNames)->each(function ($foreignClassName) use (
            $className,
            $configs
        ) {
            // Go through options and see where the model is referenced
            $foreignConfigs = collect([]);
            $foreignModel = new $foreignClassName();
            collect($foreignModel->caches())
                ->filter(function ($config) use ($className) {
                    return $config['model'] === $className;
                })
                ->each(function ($config) use ($foreignConfigs) {
                    $foreignConfigs->push($config);
                });

            // If there are no configurations that affect this model 
            if ($foreignConfigs->count() === 0) {
                return true;
            }

            $configs->put($foreignClassName, $foreignConfigs->toArray());
        });

        // Rebuild cache of instance
        $cache = new Cache($this);
        $cache->rebuild($configs->toArray());

        return $this;
    }
}
