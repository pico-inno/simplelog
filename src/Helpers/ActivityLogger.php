<?php

namespace PicoInno\SimpleLog\Helpers;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PicoInno\SimpleLog\LogBatch;
use PicoInno\SimpleLog\Models\ActivityLog;

/**
 * Class ActivityLogger
 *
 * Handles logging of model events and actions into the activity log.
 * Provides methods to set log attributes, save new logs, update existing ones,
 * query logs, and perform log maintenance.
 */
class ActivityLogger
{
    /**
     * The underlying activity log model instance.
     */
    protected ActivityLog $activityLog;

    /**
     * Arbitrary data for log properties.
     *
     * @var array|object|null
     */
    protected $propertiesData;

    /**
     * ActivityLogger constructor.
     */
    public function __construct()
    {
        $this->activityLog = app(ActivityLog::class);
        $this->activityLog->created_by = Auth::id();
    }

    /**
     * Get the default log name from the configuration.
     */
    public function getDefaultLogName(): string
    {
        return config('activity_log.log_name', 'default');
    }

    /**
     * Set the log name for this activity.
     *
     * @param  string  $name  The log name.
     * @return $this
     */
    public function name($name = null): self
    {
        $this->activityLog->log_name = $name ?? $this->getDefaultLogName();

        return $this;
    }

    /**
     * Set the log description.
     *
     * @param  string  $description  A human-readable description of the activity.
     * @return $this
     */
    public function log(string $description): self
    {
        $this->activityLog->description = $description;

        return $this;
    }

    /**
     * Set the event type for the activity.
     *
     * @param  string  $event  The event type (e.g., created, updated, deleted, restored, login, logout, import, export, upload, download).
     * @return $this
     */
    public function event(string $event): self
    {
        $this->activityLog->event = $event;

        return $this;
    }

    /**
     * Set the status of the operation.
     *
     * @param  string  $status  The status of the operation (e.g., success, warn, fail).
     * @return $this
     */
    public function status(string $status): self
    {
        $this->activityLog->status = $status;

        return $this;
    }

    /**
     * Set the properties data for the log.
     *
     * @param  array|object  $data  Arbitrary properties associated with the log.
     * @return $this
     */
    public function properties(array|object $data): self
    {
        $this->activityLog->properties = $data;

        return $this;
    }

    /**
     * Automatically detect the event type based on the calling function name.
     * Supports: store, update, destroy.
     *
     * @return $this
     */
    public function autoDetectEvent(): self
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

    /**
     * Save the activity log entry to the database.
     */
    public function save(): void
    {
        if (LogBatch::$is_logging_batch) {
            $this->activityLog->batch_id = LogBatch::getBatchUuid();
        }

        $this->activityLog->save();
    }

    /**
     * Update an existing activity log entry.
     *
     * @param  int  $id  The ID of the activity log entry to update.
     */
    public function updateLog(int $id): void
    {
        $activityLog = ActivityLog::findOrFail($id);

        $activityLog->update([
            'log_name' => $this->activityLog->log_name ?? $activityLog->log_name,
            'description' => $this->activityLog->description ?? $activityLog->description,
            'event' => $this->activityLog->event ?? $activityLog->event,
            'status' => $this->activityLog->status ?? $activityLog->status,
            'properties' => $this->propertiesData ? json_encode($this->propertiesData) : $activityLog->properties,
            'created_by' => Auth::id() ?? $activityLog->created_by,
        ]);
    }

    /**
     * Retrieve all activity log entries.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \PicoInno\SimpleLog\Models\ActivityLog>
     */
    public function all(): Collection
    {
        $query = ActivityLog::query();

        if ($this->activityLog->log_name !== null) {
            $query->where('log_name', $this->activityLog->log_name);
        }

        return $query->get();
    }

    /**
     * Retrieve the most recent activity log entry.
     *
     * @return \Illuminate\Database\Eloquent\Model|\PicoInno\SimpleLog\Models\ActivityLog|null
     */
    public function last(): ?Model
    {
        $query = ActivityLog::latest();

        if ($this->activityLog->log_name !== null) {
            $query->where('log_name', $this->activityLog->log_name);
        }

        return $query->first();
    }

    /**
     * Purge old activity logs by calling the Artisan command.
     *
     * @return int The exit code returned by the Artisan command.
     */
    public function purgeOldLogs(): int
    {
        return Artisan::call('simplelog:purge');
    }

    /**
     * Execute a closure within a database transaction.
     *
     * @template T
     *
     * @param  \Closure():T  $callback  The callback to execute within the transaction.
     * @return T
     *
     * @throws \Throwable
     */
    public static function transaction(Closure $callback)
    {
        try {
            DB::beginTransaction();

            $result = $callback();

            DB::commit();

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
