{{-- Drag handle on the sidebar's inner edge; desktop only (below lg the sidebar is
     an overlay). Fixed, because the sticky sidebar scrolls and would carry an
     absolutely placed handle away. Limits live in app.css (--sidebar-min/-max). --}}
<div
    x-data="sidebarResize(@js(config('ui.sidebar_width_key')))"
    x-on:pointerdown="start($event)"
    x-on:pointermove="drag($event)"
    x-on:pointerup="stop($event)"
    x-on:pointercancel="stop($event)"
    x-on:keydown="key($event)"
    x-on:dblclick="reset()"
    x-on:resize.window.debounce.100ms="sync()"
    role="separator"
    aria-orientation="vertical"
    aria-label="{{ __('navigation.resize_sidebar') }}"
    title="{{ __('navigation.resize_hint') }}"
    :aria-valuenow="now"
    :aria-valuemin="min"
    :aria-valuemax="max"
    tabindex="0"
    data-sidebar-resize-handle
    class="group fixed start-[calc(var(--sidebar-current)-0.5rem)] top-0 z-10 hidden h-dvh w-4 cursor-col-resize touch-none outline-none lg:block"
>
    <div class="mx-auto h-full w-px bg-zinc-200 transition-colors dark:bg-white/10 group-hover:w-0.5 group-hover:bg-accent dark:group-hover:bg-accent group-focus-visible:w-0.5 group-focus-visible:bg-accent dark:group-focus-visible:bg-accent group-data-[dragging]:w-0.5 group-data-[dragging]:bg-accent dark:group-data-[dragging]:bg-accent"></div>
</div>
