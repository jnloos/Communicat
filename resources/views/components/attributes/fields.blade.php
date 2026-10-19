@props([
    'definitions', {{-- Collection<PersonaAttribute>, in attribute order --}}
    'model' => 'attributeValues', {{-- the parent's property: attribute id => text, '' for not set --}}
    'disabled' => false, {{-- read only, e.g. the expert flyout for a non-admin --}}
])

{{-- One text field per defined attribute, bound straight to the parent form's
     property like x-tags.fields: the form loads it and stores it in its own
     save() with syncAttributeValues(), in the same request and under the same
     authorization. Under each field the sentence the persona reads is built
     in the browser from the self template and the typed value (no round trip).
     An empty field means the attribute is not set. --}}
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($definitions as $attribute)
        <div
            wire:key="attribute-field-{{ $attribute->id }}"
            x-data="{
                template: @js($attribute->self_template),
                get value() { return String($wire.{{ $model }}?.[{{ $attribute->id }}] ?? '').trim() },
                get sentence() { return this.value === '' ? '' : this.template.split(@js(\App\Models\PersonaAttribute::PLACEHOLDER)).join(this.value) },
            }"
            class="space-y-1.5"
        >
            <flux:input
                wire:model.defer="{{ $model }}.{{ $attribute->id }}"
                :label="$attribute->name"
                :placeholder="$disabled ? __('experts.attributes.not_set') : __('experts.attributes.value_placeholder')"
                :maxlength="\App\Models\PersonaAttribute::VALUE_MAX"
                :disabled="$disabled"
            />
            <flux:text size="xs" class="min-h-4" data-attribute-sentence>
                <span x-show="sentence !== ''" x-cloak>{{ __('experts.attributes.persona_reads') }} <span x-text="sentence"></span></span>
            </flux:text>
        </div>
    @endforeach
</div>
