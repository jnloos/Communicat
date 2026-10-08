<div class="space-y-6">
    @if ($droppedTagValues > 0)
        <flux:callout variant="warning" icon="exclamation-triangle"
            :heading="trans_choice('comparisons.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues])" />
    @endif

    <section class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('comparisons.series.heading') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addSeries" class="cursor-pointer">{{ __('comparisons.series.add') }}</flux:button>
        </div>

        @if ($definitions === [])
            <x-empty-state icon="queue-list">{{ __('comparisons.series.empty') }}</x-empty-state>
        @endif

        @foreach ($definitions as $index => $definition)
            @php($referenceType = $series[$index]['reference']['type'] ?? 'each')

            <flux:card wire:key="series-{{ $index }}" class="space-y-5">
                <div class="flex items-end gap-3">
                    {{-- series-dot --}}
                    <flux:input wire:model.blur="series.{{ $index }}.label" :label="__('comparisons.series.label')" class="flex-1" />
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeSeries({{ $index }})"
                        class="cursor-pointer" :aria-label="__('comparisons.series.remove')" />
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    {{-- Which runs --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.series.projects') }}</flux:heading>

                        @foreach ($projectCategories as $category)
                            <flux:select variant="listbox" multiple
                                wire:key="series-{{ $index }}-project-tags-{{ $category->id }}"
                                wire:model.live="series.{{ $index }}.projects.tags.{{ $category->id }}"
                                :label="$category->name" :placeholder="__('comparisons.series.any')">
                                @foreach ($category->values as $value)
                                    <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach

                        <flux:select wire:model.live="series.{{ $index }}.projects.pipeline" :label="__('comparisons.series.pipeline')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="series.{{ $index }}.projects.model" :label="__('comparisons.series.model')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($models as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <div>
                            <flux:text size="sm">{{ trans_choice('comparisons.series.matches', $matches[$index]->count(), ['count' => $matches[$index]->count()]) }}</flux:text>
                            @if ($matches[$index]->isNotEmpty())
                                <ul class="mt-1 max-h-32 overflow-y-auto text-xs text-zinc-500 dark:text-zinc-400">
                                    @foreach ($matches[$index] as $project)
                                        <li wire:key="series-{{ $index }}-match-{{ $project->id }}" class="truncate">{{ $project->title }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    {{-- Which agents --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.reference.heading') }}</flux:heading>

                        <flux:select wire:model.live="series.{{ $index }}.reference.type" :label="__('comparisons.reference.type')">
                            @foreach ($referenceTypes as $type)
                                <option value="{{ $type }}">{{ __("comparisons.reference.types.{$type}") }}</option>
                            @endforeach
                        </flux:select>

                        @switch($referenceType)
                            @case('persona_tags')
                                @foreach ($personaCategories as $category)
                                    <flux:select variant="listbox" multiple
                                        wire:key="series-{{ $index }}-persona-tags-{{ $category->id }}"
                                        wire:model.live="series.{{ $index }}.reference.tags.{{ $category->id }}"
                                        :label="$category->name" :placeholder="__('comparisons.series.any')">
                                        @foreach ($category->values as $value)
                                            <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endforeach
                                @break

                            @case('seat')
                                <flux:input type="number" min="1" wire:model.live.debounce.500ms="series.{{ $index }}.reference.seat" :label="__('comparisons.reference.seat')" />
                                @break

                            @case('expert_name')
                                <flux:select wire:model.live="series.{{ $index }}.reference.name" :label="__('comparisons.reference.expert_name')">
                                    <option value="">—</option>
                                    @foreach ($expertNames as $expertName)
                                        <option value="{{ $expertName }}">{{ $expertName }}</option>
                                    @endforeach
                                </flux:select>
                                @break
                        @endswitch

                        <flux:text size="sm">{{ __('comparisons.reference.shared_hint') }}</flux:text>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </section>

    {{-- results --}}
</div>