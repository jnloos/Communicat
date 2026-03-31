@use(App\Facades\Markdown)

@props([
    'id' => Str::random(6),
    'msg'
])

@php($sender = $msg->sender())

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
        @else {{-- Other user or expert --}}
            <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="ms-5 w-12 h-12"/>
        @endif
    </div>
</div>
