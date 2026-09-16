<section class="mx-auto w-full max-w-6xl">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('settings.users.heading')" :subheading="__('settings.users.subheading')" wide>
        <!-- Users Table -->
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
                                <flux:tooltip :content="__('settings.users.edit')" position="top">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        class="cursor-pointer"
                                        :aria-label="__('settings.users.edit')"
                                        wire:click="openEdit({{ $user->id }})"
                                        :disabled="$user->id === auth()->id()"
                                    />
                                </flux:tooltip>
                                <flux:tooltip :content="__('common.actions.delete')" position="top">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        class="cursor-pointer text-red-600! dark:text-red-400!"
                                        :aria-label="__('common.actions.delete')"
                                        wire:click="needsConfirmation('delete', {{ $user->id }})"
                                        :disabled="$user->id === auth()->id()"
                                    />
                                </flux:tooltip>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </div>

        <!-- Create Button -->
        <div class="mt-4">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" class="cursor-pointer">
                {{ __('settings.users.create') }}
            </flux:button>
        </div>

        <!-- Create / Edit Modal -->
        <flux:modal name="user-form" class="md:w-[32rem]">
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingUserId ? __('settings.users.edit') : __('settings.users.create') }}
                </flux:heading>

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

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:modal.close>
                            <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" class="cursor-pointer" variant="primary">{{ __('common.actions.save') }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

    </x-settings.layout>
</section>
