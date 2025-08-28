<?php

namespace PicoInno\SimpleLog\Helpers;

use Closure;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PicoInno\SimpleLog\Models\ActivityLog;
use Illuminate\Support\Str;


class ActivityLogger
{
    protected $event;
    protected $description;
    protected $log_name;
    protected $status;
    protected $data;

    public function __construct($log_name = null)
    {
        $this->log_name = $log_name;
    }

    public function transaction(Closure $callback)
    {
        $batchId = ActivityLog::query()->latest()->first()->batch_id + 1 ?? 1;

        # records
        $records = [
            'create' => [],
            'update' => [],
            'delete' => [],
        ];

        $listener = function ($query) use (&$records) {
            $sql = Str::upper(substr($query->sql, 0, 6));

            if (in_array($sql, ['INSERT', 'UPDATE', 'DELETE'])) {
                $key = match ($sql) {
                    'INSERT' => 'create',
                    'UPDATE' => 'update',
                    'DELETE' => 'delete',
                    default => 'others'
                };

                $records[$key][] = [
                    'sql'      => $query->sql,
                    'bindings' => $this->mapBindings($query),
                    'time'     => $query->time,
                    'table'    => $this->getTableNameFromSQL($query->sql)
                ];
            } else {
                $records['fail'][] = $sql;
            }
        };

        DB::listen($listener);
        $isSuccessful = false;
        try {
            $callback(); // run user code

            $isSuccessful = true;
        } catch (Exception $e) {
            if (!empty($records)) {
                $sql = $e->getSql();

                if (str_starts_with(strtoupper($sql), 'INSERT')) {
                    $action = 'create';
                } elseif (str_starts_with(strtoupper($sql), 'UPDATE')) {
                    $action = 'update';
                } elseif (str_starts_with(strtoupper($sql), 'DELETE')) {
                    $action = 'delete';
                } else {
                    $action = 'other';
                }

                $table = Str::singular($this->getTableNameFromSQL($sql));
                ActivityLog::create([
                    'log_name'    => "{$action}_{$table}",
                    'event'       => "",
                    'properties'  => $e->getMessage(),
                    'description' => "The $action of $table failed!",
                    'status'      => 'fail',
                    'batch_id'    => $batchId,
                    'created_by'  => auth()->id() ?? null
                ]);
            }
        } finally {
            if ($isSuccessful) {
                unset($records['success']);
                foreach ($records as $action => $datas) {
                    foreach ($datas as $data) {
                        $table = Str::singular($data['table']);
                        ActivityLog::create([
                            'log_name'    => "{$action}_{$table}",
                            'event'       => $action,
                            'properties'  => json_encode($data['bindings']),
                            'description' => "The {$table} has been {$action}d",
                            'status'      => 'success',
                            'batch_id'    => $batchId,
                            'created_by'  => auth()->id() ?? null
                        ]);
                    }
                }
            }
        }
    }

    public function ifFails($log_name, $description, $batchId) {}

    protected function mapBindings($query)
    {
        $sql = $query->sql;
        $bindings = $query->bindings;
        $connection = env('DB_CONNECTION', 'mysql'); // detect DB type

        // Default: fallback returns plain bindings
        $mapped = [];

        $action = strtoupper(substr($sql, 0, 6));

        switch ($action) {
            case 'INSERT':
                // Match columns inside ()
                if (preg_match('/\((.*?)\)/', $sql, $matches)) {
                    $columns = array_map(function ($col) use ($connection) {
                        // Remove quotes/backticks/brackets depending on DB
                        return trim($col, " `\"[]");
                    }, explode(',', $matches[1]));

                    $mapped = array_combine($columns, $bindings);

                    // Remove default timestamps
                    unset($mapped['created_at'], $mapped['updated_at']);
                }
                break;

            case 'UPDATE':
                if (str_starts_with(strtoupper($sql), 'UPDATE')) {
                    // Extract everything between SET and WHERE (or end of string)
                    if (preg_match('/SET\s+(.*?)(\s+WHERE\s+.*|$)/i', $sql, $matches)) {
                        $setParts = explode(',', $matches[1]); // ["\"name\" = ?", "\"updated_at\" = ?"]

                        $columns = array_map(function ($part) {
                            [$col] = explode('=', $part, 2);     // take left side of '='
                            return trim($col, " `\"[]");         // remove quotes/backticks/brackets
                        }, $setParts);

                        // Map only SET bindings (ignore WHERE bindings)
                        $mapped = array_combine($columns, array_slice($query->bindings, 0, count($columns)));

                        // Remove updated_at automatically
                        unset($mapped['updated_at']);

                        // $mapped now = ['name' => 'New Category Name']
                    }
                }
                break;

            case 'DELETE':
                // Usually only WHERE bindings
                // Could be: DELETE FROM `users` WHERE `id` = ?
                // No column names to map, store as _where bindings
                $mapped = ['_where' => $bindings];
                break;

            default:
                // fallback
                $mapped = $bindings;
                break;
        }

        return $mapped;
    }

    function getTableNameFromSQL(string $sql): ?string
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






    /**
     * Get the default log name from the configuration.
     *
     * @return string
     */
    public static function getDefaultLogName()
    {
        return Config::get('log.log_name', 'default');
    }

    public function log($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Set the event type.
     *
     * @param string $event The event type (create, update, delete, restore, login, logout, 'import', 'export', 'upload','download').
     * @return $this
     */
    public function event($event)
    {
        $this->event = $event;
        return $this;
    }

    /**
     * Set the status of the operation.
     *
     * @param string $status The status of the operation (success, warn, fail).
     * @return $this
     */
    public function status($status)
    {
        $this->status = $status;
        return $this;
    }

    public function properties($data)
    {
        $this->data = $data;
        return $this;
    }

    public function autoEvent()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        if (isset($trace[1]['function'])) {
            $methodName = $trace[1]['function'];

            if (in_array($methodName, ['store', 'update', 'destroy'])) {
                $this->event($methodName);
            }
        }

        return $this;
    }

    public function save()
    {
        return ActivityLog::create([
            'log_name' => $this->log_name ?: static::getDefaultLogName(),
            'description' => $this->description,
            'event' => $this->event,
            'status' => $this->status,
            'properties' => json_encode($this->data),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
    }

    public function update($id)
    {
        $activityLog = ActivityLog::findOrFail($id);

        $activityLog->update([
            'log_name' => $this->log_name ?? $activityLog->log_name,
            'description' => $this->description ?? $activityLog->description,
            'event' => $this->event ?? $activityLog->event,
            'status' => $this->status ?? $activityLog->status,
            'properties' => $this->data ? json_encode($this->data) : $activityLog->properties,
            'created_by' => Auth::id() ?? $activityLog->created_by,
            'updated_by' => Auth::id() ?? $activityLog->updated_by,
        ]);
    }


    /**
     * Retrieve all log entries.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        $query = ActivityLog::query();

        if ($this->log_name !== null) {
            $query->where('log_name', $this->log_name);
        }

        return $query->get();
    }

    /**
     * Retrieve the last log entry.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function last()
    {
        $query = ActivityLog::latest();

        if ($this->log_name !== null) {
            $query->where('log_name', $this->log_name);
        }

        return $query->first();
    }


    /**
     * Purge old activity logs.
     *
     * @return int The exit code returned by the Artisan command
     */
    public function purgeOldLogs()
    {
        // Call the 'simplelog:purge' command using Artisan
        return Artisan::call('simplelog:purge');
    }
}
