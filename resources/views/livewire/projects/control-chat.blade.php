@props([
    // polling
    'shouldPoll' => false,

    // execution
    'disableAll' => false,

    // generation UI
    'showGenerate' => true,
    'showStop' => false,
])

<div class="fixed bottom-0 left-[15vw] w-[85vw] justify-center z-50 bg-none">
    <div class="max-w-[1120px] justify-center mx-auto">
        <div class="text-center">

            <!-- Textarea -->
            <div class="bg-white dark:bg-zinc-800 rounded-md">
                <flux:textarea
                    wire:model="msgContent"
                    resize="none"
                    rows="5"
                    class="w-full rounded-md"
                    :placeholder="__('Contribute to the specification...')"
                    :disabled="$disableAll"
                />
            </div>

            <!-- Controls -->
            <div class="flex gap-2 my-3">

                <!-- Summary -->
                <flux:button
                    class="basis-1/5 cursor-pointer"
                    wire:click.debounce="generateSummary"
                    icon="numbered-list"
                    :disabled="$disableAll"
                />

                <!-- Send message -->
                <flux:button
                    wire:click.debounce="sendMessage"
                    variant="primary"
                    class="basis-4/5 cursor-pointer"
                    :disabled="$disableAll"
                >
                    {{ __('Send Message') }}
                </flux:button>

                <!-- Generate -->
@if($showGenerate)
    <flux:button class="basis-1/5 cursor-pointer"
        wire:click.debounce="startGenerate"
        icon="play"
        :disabled="$disableAll"
    />
@endif

@if($showStop)
    <flux:button class="basis-1/5 cursor-pointer"
        wire:click.debounce="stopGenerate"
        icon="pause"
    />
@endif


            </div>
        </div>
    </div>

    @if($shouldPoll)
        <div wire:poll.2s="tick"></div>
    @endif
</div>
