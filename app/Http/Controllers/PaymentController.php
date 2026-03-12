<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function showPaymentMethods(): JsonResponse
    {
        $paymentMethods = PaymentMethod::all();

        $formattedPaymentMethods = $paymentMethods->map(function ($paymentMethod) {
            return [
                'id' => $paymentMethod->id,
                'description' => $paymentMethod->payment_method_description
            ];
        });

        return response()->json([
            'data' => [
                'payment_methods' => $formattedPaymentMethods
            ]
        ], 200);
    }
}
