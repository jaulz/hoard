<?php

namespace Jaulz\Hoard\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * The Observer is used for watching for model updates and making the appropriate changes
 * as required. This includes watching for created, deleted and updated events.
 */
class HoardObserver
{
    /**
     * When the model has been created, update the hoardable fields.
     *
     * @param $model
     */
    public function created($model)
    {
        $this->hoard($model, 'create');
    }

    /**
     * When the model is deleted, update the hoardable fields.
     *
     * @param $model
     */
    public function deleted($model)
    {
        $this->hoard($model, 'delete');
    }

    /**
     * When the model is updated, update the hoardable fields.
     *
     * @param $model
     */
    public function updated($model)
    {
        $this->hoard($model, 'update');
    }

    /**
     * Run a specific hoard method. 
     *
     * @param Model $model
     * @param string $method
     */
    private function hoard(Model $model, string $method)
    {
        $relations = get_class($model)::getHoardRelations();

        DB::transaction(function () use ($model, $method, $relations) {
            // First the simple case where we don't need to deal with a pivot model
            if (!($model instanceof Pivot)) {
                $hoard = new Hoard($model);
                $hoard->{$method}();
            } else {
                // For pivot models it's more complex because we potentially need to update both sides of the model
                $morphClass = Hoard::getMorphClass($model);
                $previousUpdates = collect();

                foreach ($relations as $relatedModelName => $relation) {
                    $skip = $relation->getInverse() ? false: ($morphClass && $morphClass !== $relatedModelName);
                    if ($skip) {
                        continue;
                    }

                    if ($model->pivotParent && get_class($model->pivotParent) === $relatedModelName) {
                        $relatedModel = $model->pivotParent;
                    } else {
                        $relatedModel = new $relatedModelName();
                        $relatedModel->{$model->getKeyName()} = $model->{$model->getRelatedKey()};
                    }


                    // Run updates and cache all previous updates so we avoid duplicates
                    $hoard = new Hoard($relatedModel, [], $model, $previousUpdates);
                    $updates = $hoard->{$method}();
                    $previousUpdates = $previousUpdates->concat($updates);
                }
            }
        });
    }
}
