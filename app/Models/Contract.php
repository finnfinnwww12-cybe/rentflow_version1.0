<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Contract extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'room_id', 'start_date', 'end_date', 
        'rent_amount', 'billing_cycle', 'status', 'terms', 'user_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'rent_amount' => 'decimal:2',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Calculate total lease value (dynamic based on billing cycle)
     */
    public function calculateTotalRent(): float
    {
        $start = \Carbon\Carbon::parse($this->start_date);
        $end = \Carbon\Carbon::parse($this->end_date);

        if ($this->billing_cycle === 'daily') {
            $days = max(1, $start->diffInDays($end));
            return $days * $this->rent_amount;
        }

        $months = max(1, $start->diffInMonths($end));
        return $months * $this->rent_amount;
    }

    /**
     * Get dynamic stay duration in days (check-in to check-out)
     */
    public function getDurationInDays(): int
    {
        $start = \Carbon\Carbon::parse($this->start_date);
        $end = \Carbon\Carbon::parse($this->end_date);
        return max(1, $start->diffInDays($end));
    }
}
