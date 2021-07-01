<?php

namespace Jaulz\Eloquence\Behaviours\Cacheable;

use Illuminate\Support\Facades\DB;

/**
 * The Observer is used for watching for model updates and making the appropriate changes
 * as required. This includes watching for created, deleted, updated and restored events.
 */
class CacheObserver
{
    /**
     * When the model has been created, update the cache field
     *
     * @param $model
     */
    public function created($model)
    {
        DB::transaction(function () use ($model) {
            (new Cache($model))->create();
        });
    }

    /**
     * When the model is deleted, update the cache field
     *
     * @param $model
     */
    public function deleted($model)
    {
        (new Cache($model))->delete();
    }

    /**
     * When the model is updated, update the sum cache.
     *
     * @param $model
     */
    public function updated($model)
    {
        //if (get_class($model) === 'Tests\Acceptance\Models\User') {
            error_log(get_class($model));
            error_log($model);
            error_log('updated');
        //}
        (new Cache($model))->update();
    }
}
