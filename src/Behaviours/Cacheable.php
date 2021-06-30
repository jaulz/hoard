<?php
namespace Jaulz\Eloquence\Behaviours;

use Jaulz\Eloquence\Behaviours\Cacheable\CacheObserver;

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
}
