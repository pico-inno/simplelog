<?php

namespace PicoInno\SimpleLog;

use Auth;
use Cache;
use DB;
use File;
use Illuminate\Database\Eloquent\Model;
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

    protected static $listenerRegistered = false;

    protected static $records = [];

    public static function getBatchUuid()
    {
        return self::$batch_uuid;
    }

    /**
     * Start manual transaction and listen for database queries.
     */
    public static function start(): void
    {

        self::$is_logging_batch = true;
        self::$batch_uuid = uniqid();
        // self::$records = [
        //     'create' => [],
        //     'update' => [],
        //     'delete' => [],
        // ];

        // if (! self::$listenerRegistered) {
        //     DB::listen(function ($query) {

        //         if (! self::$is_logging_batch) {
        //             return;
        //         }

        //         $sql = Str::upper(substr($query->sql, 0, 6));

        //         if (in_array($sql, ['INSERT', 'UPDATE', 'DELETE'])) {
        //             $key = match ($sql) {
        //                 'INSERT' => 'create',
        //                 'UPDATE' => 'update',
        //                 'DELETE' => 'delete',
        //                 default => 'others',
        //             };

        //             self::$records[$key][] = [
        //                 'sql' => $query->sql,
        //                 'bindings' => self::mapBindings($query),
        //                 'time' => $query->time,
        //                 'table' => self::getTableNameFromSQL($query->sql),
        //             ];
        //         }

        //     });

        //     self::$listenerRegistered = true;

        // }

        DB::beginTransaction();
    }

    /**
     * Commit manual transaction and log recorded actions.
     */
    public static function end(): void
    {
        DB::commit();

        // foreach (self::$records as $action => $datas) {
        //     foreach ($datas as $data) {
        //         $model = self::find_model_by_table($data['table']);
        //         $class = new $model;
        //         if (! $class->hasLog) {
        //             continue;
        //         }

        //         $logData = $class->getLogData();

        //         $logName = $class->logName ?? ($logData[$action]['logName'] ?? null);
        //         $logAction = $class->logAction ?? ($logData[$action]['logAction'] ?? null);
        //         $logProperties = $class->logProperties ?? ($logData[$action]['logProperties'] ?? null);
        //         $logDescription = $class->logDescription ?? ($logData[$action]['logDescription'] ?? null);

        //         $table = Str::singular($data['table']);
        //         ActivityLog::create([
        //             'log_name' => $logName ?? "{$action}_{$table}",
        //             'event' => $logAction ?? $action,
        //             'properties' => $logProperties ?? json_encode($data['bindings']),
        //             'description' => $logDescription ?? "The {$table} has been {$action}d",
        //             'status' => 'success',
        //             'batch_id' => self::$batch_uuid,
        //             'created_by' => Auth::id() ?? null,
        //         ]);
        //     }
        // }

        self::reset();
    }

    /**
     * Rollback manual transaction and log rollback entries.
     */
    public static function rollback(): void
    {
        DB::rollBack();

        foreach (self::$records as $action => $datas) {
            foreach ($datas as $data) {
                $table = Str::singular($data['table']);
                ActivityLog::create([
                    'log_name' => "{$action}_{$table}",
                    'event' => $action,
                    'properties' => json_encode($data['bindings']),
                    'description' => "The {$action} on {$table} was rolled back",
                    'status' => 'fail',
                    'created_by' => Auth::id() ?? null,
                ]);
            }
        }

        self::reset();
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
        $matches = [];
        $sqlLower = strtolower($sql);

        if (str_starts_with($sqlLower, 'insert')) {
            preg_match('/insert\s+into\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'update')) {
            preg_match('/update\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'delete')) {
            preg_match('/delete\s+from\s+["`]?(\w+)["`]*/i', $sql, $matches);
        }

        return $matches[1] ?? null;
    }

    /**
     * Find the model class name by its table name.
     *
     * @return string|null Fully qualified class name or null if not found
     */
    private static function find_model_by_table(string $table): ?string
    {
        $modelsPath = app_path('Models');
        $model_files = File::allFiles($modelsPath);

        $data = Cache::get('simple_log_total_models', ['total_models' => 0, 'data' => []]);
        $cachedTotal = $data['total_models'] ?? 0;

        if (count($model_files) != $cachedTotal) {

            $data['total_models'] = count($model_files);
            $data['data'] = [];

            self::get_model_cache($data, $table);

            Cache::put('simple_log_total_models', $data);
        }

        if (! isset($data['data'][$table])) {
            self::get_model_cache($data, $table);
        }

        return $data['data'][$table] ?? null;
    }

    /**
     * Summary of get_model_cache
     *
     * @param  mixed  $data
     * @param  mixed  $table
     * @return void
     */
    private static function get_model_cache(&$data, $table)
    {

        $modelsPath = app_path('Models');
        $model_files = File::allFiles($modelsPath);
        foreach ($model_files as $file) {
            $relativePath = str_replace([$modelsPath.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $modelInstance = new $class;
                $data['data'][$modelInstance->getTable()] = $class;
            }
        }

    }
}
