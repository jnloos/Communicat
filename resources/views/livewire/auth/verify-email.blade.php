<div class="mt-4 flex flex-col gap-6">
    <flux:text class="text-center">
        {{ __('login.verify_email.instructions') }}
    </flux:text>

    @if (session('status') == 'verification-link-sent')
        <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
            {{ __('login.verify_email.link_sent') }}
        </flux:text>
    @endif

    <div class="flex flex-col items-center justify-between space-y-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full cursor-pointer">
            {{ __('login.verify_email.resend') }}
        </flux:button>

        <flux:link class="text-sm cursor-pointer" wire:click="logout">
            {{ __('login.verify_email.log_out') }}
        </flux:link>
    </div>
</div>
