<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class UserCheckin extends Model
{
    protected $guarded = [];


    protected $casts = [
        'checkin_at' => 'datetime',
        'checkout_at' => 'datetime',
    ];

    /**
     * Get the total worked time in minutes
     */
    public function getWorkedTimeInMinutesAttribute()
    {
        if (!$this->checkout_at) {
            return 0;
        }

        return $this->checkin_at->diffInMinutes($this->checkout_at);
    }

    /**
     * Get worked time formatted as hours and minutes
     */
    public function getFormattedWorkedTimeAttribute()
    {
        $minutes = $this->worked_time_in_minutes;
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return "{$hours}h {$remainingMinutes}m";
    }

    /**
     * Check if user is currently checked in
     */
    public function isCheckedIn()
    {
        return is_null($this->checkout_at);
    }

    /**
     * Scope to get today's check-ins
     */
    public function scopeToday($query)
    {
        return $query->whereDate('checkin_at', Carbon::today());
    }

    /**
     * Scope to get active check-ins (not checked out)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('checkout_at');
    }

    /**
     * Scope to get check-ins for specific user
     */
    public function scopeForUser($query, $discordUserId)
    {
        return $query->where('discord_user_id', $discordUserId);
    }
}
