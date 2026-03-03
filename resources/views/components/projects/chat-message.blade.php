@use(App\Facades\Markdown)

@props([
    'id' => Str::random(6),
    'msg'
])

@php($contributor = $msg->contributor)
@php($unmaskedContributor = $contributor->unmask())

<div class="block">
    @if($contributor->isCurrUser())
        <div class="flex justify-end">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg min-w-sm z-0 px-5 pt-4 pb-8 break-words ms-30 bg-zinc-300 dark:bg-zinc-600">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $unmaskedContributor->name }}</flux:heading>
                <span class="markdown-html">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @elseif($contributor->isAssistant())
        <div class="flex justify-center">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg min-w-sm z-0 px-5 pt-4 pb-8 break-words bg-zinc-200">
                <flux:heading size="lg" class="mb-2 font-bold text-zinc-900">{{ __('Assistant') }}</flux:heading>
                <span class="markdown-html text-zinc-900">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @else {{-- Other user or expert --}}
        <div class="flex justify-start">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg min-w-sm z-0 px-5 pt-4 pb-8 break-words me-30 bg-zinc-100  dark:bg-zinc-700">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $unmaskedContributor->name }}</flux:heading>
                <span class="markdown-html">
                    {!! Markdown::parse($msg->content) !!}
                </span>
            </div>
        </div>
    @endif

    <div class="-translate-y-5">
        @if($contributor->isCurrUser())
            <x-contributors.contributors-avatar :name="$unmaskedContributor->name" :avatar-url="$unmaskedContributor->avatar_url" class="ms-auto me-5 w-12 h-12"/>
        @elseif($contributor->isAssistant())
            <x-contributors.contributors-avatar :name="__('Assistant')" :avatar-url="url('static/img/specificat-logo.svg')" class="mx-auto w-12 h-12 bg-white"/>
        @else {{-- Other user or expert --}}
            <x-contributors.contributors-avatar :name="$unmaskedContributor->name" :avatar-url="$unmaskedContributor->avatar_url" class="ms-5 w-12 h-12"/>
        @endif
    </div>
</div>
