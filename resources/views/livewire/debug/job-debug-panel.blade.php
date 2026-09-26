<section class="mx-auto w-full max-w-6xl">
    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ __('debug.title') }}</flux:heading>
                <flux:text class="mt-1">{{ __('debug.subheading') }}</flux:text>
            </div>

            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                <flux:select wire:model.live="projectId" :placeholder="__('debug.select_project')" class="min-w-0 flex-1 sm:w-72">
                    @foreach ($projects as $project)
                        <flux:select.option :value="$project->id">{{ $project->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Live / Pause toggle --}}
                <flux:tooltip :content="$live ? __('debug.live.pause_hint') : __('debug.live.resume_hint')" position="bottom">
                    <flux:button wire:click="togglePause" :icon="$live ? 'pause' : 'play'" class="shrink-0 cursor-pointer">
                        <span class="inline-flex items-center gap-2">
                            @if ($live)
                                <span class="relative flex size-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                                </span>
                                {{ __('debug.live.on') }}
                            @else
                                <span class="inline-flex size-2 rounded-full bg-amber-500"></span>
                                {{ __('debug.live.paused') }}
                            @endif
                        </span>
                    </flux:button>
                </flux:tooltip>
            </div>
        </div>
        <x-debug.job-report :logs="$logs" :selected="$selected" :selected-job-id="$selectedJobId" />
    </div>
</section>
