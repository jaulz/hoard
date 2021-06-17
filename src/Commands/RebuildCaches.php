<?php
namespace Jaulz\Eloquence\Commands;

use Jaulz\Eloquence\Behaviours\CountCache\CountCache;
use Jaulz\Eloquence\Behaviours\SumCache\SumCache;
use hanneskod\classtools\Iterator\ClassIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

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

    $this->rebuild('count', $this->mapUsages(
        'count',
        $classNames,
        $this->option('class')
      ));
      $this->rebuild('sum', $this->mapUsages(
        'sum',
          $classNames,
          $this->option('class')
        ));
  }

  /**
   * Get a map of all cacheable classes including their usages
   *
   * @param string $type
   * @param string $className
   * @param ?string $filter
   */
  private function mapUsages(
    string $type,
    array $classNames,
    ?string $filter
  ) {
    return collect($classNames)
      ->filter(function ($className) use ($filter) {
        return $filter
          ? $filter === $className
          : true;
      })
      ->mapWithKeys(function ($className) use ($classNames, $type) {
        $options = collect([]);

        // Go through all other classes and check if they reference the current class
        collect($classNames)->each(function ($foreignClassName) use (
          $className,
          $type,
          $options
        ) {
            // Get specific cache options
          if (!method_exists($foreignClassName, $type . 'Caches')) {
            return true;
          }

          // Go through options and see where the model is referenced
          $foreignOptions = collect([]);
          $foreignModel = new $foreignClassName();
          collect($foreignModel->{$type . 'Caches'}())
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
   * @param string $type
   * @param any $mapping
   */
  private function rebuild(
    string $type, $mapping)
  {
    $mapping->each(function ($usages, $className) use ($type) {
        // Load all instances lazily
        $models = $className::lazy();
        $count = $models->count();
        $startTime = microtime(true);
    
        // Run through each model and rebuild cache
        $models->each(function ($model, $index) use ($className, $type, $count, $usages) {
          $iteration = $index + 1;
          $keyName = $model->getKeyName();
          $key = $model->getKey();
    
          if (method_exists($model, $type . 'Caches')) {
            $this->comment(
              "($iteration/$count) Rebuilding $className($keyName=$key) $type caches"
            );

            $cacheClass = 'Jaulz\\Eloquence\\Behaviours\\CountCache\\' . Str::studly($type) . 'Cache';
            $cache = new $cacheClass($model);
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
          "Finished rebuilding $className $type caches in $executionTime milliseconds"
        );
    });
  }
}
