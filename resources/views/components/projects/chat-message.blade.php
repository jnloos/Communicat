@use(App\Facades\Markdown)

@props([
    'id' => Str::random(6),
    'msg'
])

@php
    $sender = $msg->sender();
    $isOwn  = $msg->isCurrUser();

    // Resolve the "speaks to" target from the polymorphic adjacency_partner:
    // an expert or a user (no global name lookup). No self-reference.
    $partner = $msg->adjacencyPartner;
    $addressedIsExpert = $partner instanceof \App\Models\Expert;
    $addressed = null;
    if ($addressedIsExpert && $partner->id !== $msg->expert_id) {
        $addressed = $partner;
    } elseif ($partner instanceof \App\Models\User && !($msg->isUser() && $partner->id === $msg->user_id)) {
        $addressed = $partner;
    }

    $renderedContent = Markdown::parse($msg->content);
@endphp

@if ($msg->isAssistant() || is_null($sender))
    {{-- System notice (welcome text): centered, quiet, full width --}}
    <div id="{{ $id }}" wire:key="{{ $id }}" class="flex justify-center">
        <div class="markdown-html w-full rounded-xl border border-dashed border-zinc-300 px-5 py-4 text-sm text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">
            {!! $renderedContent !!}
        </div>
    </div>
@else
    <div id="{{ $id }}" wire:key="{{ $id }}" @class(['group/message flex items-start gap-3', 'flex-row-reverse' => $isOwn])>
        {{-- Sender avatar: experts open their memory flyout --}}
        @if ($msg->isExpert())
            <x-experts.memory-avatar class="mt-0.5" :expert-id="$sender->id" :name="$sender->name" :avatar-url="$sender->avatar_url" />
        @else
            <div class="mt-0.5 shrink-0">
                <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="size-9 sm:size-10"/>
            </div>
        @endif

        <div @class(['flex min-w-0 max-w-[85%] flex-col gap-1 sm:max-w-[75%]', 'items-end' => $isOwn])>
            {{-- Meta line: who speaks and whom the message addresses --}}
            <div @class(['flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-sm', 'flex-row-reverse' => $isOwn])>
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $sender->name }}</span>

                @if ($addressed)
                    <x-projects.addressed-chip :addressed="$addressed" :is-expert="$addressedIsExpert" />
                @endif
            </div>

            <div @class([
                'markdown-html rounded-2xl px-4 py-3 break-words',
                'rounded-tr-md bg-zinc-200 dark:bg-zinc-600' => $isOwn,
                'rounded-tl-md bg-zinc-100 dark:bg-zinc-700' => ! $isOwn,
            ])>
                {!! $renderedContent !!}
            </div>
        </div>
    </div>
@endif
