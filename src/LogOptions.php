<?php

namespace PicoInno\SimpleLog;

class LogOptions
{

    /**
     * Custom description callback.
     */
    public $logDescriptionCallback;

    /**
     * Whether to log timestamps.
     */
    public bool $logTimestamps = true;

    /**
     * Whether to log only dirty attributes.
     */
    public bool $logOnlyDirty = false;

    /**
     * Whether to log only fillable attributes.
     */
    public bool $logFillable = false;

    /**
     * Attributes to log.
     */
    public array $logAttributes = [];

    /**
     * Attributes to exclude from logging.
     */
    public array $logExceptAttributes = [];

    /**
     * Get a default instance of LogOptions.
     */
    public static function defaults(): static
    {
        return (new static)->logAll();
    }

    /**
     * Log all available columns.
     * Priority: 0
     */
    public function logAll(): static
    {
        $this->logAttributes = ['*'];

        return $this;
    }

    /**
     * Log only the given columns.
     * Priority: highest
     *
     * **Note:** If used with logExcept(), the columns listed here will be ignored.
     */
    public function logOnly(array|string $attributes): static
    {
        $this->logAttributes = is_array($attributes)
            ? $attributes
            : array_merge($this->logAttributes, [$attributes]);

        return $this;
    }

    /**
     * Exclude the given columns from logging.
     * Priority: highest
     */
    public function logExcept(array|string $attributes): static
    {
        $this->logExceptAttributes = is_array($attributes)
            ? $attributes
            : array_merge($this->logExceptAttributes, [$attributes]);

        return $this;
    }

    /**
     * Disable timestamp logging.
     * Priority: 1
     */
    public function dontLogTimestamps(): static
    {
        $this->logTimestamps = false;

        return $this;
    }

    /**
     * Enable logging of only dirty columns.
     * Priority: 3
     */
    public function logOnlyDirty(): static
    {
        $this->logOnlyDirty = true;

        return $this;
    }

    /**
     * Enable logging of only fillable columns.
     * Priority: 2
     */
    public function logFillable(): static
    {
        $this->logFillable = true;

        return $this;
    }

    // /**
    //  * Set a custom log name for logging.
    //  */
    // public function logName(string $name): static
    // {
    //     $this->logName = $name;

    //     return $this;
    // }

    /**
     * Set a custom description callback for logging.
     */
    public function logDescription(callable $callback): static
    {
        $this->logDescriptionCallback = $callback;

        return $this;
    }
}
