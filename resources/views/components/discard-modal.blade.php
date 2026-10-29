{{-- The one "Discard changes?" dialog, shared by every guarded flyout (x-flyout guard). Keep editing takes the focus. --}}
<flux:modal name="discard-changes" class="md:w-[28rem]">
    <div class="space-y-6">
        <x-modal-header :heading="__('common.discard.title')" />

        <flux:text>{{ __('common.discard.message') }}</flux:text>

        <x-modal-footer padding="dialog">
            <flux:modal.close>
                <flux:button variant="primary" autofocus>{{ __('common.discard.keep') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" x-on:click="$store.unsaved.discard()">{{ __('common.discard.discard') }}</flux:button>
        </x-modal-footer>
    </div>
</flux:modal>
