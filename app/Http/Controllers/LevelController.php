<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Mission;
use Illuminate\Support\Facades\Auth;

class LevelController extends Controller
{
    public static function completeMission($mission_id): void
    {
        $user = User::find(Auth::id());

        if (!$user) return;

        // garante que a missão existe
        $mission = Mission::find($mission_id);
        if (!$mission) return;

        // evita repetir missão
        if ($user->missions()->find($mission_id)) return;

        $user->missions()->attach($mission_id);

        $user->update([
            'user_xp' => $user->user_xp + $mission->mission_xp,
        ]);

        if ($user->user_xp >= 500) {
            $user->update(['level_id' => 4]);
        } elseif ($user->user_xp >= 250) {
            $user->update(['level_id' => 3]);
        } elseif ($user->user_xp >= 125) {
            $user->update(['level_id' => 2]);
        }
    }
}
