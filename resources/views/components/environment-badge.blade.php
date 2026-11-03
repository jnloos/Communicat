{{-- Study data is generated only in the `study` environment (APP_ENV=study); everything else is exploration. --}}
@php($isStudy = app()->environment('study'))
<flux:badge size="sm" :color="$isStudy ? 'green' : 'amber'" data-environment="{{ $isStudy ? 'study' : 'test' }}" {{ $attributes }}>
    {{ __($isStudy ? 'navigation.environment_study' : 'navigation.environment_test') }}
</flux:badge>
