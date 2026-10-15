<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('settings.delete_account.heading') }}</flux:heading>
        <flux:subheading>{{ __('settings.delete_account.subheading') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" icon="trash">
            {{ __('settings.delete_account.button') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <x-modal-header
                :heading="__('settings.delete_account.confirm_title')"
                :subheading="__('settings.delete_account.confirm_message')"
            />

            <flux:input wire:model="password" :label="__('common.fields.password')" type="password" />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ __('settings.delete_account.button') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
