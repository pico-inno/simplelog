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

    | In order to log for every model using SimpleLog trait, this must be marked as true
    */
    'log_name' => env('DEFAULT_LOGNAME', 'default'),

];
