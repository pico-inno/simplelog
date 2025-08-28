<?php

namespace PicoInno\SimpleLog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'log_name',
        'description',
        'event',
        'status',
        'properties',
        'batch_id',
        'created_by',
        'updated_by'
    ];

}
