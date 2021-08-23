<?php

namespace Jaulz\Eloquence\Support;

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
        DB::transaction(function () use ($model) {
            (new Cache($model))->delete();
        });
    }

    /**
     * When the model is updated, update the sum cache.
     *
     * @param $model
     */
    public function updated($model)
    {
        DB::transaction(function () use ($model) {
            (new Cache($model))->update();
        });
    }
}
