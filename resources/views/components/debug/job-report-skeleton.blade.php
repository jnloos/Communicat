{{-- Placeholder for x-debug.job-report while the flyout fetches its jobs: the same compact table
     (header and rows as tall as the real ones), so the list does not jump when it arrives. --}}
{{-- The timer starts with the click that opens the flyout (the skeleton is in the page before that); reopening sends a new request. --}}
<div role="status" aria-busy="true" data-job-report-skeleton
     x-data="{ slow: false, timer: null }"
     x-on:open-job-debug.window="slow = false; clearTimeout(timer); timer = setTimeout(() => slow = true, 15000)">
    <flux:callout x-show="slow" x-cloak variant="warning" icon="exclamation-triangle" role="alert" class="mb-4" :text="__('debug.load_failed')" />
    <span class="sr-only">{{ __('common.loading') }}</span>
    <flux:skeleton.group animate="shimmer" class="flex flex-col gap-[4px]" aria-hidden="true">
        <flux:skeleton class="h-[35px] w-full" />
        @foreach (range(1, 16) as $row)
            <flux:skeleton class="h-[36px] w-full" />
        @endforeach
    </flux:skeleton.group>
</div>
