<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStat extends Model
{
    protected $fillable = [
        'user_id',
        'total_xp',
        'level',
        'current_streak',
        'longest_streak',
        'last_completed_date',
    ];

    protected $casts = [
        'total_xp' => 'integer',
        'level' => 'integer',
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'last_completed_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
