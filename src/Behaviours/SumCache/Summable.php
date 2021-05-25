<?php
namespace Jaulz\Eloquence\Behaviours\SumCache;

use Jaulz\Eloquence\Behaviours\Cacheable;

trait Summable
{
    use Cacheable;

    /**
     * Boot the trait and its event bindings when a model is created.
     */
    public static function bootSummable()
    {
        static::observe(Observer::class);
    }

    /**
     * Return the sum cache configuration for the model.
     *
     * @return array
     */
    abstract public function sumCaches();
}
