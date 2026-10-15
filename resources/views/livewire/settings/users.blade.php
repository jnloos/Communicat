<x-settings.layout :heading="__('settings.users.heading')" :subheading="__('settings.users.subheading')" wide>
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.fields.name') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('common.fields.email') }}</flux:table.column>
            <flux:table.column>{{ __('settings.users.column_admin') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $user->name }}</div>
                        <div class="text-xs text-zinc-500 sm:hidden dark:text-zinc-400">{{ $user->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($user->is_admin)
                            <flux:badge color="blue" size="sm">{{ __('settings.users.role_admin') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('settings.users.role_user') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        <div class="flex justify-end gap-1">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="pencil-square"
                                :tooltip="__('settings.users.edit')"
                                :aria-label="__('settings.users.edit')"
                                wire:click="openEdit({{ $user->id }})"
                                :disabled="$user->id === auth()->id()"
                            />
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600! dark:text-red-400!"
                                :tooltip="__('common.actions.delete')"
                                :aria-label="__('common.actions.delete')"
                                wire:click="needsConfirmation('delete', {{ $user->id }})"
                                :disabled="$user->id === auth()->id()"
                            />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">
        <flux:button variant="primary" icon="plus" wire:click="openCreate">
            {{ __('settings.users.create') }}
        </flux:button>
    </div>

    <flux:modal name="user-form" class="md:w-[32rem]">
        <div class="space-y-6">
            <x-modal-header :heading="$editingUserId ? __('settings.users.edit') : __('settings.users.create')" />

            <form wire:submit="save" class="space-y-4">
                <flux:input
                    wire:model="name"
                    :label="__('common.fields.name')"
                    type="text"
                    required
                    autofocus
                />
                <flux:input
                    wire:model="email"
                    :label="__('common.fields.email')"
                    type="email"
                    required
                />
                <flux:input
                    wire:model="password"
                    :label="$editingUserId ? __('settings.users.password_keep') : __('common.fields.password')"
                    type="password"
                    :required="! $editingUserId"
                    autocomplete="new-password"
                />
                <flux:checkbox wire:model="is_admin" :label="__('settings.users.administrator')" />

                <x-modal-footer padding="dialog">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
                </x-modal-footer>
            </form>
        </div>
    </flux:modal>

</x-settings.layout>
