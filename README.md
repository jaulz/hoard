# Hoard

![Version](https://img.shields.io/packagist/v/jaulz/hoard.svg)
![Downloads](https://img.shields.io/packagist/dt/jaulz/hoard.svg)
![Status](https://img.shields.io/travis/jaulz/hoard/master.svg)

Hoard is a package to extend Laravel's base Eloquent models and functionality.

It provides a number of utilities and classes to work with Eloquent in new and useful ways, 
such as camel cased attributes (for JSON apis), count caching, uuids and more.

## Installation

Install the package via composer:

    composer require jaulz/hoard:~5.0

## Usage

First, add the hoard service provider to your config/app.php file:

    'Jaulz\Hoard\HoardServiceProvider',

It's important to note that this will automatically re-bind the Model class
that Eloquent uses for many-to-many relationships. This is necessary because
when the Pivot model is instantiated, we need it to utilise the parent model's
information and traits that may be needed.

You should now be good to go with your models.

### Note!

Hoard DOES NOT CHANGE how you write your schema migrations. You should still be using snake_case 
when setting up your fields and tables in your database schema migrations. This is a good thing - 
snake_case of field names is the defacto standard within the Laravel community :)


## UUIDs

Hoard comes bundled with UUID capabilities that you can use in your models.

Simply include the Uuid trait:

    use Jaulz\Hoard\Traits\Uuid;

And then disable auto incrementing ids:

    public $incrementing = false;

This will turn off id auto-incrementing in your model, and instead automatically generate a UUID4 value for your id field. One 
benefit of this is that you can actually know the id of your record BEFORE it's saved!

You must ensure that your id column is setup to handle UUID values. This can be done by creating a migration with the following 
properties:

    $table->char('id', $length = 36)->index();

It's important to note that you should do your research before using UUID functionality and whether it works for you. UUID 
field searches are much slower than indexed integer fields (such as autoincrement id fields).


### Custom UUIDs

Should you need a custom UUID solution (aka, maybe you don't want to use a UUID4 id), you can simply define the value you wish on 
the id field. The UUID model trait will not set the id if it has already been defined. In this use-case however, it's probably no good
to use the Uuid trait, as it's practically useless in this scenario.

## Traits

Hoard comes with a system for setting up Traits, which are really just small libraries that you can use with your Eloquent models.
The first of these is the count cache.

### Cache

Caching is where you cache the result of an aggregation of a related table's records. A simple example of this is where you have a user who
has many posts. In this example, you may want to count the number of posts a user has regularly - and perhaps even order by this. In SQL,
ordering by a counted field is slow and unable to be indexed. You can get around this by caching the count of the posts the user
has created on the user's record.

To get this working, you need to do two steps:

1. Use the IsHoardableTrait trait on the model and 
2. Configure the cache settings

#### Configure the cache

To setup the cache configuration, simply do the following:

```php
class Item extends Eloquent {
    use Summable;
    
    public function caches() {
        return [Order::class];
    }
}
```

This tells the cache manager that the Item model has a cache on the Order model. So, whenever an item is added, modified, or
deleted, the cache behaviour will update the appropriate order's cache for their items. In this case, it would update `item_total`
on the Order model.

```php
class Item extends Eloquent {
    use IsHoardableTrait;
    
    public function caches() {
        return [
            [
                'function'    => 'sum',
                'summaryName'     => 'total',
                'model'       => 'Order',
                'field'       => 'item_total'
                'foreignKey'  => 'order_id',
                'key'         => 'id',
                'where'       => [ 'billable' => true ]
            ]
        ];
    }
}
```

The example above uses the following conventions:

* `function` is the aggregation function
* `summary` is the field that will contain the aggregated values
* `item_total` is a defined field on the Order model table
* `total` is a defined field on the Item model table (the column we are summing) 
* `order_id` is the field representing the foreign key on the item model
* `key` is the primary key on the order model table
* `where` is an array of conditions that will be applied to the aggregation

With these settings configured, you will now see the related model's cache updated every time an item is added, updated, or removed.

#### Rebuild command

You can rebuild the cache via the following command at any time:
```
php artisan caches:rebuild
```

In case you face an memory exception it might be related to Telescope and you should consider to add the command to the `ignore_command` config:
```php
    'ignore_commands' => [
        'caches:rebuild'
    ],
```

## Changelog

#### 8.0.0

* Boost in version number to match Laravel
* Support for Laravel 7.3+

* Fixes a bug that resulted with the new guarded attributes logic in eloquent

#### 4.0.1

* Fixes a bug that resulted with the new guarded attributes logic in eloquent

#### 4.0.0

* Laravel 7 support (thanks, @msiemens!)

#### 3.0.0

* Laravel 6 support
* Better slug creation and handling

#### 2.0.7

* Slug uniqueness check upon slug creation for id-based slugs.

#### 2.0.6

* Bug fix when restoring models that was resulting in incorrect count cache values.

#### 2.0.3

* Slugs now implement Jsonable, making them easier to handle in API responses
* New artisan command for rebuilding caches (beta, use at own risk)

#### 2.0.2

* Updated PHP dependency to 5.6+
* CountCache and SumCache Traits now supported via a service layer

#### 2.0.0

* Sum cache model behaviour added
* Booting of Traits now done via Laravel trait booting
* Simplification of all Traits and their uses
* Updated readme/configuration guide

#### 1.4.0

* Slugs when retrieved from a model now return Slug value objects.

#### 1.3.4

* More random, less predictable slugs for id strategies

#### 1.3.3

* Fixed a bug with relationships not being accessible via model properties

#### 1.3.2

* Slugged behaviour
* Fix for fillable attributes

#### 1.3.1

* Relationship fixes
* Fillable attributes bug fix
* Count cache update for changing relationships fix
* Small update for implementing count cache observer

#### 1.3.0

* Count cache model behaviour added
* Many-many relationship casing fix
* Fixed an issue when using ::create

#### 1.2.0

* Laravel 5 support
* Readme updates

#### 1.1.5

* UUID model trait now supports custom UUIDs (instead of only generating them for you)

#### 1.1.4

* UUID fix

#### 1.1.3

* Removed the schema binding on the service provider

#### 1.1.2

* Removed the uuid column creation via custom blueprint

#### 1.1.1

* Dependency bug fix

#### 1.1.0

* UUIDModel trait added
* CamelCaseModel trait added
* Model class updated to use CamelCaseModel trait - deprecated, backwards-compatibility support only
* Hoard now its own namespace (breaking change)
* HoardServiceProvider added use this if you want to overload the base model automatically (required for pivot model camel casing).

#### 1.0.2

* Relationships now support camelCasing for retrieval (thanks @linxgws)

#### 1.0.1

* Fixed an issue with dependency resolution

#### 1.0.0

* Initial implementation
* Camel casing of model attributes now available for both setters and getters

## License

The Laravel framework is open-sourced software licensed under the MIT license.
