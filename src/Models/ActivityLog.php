<?php

namespace PicoInno\SimpleLog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    public $guarded = [];


    protected $casts = [
        'properties' => 'collection'
    ];
}
