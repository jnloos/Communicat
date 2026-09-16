@props([
    'name' => 'NV',
    'avatarUrl' => null
])

@if (!is_null($avatarUrl))
    <flux:avatar circle src="{{ $avatarUrl }}" alt="{{ __('common.avatar_alt', ['name' => $name]) }}" {{ $attributes->merge(['class' => 'cut-avatar']) }}/>
@else
    <flux:avatar circle name="{{ $name }}" color="auto" color:seed="{{ $name }}" {{ $attributes->merge(['class' => 'cut-avatar']) }}/>
@endif
