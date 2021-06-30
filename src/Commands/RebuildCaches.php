<?php
namespace Jaulz\Eloquence\Commands;

use Illuminate\Console\Command;
use Jaulz\Eloquence\Behaviours\Cacheable\Cache;

class RebuildCaches extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'eloquence:rebuild {--class= : Optional classes to update} {--dir= : Directory in which to look for classes}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Rebuild the caches for one or more Eloquent models';

  /**
   * Create a new command instance.
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {
    $directory = $this->option('dir') ?: app_path();
    $classNames = (new FindCacheableClasses(
      $directory
    ))->getAllCacheableClasses();

    $this->rebuild($this->mapUsages($classNames, $this->option('class')));
  }

  /**
   * Get a map of all cacheable classes including their usages
   *
   * @param string $className
   * @param ?string $filter
   */
  private function mapUsages(array $classNames, ?string $filter)
  {
    return collect($classNames)
      ->filter(function ($className) use ($filter) {
        return $filter ? $filter === $className : true;
      })
      ->mapWithKeys(function ($className) use ($classNames) {
        $options = collect([]);

        // Go through all other classes and check if they reference the current class
        collect($classNames)->each(function ($foreignClassName) use (
          $className,
          $options
        ) {
          // Get specific cache options
          if (!method_exists($foreignClassName, 'caches')) {
            return true;
          }

          // Go through options and see where the model is referenced
          $foreignOptions = collect([]);
          $foreignModel = new $foreignClassName();
          collect($foreignModel->caches())
            ->filter(function ($options) use ($className) {
              return $options['model'] === $className;
            })
            ->each(function ($options) use ($foreignOptions) {
              $foreignOptions->push($options);
            });

          if ($foreignOptions->count() === 0) {
            return true;
          }

          $options->put($foreignClassName, $foreignOptions->toArray());
        });

        return [$className => $options->toArray()];
      });
  }

  /**
   * Rebuilds the caches for the given class.
   *
   * @param any $mapping
   */
  private function rebuild($mapping)
  {
    $mapping->each(function ($usages, $className) {
      // Load all instances lazily
      $models = $className::lazy();
      $count = $models->count();
      $startTime = microtime(true);

      // Run through each model and rebuild cache
      $models->each(function ($model, $index) use (
        $className,
        $count,
        $usages
      ) {
        $iteration = $index + 1;
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        if (method_exists($model, 'caches')) {
          $this->comment(
            "($iteration/$count) Rebuilding $className($keyName=$key) caches"
          );

          $cache = new Cache($model);
          $result = $cache->rebuild($usages);
          if (!empty($result['difference'])) {
            $this->warn('Fixed cached fields:');
            $this->warn('Before: ' . json_encode($result['before']));
            $this->warn('After: ' . json_encode($result['after']));
            $this->warn('Difference: ' . json_encode($result['difference']));
          }
        }
      });

      $endTime = microtime(true);
      $executionTime = intval(($endTime - $startTime) * 1000);
      $this->info(
        "Finished rebuilding $className caches in $executionTime milliseconds"
      );
    });
  }
}
