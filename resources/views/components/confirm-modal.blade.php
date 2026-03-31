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
    <flux:modal name="confirm-action" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg" x-text="title"></flux:heading>
            <flux:text x-text="message"></flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" class="cursor-pointer" x-on:click="confirm()">{{ __('Confirm') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
