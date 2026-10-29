{{-- Runs in <head>, before the sidebar exists: the stored width is on <html> as a
     CSS variable by first paint, so a wider sidebar never jumps from narrow. CSS
     clamps it. wire:navigate replaces <html>'s attributes, so app.js calls this
     again on livewire:navigated. --}}
<script>
    window.applyStoredSidebarWidth = () => {
        try {
            const px = parseFloat(localStorage.getItem(@js(config('ui.sidebar_width_key'))));
            if (px > 0) document.documentElement.style.setProperty('--sidebar-width', px + 'px');
        } catch (e) {}
    };
    window.applyStoredSidebarWidth();
</script>
