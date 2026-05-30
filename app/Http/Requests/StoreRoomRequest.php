<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roomNumber' => 'required|string|unique:rooms,room_number',
            'type' => 'sometimes|in:standard,deluxe,suite',
            'rent' => 'required|numeric|min:0',
            'capacity' => 'sometimes|integer|min:1',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'roomNumber.required' => 'Room number is required.',
            'roomNumber.unique' => 'This room number already exists.',
            'rent.required' => 'Monthly rent amount is required.',
        ];
    }
}
