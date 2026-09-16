@props(['project'])

{{--
    Live "thinking bubble" / typing indicator for the generation pipeline.
    Driven purely client-side by the PipelineStageChanged broadcasts (see
    resources/js/app.js → Alpine.data('pipelineIndicator')), so stage changes
    never trigger a Livewire round-trip. Cleared by MessageGenerated and
    GenerationStopped.
--}}
<div
    x-data="pipelineIndicator({{ $project->id }}, @js([
        'writingOne'  => trans_choice('chat.indicator.writing', 1),
        'writingMany' => trans_choice('chat.indicator.writing', 2),
        'nextIn'      => __('chat.indicator.next_in'),
        'nextSoon'    => __('chat.indicator.next_soon'),
    ]))"
    x-show="stage !== null"
    x-cloak
    x-transition.opacity
    class="flex items-start gap-3"
    role="status"
    aria-live="polite"
>
    <div class="flex shrink-0 -space-x-3" x-show="experts.length > 0">
        <template x-for="expert in experts" :key="expert.id">
            <span class="inline-flex">
                <img
                    x-show="expert.avatar_url"
                    :src="expert.avatar_url"
                    :alt="expert.name"
                    class="size-9 rounded-full object-cover ring-2 ring-white sm:size-10 dark:ring-zinc-800"
                >
                <span
                    x-show="!expert.avatar_url"
                    class="flex size-9 items-center justify-center rounded-full bg-zinc-300 text-xs font-semibold ring-2 ring-white sm:size-10 dark:bg-zinc-600 dark:ring-zinc-800"
                    x-text="expert.name.charAt(0)"
                ></span>
            </span>
        </template>
    </div>

    <div class="flex min-w-0 items-center gap-3 rounded-2xl rounded-tl-md bg-zinc-100 px-4 py-3 dark:bg-zinc-700">
        <span class="truncate text-sm text-zinc-500 italic dark:text-zinc-300" x-text="label()"></span>
        <span class="flex shrink-0 items-center gap-1 text-zinc-400 dark:text-zinc-300" aria-hidden="true">
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
        </span>
    </div>
</div>
