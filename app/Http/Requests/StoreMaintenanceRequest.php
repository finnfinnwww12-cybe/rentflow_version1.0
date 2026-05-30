<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room' => 'required|string',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'reportedBy' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'room.required' => 'Room number is required.',
            'title.required' => 'Issue title is required.',
            'description.required' => 'Issue description is required.',
            'reportedBy.required' => 'Reporter name is required.',
        ];
    }
}
