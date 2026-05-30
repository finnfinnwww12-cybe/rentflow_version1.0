<?php

namespace App\Http\Controllers;

use App\Models\PaymentOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentOptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentOption::query();
        $query = $this->scopeByOwner($query, $request);
        
        $paymentOptions = $query->orderBy('created_at', 'desc')->get();
        
        return $this->success($paymentOptions, 'Payment options retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'payment_type' => 'required|in:static_qr,bank_transfer,cash',
            'payment_method_name' => 'required_if:payment_type,cash|nullable|string|max:255',
            'bank_name' => 'required_if:payment_type,static_qr|required_if:payment_type,bank_transfer|nullable|string|max:255',
            'account_name' => 'required_if:payment_type,static_qr|required_if:payment_type,bank_transfer|nullable|string|max:255',
            'account_number' => 'required_if:payment_type,bank_transfer|nullable|string|max:255', // optional for static_qr
            'currency' => 'required_unless:payment_type,cash|nullable|string|max:10',
            'qr_code' => 'required_if:payment_type,static_qr|nullable|string|max:1000',
            'remark' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $data = array_merge($v, [
            'user_id' => $request->user()->id,
            'is_active' => $request->input('is_active', true),
        ]);

        $paymentOption = PaymentOption::create($data);

        $this->logActivity($request, 'payment_option_created', "Created payment option of type {$paymentOption->payment_type}");

        return $this->success($paymentOption, 'Payment option created successfully', 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $query = PaymentOption::query();
        $query = $this->scopeByOwner($query, $request);
        $paymentOption = $query->findOrFail($id);

        $v = $request->validate([
            'payment_type' => 'required|in:static_qr,bank_transfer,cash',
            'payment_method_name' => 'required_if:payment_type,cash|nullable|string|max:255',
            'bank_name' => 'required_if:payment_type,static_qr|required_if:payment_type,bank_transfer|nullable|string|max:255',
            'account_name' => 'required_if:payment_type,static_qr|required_if:payment_type,bank_transfer|nullable|string|max:255',
            'account_number' => 'required_if:payment_type,bank_transfer|nullable|string|max:255', // optional for static_qr
            'currency' => 'required_unless:payment_type,cash|nullable|string|max:10',
            'qr_code' => 'required_if:payment_type,static_qr|nullable|string|max:1000',
            'remark' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $paymentOption->update($v);

        $this->logActivity($request, 'payment_option_updated', "Updated payment option ID: {$paymentOption->id}");

        return $this->success($paymentOption, 'Payment option updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $query = PaymentOption::query();
        $query = $this->scopeByOwner($query, $request);
        $paymentOption = $query->findOrFail($id);

        $paymentOption->delete();

        $this->logActivity($request, 'payment_option_deleted', "Deleted payment option ID: {$id}");

        return $this->success(null, 'Payment option deleted successfully');
    }
}
