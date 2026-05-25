@props([
    '$isUpdate' => false,
    'voices' => [],
])

<flux:modal name="edit-expert" variant="flyout" class="md:w-[52rem]">
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="space-y-1">
            <flux:heading size="lg">
                {{ $isUpdate ? __('Update Expert') : __('Create Expert') }}
            </flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Define this expert\'s identity, persona and voice.') }}
            </flux:text>
        </div>

        {{-- Avatar --}}
        <div class="flex flex-col items-center gap-3">
            <input type="file" class="hidden" wire:model="avatarUpload" accept="image/*" x-ref="fileInput"/>
            <button type="button" @click="$refs.fileInput.click()"
                class="group rounded-full cursor-pointer transition-transform hover:scale-105
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2"
                wire:loading.class="opacity-60" wire:target="avatarUpload">
                <div class="rounded-full ring-2 ring-transparent group-hover:ring-amber-400 transition-shadow">
                    @if (!is_null($avatarUrl))
                        <flux:avatar circle src="{!! $avatarUrl !!}" alt="{{ $name }} Avatar" class="cut-avatar w-32 h-32" wire:key="avatar-{{ $avatarUrl }}">
                            <x-slot:badge class="h-8 w-8 translate-y-4">
                                <flux:icon.pencil/>
                            </x-slot:badge>
                        </flux:avatar>
                    @else
                        <flux:avatar circle name="{{ $name }}" color="auto" color:seed="{{ $name }}" class="cut-avatar w-32 h-32" wire:key="initials-{{ $name }}">
                            <x-slot:badge class="h-8 w-8 translate-y-4">
                                <flux:icon.pencil/>
                            </x-slot:badge>
                        </flux:avatar>
                    @endif
                </div>
            </button>
            <flux:text size="xs" class="text-zinc-400 dark:text-zinc-500">
                <span wire:loading.remove wire:target="avatarUpload">{{ __('Click to change avatar') }}</span>
                <span wire:loading wire:target="avatarUpload">{{ __('Uploading…') }}</span>
            </flux:text>
        </div>

        <flux:accordion transition>
            {{-- Identity --}}
            <x-accordion-section :heading="__('Identity')" icon="identification" :error-fields="['name', 'job', 'description', 'tagsInput']">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input :label="__('Name')" wire:model.defer="name" />
                    <flux:input :label="__('Job')" wire:model.defer="job" />
                </div>
                <flux:textarea
                    :label="__('Description')"
                    :description="__('Kurze Beschreibung, die in der UI angezeigt wird.')"
                    wire:model.defer="description"
                    rows="4"
                />
                {{-- <flux:input
                    :label="__('Tags')"
                    :description="__('Kommagetrennt, z. B. Engineering, AI, Design')"
                    wire:model.defer="tagsInput"
                /> --}}
            </x-accordion-section>

            {{-- Persona --}}
            <x-accordion-section :heading="__('Prompt')" icon="sparkles" :expanded="false" :error-fields="['profile', 'style']">
                <flux:textarea
                    :label="__('Profil')"
                    :description="__('Wer ist diese Person? Hintergrund, Rolle, Erfahrung.')"
                    wire:model.defer="profile"
                    rows="4"
                />

                {{-- Kernüberzeugungen --}}
                <div class="space-y-3">
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Kernüberzeugungen') }}</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Welche Positionen, Werte und Überzeugungen vertritt diese Person?') }}</p>
                    </div>

                    <div class="space-y-2">
                        @forelse($coreBeliefs as $i => $belief)
                            <div class="flex gap-2 items-start" wire:key="cb-{{ $i }}">
                                <span class="flex-shrink-0 mt-[0.6rem] w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-xs flex items-center justify-center font-semibold select-none">{{ $i + 1 }}</span>
                                <flux:textarea
                                    wire:model.defer="coreBeliefs.{{ $i }}"
                                    rows="2"
                                    class="flex-1"
                                    placeholder="{{ __('Überzeugung beschreiben…') }}"
                                />
                                <button
                                    type="button"
                                    wire:click="removeCoreBelief({{ $i }})"
                                    class="flex-shrink-0 mt-[0.55rem] p-1.5 rounded-md text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                                    title="{{ __('Entfernen') }}"
                                >
                                    <flux:icon.x-mark class="size-4" />
                                </button>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400 dark:text-zinc-500 italic py-1">{{ __('Noch keine Überzeugungen hinzugefügt.') }}</p>
                        @endforelse
                    </div>

                    <button
                        type="button"
                        wire:click="addCoreBelief"
                        wire:loading.attr="disabled"
                        wire:target="addCoreBelief"
                        class="w-full py-2.5 border border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg text-sm text-zinc-500 dark:text-zinc-400 hover:text-amber-600 dark:hover:text-amber-400 hover:border-amber-400 dark:hover:border-amber-500 flex items-center justify-center gap-2 transition-colors duration-150 disabled:opacity-50"
                    >
                        <flux:icon.plus class="size-4" />
                        {{ __('Überzeugung hinzufügen') }}
                    </button>
                </div>

                {{-- Wissensgrenzen --}}
                <div class="space-y-3">
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Wissensgrenzen') }}</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Was weiß diese Person nicht, oder worüber äußert sie sich nicht?') }}</p>
                    </div>

                    <div class="space-y-2">
                        @forelse($knowledgeLimits as $i => $limit)
                            <div class="flex gap-2 items-start" wire:key="kl-{{ $i }}">
                                <span class="flex-shrink-0 mt-[0.6rem] w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-xs flex items-center justify-center font-semibold select-none">{{ $i + 1 }}</span>
                                <flux:textarea
                                    wire:model.defer="knowledgeLimits.{{ $i }}"
                                    rows="2"
                                    class="flex-1"
                                    placeholder="{{ __('Grenze oder Einschränkung beschreiben…') }}"
                                />
                                <button
                                    type="button"
                                    wire:click="removeKnowledgeLimit({{ $i }})"
                                    class="flex-shrink-0 mt-[0.55rem] p-1.5 rounded-md text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                                    title="{{ __('Entfernen') }}"
                                >
                                    <flux:icon.x-mark class="size-4" />
                                </button>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400 dark:text-zinc-500 italic py-1">{{ __('Noch keine Wissensgrenzen hinzugefügt.') }}</p>
                        @endforelse
                    </div>

                    <button
                        type="button"
                        wire:click="addKnowledgeLimit"
                        wire:loading.attr="disabled"
                        wire:target="addKnowledgeLimit"
                        class="w-full py-2.5 border border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg text-sm text-zinc-500 dark:text-zinc-400 hover:text-amber-600 dark:hover:text-amber-400 hover:border-amber-400 dark:hover:border-amber-500 flex items-center justify-center gap-2 transition-colors duration-150 disabled:opacity-50"
                    >
                        <flux:icon.plus class="size-4" />
                        {{ __('Grenze hinzufügen') }}
                    </button>
                </div>

                <flux:textarea
                    :label="__('Stil')"
                    :description="__('Wie spricht und argumentiert diese Person? Ton, Rhetorik, Eigenheiten.')"
                    wire:model.defer="style"
                    rows="4"
                />
            </x-accordion-section>

            {{-- Voice --}}
            <x-accordion-section :heading="__('Voice')" icon="microphone" :expanded="false" :error-fields="['voiceGender', 'voiceId']">
                <div
                    x-data="{
                        voiceId: @entangle('voiceId'),
                        playing: false,
                        loading: false,
                        error: '',
                        async toggle() {
                            if (! this.voiceId) return;
                            const p = $refs.player;
                            if (this.playing || this.loading) {
                                p.pause(); p.currentTime = 0;
                                this.playing = false; this.loading = false;
                                return;
                            }
                            this.error = '';
                            this.loading = true;
                            try {
                                const res = await fetch(@js(url('voices')) + '/' + this.voiceId + '/preview');
                                if (! res.ok) {
                                    this.error = res.status === 502
                                        ? '{{ __('Sprachsynthese nicht verfügbar — evtl. ElevenLabs-Kontingent erschöpft.') }}'
                                        : '{{ __('Vorschau konnte nicht geladen werden.') }}';
                                    this.loading = false;
                                    return;
                                }
                                p.src = URL.createObjectURL(await res.blob());
                                await p.play();
                                this.loading = false; this.playing = true;
                            } catch (e) {
                                this.loading = false; this.playing = false;
                                this.error = '{{ __('Vorschau konnte nicht geladen werden.') }}';
                                console.error('voice preview failed', e);
                            }
                        }
                    }"
                    class="grid grid-cols-1 sm:grid-cols-2 gap-4"
                >
                    <flux:select
                        :label="__('Geschlecht')"
                        wire:model.live="voiceGender"
                        class="w-full"
                    >
                        <flux:select.option value="female">{{ __('Weiblich') }}</flux:select.option>
                        <flux:select.option value="male">{{ __('Männlich') }}</flux:select.option>
                    </flux:select>

                    <div class="flex items-end gap-2 w-full">
                        <div class="flex-1 min-w-0">
                            <flux:select
                                :label="__('Stimme')"
                                wire:model.defer="voiceId"
                                class="w-full"
                            >
                                <flux:select.option value="">{{ __('— keine Stimme —') }}</flux:select.option>
                                @foreach ($voices as $voice)
                                    <flux:select.option value="{{ $voice['id'] }}">{{ $voice['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <button
                            type="button"
                            @click="toggle()"
                            :disabled="! voiceId"
                            :title="voiceId ? '{{ __('Stimme anhören') }}' : '{{ __('Erst eine Stimme wählen') }}'"
                            class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-lg border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:text-amber-600 dark:hover:text-amber-400 hover:border-amber-400 dark:hover:border-amber-500 transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-zinc-600 disabled:hover:border-zinc-300"
                        >
                            <span x-show="! playing && ! loading"><flux:icon.play variant="mini" class="size-5" /></span>
                            <span x-show="playing" x-cloak><flux:icon.stop variant="mini" class="size-5" /></span>
                            <span x-show="loading" x-cloak><flux:icon.arrow-path variant="mini" class="size-5 animate-spin" /></span>
                        </button>
                    </div>

                    <p x-show="error" x-cloak x-text="error" class="sm:col-span-2 text-sm text-red-500 dark:text-red-400"></p>

                    <audio x-ref="player" @ended="playing = false" @pause="playing = false" x-on:error="loading = false; playing = false" class="hidden"></audio>
                </div>
            </x-accordion-section>
        </flux:accordion>

        <div class="flex items-center justify-between pt-2">
            @if ($isUpdate)
                <flux:button type="button" variant="danger" class="cursor-pointer"
                    wire:click="needsConfirmation('delete')">
                    {{ __('Delete Expert') }}
                </flux:button>
            @else
                <div></div>
            @endif

            <flux:button type="submit" variant="primary" class="cursor-pointer">
                {{ $isUpdate ? __('Update Expert') : __('Create Expert') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
