 <div class="flex flex-col gap-6">
    <x-auth-header :title="__('login.forgot_password.heading')" :description="__('login.forgot_password.description')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('common.fields.email')"
            type="email"
            required
            autofocus
            :placeholder="__('login.email_placeholder')"
        />

        <flux:button variant="primary" type="submit" class="w-full cursor-pointer">{{ __('login.forgot_password.submit') }}</flux:button>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
        {{ __('login.forgot_password.return_to') }}
        <flux:link :href="route('login')" wire:navigate>{{ __('login.forgot_password.log_in') }}</flux:link>
    </div>
</div>
