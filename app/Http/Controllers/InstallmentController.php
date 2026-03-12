<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use Illuminate\Http\JsonResponse;

class InstallmentController extends Controller
{
    public function showInstallments($id): JsonResponse
    {
        $installments = Installment::where([
            'transaction_id' => $id
        ])->get();

        $response = [];
        foreach ($installments as $installment) {
            $response[] = $installment;
        }

        return response()->json($response, 200);
    }
}
