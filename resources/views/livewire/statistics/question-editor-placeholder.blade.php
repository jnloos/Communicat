{{-- Same blocks and gaps as question-editor, so nothing jumps when the editor arrives. --}}
<div class="space-y-6" role="status" aria-busy="true">
    <span class="sr-only">{{ __('common.loading') }}</span>

    <flux:skeleton.group animate="shimmer" class="space-y-6" aria-hidden="true">
        <flux:skeleton class="h-[34px] w-2/3 sm:w-1/2" />

        <div class="space-y-2">
            <flux:skeleton class="h-[20px] w-24" />
            <flux:skeleton class="h-[90px] w-full rounded-lg" />
        </div>

        <flux:skeleton class="h-[113px] w-full rounded-xl" />

        <div class="flex justify-center">
            <flux:skeleton class="h-[34px] w-56 rounded-lg" />
        </div>

        <flux:skeleton class="aspect-[2.8/1] h-auto w-full rounded-xl" />
    </flux:skeleton.group>
</div>
