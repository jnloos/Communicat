<section class="mx-auto w-full max-w-6xl">
    <x-page-header :heading="__('questions.heading')" :subheading="__('questions.subheading')">
        <x-slot:actions>
            <flux:button size="sm" :variant="$questions->isEmpty() ? 'primary' : 'outline'" icon="plus" wire:click="create">
                {{ __('questions.list.create') }}
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($templateNotice)
        <flux:callout class="mt-6" icon="information-circle" :text="$templateNotice" />
    @endif

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 space-y-6 lg:w-64">
            <div>
                <flux:heading size="sm" class="px-3">{{ __('questions.list.templates') }}</flux:heading>

                @if ($templates->isEmpty())
                    <flux:text size="sm" class="mt-2 px-3">{{ __('questions.list.no_templates') }}@if ($mayShare) {{ __('questions.list.no_templates_admin') }}@endif</flux:text>
                @else
                    {{-- The whole row drags; the menu offers the same move for keyboards and touch screens. Only admins order the templates, for everyone. --}}
                    <div class="mt-2 space-y-0.5" @if ($mayShare) wire:sort="sortTemplate" @endif>
                        @foreach ($templates as $template)
                            <x-statistics.list-row :entry="$template" :sentence="$sentences[$template->id]" :current="$questionId === $template->id" icon="rectangle-stack" :sortable="$mayShare">
                                {{-- Everyone may export a template's runs; only admins order it, only its admin owner renames or deletes it. --}}
                                <x-statistics.export-item :question-id="$template->id" />
                                @can('update', $template)
                                    <flux:menu.separator />
                                    <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $template->id }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                                @endcan
                                @if ($mayShare)
                                    <flux:menu.separator />
                                    <x-statistics.move-items :first="$loop->first" :last="$loop->last" action="moveTemplate({{ $template->id }}, %d)" />
                                @endif
                                @can('delete', $template)
                                    <flux:menu.separator />
                                    <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $template->id }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                                @endcan
                            </x-statistics.list-row>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <flux:heading size="sm" class="px-3">{{ __('questions.list.questions') }}</flux:heading>

                @if ($questions->isEmpty())
                    <flux:text size="sm" class="mt-2 px-3">{{ __('questions.list.empty') }}</flux:text>
                @else
                    <div class="mt-2 space-y-0.5" wire:sort="sortQuestion">
                        @foreach ($questions as $question)
                            <x-statistics.list-row :entry="$question" :sentence="$sentences[$question->id]" :current="$questionId === $question->id" sortable>
                                <x-statistics.export-item :question-id="$question->id" />
                                <flux:menu.separator />
                                <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $question->id }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                                <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $question->id }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                                <flux:menu.separator />
                                <x-statistics.move-items :first="$loop->first" :last="$loop->last" action="moveQuestion({{ $question->id }}, %d)" />
                                @if ($mayShare)
                                    <flux:menu.item icon="rectangle-stack" wire:click="saveAsTemplate({{ $question->id }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                                @endif
                                <flux:menu.separator />
                                <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $question->id }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                            </x-statistics.list-row>
                        @endforeach
                    </div>
                @endif
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            @if ($current)
                <livewire:statistics.question-editor :question-id="$current->id" wire:key="question-editor-{{ $current->id }}" />
            @else
                <x-empty-state icon="chart-bar">{{ __('questions.none_open') }}</x-empty-state>
            @endif
        </div>
    </div>

    <x-name-modal name="question-name" submit="saveName" model="name"
        :heading="__('questions.name.rename')" :label="__('questions.name.field')" :description="__('questions.name.hint')" />
</section>
