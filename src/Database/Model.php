<?php
namespace Jaulz\Eloquence\Database;

use Jaulz\Eloquence\Behaviours\CamelCasing;
use Jaulz\Eloquence\Behaviours\Uuid;

/**
 * Class Model
 *
 * Have your models extend the model class to include the below traits.
 *
 * @package Jaulz\Eloquence\Database
 */
abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    use CamelCasing;
    use Uuid;
}
