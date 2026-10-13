@props(['job'])

{{-- One opened job: its error, its calls and the message it wrote. --}}
<div class="space-y-4 py-2">
    @if ($job->status === 'failed' && filled($job->error))
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('debug.detail.error')">
            <flux:callout.text class="max-h-48 overflow-auto whitespace-pre-wrap font-mono text-xs">{{ $job->error }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:tab.group>
        <flux:tabs variant="segmented" size="sm">
            <flux:tab name="prompts" selected>{{ __('debug.detail.tab_prompts', ['count' => $job->promptLogs->count()]) }}</flux:tab>
            <flux:tab name="messages">{{ __('debug.detail.tab_messages', ['count' => $job->messages->count()]) }}</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="prompts">
            @if ($job->promptLogs->isEmpty())
                <x-empty-state icon="chat-bubble-left-right">{{ __('debug.detail.no_prompts') }}</x-empty-state>
            @else
                <x-debug.calls-table :calls="$job->promptLogs" />
            @endif
        </flux:tab.panel>

        <flux:tab.panel name="messages">
            <div class="space-y-2">
                @forelse ($job->messages as $message)
                    <flux:card size="sm" wire:key="dmsg-{{ $message->id }}" class="space-y-1">
                        <div class="flex items-center gap-2 text-sm">
                            <flux:heading size="sm">{{ $message->expert?->name ?? __('debug.detail.system_sender') }}</flux:heading>
                            @if ($message->addressee)
                                <flux:icon.arrow-right variant="micro" class="text-zinc-400" />
                                <flux:text size="sm">{{ $message->addressee->name }}</flux:text>
                            @endif
                            <flux:text size="sm" class="ms-auto tabular-nums">{{ $message->created_at?->format('H:i:s') }}</flux:text>
                        </div>
                        <flux:text class="whitespace-pre-wrap">{{ $message->content }}</flux:text>
                    </flux:card>
                @empty
                    <x-empty-state icon="chat-bubble-left-ellipsis">{{ __('debug.detail.no_messages') }}</x-empty-state>
                @endforelse
            </div>
        </flux:tab.panel>
    </flux:tab.group>
</div>
