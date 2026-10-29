@props([
    'key', {{-- translation key whose text holds the :link placeholder --}}
    'href',
    'label',
])

{{-- A translated sentence with one link. Text and label are escaped; only the
     anchor we build ourselves stays markup, so nothing a string contains can
     inject HTML. --}}
@php
    $anchor = '<a href="'.e($href).'" wire:navigate class="underline underline-offset-2">'.e($label).'</a>';
@endphp
{!! str_replace('%LINK%', $anchor, e(__($key, ['link' => '%LINK%']))) !!}
