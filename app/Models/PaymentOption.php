<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PaymentOption extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'payment_type',
        'payment_method_name',
        'bank_name',
        'account_name',
        'account_number',
        'currency',
        'qr_code',
        'remark',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
