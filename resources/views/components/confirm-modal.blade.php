<div
    x-data="{
        title: '',
        message: '',
        componentId: null,
        init() {
            window.addEventListener('open-confirm', (e) => {
                this.title       = e.detail.title;
                this.message     = e.detail.message;
                this.componentId = e.detail.componentId;
                this.$nextTick(() => this.$flux.modal('confirm-action').show());
            });
        },
        confirm() {
            if (this.componentId) {
                Livewire.find(this.componentId).call('executeConfirmed');
            }
            this.$flux.modal('confirm-action').close();
        }
    }"
>
    <flux:modal name="confirm-action" class="md:w-[28rem]">
        <div class="space-y-6">
            {{-- The title arrives with the event, so it goes in as a slot that Alpine fills. --}}
            <x-modal-header>
                <x-slot:heading><span x-text="title"></span></x-slot:heading>
            </x-modal-header>

            <flux:text x-text="message"></flux:text>

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" x-on:click="confirm()">{{ __('common.actions.confirm') }}</flux:button>
            </x-modal-footer>
        </div>
    </flux:modal>
</div>
