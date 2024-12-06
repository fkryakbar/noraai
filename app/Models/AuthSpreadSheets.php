<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthSpreadSheets extends Model
{
    protected $guarded = [];

    protected $casts = [
        'valid_until' => 'datetime',
    ];
}
