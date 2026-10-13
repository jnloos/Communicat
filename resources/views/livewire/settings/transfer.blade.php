<section class="mx-auto w-full max-w-6xl">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('transfer.heading')" :subheading="__('transfer.subheading')" wide>
        <div class="space-y-6">
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('transfer.export.heading') }}</flux:heading>
                <flux:text size="sm">{{ __('transfer.export.description') }}</flux:text>
                <flux:button icon="arrow-down-tray" :href="route('settings.export')">{{ __('transfer.export.everything') }}</flux:button>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('transfer.import.heading') }}</flux:heading>

                @if ($stage === 'upload')
                    <flux:text size="sm">{{ __('transfer.import.description') }}</flux:text>
                    <flux:file-upload wire:model="bundle" accept=".zip,application/zip">
                        <flux:file-upload.dropzone inline :heading="__('transfer.import.dropzone_heading')" :text="__('transfer.import.dropzone_text')" />
                    </flux:file-upload>
                    <flux:text size="sm" wire:loading wire:target="bundle">{{ __('transfer.import.checking') }}</flux:text>
                    <flux:error name="bundle" />
                @else
                    @if ($stage === 'done')
                        <flux:callout :variant="$importReport->hasFailures() ? 'warning' : 'success'" :icon="$importReport->hasFailures() ? 'exclamation-triangle' : 'check-circle'"
                            :heading="$importReport->hasFailures() ? __('transfer.import.done_with_failures') : __('transfer.import.done')" />
                    @endif

                    <flux:heading>{{ $stage === 'preview' ? __('transfer.import.preview_heading') : __('transfer.import.done_heading') }}</flux:heading>
                    <x-transfer.report :report="$importReport" />

                    <div class="flex justify-end gap-2">
                        @if ($stage === 'preview')
                            <flux:button variant="filled" wire:click="startOver">{{ __('common.actions.cancel') }}</flux:button>
                            <flux:button variant="primary" icon="arrow-up-tray" wire:click="import" wire:loading.attr="disabled" wire:target="import">{{ __('transfer.import.submit') }}</flux:button>
                        @else
                            <flux:button wire:click="startOver">{{ __('transfer.import.again') }}</flux:button>
                        @endif
                    </div>
                @endif
            </flux:card>
        </div>
    </x-settings.layout>
</section>
