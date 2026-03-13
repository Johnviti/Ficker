<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\AccountLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class WhatsAppLinkController extends Controller
{
    public function __construct(
        private readonly AccountLinkService $accountLinkService
    ) {
    }

    public function generateLinkCode(): JsonResponse
    {
        $linkCode = $this->accountLinkService->generateLinkCode(Auth::id());

        return response()->json([
            'data' => [
                'code' => $linkCode->code,
                'expires_at' => $linkCode->expires_at->format('Y-m-d H:i:s'),
            ]
        ], 200);
    }
}
