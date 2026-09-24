@props([
    'heading',
    'subheading' => null,
])

{{--
    A modal's title block, plus the place where its object actions live.

    Flux renders its own close button into "absolute top-0 end-0 mt-4 me-4", so
    the actions slot sits at the same offset shifted left by one button width
    (me-12 = 16px edge + 32px button) and wears the same ghost styling — the two
    have to read as one pair of controls, not as a button someone dropped next
    to the X.

    The title's right padding grows with it, so a long heading cannot run under
    either control.
--}}
<div {{ $attributes->class([isset($actions) ? 'pe-20' : 'pe-8']) }}>
    <div class="space-y-1">
        <flux:heading size="lg">{{ $heading }}</flux:heading>

        @if ($subheading)
            <flux:text size="sm">{{ $subheading }}</flux:text>
        @endif
    </div>

    @isset($actions)
        <div class="absolute top-0 end-0 mt-4 me-12">
            {{ $actions }}
        </div>
    @endisset
</div>
