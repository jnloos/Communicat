@use(App\Facades\Markdown)

@props([
    'id' => Str::random(6),
    'msg'
])

@php
    $sender = $msg->sender();

    // Resolve "talks-to" target: only when next_speaker names another expert in the project.
    $addressed = null;
    if ($msg->isExpert() && !empty($msg->next_speaker)) {
        $addressed = \App\Models\Expert::where('name', trim($msg->next_speaker))
            ->whereHas('projects', fn($q) => $q->whereKey($msg->project_id))
            ->first();

        // Don't draw a self-arrow.
        if ($addressed && $addressed->id === $msg->expert_id) {
            $addressed = null;
        }
    }
@endphp

<div class="block">
    @if($msg->isCurrUser())
        <div class="flex justify-end">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 pt-4 pb-8 break-words ms-2 sm:ms-30 bg-zinc-300 dark:bg-zinc-600">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $sender->name }}</flux:heading>
                <span class="markdown-html">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @elseif($msg->isAssistant())
        <div class="flex justify-center mt-2">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 py-4 break-words bg-zinc-200">
                <span class="markdown-html text-zinc-900">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @else {{-- Other user or expert --}}
        <div class="flex justify-start">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 pt-4 pb-8 break-words me-2 sm:me-30 bg-zinc-100 dark:bg-zinc-700">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $sender->name }}</flux:heading>
                <span class="markdown-html">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @endif

    <div class="-translate-y-5">
        @if($msg->isCurrUser())
            <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="ms-auto me-5 w-12 h-12"/>
        @elseif($msg->isAssistant())
            {{-- No avatar for assistant messages --}}
        @elseif($msg->isExpert())
            <div class="flex items-center ms-5 gap-2">
                <button
                    type="button"
                    title="{{ __('Gedanken von') }} {{ $sender->name }}"
                    @click="$dispatch('open-expert-thoughts', { expertId: {{ $sender->id }} })"
                    class="rounded-full cursor-pointer transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                >
                    <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="w-12 h-12"/>
                </button>

                @if ($addressed)
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-zinc-400 dark:text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="13 6 19 12 13 18"/>
                    </svg>
                    <button
                        type="button"
                        title="{{ __('Angesprochen') }}: {{ $addressed->name }}"
                        @click="$dispatch('open-expert-thoughts', { expertId: {{ $addressed->id }} })"
                        class="rounded-full cursor-pointer transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                    >
                        <x-contributors.contributors-avatar :name="$addressed->name" :avatar-url="$addressed->avatar_url" class="w-9 h-9 opacity-90"/>
                    </button>
                @endif
            </div>
        @else {{-- Other user --}}
            <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="ms-5 w-12 h-12"/>
        @endif
    </div>
</div>
