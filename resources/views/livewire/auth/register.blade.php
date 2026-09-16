<div class="flex flex-col gap-6">
    <x-auth-header :title="__('login.register.heading')" :description="__('login.register.description')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <flux:input
            wire:model="name"
            :label="__('common.fields.name')"
            type="text"
            required
            autofocus
            autocomplete="name"
            :placeholder="__('login.register.full_name')"
        />

        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('common.fields.email')"
            type="email"
            required
            autocomplete="email"
            :placeholder="__('login.email_placeholder')"
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
            :label="__('login.register.confirm_password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('login.register.confirm_password')"
        />

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full cursor-pointer">
                {{ __('login.register.submit') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
        {{ __('login.register.have_account') }}
        <flux:link :href="route('login')" wire:navigate>{{ __('login.register.log_in') }}</flux:link>
    </div>
</div>
