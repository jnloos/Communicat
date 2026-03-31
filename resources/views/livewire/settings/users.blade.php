<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('User Management')" :subheading="__('Create, edit and delete users')">
        <!-- Users Table -->
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Admin') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row>
                        <flux:table.cell>{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user->is_admin)
                                <flux:badge color="blue" size="sm">{{ __('Admin') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('User') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            <div class="flex justify-end gap-2">
                                <flux:button
                                    size="sm"
                                    icon="pencil"
                                    class="cursor-pointer"
                                    wire:click="openEdit({{ $user->id }})"
                                    :disabled="$user->id === auth()->id()"
                                />
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    icon="trash"
                                    class="cursor-pointer"
                                    wire:click="needsConfirmation('delete', {{ $user->id }})"
                                    :disabled="$user->id === auth()->id()"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <!-- Create Button -->
        <div class="mt-4">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" class="cursor-pointer">
                {{ __('Create User') }}
            </flux:button>
        </div>

        <!-- Create / Edit Modal -->
        <flux:modal name="user-form" class="md:w-96">
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingUserId ? __('Edit User') : __('Create User') }}
                </flux:heading>

                <form wire:submit="save" class="space-y-4">
                    <flux:input
                        wire:model="name"
                        :label="__('Name')"
                        type="text"
                        required
                        autofocus
                    />
                    <flux:input
                        wire:model="email"
                        :label="__('Email')"
                        type="email"
                        required
                    />
                    <flux:input
                        wire:model="password"
                        :label="$editingUserId ? __('Password (leave empty to keep)') : __('Password')"
                        type="password"
                        :required="! $editingUserId"
                        autocomplete="new-password"
                    />
                    <flux:checkbox wire:model="is_admin" :label="__('Administrator')" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:modal.close>
                            <flux:button variant="filled" class="cursor-pointer">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" class="cursor-pointer" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

    </x-settings.layout>
</section>
