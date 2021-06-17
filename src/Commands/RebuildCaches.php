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

    $this->rebuild('count', $this->mapDependencies(
        'count',
        $classNames,
        $this->option('class')
      ));
      $this->rebuild('sum', $this->mapDependencies(
        'sum',
          $classNames,
          $this->option('class')
        ));
  }

  /**
   * Get a map of all cacheable classes including their foreign dependencies
   *
   * @param string $type
   * @param string $className
   * @param ?string $filter
   */
  private function mapDependencies(
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
          if (!method_exists($foreignClassName, $type . 'Caches')) {
            return true;
          }

          $foreignOptions = collect([]);
          $foreignModel = new $foreignClassName();
          collect($foreignModel->{$type . 'Caches'}())
            ->filter(function ($option) use ($className) {
              return $option['model'] === $className;
            })
            ->each(function ($options) use ($foreignOptions) {
              $foreignOptions->push($options);
            });

          if ($foreignOptions->count() === 0) {
            return true;
          }

          $options->put($foreignClassName, $foreignOptions);
        });

        return [$className => $options];
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
    $mapping->each(function ($dependencies, $className) use ($type) {
        $models = $className::lazy();
        $count = $models->count();
        $startTime = microtime(true);
    
        $models->each(function ($model, $index) use ($className, $type, $count) {
          $iteration = $index + 1;
          $keyName = $model->getKeyName();
          $key = $model->getKey();
    
          if (method_exists($model, $type . 'Caches')) {
            $this->comment(
              "($iteration/$count) Rebuilding $className($keyName=$key) $type caches"
            );

            $cacheClass = 'Jaulz\\Eloquence\\Behaviours\\CountCache\\' . Str::studly($type) . 'Cache';
            $cache = new $cacheClass($model);
            $cache->rebuild($dependencies);
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
