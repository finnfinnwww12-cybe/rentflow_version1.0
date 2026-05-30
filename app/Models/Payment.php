<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'room_id', 'contract_id', 'amount', 'late_fee', 'utility_amount',
        'due_date', 'paid_date', 'status', 'payment_method',
        'month', 'invoice_type', 'billing_period_start', 'billing_period_end',
        'notes', 'receipt_number', 'invoice_number', 'auto_generated', 'user_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $casts = [
        'amount'               => 'decimal:2',
        'late_fee'             => 'decimal:2',
        'utility_amount'       => 'decimal:2',
        'due_date'             => 'date',
        'paid_date'            => 'date',
        'billing_period_start' => 'date',
        'billing_period_end'   => 'date',
        'auto_generated'       => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Generate clean, professional sequential invoice numbers
     * Format: INV-YYYY-000001
     */
    public static function generateInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $count = self::whereNotNull('invoice_number')
            ->where('invoice_number', 'like', "INV-{$year}-%")
            ->count();
        return sprintf('INV-%s-%06d', $year, $count + 1);
    }

    /**
     * Get total amount due (rent + late fee + utility)
     */
    public function getTotalAttribute(): float
    {
        return round((float)$this->amount + (float)$this->late_fee + (float)$this->utility_amount, 2);
    }
}
