<?php namespace Jaulz\Hoard\Support;

use hanneskod\classtools\Iterator\ClassIterator;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

class FindCacheableClasses
{
  /**
   * @var null|string
   */
  private $directory;

  public function __construct($directory)
  {
    $this->directory = realpath($directory);
  }

  public function getAllIsCacheableTraitClasses()
  {
    $finder = new Finder();
    $iterator = new ClassIterator($finder->in($this->directory));
    $iterator->enableAutoloading();

    $classes = [];

    foreach ($iterator->type(Model::class) as $className => $class) {
      if ($class->isInstantiable() && $this->usesCaching($class)) {
        $classes[] = $className;
      }
    }

    return $classes;
  }

  /**
   * Decide if the class uses any of the caching Traits.
   *
   * @param \ReflectionClass $class
   *
   * @return bool
   */
  private function usesCaching(\ReflectionClass $class)
  {
    return $class->hasMethod('bootIsCacheableTrait');
  }
}
