<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('login.confirm_password.heading')"
        :description="__('login.confirm_password.description')"
    />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="confirmPassword" class="flex flex-col gap-6">
        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('common.fields.password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('common.fields.password')"
        />

        <flux:button variant="primary" type="submit" class="w-full cursor-pointer">{{ __('common.actions.confirm') }}</flux:button>
    </form>
</div>
