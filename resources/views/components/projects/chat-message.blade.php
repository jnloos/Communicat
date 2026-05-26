@use(App\Facades\Markdown)

@props([
    'id' => Str::random(6),
    'msg'
])

@php
    $sender = $msg->sender();

    // Resolve the "speaks to" target from the polymorphic adjacency_partner:
    // an expert or a user (no global name lookup). No self-arrow.
    $partner = $msg->adjacencyPartner;
    $addressedIsExpert = $partner instanceof \App\Models\Expert;
    $addressed = null;
    if ($addressedIsExpert && $partner->id !== $msg->expert_id) {
        $addressed = $partner;
    } elseif ($partner instanceof \App\Models\User && !($msg->isUser() && $partner->id === $msg->user_id)) {
        $addressed = $partner;
    }

    // Render the message body and rewrite "@PersonaName" into clickable badges
    // that open the matching expert's thoughts flyout. Longest contributor name
    // first so "@Sophie Wagner" wins over a partial "@Sophie" match.
    $renderedContent = Markdown::parse($msg->content);

    $projectContributors = \App\Models\Expert::whereHas(
            'projects',
            fn($q) => $q->whereKey($msg->project_id)
        )
        ->get(['id', 'name'])
        ->sortByDesc(fn($e) => mb_strlen($e->name));

    foreach ($projectContributors as $contributor) {
        $pattern = '/(?<![\w@])@' . preg_quote($contributor->name, '/') . '(?!\w)/u';
        // @-mentions are only emphasised (bold) in the message body. The
        // adjacency-pair target is shown separately by the arrow below the
        // bubble, so the mention itself is no longer an interactive button.
        $replacement = sprintf('<strong class="font-semibold">@%s</strong>', e($contributor->name));
        $renderedContent = preg_replace($pattern, $replacement, $renderedContent);
    }
@endphp

<div class="block">
    @if($msg->isCurrUser())
        <div class="flex justify-end">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 pt-4 pb-8 break-words ms-2 sm:ms-30 bg-zinc-300 dark:bg-zinc-600">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $sender->name }}</flux:heading>
                <span class="markdown-html">
                    {!! $renderedContent !!}
                </span>
            </div>
        </div>
    @elseif($msg->isAssistant())
        <div class="flex justify-center mt-2">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 py-4 break-words bg-zinc-200">
                <span class="markdown-html text-zinc-900">
                    {!! $renderedContent !!}
                </span>
            </div>
        </div>
    @else {{-- Other user or expert --}}
        <div class="flex justify-start">
            <div id="{{ $id }}" wire:key="{{ $id }}" class="rounded-lg w-full sm:w-auto sm:min-w-sm z-0 px-5 pt-4 pb-8 break-words me-2 sm:me-30 bg-zinc-100 dark:bg-zinc-700">
                <flux:heading size="lg" class="mb-2 font-bold">{{ $sender->name }}</flux:heading>
                <span class="markdown-html">
                    {!! $renderedContent !!}
                </span>
            </div>
        </div>
    @endif

    <div class="-translate-y-5">
        @if($msg->isCurrUser())
            <div class="flex items-center justify-end me-5 gap-2">
                @if ($addressed)
                    <x-projects.addressed-arrow :addressed="$addressed" :is-expert="$addressedIsExpert" flip />
                @endif
                <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="w-12 h-12"/>
            </div>
        @elseif($msg->isAssistant())
            {{-- No avatar for assistant messages --}}
        @elseif($msg->isExpert())
            <div class="flex items-center ms-5 gap-2">
                <div class="relative group">
                    <button
                        type="button"
                        title="{{ __('Gedanken von') }} {{ $sender->name }}"
                        @click="$dispatch('open-expert-thoughts', { expertId: {{ $sender->id }} })"
                        class="rounded-full cursor-pointer transition-transform hover:scale-105 group-hover:scale-105 group-active:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                    >
                        <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="w-12 h-12"/>
                    </button>
                    <button
                        type="button"
                        title="{{ __('Gedächtnis anzeigen') }}"
                        @click="$dispatch('open-expert-thoughts', { expertId: {{ $sender->id }} })"
                        class="absolute -bottom-1 -right-1 inline-flex items-center justify-center
                               w-5 h-5 rounded-full
                               bg-white dark:bg-zinc-800
                               ring-2 ring-white dark:ring-zinc-800
                               text-zinc-500 dark:text-zinc-300
                               hover:text-amber-600 dark:hover:text-amber-400
                               group-hover:text-amber-600 dark:group-hover:text-amber-400
                               group-active:text-amber-600 dark:group-active:text-amber-400
                               cursor-pointer transition-colors
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                        aria-label="{{ __('Gedächtnis anzeigen') }}"
                    >
                        <x-icons.brain class="w-3 h-3"/>
                    </button>
                </div>

                @if ($addressed)
                    <x-projects.addressed-arrow :addressed="$addressed" :is-expert="$addressedIsExpert" />
                @endif
            </div>
        @else {{-- Other user --}}
            <div class="flex items-center ms-5 gap-2">
                <x-contributors.contributors-avatar :name="$sender->name" :avatar-url="$sender->avatar_url" class="w-12 h-12"/>
                @if ($addressed)
                    <x-projects.addressed-arrow :addressed="$addressed" :is-expert="$addressedIsExpert" />
                @endif
            </div>
        @endif
    </div>
</div>
