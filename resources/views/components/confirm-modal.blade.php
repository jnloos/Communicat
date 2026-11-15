<div
    x-data="{
        title: '',
        message: '',
        label: @js(__('common.actions.confirm')),
        variant: 'danger',
        componentId: null,
        init() {
            window.addEventListener('open-confirm', (e) => {
                this.title       = e.detail.title;
                this.message     = e.detail.message;
                this.label       = e.detail.label ?? @js(__('common.actions.confirm'));
                this.variant     = e.detail.variant ?? 'danger';
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
                {{-- Flux renders the variant on the server, so both colours exist and Alpine shows one. --}}
                <flux:button variant="danger" x-show="variant === 'danger'" x-on:click="confirm()"><span x-text="label">{{ __('common.actions.confirm') }}</span></flux:button>
                <flux:button variant="primary" x-show="variant === 'primary'" x-cloak x-on:click="confirm()"><span x-text="label">{{ __('common.actions.confirm') }}</span></flux:button>
            </x-modal-footer>
        </div>
    </flux:modal>
</div>
