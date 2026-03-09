<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class VerifyEmailController extends Controller
{
    /**
     * Mark the user's email address as verified using signed URL parameters.
     */
    public function __invoke(Request $request, $id, $hash): RedirectResponse
    {
        // A assinatura já é validada pelo middleware "signed",
        // mas aqui seguimos validando o usuário e o hash esperado.
        $user = User::find($id);

        if (!$user) {
            return redirect()->away(
                config('app.frontend_url') . RouteServiceProvider::HOME . '?verified=0&reason=user-not-found'
            );
        }

        $expectedHash = sha1($user->getEmailForVerification());

        if (!hash_equals($expectedHash, (string) $hash)) {
            return redirect()->away(
                config('app.frontend_url') . RouteServiceProvider::HOME . '?verified=0&reason=invalid-hash'
            );
        }

        if (!$user->hasVerifiedEmail()) {
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        return redirect()->away(
            config('app.frontend_url') . RouteServiceProvider::HOME . '?verified=1'
        );
    }
}
