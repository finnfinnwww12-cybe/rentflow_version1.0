<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'amount' => 'required|numeric|min:0',
            'paidDate' => 'nullable|date',
            'month' => 'required|string',
            'paymentMethod' => 'nullable|in:cash,bank_transfer,cheque',
        ];
    }

    public function messages(): array
    {
        return [
            'tenantId.required' => 'Tenant is required.',
            'tenantId.exists' => 'Selected tenant does not exist.',
            'amount.required' => 'Payment amount is required.',
            'month.required' => 'Payment month is required.',
        ];
    }
}
