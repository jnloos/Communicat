<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Users;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Users::class)->assertForbidden();
    }

    /** Every request after the mount, as an admin who loses the flag in between would send it. */
    public static function adminActions(): array
    {
        return [
            'openCreate' => ['openCreate'],
            'openEdit' => ['openEdit', true],
            'save' => ['save'],
            'delete' => ['delete', true],
            'needsConfirmation' => ['needsConfirmation', true],
            'executeConfirmed' => ['executeConfirmed'],
            '\$refresh' => ['$refresh'],
        ];
    }

    #[DataProvider('adminActions')]
    public function test_every_action_is_rejected_once_the_user_is_no_admin(string $action, bool $targetsUser = false): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $this->actingAs($admin);
        $component = Livewire::test(Users::class)
            ->set('name', 'Neu')
            ->set('email', 'neu@example.com')
            ->set('password', 'geheim-genug');

        $admin->update(['is_admin' => false]);
        $params = match ($action) {
            'needsConfirmation' => ['delete', $other->id],
            default => $targetsUser ? [$other->id] : [],
        };

        $component->call($action, ...$params)->assertForbidden();
        $this->assertSame(2, User::count());
        $this->assertSame('other@example.com', $other->fresh()->email);
    }

    public function test_an_admin_deletes_a_user(): void
    {
        $other = User::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Users::class)->call('delete', $other->id)->assertOk();

        $this->assertNull($other->fresh());
    }

    /** The model's 'hashed' cast hashes the password once; the stored hash checks against the plain text. */
    public function test_a_new_users_password_is_stored_hashed(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Users::class)
            ->set('name', 'Neu')
            ->set('email', 'neu@example.com')
            ->set('password', 'geheim-genug')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('geheim-genug', User::where('email', 'neu@example.com')->sole()->password));
    }

    /** Which user a save edits is set by openEdit() alone, never by the client. */
    public function test_the_edited_user_cannot_be_set_from_the_client(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Users::class)->set('editingUserId', 1);
    }
}
