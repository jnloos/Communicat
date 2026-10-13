@use('App\Console\Commands\PrunePrompts')
@use('App\Discussion\Support\LocaleNumber')
@use('App\Discussion\Values\TokenTotal')

@props([
    'calls', {{-- the job's PromptLogs, in order --}}
])

{{-- One row per model call, the turn's sum as the last row in the same columns.
     A count nobody reported is "–", never 0: 0 would claim the call spent none. --}}
@php($columns = ['tokens_in', 'tokens_out', 'tokens_reasoning'])
@php($number = fn (?int $value) => $value === null ? null : LocaleNumber::decimal($value, 0))
{{-- Structured output arrives as one line of JSON; indent it so its fields can be read. --}}
@php($readable = fn (?string $text) => is_array($decoded = json_decode((string) $text, true)) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $text)

<div x-data="{ open: null }">
    <flux:table class="[&_[data-flux-cell]]:py-1.5 [&_[data-flux-column]]:py-2">
        <flux:table.columns>
            <flux:table.column class="w-8"><span class="sr-only">{{ __('debug.detail.calls.toggle') }}</span></flux:table.column>
            <flux:table.column>{{ __('debug.detail.calls.call') }}</flux:table.column>
            <flux:table.column align="end">{{ __('debug.detail.calls.latency') }}</flux:table.column>
            @foreach ($columns as $column)
                <flux:table.column align="end">{{ __("debug.detail.calls.{$column}") }}</flux:table.column>
            @endforeach
            <flux:table.column align="end">{{ __('debug.detail.calls.finished') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($calls as $call)
                <flux:table.row wire:key="call-{{ $call->id }}">
                    <flux:table.cell>
                        <flux:button size="xs" variant="ghost" icon="chevron-right" :aria-label="__('debug.detail.calls.toggle')"
                            x-on:click="open = open === {{ $call->id }} ? null : {{ $call->id }}"
                            x-bind:aria-expanded="open === {{ $call->id }}"
                            x-bind:class="open === {{ $call->id }} && '[&_svg]:rotate-90'" />
                    </flux:table.cell>
                    <flux:table.cell variant="strong" class="font-mono">
                        <span class="flex items-center gap-2">
                            {{ $call->label ?? '–' }}
                            @if ($call->status === 'failed')
                                <flux:badge size="sm" color="red">{{ __('debug.status.failed') }}</flux:badge>
                            @endif
                        </span>
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $call->latency_ms === null ? '–' : $number($call->latency_ms).' ms' }}</flux:table.cell>
                    @foreach ($columns as $column)
                        <flux:table.cell align="end" class="tabular-nums">
                            @if ($call->{$column} === null)
                                <span aria-label="{{ __('debug.detail.tokens_missing') }}">–</span>
                            @else
                                {{ $number($call->{$column}) }}
                            @endif
                        </flux:table.cell>
                    @endforeach
                    {{-- RecordPromptLog writes a call when it ends, so created_at is its finish time. --}}
                    <flux:table.cell align="end" class="tabular-nums">{{ $call->created_at?->format('H:i:s') }}</flux:table.cell>
                </flux:table.row>

                <flux:table.row wire:key="call-detail-{{ $call->id }}" x-show="open === {{ $call->id }}" x-cloak>
                    <flux:table.cell colspan="7" class="whitespace-normal">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <flux:heading size="sm" class="mb-1">{{ __('debug.detail.prompt') }}</flux:heading>
                                @if (PrunePrompts::removed((string) $call->prompt))
                                    <flux:text size="sm" class="italic">{{ __('debug.detail.prompt_removed') }}</flux:text>
                                @else
                                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $readable($call->prompt) }}</pre>
                                @endif
                            </div>
                            <div>
                                @if ($call->status === 'failed')
                                    <flux:heading size="sm" class="mb-1 text-red-600! dark:text-red-400!">{{ __('debug.detail.error') }}</flux:heading>
                                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs text-red-700 dark:text-red-300">{{ $call->error }}</pre>
                                @else
                                    <flux:heading size="sm" class="mb-1">{{ __('debug.detail.response') }}</flux:heading>
                                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $readable($call->response) }}</pre>
                                @endif
                            </div>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach

            {{-- The turn's sum (TokenTotal): "–" when no call reported, "≥ n" when only some did. --}}
            <flux:table.row data-tokens-total>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell variant="strong" colspan="2">{{ __('debug.detail.calls.total') }}</flux:table.cell>
                @foreach ($columns as $column)
                    @php($total = TokenTotal::of($calls->pluck($column)))
                    <flux:table.cell variant="strong" align="end" class="tabular-nums" data-total="{{ $column }}">
                        @if ($total->sum === null)
                            <span aria-label="{{ __('debug.detail.tokens_missing') }}">–</span>
                        @elseif ($total->complete)
                            {{ $number($total->sum) }}
                        @else
                            <flux:tooltip :content="__('debug.detail.tokens_partial')">
                                <span tabindex="0" class="cursor-help underline decoration-dotted underline-offset-4">≥ {{ $number($total->sum) }}</span>
                            </flux:tooltip>
                        @endif
                    </flux:table.cell>
                @endforeach
                <flux:table.cell></flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</div>
