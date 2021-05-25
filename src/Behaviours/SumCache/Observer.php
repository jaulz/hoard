<?php

namespace Jaulz\Eloquence\Behaviours\SumCache;

/**
 * The Observer is used for watching for model updates and making the appropriate changes
 * as required. This includes watching for created, deleted, updated and restored events.
 */
class Observer
{

    /**
     * When the model has been created, increment the count cache by columnToSum field.
     *
     * @param $model
     */
    public function created($model)
    {
        $sumCache = new SumCache($model);
        $sumCache->apply('sum', function ($config) use ($model, $sumCache) {
            $sumCache->updateCacheRecord($config, '+', $model->{$config['columnToSum']}, $model->{$config['foreignKey']});
        });
    }

    /**
     * When the model is deleted, decrement the count cache by columnToSum field.
     *
     * @param $model
     */
    public function deleted($model)
    {
        $sumCache = new SumCache($model);
        $sumCache->apply('sum', function ($config) use ($model, $sumCache) {
            $sumCache->updateCacheRecord($config, '-', $model->{$config['columnToSum']}, $model->{$config['foreignKey']});
        });
    }

    /**
     * When the model is updated, update the sum cache.
     *
     * @param $model
     */
    public function updated($model)
    {
        (new SumCache($model))->update();
    }

    /**
     * When the model is restored, again increment the count cache by 1.
     *
     * @param $model
     */
    public function restored($model)
    {
        $this->created($model);
    }
}
