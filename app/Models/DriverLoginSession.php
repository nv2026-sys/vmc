<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLoginSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_identifier',
        'auth_type',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
        ];
    }
}
