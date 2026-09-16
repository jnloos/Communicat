<section class="mx-auto w-full max-w-6xl">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('settings.password.heading')" :subheading="__('settings.password.subheading')">
        <form wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('settings.password.current')"
                type="password"
                required
                autocomplete="current-password"
            />
            <flux:input
                wire:model="password"
                :label="__('settings.password.new')"
                type="password"
                required
                autocomplete="new-password"
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('settings.password.confirm')"
                type="password"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full cursor-pointer">{{ __('common.actions.save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="password-updated">
                    {{ __('common.saved') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
