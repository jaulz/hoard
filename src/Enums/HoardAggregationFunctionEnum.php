<?php

namespace Jaulz\Hoard\Enums;

use \Spatie\Enum\Enum;
use Illuminate\Support\Str;

/**
 * @method static self sum()
 * @method static self count()
 * @method static self maximum()
 * @method static self minimum()
 * @method static self copy()
 * @method static self push()
 * @method static self group()
 */
class HoardAggregationFunctionEnum extends Enum
{
    protected static function values()
    {
      return function (string $name): string {
        return Str::upper($name);
      };
    }
}