<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        Level::factory()->create(['id' => 2]);
        Level::factory()->create(['id' => 3]);
        Level::factory()->create(['id' => 4]);

        Route::middleware('knight')->get('/_test/permissions/knight', fn () => response()->json([
            'message' => 'ok',
        ]));

        Route::middleware('master')->get('/_test/permissions/master', fn () => response()->json([
            'message' => 'ok',
        ]));

        Route::middleware('grandmaster')->get('/_test/permissions/grandmaster', fn () => response()->json([
            'message' => 'ok',
        ]));
    }

    public function test_knight_middleware_allows_level_two_and_above(): void
    {
        $knight = User::factory()->create(['level_id' => 2]);
        $master = User::factory()->create(['level_id' => 3]);

        $this->actingAs($knight)
            ->getJson('/_test/permissions/knight')
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->actingAs($master)
            ->getJson('/_test/permissions/knight')
            ->assertOk()
            ->assertJsonPath('message', 'ok');
    }

    public function test_knight_middleware_denies_lower_level_and_redirects_guest(): void
    {
        $basicUser = User::factory()->create(['level_id' => 1]);

        $this->actingAs($basicUser)
            ->getJson('/_test/permissions/knight')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden');

        auth()->logout();

        $this->get('/_test/permissions/knight')
            ->assertRedirect('/login');
    }

    public function test_master_middleware_allows_level_three_and_above(): void
    {
        $master = User::factory()->create(['level_id' => 3]);
        $grandmaster = User::factory()->create(['level_id' => 4]);

        $this->actingAs($master)
            ->getJson('/_test/permissions/master')
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->actingAs($grandmaster)
            ->getJson('/_test/permissions/master')
            ->assertOk()
            ->assertJsonPath('message', 'ok');
    }

    public function test_master_middleware_denies_lower_level_and_redirects_guest(): void
    {
        $knight = User::factory()->create(['level_id' => 2]);

        $this->actingAs($knight)
            ->getJson('/_test/permissions/master')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden');

        auth()->logout();

        $this->get('/_test/permissions/master')
            ->assertRedirect('/login');
    }

    public function test_grandmaster_middleware_allows_only_level_four(): void
    {
        $grandmaster = User::factory()->create(['level_id' => 4]);

        $this->actingAs($grandmaster)
            ->getJson('/_test/permissions/grandmaster')
            ->assertOk()
            ->assertJsonPath('message', 'ok');
    }

    public function test_grandmaster_middleware_denies_lower_level_and_redirects_guest(): void
    {
        $master = User::factory()->create(['level_id' => 3]);

        $this->actingAs($master)
            ->getJson('/_test/permissions/grandmaster')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden');

        auth()->logout();

        $this->get('/_test/permissions/grandmaster')
            ->assertRedirect('/login');
    }
}
