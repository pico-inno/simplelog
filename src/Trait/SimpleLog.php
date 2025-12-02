<?php

namespace PicoInno\SimpleLog\Trait;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PicoInno\SimpleLog\LogOptions;

trait SimpleLog
{
    /**
     * For storing old attributes value on update event
     *
     * @var bool
     */
    public $hasLog = true;

    /**
     * Get the default log options for the model.
     *
     * @return \PicoInno\SimpleLog\LogOptions
     */
    public function getLogOptions()
    {
        return LogOptions::defaults();
    }

    /**
     * Determine which model events should be logged.
     *
     * @return array<string>
     */
    protected function shouldLogEvents()
    {
        return ['created', 'updated', 'deleted'];
    }

    /**
     * Boot the SimpleLog trait and attach event listeners.
     *
     * @return void
     */
    protected static function bootSimpleLog()
    {
        if (!config('activity_log.must_be_logged')) {
            return;
        }

        foreach ((new static)->shouldLogEvents() as $event) {
            static::$event(function ($model) use ($event) {
                static::logActivity($model, $event);
            });
        }
    }

    /**
     * Handle logging of activity for a given model event.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $event
     * @return void
     *
     * @throws \Exception
     */
    protected static function logActivity($model, $event)
    {
        try {

            $logOptions = (new static)->getLogOptions();
            $logColumns = $logOptions->logAttributes;

            $allDbColumns = Schema::getColumnListing($model->getTable());
            $logExceptColumns = $logOptions->logExceptAttributes;

            $modelName = class_basename($model);

            $logDescription = $logOptions->logDescriptionCallback
                ? ($logOptions->logDescriptionCallback)($event)
                : "The {$modelName} has been {$event}";

            if (in_array('*', $logColumns)) {
                $logColumns = $allDbColumns;
            }

            if ($logOptions->logFillable) {
                $logColumns = array_unique(array_merge($logColumns, $model->getFillable()));
            }

            if ($logOptions->logOnlyDirty && $event === 'updated') {
                $logColumns = array_intersect($logColumns, array_keys($model->getDirty()));
            }

            if (!$logOptions->logTimestamps) {
                $logExceptColumns = array_merge($logExceptColumns, $model->getDates());
            }

            $loggingColumns = array_diff($logColumns, $logExceptColumns);

            if (empty($loggingColumns)) {
                return;
            }

            $newData = $model->getAttributes();
            $oldData = $model->getRawOriginal();

            $properties = [];
            foreach ($loggingColumns as $column) {
                $properties['data'][$column] = data_get($newData, $column);

                if ($event === 'updated') {
                    $properties['old'][$column] = data_get($oldData, $column);
                }
            }

            activity($model->getLogName())
                ->log($logDescription)
                ->properties($properties)
                ->event($event)
                ->status('success')
                ->save();
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Get the log name for the model.
     *
     * @return string
     */
    public function getLogName()
    {
        return $this->logName;
    }

    public function getFailureDescription($event): ?string
    {
        return null;
    }

    /**
     * Get the log description for the model activity.
     *
     * @param  string  $event
     * @return string
     */
    protected function getLogDescription($event)
    {
        $modelName = ucfirst(Str::singular($this->getTable()));

        switch ($event) {
            case 'created':
                return "{$modelName} record was created";
            case 'updated':
                return "{$modelName} record was updated";
            case 'deleted':
                return "{$modelName} record was deleted";
            default:
                return "{$modelName} record was {$event}";
        }
    }
}
