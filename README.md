# Pico Inno Simple Log

## Usage

Use the trait **SimpleLog** trait in the model to start logging. You must add a method **getLogOptions** in order to config the setting for logging for current model.

```php
use PicoInno\SimpleLog\Trait\SimpleLog;

class ExampleClass extends Model {

    use SimpleLog;

    public function getLogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }

}

```


## 📦 Installation

Install the package via Composer:

```bash
composer require pico-inno/simplelog
```


## ⚙️ Publish Config and Migrations

After installing, publish the configuration file and migrations:

```
php artisan vendor:publish --provider="PicoInno\SimpleLog\SimpleLogServiceProvider" --tag=config

php artisan vendor:publish --provider="PicoInno\SimpleLog\SimpleLogServiceProvider" --tag=migrations
```


This will publish: config/activity_log.php

## 🗄️ Run the Migrations

Apply the database changes:
```
php artisan migrate
```

---
## Log Options

The LogOptions class defines how logging should behave for a given model.

### logAll()

Logs all available attributes of the model.

~ Priority - 0

```php
public function logAll(): LogOptions;
```

### logOnly()

Specifies which attributes should be logged.

~ Priority - highest

```php
public function logOnly(array|string $attributes): LogOptions;
```

### logExcept()

Excludes specific attributes from being logged.
If used together with ***logOnly()***, this will explicitly remove the specified attributes from the logging list.

~ Priority - highest

```php
public function logExcept(array|string $attributes): LogOptions;
```

### dontLogTimestamps()

Disables logging of the default timestamp columns (created_at and updated_at).
This is useful if you do not want timestamp changes to appear in the activity log.

~ Priority: 1

```php
public function dontLogTimestamps(): LogOptions;
```

### logOnlyDirty()

Enables logging of only the attributes that have changed (dirty attributes) during a model update.
This helps reduce unnecessary log entries by excluding unchanged attributes.

~ Priority: 3

```php
public function logOnlyDirty(): LogOptions;
```

### logFillable()

Restricts logging to only the attributes defined in the model’s $fillable property.
This is helpful when you want to ensure that only mass-assignable attributes are logged.

~ Priority: 2


```php
public function logFillable(): LogOptions;
```

# Logging with Batch

**LogBatch** class can be used for logging the pile of model which use **SimpleLog** trait with a uuid batch id.

```php
function example()
{

    LogBatch::start();

    ExampleClass::create([ // will log for 'created' event
        'foo' => 'bar'
    ]);

    ExampleClassTwo::create([ // will not log if not use 'SimpleLog' trait
        'bar' => 'foo'
    ]);

    LogBatch::end();
}
```

**Note:** **LogBatch** start and end methods can be used with database transaction. It is done by env value or config.

```php

DB_TRANSACTION_ON_LOG=true

// or

'db_transaction_on_log' => env('DB_TRANSACTION_ON_LOG', false),

```

# Use of Logging with DB transaction on

```php
try{

    LogBatch::start();

    ExampleClass::create([ // will log for 'created' event
        'foo' => 'bar'
    ]);

    ExampleClassTwo::create([ // will not log if not use 'SimpleLog' trait
        'bar' => 'foo'
    ]);

    LogBatch::end();
}catch(\Exception $e){
    
    LogBatch::rollback();
    
}

```
