<div>
    <x-flyout name="expert-thoughts-flyout" width="560px" :flex="false">
        @if ($expert)
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-contributors.contributors-avatar
                        :name="$expert->name"
                        :avatar-url="$expert->avatar_url"
                        class="w-12 h-12"
                    />
                    <div>
                        <flux:heading size="lg">{{ $expert->name }}</flux:heading>
                        <flux:text>{{ $expert->role }}</flux:text>
                    </div>
                </div>

                <flux:subheading class="text-xs font-medium uppercase tracking-wide">
                    {{ __('experts.memory.heading') }}
                </flux:subheading>

                @if ($thought === null)
                    <x-empty-state icon="light-bulb">
                        {{ __('experts.memory.empty', ['name' => $expert->name]) }}
                    </x-empty-state>
                @else
                    <flux:text class="whitespace-pre-wrap max-h-[70vh] overflow-y-auto pr-1">{{ $thought }}</flux:text>
                @endif
            </div>
        @else
            <flux:text class="py-8 text-center">{{ __('experts.memory.none_selected') }}</flux:text>
        @endif
    </x-flyout>
</div>
