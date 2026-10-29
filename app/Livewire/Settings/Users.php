<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Users extends Component
{
    use NeedsConfirmation;

    /** Set by openEdit() only; a client could otherwise point save() at another user. */
    #[Locked]
    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $is_admin = false;

    /**
     * Livewire boots the component on every request, the mount and each update
     * alike; the route's admin middleware guards only the first of them.
     */
    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function openCreate(): void
    {
        $this->reset(['editingUserId', 'name', 'email', 'password', 'is_admin']);
        Flux::modal('user-form')->show();
    }

    public function openEdit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingUserId = $userId;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->is_admin = $user->is_admin;
        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'is_admin' => ['boolean'],
        ];

        if ($this->editingUserId) {
            $rules['password'] = ['nullable', 'string', 'min:8'];
        } else {
            $rules['password'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        if ($this->editingUserId) {
            $data = ['name' => $this->name, 'email' => $this->email, 'is_admin' => $this->is_admin];
            // The model's 'hashed' cast hashes it.
            if ($this->password) {
                $data['password'] = $this->password;
            }
            User::findOrFail($this->editingUserId)->update($data);
        } else {
            User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'is_admin' => $this->is_admin,
            ]);
        }

        Flux::modal('user-form')->close();
        $this->reset(['editingUserId', 'name', 'email', 'password', 'is_admin']);
    }

    public function confirmDelete(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->confirmTitle = __('settings.users.confirm.title');
        $this->confirmLabel = __('settings.users.confirm.action');
        $this->confirmMessage = __('settings.users.confirm.message', ['name' => $user->name, 'email' => $user->email]);
        $this->needsConfirmation('delete', $userId);
    }

    public function delete(int $userId): void
    {
        User::findOrFail($userId)->delete();
    }

    public function render(): mixed
    {
        return view('livewire.settings.users', [
            'users' => User::orderBy('name')->get(),
        ])->title(__('settings.users.heading'));
    }
}
