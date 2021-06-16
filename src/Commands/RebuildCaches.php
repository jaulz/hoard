<?php
namespace Jaulz\Eloquence\Commands;

use Jaulz\Eloquence\Behaviours\CountCache\CountCache;
use Jaulz\Eloquence\Behaviours\SumCache\SumCache;
use hanneskod\classtools\Iterator\ClassIterator;
use Illuminate\Console\Command;
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

        if ($class = $this->option('class')) {
            $classes = [$class];
        } else {
            $directory = $this->option('dir') ?: app_path();
            $classes = (new FindCacheableClasses($directory))->getAllCacheableClasses();
        }
        foreach ($classes as $className) {
            $this->rebuild($className);
        }
    }

    /**
     * Rebuilds the caches for the given class.
     *
     * @param string $className
     */
    private function rebuild($className)
    {
        $models = $className::all();
        $count = $models->count();
        $startTime = microtime(true); 

        $models->each(function ($model, $index) use ($className, $count) {
            $iteration = $index + 1;
            $keyName = $model->getKeyName();
            $key = $model->getKey();

            if (method_exists($model, 'countCaches')) {
                $this->info("($iteration/$count) Rebuilding $className($keyName=$key) count caches");
                $countCache = new CountCache($model);
                $countCache->rebuild();
            }

            if (method_exists($model, 'sumCaches')) {
                $this->info("($iteration/$count) Rebuilding $className($keyName=$key) sum caches");
                $sumCache = new SumCache($model);
                $sumCache->rebuild();
            }
        });

        $endTime = microtime(true);
        $executionTime = intval(($endTime - $startTime) * 1000);
        $this->info("Finished rebuilding $className caches in $executionTime milliseconds");
    }

}
