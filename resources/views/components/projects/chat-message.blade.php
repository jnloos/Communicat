@props(['id', 'msg'])

@php
    $sender = $msg->sender();
    $addressed = $msg->visibleAddressee();

    // Message text comes from a model or an imported bundle, so it is untrusted:
    // raw HTML is stripped and javascript:/data: links are dropped.
    $renderedContent = Str::markdown($msg->content, [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);
@endphp

@if ($msg->isAssistant() || is_null($sender))
    {{-- System notice (welcome text): centered, quiet, full width --}}
    <div id="{{ $id }}" wire:key="{{ $id }}" class="flex justify-center">
        <div class="markdown-html w-full rounded-xl border border-dashed border-zinc-300 px-5 py-4 text-sm text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">
            {!! $renderedContent !!}
        </div>
    </div>
@else
    <div id="{{ $id }}" wire:key="{{ $id }}" class="group/message flex items-start gap-3">
        {{-- Sender avatar: experts open their memory flyout --}}
        @if ($msg->isExpert())
            <x-experts.memory-avatar class="mt-0.5" :expert-id="$sender->id" :name="$sender->name" :avatar-url="$sender->avatar_url" />
        @else
            <div class="mt-0.5 shrink-0">
                <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="size-9 sm:size-10"/>
            </div>
        @endif

        <div class="flex min-w-0 max-w-[85%] flex-col gap-1 sm:max-w-[75%]">
            {{-- Meta line: who speaks and whom the message addresses --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-sm">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $sender->name }}</span>

                @if ($addressed)
                    <x-projects.addressed-chip :addressed="$addressed" />
                @endif
            </div>

            <div class="markdown-html rounded-2xl rounded-tl-md bg-zinc-100 px-4 py-3 break-words dark:bg-zinc-700">
                {!! $renderedContent !!}
            </div>
        </div>
    </div>
@endif
