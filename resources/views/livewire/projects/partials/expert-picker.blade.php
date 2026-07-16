{{-- Expert picker: shared between the admin tab layout and the non-admin
     single-panel layout of select-contributors. Inherits the parent scope. --}}
<div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
        @if(!$hasSuggestions)
            <flux:button
                size="sm"
                icon="sparkles"
                wire:click="suggestExperts"
                wire:loading.attr="disabled"
                wire:target="suggestExperts"
                class="cursor-pointer"
            >
                <span wire:loading.remove wire:target="suggestExperts">{{ __('Suggest experts') }}</span>
                <span wire:loading wire:target="suggestExperts">{{ __('Suggesting...') }}</span>
            </flux:button>
        @else
            <div class="flex items-center gap-2">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-path"
                    wire:click="suggestExperts"
                    wire:loading.attr="disabled"
                    wire:target="suggestExperts"
                    class="cursor-pointer"
                >
                    <span wire:loading.remove wire:target="suggestExperts">{{ __('Refresh suggestions') }}</span>
                    <span wire:loading wire:target="suggestExperts">{{ __('Suggesting...') }}</span>
                </flux:button>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="x-mark"
                    wire:click="clearSuggestions"
                    class="cursor-pointer"
                    :title="__('Clear suggestions')"
                />
            </div>
        @endif
    </div>

    @if($suggestionError)
        <p class="text-xs text-red-500 dark:text-red-400">{{ $suggestionError }}</p>
    @endif

    @if($limitWarning)
        <p class="text-xs rounded-md bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700/60 text-amber-800 dark:text-amber-200 px-3 py-2">
            {{ $limitWarning }}
        </p>
    @elseif(!$canAddExpert)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Limit erreicht: maximal :n Experten pro Projekt.', ['n' => $expertLimit]) }}
        </p>
    @endif

    <x-experts.filter-bar />

    @if($experts->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center py-6">
            @if($hasFilters)
                {{ __('No experts match the current filters.') }}
            @else
                {{ __('Keine Einträge') }}
            @endif
        </p>
    @else
        @foreach ($experts as $expert)
            @php($active = $expert->isContributing($project))
            @php($isSuggested = isset($suggestedIdSet[$expert->id]))
            @php($limitBlocked = !$active && !$canAddExpert)
            <div class="relative">
                <x-contributors.contributors-card
                    class="cursor-pointer {{ $limitBlocked ? 'opacity-50' : '' }} {{ $active ? 'ring-2 ring-primary' : '' }}"
                    :name="$expert->name"
                    :job="$expert->job"
                    :avatar-url="$expert->avatar_url ?? null"
                    :seed="$expert->id"
                    :suggested="$isSuggested"
                    :suggestion-reason="$isSuggested ? ($suggestionReasons[$expert->id] ?? null) : null"
                    wire:loading.attr="disabled"
                    wire:click="{{ $active ? 'removeExpert' : 'addExpert' }}({{ $expert->id }})"
                />
                <button
                    type="button"
                    title="{{ __('Details zu') }} {{ $expert->name }}"
                    @click.stop="$dispatch('open-expert-details', { expertId: {{ $expert->id }} })"
                    class="absolute top-2 end-2 z-10 rounded-full p-1 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200
                           cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                >
                    <flux:icon.information-circle class="w-5 h-5"/>
                </button>
            </div>
        @endforeach
    @endif
</div>
