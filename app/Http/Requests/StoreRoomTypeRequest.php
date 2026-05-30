<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('room_types', 'name')->where(function ($query) {
                    $user = request()->user();
                    return $query->where('user_id', $user ? $user->id : null);
                }),
            ],
            'billing_cycle' => 'required|in:daily,monthly',
            'base_price' => 'required_if:billing_cycle,monthly|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'base_daily_price' => 'required_if:billing_cycle,daily|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'capacity' => 'required|integer|min:1|max:20',
            'description' => 'nullable|string|max:1000',
            'status' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Room type name is required',
            'name.unique' => 'This room type name already exists',
            'billing_cycle.required' => 'Billing cycle is required',
            'billing_cycle.in' => 'Billing cycle must be daily or monthly',
            'base_price.required_if' => 'Base monthly price is required when monthly billing cycle is enabled',
            'base_price.numeric' => 'Base monthly price must be a valid number',
            'base_price.min' => 'Base monthly price must be greater than or equal to 0',
            'base_daily_price.required_if' => 'Base daily price is required when daily billing cycle is enabled',
            'base_daily_price.numeric' => 'Base daily price must be a valid number',
            'base_daily_price.min' => 'Base daily price must be greater than or equal to 0',
            'capacity.required' => 'Capacity is required',
            'capacity.integer' => 'Capacity must be a whole number',
            'capacity.min' => 'Capacity must be at least 1',
            'capacity.max' => 'Capacity cannot exceed 20',
        ];
    }
}
