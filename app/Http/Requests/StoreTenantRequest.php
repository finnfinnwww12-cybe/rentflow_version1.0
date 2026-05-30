<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'room' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'moveInDate' => 'nullable|date',
            'moveOutDate' => 'nullable|date',
            'idNumber' => 'nullable|string|max:100',
            'emergencyContact' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tenant name is required.',
            'email.email' => 'Please provide a valid email address.',
        ];
    }
}
