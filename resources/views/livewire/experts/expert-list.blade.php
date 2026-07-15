@props([
    'experts' => [],
    'hasFilters' => false,
])

<div>
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Experts') }}</flux:heading>
        @can('admin')
            <flux:button variant="primary" @click="$wire.dispatch('edit_expert')" class="cursor-pointer">
                {{ __('Create Expert') }}
            </flux:button>
        @endcan
    </div>

    <div class="my-5 space-y-5">
        @can('admin')
            <livewire:experts.expert-editor/>
        @endcan
        <livewire:experts.expert-details-flyout/>

        <x-experts.filter-bar />

        @if($experts->isEmpty())
            <div class="text-center py-12 text-sm text-zinc-500 dark:text-zinc-400">
                @if($hasFilters)
                    {{ __('No experts match the current filters.') }}
                @else
                    {{ __('No experts yet.') }}
                @endif
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($experts as $expert)
                    @php($cardEvent = auth()->user()->can('admin') ? 'edit_expert' : 'open-expert-details')
                    @php($cardPayload = auth()->user()->can('admin') ? "{ id: {$expert->id} }" : "{ expertId: {$expert->id} }")
                    <div class="relative">
                        <x-contributors.contributors-card @click="$wire.dispatch('{{ $cardEvent }}', {{ $cardPayload }})"
                            :name="$expert->name"
                            :job="$expert->job"
                            :avatar-url="$expert->avatar_url ?? null"
                            :description="$expert->description"
                            :seed="$expert->id"
                        />
                        @can('admin')
                            @php($voiceLabel = \App\Support\VoiceCatalog::labelFor($expert->voice_id))
                            @php($voiceGender = \App\Support\VoiceCatalog::genderFor($expert->voice_id))
                            @if($voiceLabel)
                                <div class="pointer-events-none absolute top-2 end-2 inline-flex items-center gap-1
                                            rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $voiceGender === 'male'
                                                ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'
                                                : 'bg-pink-100 text-pink-800 dark:bg-pink-900/40 dark:text-pink-200' }}"
                                     title="{{ __('Stimme') }}: {{ $voiceLabel }}">
                                    <flux:icon.speaker-wave class="w-3 h-3"/>
                                    <span class="max-w-[8rem] truncate">{{ $voiceLabel }}</span>
                                </div>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
