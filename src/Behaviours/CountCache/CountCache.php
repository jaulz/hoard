<?php
namespace Jaulz\Eloquence\Behaviours\CountCache;

use Jaulz\Eloquence\Behaviours\Cacheable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CountCache
{
    use Cacheable;

    /**
     * @var Model
     */
    private $model;

    /**
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Update the cache for all operations.
     */
    public function update()
    {
        $this->apply('count', function ($config, $isRelevant, $wasRelevant) {
            $foreignKey = Str::snake($this::key(get_class($this->model), $config['foreignKey']));

            // In case the foreign key changed, we just transfer the values from one model to the other
            if ($this->model->getOriginal($foreignKey) && $this->model->{$foreignKey} != $this->model->getOriginal($foreignKey)) {
                $this->updateCacheRecord($config, '-', 1, $this->model->getOriginal($foreignKey));
                $this->updateCacheRecord($config, '+', 1, $this->model->{$foreignKey});
            } else {
                if ($isRelevant && $wasRelevant) {
                    // We do not need to do anything when the model is as relevant as before
                } else if ($isRelevant && !$wasRelevant) {
                    // Increment because it was not relevant before but now it is
                    $this->updateCacheRecord($config, '+', 1, $this->model->{$foreignKey});
                } else if (!$isRelevant && $wasRelevant) {
                    // Decrement because it was relevant before but now it is not anymore
                    $this->updateCacheRecord($config, '-', 1, $this->model->{$foreignKey});
                }
            }
        });
    }

    /**
     * Rebuild the count caches from the database
     * 
     * @param array $configs
     * @return array
     */
    public function rebuild($configs)
    {
        return self::rebuildCacheRecords($this->model, $configs, 'COUNT');
    }

    /**
     * Takes a registered counter cache, and setups up defaults.
     *
     * @param string $model
     * @param string $cacheKey
     * @param array $cacheOptions
     * @return array
     */
    protected static function config($model, $cacheKey, $cacheOptions)
    {
        $opts = [];

        if (is_numeric($cacheKey)) {
            if (is_array($cacheOptions)) {
                // Most explicit configuration provided
                $opts = $cacheOptions;
                $relatedModel = Arr::get($opts, 'model');
            } else {
                // Smallest number of options provided, figure out the rest
                $relatedModel = $cacheOptions;
            }
        } else {
            // Semi-verbose configuration provided
            $relatedModel = $cacheOptions;
            $opts['field'] = $cacheKey;

            if (is_array($cacheOptions)) {
                if (isset($cacheOptions[2])) {
                    $opts['key'] = $cacheOptions[2];
                }
                if (isset($cacheOptions[1])) {
                    $opts['foreignKey'] = $cacheOptions[1];
                }
                if (isset($cacheOptions[0])) {
                    $relatedModel = $cacheOptions[0];
                }
            }
        }

        return self::defaults($opts, $model, $relatedModel);
    }

    /**
     * Returns necessary defaults, overwritten by provided options.
     *
     * @param array $options
     * @param string $model
     * @param string $relatedModel
     * @return array
     */
    protected static function defaults($options, $model, $relatedModel)
    {
        $defaults = [
            'model' => $relatedModel,
            'field' => self::field($model, 'count'),
            'foreignKey' => self::field($relatedModel, 'id'),
            'key' => 'id',
            'where' => []
        ];

        return array_merge($defaults, $options);
    }
}
