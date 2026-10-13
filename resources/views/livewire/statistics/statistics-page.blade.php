<section class="mx-auto w-full max-w-6xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('questions.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('questions.subheading') }}</flux:text>
        </div>

        <flux:button size="sm" :variant="$questions->isEmpty() ? 'primary' : 'outline'" icon="plus" wire:click="create">
            {{ __('questions.list.create') }}
        </flux:button>
    </div>

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
                            @php($owns = $template->user_id === auth()->id())
                            <div class="relative" wire:key="template-{{ $template->id }}" @if ($mayShare) wire:sort:item="{{ $template->id }}" @endif>
                                <x-statistics.entry :title="$template->name" :notes="$template->notes" :sentence="$sentences[$template->id]" :current="$questionId === $template->id"
                                    icon="rectangle-stack" with-menu wire:click="open({{ $template->id }})" />

                                {{-- Everyone may export a template's runs; only admins order it, only its admin owner renames or deletes it. --}}
                                <div class="absolute end-1 top-1.5" wire:sort:ignore>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                            class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                        <flux:menu>
                                            <x-statistics.export-item :question-id="$template->id" />
                                            @if ($mayShare && $owns)
                                                <flux:menu.separator />
                                                <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $template->id }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                                            @endif
                                            @if ($mayShare)
                                                <flux:menu.separator />
                                                <x-statistics.move-items :first="$loop->first" :last="$loop->last" action="moveTemplate({{ $template->id }}, %d)" />
                                            @endif
                                            @if ($mayShare && $owns)
                                                <flux:menu.separator />
                                                <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $template->id }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
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
                            <div class="relative" wire:key="question-{{ $question->id }}" wire:sort:item="{{ $question->id }}">
                                <x-statistics.entry :title="$question->name" :notes="$question->notes" :sentence="$sentences[$question->id]" :current="$questionId === $question->id"
                                    with-menu wire:click="open({{ $question->id }})" />

                                <div class="absolute end-1 top-1.5" wire:sort:ignore>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                            class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                        <flux:menu>
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
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
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

    <flux:modal name="question-name" class="md:w-96">
        <form wire:submit.prevent="saveName" class="space-y-6">
            <x-modal-header :heading="__('questions.name.rename')" />

            <flux:input wire:model="name" :label="__('questions.name.field')" :description="__('questions.name.hint')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
