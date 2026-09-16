# Rückbau Voice-Chat und Persona-Felder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Voice-Chat (ElevenLabs-TTS, Spracheingabe, Voice-Stage, Stimmenauswahl), @-Mentions, den Hinweis „Deine Eingabe ist gefragt", den KI-Expertenvorschlag und Tags vollständig entfernen und Experten auf `name`, `job` und `description` reduzieren, sodass der Persona-Kern im Prompt nur noch aus `description` besteht.

**Architecture:** Reiner Rückbau in drei Schichten: erst die UI (Konsumenten), dann Backend und Daten für Voice, dann die Persona-Felder. Die Experten-Migration wird direkt überschrieben (keine Produktivdatenbank). Danach Doku und Diagramme nachziehen.

**Tech Stack:** Laravel 12, Livewire 3 + Volt + Flux, Alpine.js, PHPUnit 11, Vite/Tailwind 4.

**Spec:** Anweisung des Nutzers in der Session vom 2026-09-16: „entferne die voice Funktionen (den voice chat) vollständig aus diesem repo. Ebenso sollen Stil, Kernüberzeugungen und Wissensgrenzen aus dem Prompt raus. D.h. wir reduzieren die Personas auf Name, Job und Description. Kein eigener Prompt mehr. Du darfst die Datenbank migrationen direkt überschreiben, da es keine produktiv datenbanken gibt."

## Global Constraints

- Migrationen werden überschrieben, keine neuen Drop-Column-Migrationen anlegen.
- Bleibt erhalten: Nachrichtenton „Pop" (WebAudio-Blip in `control-chat.blade.php`, kein Voice-Chat), Senden per Enter, Avatar, Suche in der Expertenliste, `guzzlehttp/guzzle` (war schon vor Voice im Projekt).
- Wird entfernt (Nachtrag des Nutzers): @-Mentions, also das Autocomplete-Dropdown im Composer und das Fettsetzen von `@Name` in Chatnachrichten. Der Moderator erkennt direkte Ansprache weiter aus dem Transkript, das ist schon heute kein Code-Sonderfall.
- Wird entfernt (Nachtrag des Nutzers): Hinweis „Deine Eingabe ist gefragt" samt Event `UserInputRequested`. Die fachliche Übergabe bleibt: Spricht ein Experte den Nutzer an (`addressUser`), stoppt die Schleife weiterhin, die Nachricht bekommt weiterhin `Abschluss→Nutzer` und den Pfeil zum Nutzer. Clients erfahren den Stopp über das bestehende Event `GenerationStopped`.
- Wird entfernt (Nachtrag des Nutzers): KI-Expertenvorschlag (`ExpertSuggester`, Prompt `suggest-experts`, Vorschlags-UI) und Tags (Modell, beide Migrationen, Editor, Import, Export, `experts.json`).
- Bleibt erhalten: `ProjectExport::SCHEMA_VERSION`. `ProjectImporter` liest keine Persona- oder Voice-Felder, ein Bump ist nicht nötig.
- Baseline vor dem Umbau (`php artisan test`): 5 Tests schlagen bereits fehl und sind nicht Teil dieses Plans: `RegistrationTest > registration screen can be rendered`, `DashboardTest` (2 Tests), `ExampleTest > returns a successful response`, `ProjectExportTest > owner can export json`. Nach jedem Task darf keine weitere Failure dazukommen.
- Commits nur nach Freigabe durch den Nutzer. Commit-Messages englisch, kurz, im Stil der bestehenden Historie, mit Zeile `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Nach Codeänderungen den Skill `clean-code-review` auf die geänderten Dateien anwenden (Vorgabe aus `~/.claude/CLAUDE.md`).

## Dateiübersicht

**Löschen**
- `app/Http/Controllers/MessageAudioController.php`
- `app/Http/Controllers/VoicePreviewController.php`
- `app/Services/Clients/ElevenLabsClient.php`
- `app/Support/VoiceCatalog.php` (danach ist `app/Support/` leer, Verzeichnis entfernen)
- `config/voices.php`
- `resources/views/components/projects/voice-stage.blade.php`
- `tests/Feature/ExpertEditorVoiceTest.php`
- `tests/Feature/VoicePreviewTest.php`
- `tests/Feature/MessageAudioRouteTest.php`
- `tests/Unit/ElevenLabsClientTest.php`
- `tests/Feature/PersonaListTest.php`
- `storage/app/private/voice/` (ungetrackter TTS-Cache)
- `app/Events/UserInputRequested.php`, `tests/Feature/UserInputRequestedDispatchTest.php`
- `app/Services/ExpertSuggester.php`, `resources/views/prompts/suggest-experts.blade.php`
- `app/Models/Tag.php`, `database/migrations/2026_04_24_000000_create_tags_table.php`, `database/migrations/2026_04_24_000001_create_expert_tag_table.php`

**Ändern**
- `routes/web.php` (Audio- und Preview-Routen)
- `config/apis.php` (Block `elevenlabs`)
- `resources/views/livewire/projects/project-chat.blade.php` (Text/Voice-Umschalter, Voice-Bereich)
- `resources/views/livewire/projects/control-chat.blade.php` (Voice-Modus, Spracherkennung)
- `resources/js/app.js` (Store `discussionMode`)
- `resources/css/app.css` (`.voice-tile`)
- `app/Livewire/Projects/ProjectChat.php`, `app/Livewire/Projects/ControlChat.php` (Kommentare)
- `resources/views/livewire/experts/expert-list.blade.php` (Stimmen-Badge)
- `resources/views/livewire/experts/expert-editor.blade.php` (Accordion „Prompt" und „Voice")
- `app/Livewire/Experts/ExpertEditor.php`
- `app/Models/Expert.php`
- `database/migrations/2025_05_30_132453_create_experts_table.php`
- `database/factories/ExpertFactory.php`
- `database/experts.json`
- `app/Console/Commands/InitExperts.php`
- `app/Services/ProjectTransfer/ProjectExport.php`
- `resources/views/prompts/agent/speak.blade.php` (Regel zur „Stilfarbe")
- `tests/Feature/InitExpertsCommandTest.php`
- `CLAUDE.md`, `docs/archify/architektur.architecture.json` (+ neu erzeugtes `architektur.html`)
- `resources/views/components/projects/chat-message.blade.php`, `app/Services/PromptingPipeline/Stages/RunOrchestratorInstructions.php`, `config/discussion.php` (Mentions, Task 1b)
- `app/Jobs/MessageGenerator.php`, `app/Services/PromptingPipeline/DiscussionPipeline.php`, `resources/views/components/projects/pipeline-indicator.blade.php`, `app/Events/PipelineStageChanged.php`, `tests/Feature/Pipeline/PipelineModeratorTest.php`, `tests/Feature/Jobs/MessageGeneratorTest.php` (Task 1c)
- `app/Livewire/Projects/SelectContributors.php`, `resources/views/livewire/projects/select-contributors.blade.php`, `resources/views/components/contributors/contributors-card.blade.php` (Task 2b)

**Reihenfolge der Tasks:** 1 → 1b → 1c → 2 → 2b → 2c → 3 → 4 → 5. Die Tasks bauen auf den Dateiständen der vorherigen auf.

**Neu**
- `tests/Feature/ExpertPersonaTest.php`

---

### Task 1: Voice-UI entfernen

Entfernt alle Stellen, an denen Nutzer Voice sehen oder bedienen: Umschalter Text/Voice, Voice-Stage, Spracheingabe, Stimmenauswahl im Experten-Editor und Stimmen-Badge in der Expertenliste. Danach referenziert keine View mehr `messages.audio`, `voices/…/preview` oder `VoiceCatalog`.

**Files:**
- Delete: `resources/views/components/projects/voice-stage.blade.php`
- Modify: `resources/views/livewire/projects/project-chat.blade.php:6-22, 30, 58-62, 73, 152-154`
- Modify: `resources/views/livewire/projects/control-chat.blade.php:21-191, 355, 442-522`
- Modify: `resources/js/app.js:105-122`
- Modify: `resources/css/app.css:163-169`
- Modify: `app/Livewire/Projects/ProjectChat.php:73-76`
- Modify: `app/Livewire/Projects/ControlChat.php:102-105`
- Modify: `resources/views/livewire/experts/expert-list.blade.php:30-31, 40-50`
- Modify: `resources/views/livewire/experts/expert-editor.blade.php:1-4, 13, 169-248`
- Modify: `app/Livewire/Experts/ExpertEditor.php:8, 52-56, 81-99, 114, 209, 218-241`
- Delete: `tests/Feature/ExpertEditorVoiceTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces: Keine View und keine Livewire-Komponente nutzt mehr `VoiceCatalog`, `config('voices.*')`, `route('messages.audio')`, `url('voices')` oder `$store.discussionMode`. Task 2 kann diese Backend-Teile gefahrlos löschen. Der Browser-Event `message_generated` bleibt (Pop-Ton hört darauf).

- [ ] **Step 1: Ausgangslage per grep festhalten (erwartet: Treffer)**

Run: `grep -rnE "voice|discussionMode|SpeechRecognition|VoiceCatalog" resources/views resources/js resources/css app/Livewire`
Expected: Treffer in allen oben genannten Dateien.

- [ ] **Step 2: Voice-Stage-Komponente löschen**

```bash
git rm resources/views/components/projects/voice-stage.blade.php
```

- [ ] **Step 3: `project-chat.blade.php` bereinigen**

Das äußere `x-data` existiert nur für den Modus. Ersetzen:

```blade
<div
    class="w-full"
    x-data="{
        mode: 'text',
        init() {
            this.$store.discussionMode.initForProject('{{ $project->id }}');
            this.mode = this.$store.discussionMode.value;

            this.$watch('$store.discussionMode.value', (value) => {
                this.mode = value;
            });
        },
        setMode(value) {
            this.$store.discussionMode.setMode(value);
        }
    }"
>
```

durch:

```blade
<div class="w-full">
```

Kommentar in Zeile 30 ersetzen:

```blade
        {{-- Left: settings/leave + title (equal flex so the tabs stay centered) --}}
```

durch:

```blade
        {{-- Left: settings/leave + title --}}
```

Umschalter vollständig entfernen:

```blade
        {{-- Center: tab selection — always exactly centered --}}
        <flux:radio.group variant="segmented" x-model="mode" x-on:change="setMode(mode)" class="shrink-0">
            <flux:radio value="text" icon="chat-bubble-left-right">{{ __('Text') }}</flux:radio>
            <flux:radio value="voice" icon="microphone">{{ __('Voice') }}</flux:radio>
        </flux:radio.group>

```

Chat-Container ohne Modus-Bindung:

```blade
    <div class="relative py-6" x-show="mode === 'text'">
```

wird zu:

```blade
    <div class="relative py-6">
```

Voice-Bereich vollständig entfernen:

```blade
    <div x-show="mode === 'voice'" class="py-6">
        <x-projects.voice-stage :project="$project" :messages="$messages" />
    </div>

```

- [ ] **Step 4: `control-chat.blade.php` bereinigen**

Im `x-data`-Objekt diese Properties entfernen:

```js
        mode: 'text',
        recording: false,
        transcript: '',
        recognition: null,
        supportsSpeech: Boolean(window.SpeechRecognition || window.webkitSpeechRecognition),
```

`init()` beginnt dann ohne Modus-Watcher. Entfernen:

```js
            this.mode = this.$store.discussionMode?.value ?? 'text';
            this.$watch('$store.discussionMode.value', (value) => {
                this.mode = value;
                if (value !== 'voice') {
                    this.stopRecording();
                }
                this.closeMention();
            });

```

Pop-Kommentar und Modus-Check anpassen. Ersetzen:

```js
            // Chat message 'pop' sound — opt-in, persisted per browser. Plays a
            // short blip when a new message arrives while the Text tab is open.
            this.popEnabled = localStorage.getItem('chatPop') !== '0';
            this.popListener = (event) => {
                if (!this.popEnabled) return;
                if ((this.$store.discussionMode?.value ?? 'text') !== 'text') return;
```

durch:

```js
            // Chat message 'pop' sound, persisted per browser. Plays a short
            // blip when a new message arrives.
            this.popEnabled = localStorage.getItem('chatPop') !== '0';
            this.popListener = (event) => {
                if (!this.popEnabled) return;
```

Den kompletten Block `if (this.supportsSpeech) { … }` am Ende von `init()` (aktuell Zeilen 62-94) entfernen, ebenso die Methoden `startRecording()`, `stopRecording()` (Zeilen 96-115) und `rewriteVoiceMentions(text)` (Zeilen 166-191). `init()` endet danach mit:

```js
            window.addEventListener('message_generated', this.popListener);
        },
        destroy() {
```

Formular ohne Modus-Bindung. Ersetzen:

```blade
            <form wire:submit="sendMessage" x-show="mode === 'text'">
```

durch:

```blade
            <form wire:submit="sendMessage">
```

Den kompletten Voice-Block ab `<div x-show="mode === 'voice'" class="py-4">` bis zu seinem schließenden `</div>` (aktuell Zeilen 442-522) entfernen. Danach folgen direkt auf `</form>` die schließenden Tags:

```blade
            </form>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Store `discussionMode` aus `resources/js/app.js` entfernen**

Entfernen (der Aufruf `window.Alpine.data(...)` davor bleibt, `});` des Listeners bleibt):

```js

    window.Alpine.store('discussionMode', {
        value: 'text',
        projectId: null,
        initForProject(projectId) {
            this.projectId = String(projectId);
            const saved = window.localStorage.getItem(this.storageKey());
            this.value = saved === 'voice' ? 'voice' : 'text';
        },
        storageKey() {
            return `discussionMode:${this.projectId ?? 'global'}`;
        },
        setMode(mode) {
            this.value = mode === 'voice' ? 'voice' : 'text';
            if (this.projectId !== null) {
                window.localStorage.setItem(this.storageKey(), this.value);
            }
        },
    });
```

- [ ] **Step 6: `.voice-tile` aus `resources/css/app.css` entfernen**

```css
/* Voice stage tiles: animate filter/transform/ring/shadow together so the
   transition between grayscale-default and full-color speaker/addressed
   states feels fluid instead of snapping. */
.voice-tile {
  transition: filter 400ms ease, transform 300ms ease,
              box-shadow 300ms ease, --tw-ring-color 300ms ease;
}

```

- [ ] **Step 7: Kommentare in den Livewire-Komponenten korrigieren**

`app/Livewire/Projects/ProjectChat.php`, ersetzen:

```php
        // Dispatched AFTER this component re-renders, so the voice-stage's
        // data-last-expert-* attributes already reflect the new turn when
        // the listener fires playLatest().
        $this->dispatch('message_generated', projectId: $this->projectId);
```

durch:

```php
        $this->dispatch('message_generated', projectId: $this->projectId);
```

`app/Livewire/Projects/ControlChat.php`, ersetzen:

```php
        // The browser-side `message_generated` event is dispatched by
        // ProjectChat after IT re-rendered (so voice-stage's data-*
        // attributes are fresh). Duplicating the dispatch here would race
        // ahead of the DOM morph.
```

durch:

```php
        // The browser-side `message_generated` event is dispatched by
        // ProjectChat; dispatching it here too would double the pop sound.
```

- [ ] **Step 8: Stimmen-Badge in `expert-list.blade.php` entfernen**

Entfernen:

```blade
                    @php($voiceLabel = \App\Support\VoiceCatalog::labelFor($expert->voice_id))
                    @php($voiceGender = \App\Support\VoiceCatalog::genderFor($expert->voice_id))
```

und den Block `@if($voiceLabel) … @endif` (aktuell Zeilen 40-50). Die Schleife sieht danach so aus:

```blade
                @foreach ($experts as $expert)
                    <div class="relative">
                        <x-contributors.contributors-card @click="$wire.dispatch('edit_expert', { id: {{ $expert->id }} })"
                            :name="$expert->name"
                            :job="$expert->job"
                            :avatar-url="$expert->avatar_url ?? null"
                            :description="$expert->description"
                            :seed="$expert->id"
                        />
                    </div>
                @endforeach
```

- [ ] **Step 9: Voice aus dem Experten-Editor entfernen (View)**

`expert-editor.blade.php`: `'voices' => [],` aus `@props` entfernen. Untertitel ersetzen:

```blade
                {{ __('Define this expert\'s identity, persona and voice.') }}
```

durch:

```blade
                {{ __('Define this expert\'s identity.') }}
```

Den kompletten Block `{{-- Voice --}}` mit `<x-accordion-section :heading="__('Voice')" …>` bis zum zugehörigen `</x-accordion-section>` (aktuell Zeilen 169-248) entfernen.

- [ ] **Step 10: Voice aus `ExpertEditor.php` entfernen**

- Import `use App\Support\VoiceCatalog;` entfernen.
- Properties `$voiceGender` und `$voiceId` samt `#[Validate]`-Attributen entfernen.
- In `edit()` diese Zeilen entfernen:

```php

        $this->voiceId = $expert->voice_id ?? '';
        $this->voiceGender = $this->resolveGenderForVoice($this->voiceId) ?? 'female';
```

- Methoden `updatedVoiceGender()` und `resolveGenderForVoice()` entfernen.
- In `save()` die Zeile `$expert->voice_id = $this->voiceId !== '' ? $this->voiceId : null;` entfernen.
- In `resetForm()` `'voiceId', 'voiceGender'` aus dem `reset([...])`-Array entfernen.
- `render()` wird zu:

```php
    public function render(): mixed
    {
        return view('livewire.experts.expert-editor', [
            'isUpdate' => ! is_null($this->expertId),
        ]);
    }
```

- [ ] **Step 11: Voice-Editor-Test löschen**

```bash
git rm tests/Feature/ExpertEditorVoiceTest.php
```

- [ ] **Step 12: Prüfen, dass die UI frei von Voice ist**

Run: `grep -rnE "voice|discussionMode|SpeechRecognition|VoiceCatalog|messages\.audio|mode === " resources/views resources/js resources/css app/Livewire`
Expected: keine Ausgabe.

Run: `npm run build`
Expected: Build ohne Fehler.

Run: `php artisan view:cache && php artisan view:clear`
Expected: beide ohne Fehler (alle Blade-Views kompilieren).

Run: `php artisan test`
Expected: nur die 5 Baseline-Failures, keine weiteren.

- [ ] **Step 13: Commit (nach Freigabe)**

```bash
git add -A resources app/Livewire tests/Feature/ExpertEditorVoiceTest.php
git commit -m "Remove voice chat UI

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 1b: @-Mentions entfernen

Entfernt das Mention-Autocomplete im Composer und die Hervorhebung von `@Name` in Nachrichten. Senden per Enter bleibt. Setzt auf Task 1 auf, weil beide `control-chat.blade.php` ändern.

**Files:**
- Modify: `resources/views/livewire/projects/control-chat.blade.php` (Prop `mentionables`, `data-mentionables`, Mention-State und -Methoden, Dropdown-Markup, `@input.capture`, `@click.outside`)
- Modify: `app/Livewire/Projects/ControlChat.php:175-182, 193`
- Modify: `resources/views/components/projects/chat-message.blade.php:27-41`
- Modify: `app/Services/PromptingPipeline/Stages/RunOrchestratorInstructions.php:18-20` (Kommentar)
- Modify: `config/discussion.php:10-12` (Kommentar)

**Interfaces:**
- Consumes: Task 1 (`control-chat.blade.php` ohne Voice-Modus).
- Produces: `ControlChat::render()` übergibt kein `mentionables` mehr. `onComposerKeydown(event)` behandelt nur noch Enter.

- [ ] **Step 1: Ausgangslage per grep festhalten (erwartet: Treffer)**

Run: `grep -rniE "mention" app resources config`
Expected: Treffer in den oben genannten Dateien.

- [ ] **Step 2: `ControlChat.php` bereinigen**

In `render()` entfernen:

```php
        $mentionables = $this->project->contributingExperts()
            ->map(fn($expert) => [
                'name'       => $expert->name,
                'job'        => $expert->job,
                'avatar_url' => $expert->avatar_url,
            ])
            ->values()
            ->all();

```

und im View-Array die Zeile `'mentionables' => $mentionables,`.

- [ ] **Step 3: `control-chat.blade.php` bereinigen**

- In `@props` die Zeile `'mentionables'         => [],` entfernen.
- Auf dem Wurzel-`<div>` die Attribute `:data-mentionables="…"`, `@input.capture="onComposerInput($event)"` und `@click.outside="closeMention()"` entfernen. `@keydown.capture="onComposerKeydown($event)"` bleibt.
- Im `x-data` die Properties `mentionPattern`, `mentionOpen`, `mentionQuery`, `mentionMatches`, `mentionIndex`, `activeInput` entfernen.
- Methoden `closeMention()`, `readMentionables()`, `onComposerInput(event)` und `applyMention(item)` entfernen.
- `onComposerKeydown(event)` komplett ersetzen durch:

```js
        onComposerKeydown(event) {
            // Enter (without Shift) sends the message; Shift+Enter keeps the
            // newline. Only inside the composer fields, and not mid-IME.
            if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
            const el = event.target;
            if (!(el instanceof HTMLTextAreaElement) && !(el instanceof HTMLInputElement)) return;
            event.preventDefault();
            event.stopPropagation();
            // Flush the (debounced) value before validating server-side,
            // otherwise a fast Enter can submit a stale/empty msgContent.
            Promise.resolve($wire.set('msgContent', el.value))
                .then(() => $wire.sendMessage());
        },
```

- Den Block `<!-- Mention autocomplete dropdown -->` samt `<div x-show="mentionOpen" …>` bis zum zugehörigen `</div>` entfernen (aktuell Zeilen 303-342). Der Container `<div class="max-w-240 mx-auto pb-4 px-4 relative">` beginnt danach direkt mit `@if ($userInputRequested)`.

- [ ] **Step 4: Hervorhebung in `chat-message.blade.php` entfernen**

Im `@php`-Block entfernen:

```php

    $projectContributors = \App\Models\Expert::whereHas(
            'projects',
            fn($q) => $q->whereKey($msg->project_id)
        )
        ->get(['id', 'name'])
        ->sortByDesc(fn($e) => mb_strlen($e->name));

    foreach ($projectContributors as $contributor) {
        $pattern = '/(?<![\w@])@' . preg_quote($contributor->name, '/') . '(?!\w)/u';
        // @-mentions are only emphasised (bold) in the message body. The
        // adjacency-pair target is shown separately by the arrow below the
        // bubble, so the mention itself is no longer an interactive button.
        $replacement = sprintf('<strong class="font-semibold">@%s</strong>', e($contributor->name));
        $renderedContent = preg_replace($pattern, $replacement, $renderedContent);
    }
```

`$renderedContent = Markdown::parse($msg->content);` bleibt.

- [ ] **Step 5: Kommentare anpassen**

`RunOrchestratorInstructions.php`, ersetzen:

```php
 * User priority is no longer hard-coded: the pending user excerpt is exposed to
 * the moderator, which decides addressUser itself. There is no @-mention special
 * case either — the moderator infers direct address from the visible transcript.
```

durch:

```php
 * User priority is not hard-coded: the pending user excerpt is exposed to the
 * moderator, which decides addressUser itself and infers direct address from
 * the visible transcript.
```

`config/discussion.php`, ersetzen:

```php
    | every contributing expert. Direct address (incl. @-mentions) is inferred
    | by the moderator from the transcript, not special-cased in code.
```

durch:

```php
    | every contributing expert. Direct address is inferred by the moderator
    | from the transcript, not special-cased in code.
```

- [ ] **Step 6: Prüfen**

Run: `grep -rniE "mention" app resources config`
Expected: keine Ausgabe.

Run: `npm run build && php artisan view:cache && php artisan view:clear`
Expected: ohne Fehler.

Run: `php artisan test`
Expected: nur die 5 Baseline-Failures.

Manuell: Im Composer sendet Enter, Shift+Enter erzeugt eine neue Zeile, Tippen von `@` öffnet kein Dropdown mehr.

- [ ] **Step 7: Commit (nach Freigabe)**

```bash
git add -A app resources config
git commit -m "Remove @-mention autocomplete and highlighting

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 1c: Hinweis „Deine Eingabe ist gefragt" entfernen

Entfernt den Hinweis über dem Composer, den pulsierenden Rahmen, die Browser-Events `user-input-requested`/`user-input-cleared` und das Broadcast-Event `UserInputRequested`. Bei einer Übergabe an den Nutzer sendet `MessageGenerator` stattdessen `GenerationStopped`, sodass alle Clients ihre Buttons zurücksetzen und die Statusanzeige verschwindet.

**Files:**
- Delete: `app/Events/UserInputRequested.php`
- Delete: `tests/Feature/UserInputRequestedDispatchTest.php`
- Modify: `app/Jobs/MessageGenerator.php:8, 67-75`
- Modify: `app/Services/PromptingPipeline/DiscussionPipeline.php:28, 46-53`
- Modify: `app/Livewire/Projects/ControlChat.php:27, 59, 84, 108-124, 136-138, 150-156, 192`
- Modify: `resources/views/livewire/projects/control-chat.blade.php` (Prop `userInputRequested`, Hinweis-Block, Rahmen-Wrapper)
- Modify: `resources/js/app.js:62`
- Modify: `resources/views/components/projects/pipeline-indicator.blade.php:7-8` (Kommentar)
- Modify: `app/Events/PipelineStageChanged.php:17-18` (Kommentar)
- Modify: `tests/Feature/Pipeline/PipelineModeratorTest.php:122`
- Modify: `tests/Feature/Jobs/MessageGeneratorTest.php` (Test `test_does_not_queue_follow_up_on_user_handoff`)

**Interfaces:**
- Consumes: Task 1b (`control-chat.blade.php` ohne Mentions).
- Produces: `DiscussionPipeline::run(): array{stop: bool, reason: ?string}` (ohne `user_id`). `ControlChat` hat keine Property `userInputRequested` mehr. `Project::handoffUser()` und `Message::handsBackToUser()` bleiben, sie setzen weiterhin den Adressaten der Nachricht.

- [ ] **Step 1: Job-Test um die erwartete Broadcast-Wirkung erweitern (failing test)**

In `tests/Feature/Jobs/MessageGeneratorTest.php` Imports ergänzen:

```php
use App\Events\GenerationStopped;
use Illuminate\Support\Facades\Event;
```

Im Test `test_does_not_queue_follow_up_on_user_handoff` direkt nach `Queue::fake();` einfügen:

```php
        Event::fake([GenerationStopped::class]);
```

und am Ende nach `Queue::assertNotPushed(MessageGenerator::class);` ergänzen:

```php
        Event::assertDispatched(
            GenerationStopped::class,
            fn (GenerationStopped $event) => $event->projectId === $this->project->id
        );
        $this->assertFalse(ProjectJob::isGenerating($this->project->id));
```

In `tests/Feature/Pipeline/PipelineModeratorTest.php` die Zeile entfernen:

```php
        $this->assertSame($this->user->id, $result['user_id']);
```

und stattdessen ergänzen:

```php
        $this->assertArrayNotHasKey('user_id', $result);
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter="MessageGeneratorTest|PipelineModeratorTest"`
Expected: FAIL in `test_does_not_queue_follow_up_on_user_handoff` (`GenerationStopped` wurde nicht gesendet) und im Handoff-Test von `PipelineModeratorTest` (`user_id` ist noch vorhanden).

- [ ] **Step 3: `DiscussionPipeline::run()` vereinfachen**

Docblock-Zeile ersetzen:

```php
     * @return array{stop: bool, reason: ?string, user_id: ?int}
```

durch:

```php
     * @return array{stop: bool, reason: ?string}
```

Return ersetzen:

```php
        return [
            'stop'    => $ctx->stop,
            'reason'  => $ctx->reason,
            // The concrete user the expert handed off to (if any), so only that
            // user is prompted for input — not everyone in the project.
            'user_id' => $ctx->message?->handsBackToUser()
                ? $ctx->message->adjacency_partner_id
                : null,
        ];
```

durch:

```php
        return [
            'stop'   => $ctx->stop,
            'reason' => $ctx->reason,
        ];
```

- [ ] **Step 4: `MessageGenerator` sendet beim Stopp `GenerationStopped`**

Import `use App\Events\UserInputRequested;` entfernen. Ersetzen:

```php
                if (! empty($pipelineResult['stop'])) {
                    $continue = false;
                    ProjectJob::stopGenerating($project->id);
                    UserInputRequested::dispatch(
                        $project->id,
                        $pipelineResult['reason'] ?? 'stop',
                        $pipelineResult['user_id'] ?? null,
                    );
                }
```

durch:

```php
                if (! empty($pipelineResult['stop'])) {
                    $continue = false;
                    ProjectJob::stopGenerating($project->id);
                    GenerationStopped::dispatch($project->id);
                }
```

Den Kommentar darüber anpassen. Ersetzen:

```php
            // Whether the discussion loop should keep running after this turn.
            // Any stop signal (user input requested, hard failure) clears the
            // shared flag so no further turn is dispatched.
```

durch:

```php
            // Whether the discussion loop should keep running after this turn.
            // Any stop signal (hand-off to a user, hard failure) clears the
            // shared flag so no further turn is dispatched.
```

- [ ] **Step 5: Event und dessen Test löschen**

```bash
git rm app/Events/UserInputRequested.php tests/Feature/UserInputRequestedDispatchTest.php
```

- [ ] **Step 6: `ControlChat.php` bereinigen**

- Property `public bool $userInputRequested = false;` entfernen.
- In `startGenerate()` und `onGenerationStarted()` jeweils die Zeile `$this->userInputRequested = false;` entfernen.
- Methode `onUserInputRequested()` samt `#[On('echo-private:projects.{projectId},.UserInputRequested')]` entfernen.
- In `sendMessage()` entfernen:

```php
        $this->dispatch('user-input-cleared', projectId: $this->projectId);
```

und

```php
        $this->userInputRequested = false;
```

- Methode `updatedMsgContent()` komplett entfernen.
- In `render()` die Zeile `'userInputRequested' => $this->userInputRequested,` entfernen.

- [ ] **Step 7: `control-chat.blade.php` bereinigen**

- In `@props` die Zeile `'userInputRequested'   => false,` entfernen.
- Hinweis-Block entfernen:

```blade
            @if ($userInputRequested)
                <div class="mb-2 flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                        <path d="M12 8v4"/>
                        <path d="M12 16h.01"/>
                    </svg>
                    {{ __('Deine Eingabe ist gefragt.') }}
                </div>
            @endif

```

- Den Rahmen-Wrapper um `<flux:composer>` entfernen. Ersetzen:

```blade
            <form wire:submit="sendMessage">
                <div @class([
                    'rounded-lg transition-shadow',
                    'ring-2 ring-amber-400 dark:ring-amber-500 shadow-[0_0_0_4px_rgba(251,191,36,0.15)] animate-pulse' => $userInputRequested,
                ])>
                <flux:composer
```

durch:

```blade
            <form wire:submit="sendMessage">
                <flux:composer
```

und das zugehörige schließende `</div>` direkt vor `</form>` entfernen:

```blade
                </flux:composer>
                </div>
            </form>
```

wird zu:

```blade
                </flux:composer>
            </form>
```

- [ ] **Step 8: JS-Listener und Kommentare anpassen**

`resources/js/app.js`: Zeile `channel.listen('.UserInputRequested', clear);` entfernen. Da `clear` danach nur noch einmal benutzt wird, ersetzen:

```js
            const clear = () => this.clear();
            channel.listen('.GenerationStopped', clear);
```

durch:

```js
            channel.listen('.GenerationStopped', () => this.clear());
```

`pipeline-indicator.blade.php`, ersetzen:

```blade
    never trigger a Livewire round-trip. Cleared by MessageGenerated,
    GenerationStopped and UserInputRequested.
```

durch:

```blade
    never trigger a Livewire round-trip. Cleared by MessageGenerated and
    GenerationStopped.
```

`app/Events/PipelineStageChanged.php`, ersetzen:

```php
 * Cleared client-side by MessageGenerated / GenerationStopped /
 * UserInputRequested.
```

durch:

```php
 * Cleared client-side by MessageGenerated / GenerationStopped.
```

- [ ] **Step 9: Tests laufen lassen**

Run: `php artisan test --filter="MessageGeneratorTest|PipelineModeratorTest"`
Expected: PASS.

Run: `grep -rniE "UserInputRequested|userInputRequested|user-input-(requested|cleared)|Eingabe ist gefragt|user_id'\]" app resources tests`
Expected: keine Ausgabe.

Run: `npm run build && php artisan view:cache && php artisan view:clear && php artisan test`
Expected: Build und Views ohne Fehler, Tests nur mit den 5 Baseline-Failures.

- [ ] **Step 10: Commit (nach Freigabe)**

```bash
git add -A app resources tests
git commit -m "Remove user input requested hint, stop via GenerationStopped

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Voice-Backend und `voice_id` entfernen

Entfernt TTS-Client, Controller, Routen, Stimmenkatalog, Konfiguration, die Spalte `voice_id` und alle Voice-Tests.

**Files:**
- Delete: `app/Http/Controllers/MessageAudioController.php`, `app/Http/Controllers/VoicePreviewController.php`, `app/Services/Clients/ElevenLabsClient.php`, `app/Support/VoiceCatalog.php`, `config/voices.php`
- Delete: `tests/Feature/VoicePreviewTest.php`, `tests/Feature/MessageAudioRouteTest.php`, `tests/Unit/ElevenLabsClientTest.php`
- Modify: `routes/web.php:7-8, 47-54`
- Modify: `config/apis.php:18-22`
- Modify: `app/Models/Expert.php:40`
- Modify: `database/migrations/2025_05_30_132453_create_experts_table.php:23`
- Modify: `app/Console/Commands/InitExperts.php:63-68`
- Modify: `app/Services/ProjectTransfer/ProjectExport.php:37`
- Modify: `database/experts.json` (Schlüssel `voice_id`)
- Modify: `tests/Feature/InitExpertsCommandTest.php`

**Interfaces:**
- Consumes: Task 1 (keine View nutzt mehr Voice-Routen oder `VoiceCatalog`).
- Produces: `Expert` hat kein Attribut `voice_id` mehr. `config('apis.elevenlabs')` existiert nicht mehr.

- [ ] **Step 1: `InitExpertsCommandTest` auf das neue Verhalten umschreiben (failing test)**

Die beiden Voice-Tests prüfen nur `voice_id`. Datei komplett ersetzen durch einen Test, der das Import-Grundverhalten absichert und sicherstellt, dass unbekannte Altschlüssel wie `voice_id` ignoriert werden:

```php
<?php

namespace Tests\Feature;

use App\Models\Expert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InitExpertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_experts_creates_and_updates_by_name(): void
    {
        Expert::factory()->create([
            'name' => 'Existing Expert',
            'job' => 'Old job',
            'description' => 'Old description.',
        ]);

        $path = storage_path('framework/testing-init-experts.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'name' => 'Existing Expert',
                'avatar_url' => '',
                'job' => 'New job',
                'description' => 'New description.',
                'tags' => [],
            ],
            [
                'name' => 'New Expert',
                'avatar_url' => '',
                'job' => 'QA',
                'description' => 'Short description.',
                'voice_id' => 'legacy-key-is-ignored',
                'tags' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $code = Artisan::call('init:experts', ['--file' => $path]);

            $this->assertSame(0, $code);
            $this->assertSame('New job', Expert::where('name', 'Existing Expert')->firstOrFail()->job);
            $this->assertSame('Short description.', Expert::where('name', 'New Expert')->firstOrFail()->description);
            $this->assertFalse(Schema::hasColumn('experts', 'voice_id'));
        } finally {
            File::delete($path);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen, er muss fehlschlagen**

Run: `php artisan test --filter=InitExpertsCommandTest`
Expected: FAIL bei `assertFalse(Schema::hasColumn('experts', 'voice_id'))`.

- [ ] **Step 3: Voice-Dateien und Voice-Tests löschen**

```bash
git rm app/Http/Controllers/MessageAudioController.php \
       app/Http/Controllers/VoicePreviewController.php \
       app/Services/Clients/ElevenLabsClient.php \
       app/Support/VoiceCatalog.php \
       config/voices.php \
       tests/Feature/VoicePreviewTest.php \
       tests/Feature/MessageAudioRouteTest.php \
       tests/Unit/ElevenLabsClientTest.php
rmdir app/Support
```

- [ ] **Step 4: Routen entfernen (`routes/web.php`)**

Imports entfernen:

```php
use App\Http\Controllers\MessageAudioController;
use App\Http\Controllers\VoicePreviewController;
```

Routen entfernen:

```php
Route::middleware(['auth', 'verified'])
    ->get('messages/{message}/audio', [MessageAudioController::class, 'show'])
    ->name('messages.audio');

Route::middleware(['auth', 'verified'])
    ->get('voices/{voiceId}/preview', [VoicePreviewController::class, 'show'])
    ->name('voices.preview');

```

- [ ] **Step 5: ElevenLabs-Konfiguration entfernen (`config/apis.php`)**

```php
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
        'default_voice' => env('ELEVENLABS_DEFAULT_VOICE', 'EXAVITQu4vr4xnSDxMaL'),
    ],
```

- [ ] **Step 6: `voice_id` aus Modell, Migration, Import und Export entfernen**

- `app/Models/Expert.php`: `'voice_id',` aus `$fillable` entfernen.
- Migration: Zeile `$table->string('voice_id', 64)->nullable();` entfernen.
- `app/Console/Commands/InitExperts.php`: Block entfernen:

```php

            if (array_key_exists('voice_id', $expert)) {
                $voiceId = $expert['voice_id'];
                $attributes['voice_id'] = is_string($voiceId) && trim($voiceId) !== ''
                    ? trim($voiceId)
                    : null;
            }
```

- `app/Services/ProjectTransfer/ProjectExport.php`: Zeile `'voice_id'         => $e->voice_id,` entfernen.

- [ ] **Step 7: `voice_id` aus `database/experts.json` entfernen**

```bash
python3 - <<'EOF'
import json
path = 'database/experts.json'
experts = json.load(open(path, encoding='utf-8'))
for expert in experts:
    expert.pop('voice_id', None)
with open(path, 'w', encoding='utf-8') as f:
    json.dump(experts, f, ensure_ascii=False, indent=4)
    f.write('\n')
EOF
```

Run: `git diff --stat database/experts.json`
Expected: nur entfernte Zeilen (19 `voice_id`-Zeilen), sonst keine Umformatierung. Falls der Diff mehr zeigt, Einrückung oder Escaping an das Original anpassen.

- [ ] **Step 8: Tests laufen lassen**

Run: `php artisan test --filter=InitExpertsCommandTest`
Expected: PASS.

Run: `php artisan test`
Expected: nur die 5 Baseline-Failures.

- [ ] **Step 9: Reste suchen**

Run: `grep -rniE "voice|elevenlabs|audio/mpeg|synthesize" app config routes database tests resources`
Expected: keine Ausgabe außer dem WebAudio-Pop in `control-chat.blade.php` (`AudioContext`, `audioCtx`). Der Suchbegriff `voice` darf dort nicht mehr vorkommen.

- [ ] **Step 10: TTS-Cache löschen**

```bash
rm -rf storage/app/private/voice
```

- [ ] **Step 11: Commit (nach Freigabe)**

```bash
git add -A app config routes database tests
git commit -m "Remove ElevenLabs TTS backend and voice_id

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2b: KI-Expertenvorschlag entfernen

Entfernt den LLM-gestützten Vorschlag passender Experten im Dialog „Choose Contributors". Manuelles Hinzufügen, Suche und Limit bleiben. Muss vor Task 2c laufen, weil `ExpertSuggester` Tags lädt.

**Files:**
- Delete: `app/Services/ExpertSuggester.php`
- Delete: `resources/views/prompts/suggest-experts.blade.php`
- Modify: `app/Livewire/Projects/SelectContributors.php:9, 12, 16, 29-37, 51, 114-180, 186-192, 206-211, 233-235`
- Modify: `resources/views/livewire/projects/select-contributors.blade.php:6-8, 30-71, 96, 104-105`
- Modify: `resources/views/components/contributors/contributors-card.blade.php:6-7, 17-21, 36-40`

**Interfaces:**
- Consumes: nichts.
- Produces: `SelectContributors` rendert ohne `suggestedIdSet`, `suggestionReasons`, `hasSuggestions`. `<x-contributors.contributors-card>` hat keine Props `suggested`/`suggestionReason` mehr. Der Projekt-Settings-Schlüssel `suggested_experts` wird nirgends mehr gelesen oder geschrieben.

- [ ] **Step 1: Ausgangslage per grep festhalten (erwartet: Treffer)**

Run: `grep -rniE "suggest" app resources`
Expected: Treffer in den oben genannten Dateien.

- [ ] **Step 2: Service und Prompt löschen**

```bash
git rm app/Services/ExpertSuggester.php resources/views/prompts/suggest-experts.blade.php
```

- [ ] **Step 3: `SelectContributors.php` bereinigen**

- Imports `use App\Services\ExpertSuggester;`, `use Illuminate\Support\Facades\Log;` und `use Throwable;` entfernen.
- Properties `$suggestedIds`, `$suggestionReasons`, `$isSuggesting`, `$suggestionError` samt Docblocks entfernen.
- `mount()` wird zu:

```php
    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->forProject = $project;
    }
```

- Methoden `suggestExperts()`, `clearSuggestions()`, `loadSuggestionsFromSettings()` und `applySuggestions()` samt Docblock entfernen.
- In `render()` den Block `$existingSuggestedIds = []; if (! empty($this->suggestedIds)) { … }` und den Sortier-Block `if (! empty($existingSuggestedIds)) { … }` entfernen. Die Expertenabfrage wird zu:

```php
        $experts = Expert::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('job', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();
```

- Im View-Array die Zeilen `'suggestedIdSet' => …`, `'suggestionReasons' => …` und `'hasSuggestions' => …` entfernen.

- [ ] **Step 4: `select-contributors.blade.php` bereinigen**

- In `@props` die Zeilen `'suggestedIdSet' => [],`, `'suggestionReasons' => [],` und `'hasSuggestions' => false,` entfernen.
- Die Button-Leiste `<div class="flex items-center justify-between gap-2"> @if(!$hasSuggestions) … @endif </div>` (aktuell Zeilen 30-67) und den Fehlerblock `@if($suggestionError) … @endif` (Zeilen 69-71) entfernen. Das Panel beginnt danach mit `@if($limitWarning)`.
- In der Schleife `@php($isSuggested = isset($suggestedIdSet[$expert->id]))` entfernen und am Card-Aufruf die Attribute `:suggested="$isSuggested"` und `:suggestion-reason="…"` entfernen.

- [ ] **Step 5: `contributors-card.blade.php` bereinigen**

In `@props` die Zeilen `'suggested' => false,` und `'suggestionReason' => null,` entfernen. Die Blöcke entfernen:

```blade
        @if($suggested)
            <div class="absolute top-2 end-2">
                <flux:badge color="blue" size="sm" icon="sparkles">{{ __('Suggested') }}</flux:badge>
            </div>
        @endif
```

```blade
            @if($suggested && !empty($suggestionReason))
                <div class="mt-3 text-xs italic text-blue-600 dark:text-blue-400">
                    {{ $suggestionReason }}
                </div>
            @endif

```

- [ ] **Step 6: Prüfen**

Run: `grep -rniE "suggest" app resources`
Expected: keine Ausgabe.

Run: `php artisan view:cache && php artisan view:clear && php artisan test`
Expected: Views ohne Fehler, Tests nur mit den 5 Baseline-Failures.

Manuell: Dialog „Choose Contributors" öffnen, Experten suchen, hinzufügen und entfernen funktioniert, kein Vorschlags-Button mehr.

- [ ] **Step 7: Commit (nach Freigabe)**

```bash
git add -A app resources
git commit -m "Remove AI expert suggestions

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2c: Tags entfernen

Entfernt Tags vollständig: Modell, beide Migrationen, Relation am Experten, auskommentiertes Eingabefeld und Speicherlogik im Editor, Import, Export und Seed-Daten.

**Files:**
- Delete: `app/Models/Tag.php`
- Delete: `database/migrations/2026_04_24_000000_create_tags_table.php`, `database/migrations/2026_04_24_000001_create_expert_tag_table.php`
- Modify: `app/Models/Expert.php:9, 53-56`
- Modify: `app/Livewire/Experts/ExpertEditor.php:7, 49-50, 77-79, 123-132, resetForm`
- Modify: `resources/views/livewire/experts/expert-editor.blade.php:48, 59-63`
- Modify: `app/Console/Commands/InitExperts.php:6, 75-82`
- Modify: `app/Services/ProjectTransfer/ProjectExport.php` (Zeile `'tags' => …` und ggf. `->with('tags')`)
- Modify: `database/experts.json` (Schlüssel `tags`)
- Modify: `tests/Feature/InitExpertsCommandTest.php` (`'tags' => []` aus den Fixtures)

**Interfaces:**
- Consumes: Task 2b (`ExpertSuggester` ist gelöscht).
- Produces: `Expert` hat keine Relation `tags()` mehr. `ExpertEditor::resetForm()` setzt `['expertId', 'name', 'avatarUpload', 'avatarUrl', 'job', 'description', 'profile', 'style']` zurück (die Persona-Felder entfernt Task 3).

- [ ] **Step 1: Schema-Test ergänzen (failing test)**

In `tests/Feature/InitExpertsCommandTest.php` (Fassung aus Task 2) die beiden Zeilen `'tags' => [],` aus den Fixtures entfernen und im `try`-Block nach der `voice_id`-Prüfung ergänzen:

```php
            $this->assertFalse(Schema::hasTable('tags'));
            $this->assertFalse(Schema::hasTable('expert_tag'));
```

- [ ] **Step 2: Test laufen lassen, er muss fehlschlagen**

Run: `php artisan test --filter=InitExpertsCommandTest`
Expected: FAIL bei `assertFalse(Schema::hasTable('tags'))`.

- [ ] **Step 3: Modell und Migrationen löschen**

```bash
git rm app/Models/Tag.php \
       database/migrations/2026_04_24_000000_create_tags_table.php \
       database/migrations/2026_04_24_000001_create_expert_tag_table.php
```

- [ ] **Step 4: Relation aus `Expert` entfernen**

Import `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` und die Methode entfernen:

```php
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

```

- [ ] **Step 5: Tags aus dem Editor entfernen**

`ExpertEditor.php`:
- Import `use App\Models\Tag;` entfernen.
- Property `$tagsInput` samt `#[Validate('nullable|string|max:500')]` entfernen.
- In `edit()` entfernen:

```php
        $this->tagsInput = $expert
            ? $expert->tags()->orderBy('name')->pluck('name')->implode(', ')
            : '';
```

- In `save()` entfernen:

```php
        $tagIds = collect(explode(',', $this->tagsInput))
            ->map(fn ($name) => trim($name))
            ->filter(fn ($name) => $name !== '')
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreateByName($name)->id)
            ->unique()
            ->values()
            ->all();

        $expert->tags()->sync($tagIds);

```

- In `resetForm()` `'tagsInput'` aus dem `reset([...])`-Array entfernen.

`expert-editor.blade.php`: In `:error-fields="['name', 'job', 'description', 'tagsInput']"` den Eintrag `'tagsInput'` entfernen. Den auskommentierten Block entfernen:

```blade
                {{-- <flux:input
                    :label="__('Tags')"
                    :description="__('Kommagetrennt, z. B. Engineering, AI, Design')"
                    wire:model.defer="tagsInput"
                /> --}}
```

- [ ] **Step 6: Tags aus Import und Export entfernen**

`InitExperts.php`: Import `use App\Models\Tag;` entfernen und ersetzen:

```php
            $model = Expert::updateOrCreate(
                ['name' => $expert['name']],
                $attributes
            );

            $tagIds = collect($expert['tags'] ?? [])
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->map(fn (string $name) => Tag::firstOrCreateByName($name)->id)
                ->unique()
                ->values()
                ->all();

            $model->tags()->sync($tagIds);

```

durch:

```php
            $model = Expert::updateOrCreate(
                ['name' => $expert['name']],
                $attributes
            );

```

`ProjectExport.php`: Zeile `'tags'             => $e->tags->pluck('name')->all(),` entfernen.

Run: `grep -n "tags" app/Services/ProjectTransfer/ProjectExport.php app/Models/Project.php`
Expected: keine Ausgabe. Falls ein Eager-Load wie `->with('tags')` auftaucht, ihn ebenfalls entfernen.

- [ ] **Step 7: Tags aus `database/experts.json` entfernen**

```bash
python3 - <<'EOF'
import json
path = 'database/experts.json'
experts = json.load(open(path, encoding='utf-8'))
for expert in experts:
    expert.pop('tags', None)
with open(path, 'w', encoding='utf-8') as f:
    json.dump(experts, f, ensure_ascii=False, indent=4)
    f.write('\n')
EOF
```

- [ ] **Step 8: Tests laufen lassen und Reste suchen**

Run: `php artisan test --filter=InitExpertsCommandTest`
Expected: PASS.

Run: `grep -rnE "\bTag\b|tags\(|tagsInput|expert_tag|firstOrCreateByName|'tags'" app database resources/views tests`
Expected: keine Ausgabe.

Run: `php artisan view:cache && php artisan view:clear && php artisan test`
Expected: Views ohne Fehler, Tests nur mit den 5 Baseline-Failures.

- [ ] **Step 9: Commit (nach Freigabe)**

```bash
git add -A app database resources/views tests
git commit -m "Remove expert tags

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Persona auf Name, Job und Description reduzieren

Entfernt `profile`, `core_beliefs`, `knowledge_limits` und `style` aus Datenbank, Modell, Editor, Import, Export und Seed-Daten. `Expert::asPromptArray()` liefert als `description` direkt die Beschreibung. Der Accordion-Abschnitt „Prompt" im Editor entfällt.

**Files:**
- Create: `tests/Feature/ExpertPersonaTest.php`
- Delete: `tests/Feature/PersonaListTest.php`
- Modify: `app/Models/Expert.php:26-39, 76-115`
- Modify: `database/migrations/2025_05_30_132453_create_experts_table.php:17-22`
- Modify: `database/factories/ExpertFactory.php:18-21`
- Modify: `app/Livewire/Experts/ExpertEditor.php`
- Modify: `resources/views/livewire/experts/expert-editor.blade.php:66-167`
- Modify: `app/Console/Commands/InitExperts.php:56-59`
- Modify: `app/Services/ProjectTransfer/ProjectExport.php:33-36`
- Modify: `resources/views/prompts/agent/speak.blade.php:125`
- Modify: `database/experts.json`

**Interfaces:**
- Consumes: Task 2 (Migration und `experts.json` ohne `voice_id`).
- Produces: `Expert::asPromptArray(Project $project): array{name: string, expert_id: int, prompt_id: string, job: string, description: string, thoughts: Summary}`. Die Prompt-Views `agent/think` und `agent/speak` lesen weiter `$expert['description']`, sie ändern sich bis auf eine Regelzeile nicht.

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/ExpertPersonaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertPersonaTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_description_is_the_plain_expert_description(): void
    {
        $expert = Expert::factory()->create(['description' => 'Pragmatische Architektin.']);
        $project = Project::factory()->create();

        $prompt = $expert->asPromptArray($project);

        $this->assertSame('Pragmatische Architektin.', $prompt['description']);
    }

    public function test_experts_table_has_no_persona_columns(): void
    {
        foreach (['profile', 'core_beliefs', 'knowledge_limits', 'style'] as $column) {
            $this->assertFalse(Schema::hasColumn('experts', $column), "Column $column still exists");
        }
    }

    public function test_editor_saves_name_job_and_description(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ExpertEditor::class)
            ->call('edit')
            ->set('name', 'Test Persona')
            ->set('job', 'Tester')
            ->set('description', 'Prüft alles.')
            ->call('save')
            ->assertHasNoErrors();

        $expert = Expert::where('name', 'Test Persona')->firstOrFail();
        $this->assertSame('Tester', $expert->job);
        $this->assertSame('Prüft alles.', $expert->description);
    }
}
```

- [ ] **Step 2: Test laufen lassen, er muss fehlschlagen**

Run: `php artisan test --filter=ExpertPersonaTest`
Expected: FAIL in `test_prompt_description_is_the_plain_expert_description` (Beschreibung ist aktuell der `[Profil]…`-Block) und in `test_experts_table_has_no_persona_columns`. `test_editor_saves_name_job_and_description` darf schon grün sein.

- [ ] **Step 3: Alten Persona-Test löschen**

```bash
git rm tests/Feature/PersonaListTest.php
```

- [ ] **Step 4: Migration überschreiben**

`database/migrations/2025_05_30_132453_create_experts_table.php`, `up()` wird zu:

```php
    public function up(): void
    {
        Schema::create('experts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('avatar_url')->nullable();
            $table->string('job');
            $table->text('description');
            $table->timestamps();
        });
    }
```

- [ ] **Step 5: Modell vereinfachen (`app/Models/Expert.php`)**

`$casts` komplett entfernen. `$fillable` wird zu:

```php
    protected $fillable = [
        'name',
        'avatar_url',
        'job',
        'description',
    ];
```

`asPromptArray()` wird zu:

```php
    public function asPromptArray(Project $project): array
    {
        return [
            'name'        => $this->name,
            'expert_id'   => $this->id,
            'prompt_id'   => $this->promptId,
            'job'         => $this->job,
            'description' => $this->description,
            'thoughts'    => $this->thoughtsAbout($project),
        ];
    }
```

Die private Methode `formatPersonaList()` entfernen.

- [ ] **Step 6: Factory anpassen (`database/factories/ExpertFactory.php`)**

```php
    public function definition(): array
    {
        return [
            'name'        => fake()->name(),
            'job'         => fake()->jobTitle(),
            'description' => fake()->sentence(),
            'avatar_url'  => null,
        ];
    }
```

- [ ] **Step 7: Editor-Komponente anpassen (`app/Livewire/Experts/ExpertEditor.php`)**

- Properties `$profile`, `$coreBeliefs`, `$knowledgeLimits`, `$style` samt `#[Validate]` entfernen.
- In `edit()` entfernen:

```php
        $this->profile = $expert->profile ?? '';
        $this->coreBeliefs = is_array($expert?->core_beliefs) ? array_values($expert->core_beliefs) : [];
        $this->knowledgeLimits = is_array($expert?->knowledge_limits) ? array_values($expert->knowledge_limits) : [];
        $this->style = $expert->style ?? '';
```

- In `save()` entfernen:

```php
        $expert->profile = $this->profile;
        $expert->core_beliefs = array_values(array_filter($this->coreBeliefs, fn ($v) => trim((string) $v) !== ''));
        $expert->knowledge_limits = array_values(array_filter($this->knowledgeLimits, fn ($v) => trim((string) $v) !== ''));
        $expert->style = $this->style;
```

- Methoden `addCoreBelief()`, `removeCoreBelief()`, `addKnowledgeLimit()`, `removeKnowledgeLimit()` entfernen.
- `resetForm()` wird zu:

```php
    protected function resetForm(): void
    {
        $this->reset(['expertId', 'name', 'avatarUpload', 'avatarUrl', 'job', 'description']);
    }
```

- [ ] **Step 8: Editor-View anpassen (`expert-editor.blade.php`)**

Den kompletten Block ab `{{-- Persona --}}` mit `<x-accordion-section :heading="__('Prompt')" …>` bis zum zugehörigen `</x-accordion-section>` (aktuell Zeilen 66-167) entfernen. Die Beschreibung ist jetzt auch Prompt-Inhalt, deshalb den Hilfetext im Identity-Abschnitt ersetzen:

```blade
                    :description="__('Kurze Beschreibung, die in der UI angezeigt wird.')"
```

durch:

```blade
                    :description="__('Wird in der UI angezeigt und ist der Persona-Kern im Prompt.')"
```

- [ ] **Step 9: Import und Export anpassen**

`app/Console/Commands/InitExperts.php`, `$attributes` wird zu:

```php
            $attributes = [
                'description' => $expert['description'],
                'job'         => $expert['job'],
                'avatar_url'  => $avatarUrl,
            ];
```

`app/Services/ProjectTransfer/ProjectExport.php`, Experten-Mapping wird zu:

```php
                ->map(fn ($e) => [
                    'id'          => $e->id,
                    'name'        => $e->name,
                    'job'         => $e->job,
                    'description' => $e->description,
                    'avatar_url'  => $e->avatar_url,
                ])
```

- [ ] **Step 10: Stil-Regel aus dem SPEAK-Prompt entfernen**

`resources/views/prompts/agent/speak.blade.php`, Zeile entfernen (die Regel bezieht sich auf das entfernte Feld `style`):

```
- Die Stilfarbe deiner Persona ist NUR ein Klang, niemals ein wörtlicher Satzbaustein. Auch im allerersten eigenen Beitrag verwendest du sie nicht als ganze Floskel, sondern höchstens als Tonfall.
```

Die übrigen Regeln unter `ERÖFFNUNG` (Verbot von Rollen-Eröffnungen wie „Aus … Sicht") bleiben, sie sind unabhängig vom Feld.

- [ ] **Step 11: Persona-Felder aus `database/experts.json` entfernen**

```bash
python3 - <<'EOF'
import json
path = 'database/experts.json'
experts = json.load(open(path, encoding='utf-8'))
for expert in experts:
    for key in ('profile', 'core_beliefs', 'knowledge_limits', 'style'):
        expert.pop(key, None)
with open(path, 'w', encoding='utf-8') as f:
    json.dump(experts, f, ensure_ascii=False, indent=4)
    f.write('\n')
EOF
python3 -c "import json; print({tuple(sorted(e)) for e in json.load(open('database/experts.json'))})"
```

Expected: genau ein Tupel `('avatar_url', 'description', 'job', 'name')`.

- [ ] **Step 12: Tests laufen lassen**

Run: `php artisan test --filter=ExpertPersonaTest`
Expected: 3 Tests PASS.

Run: `php artisan test`
Expected: nur die 5 Baseline-Failures.

- [ ] **Step 13: Reste suchen**

Run: `grep -rnE "core_beliefs|knowledge_limits|coreBeliefs|knowledgeLimits|Kernüberzeugung|Wissensgrenz|Stilfarbe|formatPersonaList|->profile|->style\b|'profile'|'style'" app database resources/views tests`
Expected: keine Ausgabe.

- [ ] **Step 14: Commit (nach Freigabe)**

```bash
git add -A app database resources/views tests
git commit -m "Reduce expert persona to name, job and description

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Doku, Diagramm und lokale Datenbank nachziehen

**Files:**
- Modify: `CLAUDE.md:61`
- Modify: `docs/archify/architektur.architecture.json` (Komponente `elevenlabs`, Verbindung `web-elevenlabs`)
- Regenerate: `docs/archify/architektur.html`

**Interfaces:**
- Consumes: Tasks 1 bis 3.
- Produces: Doku ohne Voice-Bezüge.

- [ ] **Step 1: `CLAUDE.md` anpassen**

Unter „Other integrations" ersetzen:

```markdown
`Clients\ElevenLabsClient` provides TTS for messages (`messages/{message}/audio`) and voice previews; `ProjectTransfer\*` handles JSON export/import of projects.
```

durch:

```markdown
`ProjectTransfer\*` handles JSON export/import of projects. Experts consist only of `name`, `job` and `description`; `description` is the persona core in the THINK/SPEAK prompts.
```

Unter „Generation loop" ersetzen:

```markdown
A turn stops the loop when the pipeline returns `stop` (expert hands the floor to a user → `UserInputRequested` for that specific user) or on exception (`GenerationStopped`).
```

durch:

```markdown
A turn stops the loop when the pipeline returns `stop` (expert hands the floor to a user, or no candidates) or on exception; both broadcast `GenerationStopped`.
```

- [ ] **Step 2: ElevenLabs aus dem Architekturdiagramm entfernen**

```bash
python3 - <<'EOF'
import json
path = 'docs/archify/architektur.architecture.json'
spec = json.load(open(path, encoding='utf-8'))
spec['components'] = [c for c in spec['components'] if c['id'] != 'elevenlabs']
spec['connections'] = [c for c in spec['connections'] if 'elevenlabs' not in (c['from'], c['to'])]
with open(path, 'w', encoding='utf-8') as f:
    json.dump(spec, f, ensure_ascii=False, indent=2)
    f.write('\n')
EOF
```

- [ ] **Step 3: Diagramm validieren und neu erzeugen**

Run: `node ~/.claude/skills/archify/bin/archify.mjs validate architecture docs/archify/architektur.architecture.json --quality showcase --json`
Expected: `"ok": true`, 9 Checks, `errors: 0`, `warnings: 0`.

Run: `node ~/.claude/skills/archify/bin/archify.mjs deliver architecture docs/archify/architektur.architecture.json docs/archify/architektur.html --quality showcase --json`
Expected: `"ok": true`, Exit-Code 0.

- [ ] **Step 4: Lokale Datenbank neu aufbauen (nur nach Rückfrage beim Nutzer, löscht lokale Daten)**

```bash
php artisan migrate:fresh
php artisan init:experts
php artisan dev:build-suite
```

Expected: alle drei Befehle ohne Fehler. `dev:build-suite` legt Admin und Demo-Projekte neu an.

- [ ] **Step 5: Hinweis an den Nutzer**

`.env` ist nicht versioniert und enthält noch `ELEVENLABS_API_KEY`. Der Nutzer entfernt die Zeile selbst.

- [ ] **Step 6: Commit (nach Freigabe)**

```bash
git add CLAUDE.md docs/archify/architektur.architecture.json docs/archify/architektur.html
git commit -m "Update docs after removing voice and persona fields

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Abschlussprüfung

- [ ] **Step 1: Gesamte Suite**

Run: `php artisan test`
Expected: nur die 5 Baseline-Failures.

- [ ] **Step 2: Repo-weite Restsuche**

Run: `git grep -niE "voice|elevenlabs|mention|suggest|UserInputRequested|Eingabe ist gefragt|expert_tag|tagsInput|core_beliefs|knowledge_limits|Kernüberzeugung|Wissensgrenz" -- ':!docs/superpowers' ':!docs/archify/*.html' ':!package-lock.json' ':!composer.lock'`
Expected: keine Ausgabe.

- [ ] **Step 3: Frontend-Build und manueller Check**

Run: `npm run build`
Expected: ohne Fehler.

Manuell mit `composer dev`: Projektseite zeigt keinen Text/Voice-Umschalter, der Chat ist sichtbar, Senden per Enter funktioniert, `@` öffnet kein Dropdown, der Pop-Ton lässt sich umschalten. Wenn ein Experte den Nutzer anspricht, stoppt die Diskussion, der Start-Button erscheint wieder, es gibt keinen Hinweis und keinen pulsierenden Rahmen. Im Dialog „Choose Contributors" gibt es keinen Vorschlags-Button. Der Experten-Editor zeigt nur noch Avatar und „Identity" (Name, Job, Description), Speichern funktioniert.

- [ ] **Step 4: Clean-Code-Review**

Skill `clean-code-review` auf `app/Models/Expert.php`, `app/Livewire/Experts/ExpertEditor.php`, `app/Console/Commands/InitExperts.php`, `app/Services/ProjectTransfer/ProjectExport.php` und `tests/Feature/ExpertPersonaTest.php` anwenden.
