<?php namespace Jaulz\Hoard\Support;

use hanneskod\classtools\Iterator\ClassIterator;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

class FindHoardableClasses
{
  /**
   * @var null|string
   */
  private $directory;

  public function __construct($directory)
  {
    $this->directory = realpath($directory);
  }

  public function getClassNames()
  {
    $finder = new Finder();
    $iterator = new ClassIterator($finder->in($this->directory));
    $iterator->enableAutoloading();

    $classNames = [];

    foreach ($iterator->type(Model::class) as $className => $class) {
      if ($class->isInstantiable() && $this->usesHoard($class)) {
        $classNames[] = $className;
      }
    }

    return $classNames;
  }

  /**
   * Decide if the class uses the IsHoardableTrait.
   *
   * @param \ReflectionClass $class
   *
   * @return bool
   */
  private function usesHoard(\ReflectionClass $class)
  {
    return $class->hasMethod('bootIsHoardableTrait');
  }
}
