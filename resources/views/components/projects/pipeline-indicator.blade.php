@props(['project'])

{{--
    Live "thinking bubble" / typing indicator for the generation pipeline.
    Driven purely client-side by the PipelineStageChanged broadcasts (see
    resources/js/app.js → Alpine.data('pipelineIndicator')), so stage changes
    never trigger a Livewire round-trip. Cleared by MessageGenerated,
    GenerationStopped and UserInputRequested.
--}}
<div
    x-data="pipelineIndicator({{ $project->id }})"
    x-show="stage !== null"
    x-cloak
    class="flex justify-start"
>
    <div class="rounded-lg z-0 px-5 py-4 me-2 sm:me-30 bg-zinc-100 dark:bg-zinc-700 flex items-center gap-3">
        <div class="flex -space-x-3" x-show="experts.length > 0">
            <template x-for="expert in experts" :key="expert.id">
                <span class="inline-flex">
                    <img
                        x-show="expert.avatar_url"
                        :src="expert.avatar_url"
                        :alt="expert.name"
                        class="w-8 h-8 rounded-full object-cover ring-2 ring-zinc-100 dark:ring-zinc-700"
                    >
                    <span
                        x-show="!expert.avatar_url"
                        class="w-8 h-8 rounded-full bg-zinc-300 dark:bg-zinc-600 flex items-center justify-center text-xs font-semibold ring-2 ring-zinc-100 dark:ring-zinc-700"
                        x-text="expert.name.charAt(0)"
                    ></span>
                </span>
            </template>
        </div>
        <span class="text-sm italic text-zinc-500 dark:text-zinc-300" x-text="label()"></span>
        <span class="flex items-center gap-1 text-zinc-400 dark:text-zinc-300" aria-hidden="true">
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
        </span>
    </div>
</div>
