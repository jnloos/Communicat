{{-- Study-only, removable: see app/Study/README.md. Upload, preview, confirm; the writing runs in ApplyWave. --}}
@php($failed = $applyingWave?->hasFailed() ?? false)
@php($writing = $applyingWave !== null && ! $applyingWave->isApplied() && ! $failed)

<section class="mx-auto w-full max-w-6xl" @if ($writing) wire:poll.5s @endif>
    <x-page-header :heading="__('study::plan.heading')" :subheading="__('study::plan.subheading')">
        <x-slot:actions>
            <flux:button size="sm" icon="arrow-left" :href="route('study.progress')" wire:navigate>{{ __('study::plan.back') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($failed)
        <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" data-failed
            :heading="__('study::plan.failed', ['done' => $written, 'total' => $applyingWave->plannedRuns()])" :text="$applyingWave->failed_reason" />
    @elseif ($applyingWave !== null)
        <flux:callout class="mt-6" :variant="$writing ? 'secondary' : 'success'" :icon="$writing ? 'arrow-path' : 'check-circle'" data-applying
            :heading="$writing
                ? __('study::plan.applying', ['done' => $written, 'total' => $applyingWave->plannedRuns()])
                : __('study::plan.applied', ['total' => $written])" />
    @endif

    <flux:card class="mt-6 space-y-4">
        <flux:file-upload wire:model="manifest" accept=".json,application/json" :label="__('study::plan.manifest')">
            <flux:file-upload.dropzone inline role="button" :aria-label="__('study::plan.manifest')"
                :heading="$manifest?->getClientOriginalName() ?? __('study::plan.dropzone_heading')"
                :text="__('study::plan.dropzone_text', ['mb' => $maxMegabytes])" />
        </flux:file-upload>
        <flux:error name="manifest" />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input type="number" min="1" wire:model="wave" :label="__('study::plan.wave')" />
            <flux:input type="number" min="1" wire:model="from" :label="__('study::plan.from')" />
            <flux:input type="number" min="1" wire:model="to" :label="__('study::plan.to')" />
        </div>
        <flux:textarea wire:model="decision" rows="3" :label="__('study::plan.decision')" :description="__('study::plan.decision_help')" />

        <div class="flex items-center justify-end gap-2">
            <flux:button wire:click="preview" icon="eye">{{ __('study::plan.preview') }}</flux:button>
            <flux:button variant="primary" icon="play" wire:click="confirmApply"
                :disabled="$overview === [] || $overview['problems'] !== []">{{ __('study::plan.apply') }}</flux:button>
        </div>
    </flux:card>

    @if ($overview !== [])
        @if ($overview['problems'] !== [])
            <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" :heading="__('study::plan.problems')" data-problems>
                <x-slot:text>
                    <ul class="list-disc ps-5">
                        @foreach ($overview['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </x-slot:text>
            </flux:callout>
        @endif

        @foreach (\App\Study\Planning\PlanPreview::tables($overview) as $table)
            @include('study::partials.table', ['table' => $table])
        @endforeach

        @if ($overview['runs']['total'] > 0)
            <flux:text class="mt-3">{{ __('study::plan.duplicates', ['count' => $overview['duplicate_full_names']]) }}</flux:text>
        @endif

        @foreach ($overview['prompts'] as $scenario => $prompt)
            <flux:card class="mt-6">
                <flux:heading>{{ __('study::plan.prompt', ['scenario' => $scenario]) }}</flux:heading>
                <pre class="mt-3 whitespace-pre-wrap font-mono text-xs">{{ $prompt }}</pre>
            </flux:card>
        @endforeach
    @endif
</section>
