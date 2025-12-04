<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ENABLE DEFAULT MODEL LOGGING
    |--------------------------------------------------------------------------

    | In order to log for every model using SimpleLog trait, this must be marked as true
    */
    'must_be_logged' => env('MUST_BE_LOGGED', true),

    /*
    |--------------------------------------------------------------------------
    | DEFAULT LOG NAME FOR LOGGGING
    |--------------------------------------------------------------------------

    | This log name will be used in case there is no name for activity() method on logging
    */
    'log_name' => env('DEFAULT_LOGNAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | ENABLE DB TRANSACTION & ROLLBACK WITH LOGGING
    |--------------------------------------------------------------------------

    | In order to use DB transaction & rollback on every log, this must be marked as true
    */
    'db_transaction_on_log' => env('DB_TRANSACTION_ON_LOG', false),


];
