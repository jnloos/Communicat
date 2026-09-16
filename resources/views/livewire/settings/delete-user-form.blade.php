<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('settings.delete_account.heading') }}</flux:heading>
        <flux:subheading>{{ __('settings.delete_account.subheading') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" icon="trash" class="cursor-pointer" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('settings.delete_account.button') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('settings.delete_account.confirm_title') }}</flux:heading>

                <flux:subheading>
                    {{ __('settings.delete_account.confirm_message') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('common.fields.password')" type="password" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" class="cursor-pointer">{{ __('settings.delete_account.button') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
