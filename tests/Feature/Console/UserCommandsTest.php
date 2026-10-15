<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_stores_a_hashed_password(): void
    {
        $this->artisan('auth:create-user')
            ->expectsQuestion('Enter the name', 'Ada')
            ->expectsQuestion('Enter the email', 'ada@example.test')
            ->expectsQuestion('Enter the password', 'geheim-genug')
            ->expectsQuestion('Confirm the password', 'geheim-genug')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('geheim-genug', User::where('email', 'ada@example.test')->sole()->password));
    }

    public function test_create_user_refuses_passwords_that_differ(): void
    {
        $this->artisan('auth:create-user')
            ->expectsQuestion('Enter the name', 'Ada')
            ->expectsQuestion('Enter the email', 'ada@example.test')
            ->expectsQuestion('Enter the password', 'geheim-genug')
            ->expectsQuestion('Confirm the password', 'anders-genug')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** A second run used to fail on demouser-1; it numbers on, with timestamps set. */
    public function test_bulk_creation_numbers_on_across_runs(): void
    {
        config(['app.url' => 'https://communicat.test']);

        $this->artisan('auth:create-users', ['count' => 2])->assertSuccessful();
        $this->artisan('auth:create-users', ['count' => 1])->assertSuccessful();

        $this->assertSame(
            ['demouser-1@communicat.test', 'demouser-2@communicat.test', 'demouser-3@communicat.test'],
            User::orderBy('id')->pluck('email')->all(),
        );
        $this->assertSame(0, User::whereNull('created_at')->count());
    }
}
