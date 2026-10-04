<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sign in is backed by the Costs to Expect API, there are no local users, no Sanctum and no API routes,
 * the only tables are the ones that keep the app running (sessions, queued jobs, the cache, share links
 * and the registrations waiting for a password).
 */
class AppFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_are_no_api_routes(): void
    {
        foreach (Route::getRoutes() as $route) {
            self::assertStringStartsNotWith('api/', $route->uri());
            self::assertStringNotContainsString('sanctum', $route->uri());
        }

        $this->getJson('/api/user')->assertNotFound();
    }

    public function test_sanctum_and_the_skeleton_user_are_not_part_of_the_app(): void
    {
        self::assertFalse(class_exists('Laravel\Sanctum\Sanctum'));
        self::assertFalse(class_exists('App\Models\User'));
        self::assertNull(config('sanctum'));
    }

    public function test_the_migrations_create_the_tables_the_app_uses_and_no_others(): void
    {
        foreach (['sessions', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'share_token', 'partial_registration'] as $table) {
            self::assertTrue(Schema::hasTable($table), "{$table} should exist");
        }

        foreach (['users', 'password_resets', 'password_reset_tokens', 'personal_access_tokens'] as $table) {
            self::assertFalse(Schema::hasTable($table), "{$table} should not exist");
        }
    }

    public function test_the_share_token_table_is_keyed_by_the_token_and_holds_text_for_the_encrypted_parameters(): void
    {
        $columns = collect(Schema::getColumns('share_token'))->keyBy('name');

        self::assertSame(['token', 'game_id', 'player_id', 'parameters', 'created_at', 'updated_at'], $columns->keys()->all());
        self::assertSame('text', $columns['parameters']['type_name']);
        self::assertContains('game_id', collect(Schema::getIndexes('share_token'))->pluck('columns')->flatten()->all());
    }

    public function test_the_migrations_can_be_rolled_back_and_run_again(): void
    {
        $this->artisan('migrate:rollback')->assertSuccessful();

        foreach (['sessions', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'share_token', 'partial_registration'] as $table) {
            self::assertFalse(Schema::hasTable($table), "{$table} should have been dropped");
        }

        $this->artisan('migrate')->assertSuccessful();

        self::assertTrue(Schema::hasTable('share_token'));
    }
}
