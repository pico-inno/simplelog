<?php

namespace PicoInno\SimpleLog\Helpers;

use PicoInno\SimpleLog\Models\ActivityLog;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityLogger
{
    protected $event;
    protected $description;
    protected $log_name;
    protected $status;
    protected $data;

    // Manual transaction state
    protected static $isLogging = false;
    protected static $records = [];
    protected static $batchId = 0;
    protected static $listenerRegistered = false;

    public function __construct($log_name = null)
    {
        $this->log_name = $log_name;
    }

    /**
     * Start manual transaction
     */
    public static function beginTransaction()
    {
        self::$isLogging = true;
        self::$batchId = (ActivityLog::max('batch_id') ?? 0) + 1;
        self::$records = [
            'create' => [],
            'update' => [],
            'delete' => [],
        ];

        if (!self::$listenerRegistered) {
            DB::listen(function ($query) {
                if (!self::$isLogging) return;

                $sql = Str::upper(substr($query->sql, 0, 6));
                if (in_array($sql, ['INSERT', 'UPDATE', 'DELETE'])) {
                    $key = match ($sql) {
                        'INSERT' => 'create',
                        'UPDATE' => 'update',
                        'DELETE' => 'delete',
                        default => 'others',
                    };

                    self::$records[$key][] = [
                        'sql' => $query->sql,
                        'bindings' => self::mapBindings($query),
                        'time' => $query->time,
                        'table' => self::getTableNameFromSQL($query->sql),
                    ];
                }
            });
            self::$listenerRegistered = true;
        }

        DB::beginTransaction();
    }

    /**
     * Commit manual transaction
     */
    public static function commit()
    {
        DB::commit();

        foreach (self::$records as $action => $datas) {
            foreach ($datas as $data) {
                $table = Str::singular($data['table']);
                ActivityLog::create([
                    'log_name'    => "{$action}_{$table}",
                    'event'       => $action,
                    'properties'  => json_encode($data['bindings']),
                    'description' => "The {$table} has been {$action}d",
                    'status'      => 'success',
                    'batch_id'    => self::$batchId,
                    'created_by'  => Auth::id() ?? null,
                ]);
            }
        }

        self::reset();
    }

    /**
     * Rollback manual transaction
     */
    public static function rollback()
    {
        DB::rollBack();

        foreach (self::$records as $action => $datas) {
            foreach ($datas as $data) {
                $table = Str::singular($data['table']);
                ActivityLog::create([
                    'log_name'    => "{$action}_{$table}",
                    'event'       => $action,
                    'properties'  => json_encode($data['bindings']),
                    'description' => "The {$action} on {$table} was rolled back",
                    'status'      => 'fail',
                    'batch_id'    => self::$batchId,
                    'created_by'  => Auth::id() ?? null,
                ]);
            }
        }

        self::reset();
    }

    /**
     * Closure style transaction (wraps manual methods)
     */
    public static function transaction(Closure $callback)
    {
        try {
            self::beginTransaction();

            $result = $callback();

            self::commit();

            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * Reset state
     */
    protected static function reset()
    {
        self::$isLogging = false;
        self::$records = [];
        self::$batchId = 0;
    }

    /**
     * Convert bindings
     */
    protected static function mapBindings($query)
    {
        return collect($query->bindings)->map(function ($binding) {
            if ($binding instanceof \DateTime) {
                return $binding->format('Y-m-d H:i:s');
            }
            return $binding;
        })->toArray();
    }

    /**
     * Extract table name from SQL
     */
    private static function getTableNameFromSQL(string $sql): ?string
    {
        $matches = []; // always initialize
        $sqlLower = strtolower($sql); // convert to lowercase for comparison

        if (str_starts_with($sqlLower, 'insert')) {
            preg_match('/insert\s+into\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'update')) {
            preg_match('/update\s+["`]?(\w+)["`]*/i', $sql, $matches);
        } elseif (str_starts_with($sqlLower, 'delete')) {
            preg_match('/delete\s+from\s+["`]?(\w+)["`]*/i', $sql, $matches);
        }

        return $matches[1] ?? null;
    }
}
