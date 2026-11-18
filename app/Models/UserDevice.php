<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = [
        'user_id',
        'fcm_token_hash',
        'fcm_token',
        'device_type',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'fcm_token' => 'encrypted',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
