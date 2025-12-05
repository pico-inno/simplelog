<?php

namespace PicoInno\SimpleLog;

use Auth;
use Cache;
use DB;
use Exception;
use File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PicoInno\SimpleLog\Models\ActivityLog;

class LogBatch
{
    /**
     * uuid generate for batch
     */
    private static ?string $batch_uuid;

    /**
     * checking if it is logging batch
     */
    public static bool $is_logging_batch = false;

    /**
     * a logname set for batch logging
     * @var string
     */
    public static ?string $inline_logname = null;

    protected static $listenerRegistered = false;

    protected static $records = [];

    public static function getBatchUuid()
    {
        return self::$batch_uuid;
    }

    /**
     * Start manual transaction and listen for database queries.
     */
    public static function start($inline_logname = null): void
    {

        self::$inline_logname = $inline_logname;

        self::$is_logging_batch = true;
        self::$batch_uuid = uniqid();

        self::$records = [];

        if (!self::$listenerRegistered) {
            DB::listen(function ($query) {

                if (!self::$is_logging_batch) {
                    return;
                }

                $sql = Str::upper(substr($query->sql, 0, 6));

                if (in_array($sql, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])) {
                    $event = match ($sql) {
                        'INSERT' => 'create',
                        'UPDATE' => 'update',
                        'DELETE' => 'delete',
                        default => 'others',
                    };

                    self::$records[] = [
                        'sql' => $query->sql,
                        'bindings' => self::mapBindings($query),
                        'time' => $query->time,
                        'table' => self::getTableNameFromSQL($query->sql),
                        'event' => $event,
                    ];
                }

            });

            self::$listenerRegistered = true;

        }

        if (config('activity_log.db_transaction_on_log')) {
            DB::beginTransaction();
        }
    }

    /**
     * Commit manual transaction and log recorded actions.
     */
    public static function end(): void
    {
        if (config('activity_log.db_transaction_on_log')) {
            DB::commit();
        }

        self::reset();
    }

    /**
     * Rollback manual transaction and log rollback entries.
     */
    public static function rollback(Exception|string|null $message = null, $use_exception_message = false): void
    {
        if (config('activity_log.db_transaction_on_log')) {
            DB::rollBack();
        }

        $has_valid_logging = true;
        $table_name = null;
        $event = 'unknown';
        $properties = [];
        $model = '';

        if ($message instanceof QueryException) {
            $table_name = self::getTableNameFromSQL($message->getSql());
            $sql = strtolower(substr($message->getSql(), 0, 6));
            $event = match ($sql) {
                'insert' => 'create',
                'update' => 'update',
                'delete' => 'delete',
                default => 'others',
            };
            $model = self::get_model($table_name);

            $properties = [
                'data' => $message->getBindings()
            ];

            $message = $use_exception_message ? $message->getMessage() : null;
        } else {
            if (!count(self::$records)) {
                $has_valid_logging = false;
            } else {

                $lastRecord = collect(self::$records)->last();
                $table_name = $lastRecord ? self::getTableNameFromSQL($lastRecord['sql']) : null;
                $event = $lastRecord['event'] ?? 'unknown';
                $properties = [
                    'data' => $lastRecord['bindings'] ?? [],
                ];
                $model = self::get_model($table_name);
                $has_valid_logging = method_exists($model, 'getFailureDescription');
            }
        }

        # create model from table name
        $event_text = in_array($event, ['create', 'update', 'delete']) ? $event . 'd' : $event;

        if ($has_valid_logging) {

            $activityLog = app(ActivityLog::class);
            $activityLog->created_by = Auth::id();

            activity(self::$inline_logname ?? $model->getLogName())
                ->log($message ?? $model->getFailureDescription($event) ?? "The {$event} of {$model->getTable()} has failed!")
                ->properties($properties)
                ->event($event_text)
                ->status('fail')
                ->save();
        } else {
            activity(self::$inline_logname)
                ->log($message ?? "Something has been wrong with the table $table_name")
                ->properties($properties)
                ->event($event_text)
                ->status('fail')
                ->save();
        }

        self::reset();
    }

    /**
     * Log on database transaction callback
     */
    public static function transaction(callable $callback, $inline_logname = null)
    {
        try {

            LogBatch::start($inline_logname);
            $callback();
            LogBatch::end();
        } catch (\Throwable $e) {
            LogBatch::rollback();
        }
    }

    /**
     * Convert query bindings to a usable format.
     *
     * @param  object  $query
     */
    protected static function mapBindings($query): array
    {
        return collect($query->bindings)->map(function ($binding) {
            if ($binding instanceof \DateTime) {
                return $binding->format('Y-m-d H:i:s');
            }

            return $binding;
        })->toArray();
    }

    /**
     * Reset internal transaction state.
     */
    protected static function reset(): void
    {
        self::$is_logging_batch = false;
        self::$records = [];
        // self::$batchId = 0;
    }

    /**
     * Extract table name from SQL statement.
     */
    private static function getTableNameFromSQL(string $sql): ?string
    {
        if (!$sql)
            return null;
        $matches = [];
        $sqlLower = strtolower($sql);

        if (str_starts_with($sqlLower, 'insert')) {
            preg_match('/insert\s+into\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'update')) {
            preg_match('/update\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'delete')) {
            preg_match('/delete\s+from\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'select')) {
            preg_match('/from\s+["`]?(\w+)["`]*/i', $sql, $matches);
        }

        return $matches[1] ?? null;
    }

    /**
     * Find the model class by its table name.
     *
     * @return Model
     */
    private static function get_model(string $table)
    {
        $model_name = self::get_model_cache($table);
        return app($model_name);

    }

    /**
     * Summary of get_model_cache
     *
     * @param  mixed  $data
     * @param  mixed  $table
     * @return ?string
     */
    private static function get_model_cache($table)
    {

        $modelsPath = app_path('Models');
        $model_files = File::allFiles($modelsPath);
        foreach ($model_files as $file) {
            $relativePath = str_replace([$modelsPath . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Models\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $modelInstance = new $class;
                if ($modelInstance->getTable() === $table) {
                    return $class;
                }
            }
        }

        return null;

    }


}
