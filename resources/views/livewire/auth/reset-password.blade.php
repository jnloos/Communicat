<div class="flex flex-col gap-6">
    <x-auth-header :title="__('login.reset_password.heading')" :description="__('login.reset_password.description')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="resetPassword" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('common.fields.email')"
            type="email"
            required
            autocomplete="email"
        />

        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('common.fields.password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('common.fields.password')"
        />

        <!-- Confirm Password -->
        <flux:input
            wire:model="password_confirmation"
            :label="__('login.reset_password.confirm_password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('login.reset_password.confirm_password')"
        />

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full cursor-pointer">
                {{ __('login.reset_password.submit') }}
            </flux:button>
        </div>
    </form>
</div>
