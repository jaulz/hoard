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
        $this->info("Rebuilding $count $className caches");
        $models->each(function ($model, $index) use ($className) {
            $iteration = $index + 1;
            $keyName = $model->getKeyName();
            $key = $model->getKey();

            if (method_exists($model, 'countCaches')) {
                $this->info("($iteration) Rebuilding $keyName=$key count caches");
                $countCache = new CountCache($model);
                $countCache->rebuild();
            }

            if (method_exists($model, 'sumCaches')) {
                $this->info("($iteration) Rebuilding $keyName=$key sum caches");
                $sumCache = new SumCache($model);
                $sumCache->rebuild();
            }
        });
        $this->info("Rebuilt $className caches");
    }

}
