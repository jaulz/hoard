<?php
namespace Jaulz\Eloquence\Commands;

use Illuminate\Console\Command;
use Jaulz\Eloquence\Behaviours\Cacheable\Cache;
use Jaulz\Eloquence\Support\FindCacheableClasses;

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

    // Iterate through all cacheable classes and rebuild cache
    $classNames->each(function ($className) {
      return !!$this->option('filter') ? $className === $this->option('filter') : true;
    })->each(function ($className) {
      // Load all instances lazily
      $models = $className::lazy();
      $count = $models->count();
      $bar = $this->output->createProgressBar($count);
      $startTime = microtime(true);

      // Run through each model and rebuild cache
      $bar->start();
      $models->each(function ($model, $index) use (
        $className,
        $count
      ) {
        $iteration = $index + 1;
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        $this->comment(
          "($iteration/$count) Rebuilding $className($keyName=$key) caches"
        );

        $model->cache();

          /*if (!empty($result['difference'])) {
            $this->warn('Fixed cached fields:');
            $this->warn('Before: ' . json_encode($result['before']));
            $this->warn('After: ' . json_encode($result['after']));
            $this->warn('Difference: ' . json_encode($result['difference']));
          }*/

        $bar->advance();
      });
      $bar->finish();

      $endTime = microtime(true);
      $executionTime = intval(($endTime - $startTime) * 1000);
      $this->info(
        "Finished rebuilding $className caches in $executionTime milliseconds"
      );
    });


    $before = collect($model->getAttributes())->only($valueColumns);
    $success = $model::unguarded(function () use ($model, $updates) {
      $model->fill($updates);
      $model->timestamps = false;

      return $model->saveQuietly();
    });
    $after = collect($model->refresh()->getAttributes())->only($valueColumns);

    return [
      'before' => $before->toArray(),
      'after' => $after->toArray(),
      'difference' => $before->diffAssoc($after)->toArray(),
    ];
    $this->rebuild($this->mapUsages($classNames, $this->option('class')));
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
