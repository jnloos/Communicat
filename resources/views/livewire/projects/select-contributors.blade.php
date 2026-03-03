@props([
    'project',
    'experts'
])

<flux:modal name="select-contributors" variant="flyout" class="md:w-96">
    <flux:heading size="lg">
        {{ __('Choose Experts') }}
    </flux:heading>
    <flux:spacer/>
    <div class="space-y-4 mt-5">
        @foreach ($experts as $expert)
            @php($active = $expert->isContributing($project))
            <x-contributors.contributors-card class="cursor-pointer {{ $active ? 'ring-2 ring-primary' : '' }}"
                :name="$expert->name"
                :job="$expert->job"
                :avatar-url="$expert->avatar_url ?? null"
                :seed="$expert->id"
                wire:loading.attr="disabled"
                wire:click="{{ $active ? 'removeExpert' : 'addExpert' }}({{ $expert->id }})"
            />
        @endforeach
    </div>
</flux:modal>
