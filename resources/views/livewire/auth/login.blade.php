<div class="flex flex-col gap-6">
    <x-auth-header :title="__('login.sign_in.heading')" :description="__('login.sign_in.description')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('common.fields.email')"
            type="email"
            required
            autofocus
            autocomplete="email"
            :placeholder="__('login.email_placeholder')"
        />

        <!-- Password -->
        <div class="relative">
            <flux:input
                wire:model="password"
                :label="__('common.fields.password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('common.fields.password')"
            />

            @if (Route::has('password.request'))
                <flux:link class="absolute end-0 top-0 text-sm" :href="route('password.request')" wire:navigate>
                    {{ __('login.sign_in.forgot_password') }}
                </flux:link>
            @endif
        </div>

        <!-- Remember Me -->
        <flux:checkbox wire:model="remember" :label="__('login.sign_in.remember_me')" />

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full cursor-pointer">{{ __('login.sign_in.submit') }}</flux:button>
        </div>
    </form>

    @if (Route::has('register'))
        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('login.sign_in.no_account') }}
            <flux:link :href="route('register')" wire:navigate>{{ __('login.sign_in.sign_up') }}</flux:link>
        </div>
    @endif
</div>
