<div class="mx-auto w-full max-w-6xl">
    <x-page-header :heading="__('experts.list.heading')" :subheading="trans_choice('experts.list.count', $experts->count())" align="center">
        @can('create', App\Models\Expert::class)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" @click="$wire.dispatch('edit_expert')">
                    {{ __('experts.list.create') }}
                </flux:button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="my-6 space-y-5">
        <livewire:experts.expert-editor/>

        <x-experts.filter-bar :tag-categories="$tagCategories" :active-tag-count="$activeTagCount" />

        @if($experts->isEmpty())
            <x-empty-state :icon="$hasFilters ? 'magnifying-glass' : 'user-circle'">
                @if($hasFilters)
                    {{ __('experts.list.no_matches') }}
                @else
                    {{ __('experts.list.empty') }}
                @endif
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($experts as $expert)
                    <x-contributors.contributors-card
                        wire:key="expert-{{ $expert->id }}"
                        @click="$wire.dispatch('edit_expert', { id: {{ $expert->id }} })"
                        :name="$expert->name"
                        :role="$expert->role"
                        :avatar-url="$expert->avatar_url ?? null"
                        :description="Str::limit($expert->description, 140)"
                    >
                        @if ($expert->tagValues->isNotEmpty())
                            <x-slot:badges>
                                <x-tags.badges :values="$expert->tagValues" class="justify-end" />
                            </x-slot:badges>
                        @endif
                    </x-contributors.contributors-card>
                @endforeach
            </div>
        @endif
    </div>
</div>
