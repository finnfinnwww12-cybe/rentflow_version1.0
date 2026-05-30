<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MaintenanceRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'room_id', 'title', 'description', 'priority', 
        'status', 'cost', 'reported_by', 'reported_date', 'completed_date', 'notes',
        'user_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $casts = [
        'reported_date' => 'date',
        'completed_date' => 'date',
        'cost' => 'decimal:2',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function expense()
    {
        return $this->hasOne(Expense::class);
    }
}
