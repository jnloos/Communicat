@props([
    'label',          {{-- what the sentence says for this part --}}
    'muted' => false, {{-- the part filters nothing ("everyone", "all runs", "nothing") --}}
    'readOnly' => false, {{-- a template: the words stay, the controls go --}}
    'source' => null, {{-- where the labelled item comes from; shown as a badge after the label --}}
])

{{--
    One part of the question's sentence. Editable: a flux:badge button with a
    chevron, its controls in a popover. Read only: plain text, so nothing
    looks like something to press.
--}}
@if ($readOnly)
    <span @class(['inline-flex max-w-full items-center gap-1.5 align-baseline font-medium', 'text-zinc-500 dark:text-zinc-400' => $muted, 'text-zinc-900 dark:text-white' => ! $muted])>
        {{ $label }}
        @if ($source !== null)
            <x-statistics.source :label="$source" />
        @endif
    </span>
@else
    <flux:dropdown position="bottom" align="start" class="inline-block">
        <flux:badge as="button" icon:trailing="chevron-down" :color="$muted ? 'zinc' : 'sky'"
            class="max-w-full cursor-pointer whitespace-normal! text-start align-baseline">
            {{ $label }}
            @if ($source !== null)
                <x-statistics.source :label="$source" class="ms-1.5" />
            @endif
        </flux:badge>

        <flux:popover class="w-80 max-w-[calc(100vw-2rem)] space-y-4">
            {{ $slot }}
        </flux:popover>
    </flux:dropdown>
@endif
