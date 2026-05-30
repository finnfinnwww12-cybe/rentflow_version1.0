<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'roomId' => 'required|exists:rooms,id',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
            'rentAmount' => 'required|numeric|min:0',
            'terms' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'tenantId.exists' => 'Selected tenant does not exist.',
            'roomId.exists' => 'Selected room does not exist.',
            'endDate.after' => 'End date must be after start date.',
        ];
    }
}
