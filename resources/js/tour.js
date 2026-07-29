import Shepherd from 'shepherd.js';
import 'shepherd.js/dist/css/shepherd.css';
import '../css/tour.css';

// --- Small helpers -----------------------------------------------------------

const byTour = (name) => document.querySelector(`[data-tour="${name}"]`);

/** Resolve a [data-tour] anchor lazily so Shepherd re-reads the DOM each step. */
const anchor = (name, on) => ({ element: () => byTour(name), on });

/**
 * The one demo message used for the "anatomy of a message" steps (4–7): the
 * first message that carries BOTH an addressed arrow and a brain badge, so the
 * bubble, arrow, brain icon and thoughts all belong to the SAME message and the
 * tour never jumps between messages. Each step highlights only its own element.
 */
function tourMsg() {
    for (const arrow of document.querySelectorAll('[data-tour="addressed-arrow"]')) {
        const root = arrow.closest('.block');
        const brain = root?.querySelector('[data-tour="brain-badge"]');
        const bubble = root?.querySelector('[data-tour="chat-message"]');
        if (brain && bubble) {
            return { root, arrow, brain, bubble };
        }
    }

    return null;
}

/** Poll for a selector to appear (used after opening a modal/flyout). */
function waitFor(selector, timeout = 2500) {
    return new Promise((resolve) => {
        const existing = document.querySelector(selector);
        if (existing) return resolve(existing);

        const start = Date.now();
        const iv = setInterval(() => {
            const el = document.querySelector(selector);
            if (el) {
                clearInterval(iv);
                resolve(el);
            } else if (Date.now() - start > timeout) {
                clearInterval(iv);
                resolve(null);
            }
        }, 60);
    });
}

/**
 * Wait until a selector is not just present but actually laid out and visible,
 * then let its open/slide-in transition settle. Flux flyouts are <dialog> panels
 * that animate in with a transform; measuring them too early makes Shepherd
 * anchor against a zero/offscreen rect and drop the popover into the corner.
 */
function waitForVisible(selector, { settle = 320, timeout = 3000 } = {}) {
    return new Promise((resolve) => {
        const start = Date.now();
        const check = () => {
            const el = document.querySelector(selector);
            const ready = el && el.offsetParent !== null && el.getBoundingClientRect().width > 4;
            if (ready) {
                setTimeout(() => resolve(el), settle);
            } else if (Date.now() - start > timeout) {
                resolve(el ?? null);
            } else {
                requestAnimationFrame(check);
            }
        };
        check();
    });
}

/**
 * Force Shepherd/floating-ui to recompute the popover position on the next
 * frames — belt-and-braces after a panel has slid in, in case the first
 * measurement happened a hair too early.
 */
function nudgeReposition() {
    requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
    setTimeout(() => window.dispatchEvent(new Event('resize')), 150);
}

/** Open/close a Flux modal by name, tolerating API/version differences. */
function fluxModal(name, action) {
    try {
        if (window.Flux && typeof window.Flux.modal === 'function') {
            const modal = window.Flux.modal(name);
            if (modal && typeof modal[action] === 'function') {
                modal[action]();
                return;
            }
        }
    } catch (e) {
        /* fall through to the event-based fallback */
    }
    window.dispatchEvent(
        new CustomEvent(action === 'show' ? 'modal-show' : 'modal-close', {
            detail: { name },
        }),
    );
}

const closeAnyOverlays = () => {
    fluxModal('select-contributors', 'close');
    fluxModal('expert-thoughts-flyout', 'close');
    // Drop the tour-scoped z-index lift and any leftover simulated states.
    document.body.classList.remove('wt-active');
    teardownInputRequestedDemo();
};

// --- "Your input is requested" simulation ------------------------------------
// The read-only demo never actually pauses for input, so the tour temporarily
// reproduces the visual state: the amber pulsing ring on the composer plus the
// "Deine Eingabe ist gefragt." banner. Purely cosmetic; removed on step exit.

const WT_INPUT_RING = ['ring-2', 'ring-amber-400', 'dark:ring-amber-500', 'animate-pulse'];
const WT_BANNER_ID = 'wt-input-banner';

function setupInputRequestedDemo() {
    const ring = byTour('composer-ring');
    if (ring) ring.classList.add(...WT_INPUT_RING);

    const form = byTour('composer');
    if (form && !document.getElementById(WT_BANNER_ID)) {
        const banner = document.createElement('div');
        banner.id = WT_BANNER_ID;
        banner.className = 'mb-2 flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400';
        banner.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><span>Deine Eingabe ist gefragt.</span>';
        form.parentNode.insertBefore(banner, form);
    }
}

function teardownInputRequestedDemo() {
    byTour('composer-ring')?.classList.remove(...WT_INPUT_RING);
    document.getElementById(WT_BANNER_ID)?.remove();
}

// --- Tour definition ---------------------------------------------------------

function buildTour() {
    const tour = new Shepherd.Tour({
        useModalOverlay: true,
        exitOnEsc: true,
        keyboardNavigation: true,
        defaultStepOptions: {
            classes: 'wt-step',
            scrollTo: { behavior: 'smooth', block: 'center' },
            cancelIcon: { enabled: true },
            modalOverlayOpeningPadding: 6,
            modalOverlayOpeningRadius: 12,
            arrow: true,
        },
    });

    // Always leave the UI clean when the tour ends for any reason.
    tour.on('complete', closeAnyOverlays);
    tour.on('cancel', closeAnyOverlays);

    const backBtn = { text: 'Zurück', action: () => tour.back(), secondary: true };
    const nextBtn = { text: 'Weiter', action: () => tour.next() };

    tour.addStep({
        id: 'welcome',
        title: 'Willkommen 👋',
        text: `
            <p>Dieses Tool simuliert eine <strong>moderierte Diskussion mehrerer KI-Experten</strong> zu einem Thema deiner Wahl.</p>
            <p>Du gibst ein Thema vor, wählst passende Experten – und beobachtest (oder lenkst), wie sie miteinander diskutieren. Dieser kurze Rundgang zeigt dir alles Wichtige an einer fertigen Beispiel-Diskussion.</p>
        `,
        buttons: [
            { text: 'Überspringen', action: () => tour.cancel(), secondary: true },
            { text: "Los geht's", action: () => tour.next() },
        ],
    });

    tour.addStep({
        id: 'new-project',
        title: '1 · Projekt erstellen',
        text: `
            <p>Über <strong>„New Project"</strong> startest du ein neues Thema. Du gibst einen Titel und eine Beschreibung ein.</p>
            <p><em>Tipp:</em> Je konkreter die Beschreibung (Ziel, Rahmenbedingungen, Zielgruppe), desto besser und fokussierter diskutieren die Experten.</p>
        `,
        attachTo: anchor('new-project', 'right'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'experts-nav',
        title: '2 · Experten kennenlernen',
        text: `
            <p>Unter <strong>„Experts"</strong> findest du alle verfügbaren Personas. Über das <strong>ℹ️-Symbol</strong> siehst du jede Persona im Detail: Profil, Kernüberzeugungen, Wissensgrenzen und Sprachstil.</p>
        `,
        attachTo: anchor('experts-nav', 'right'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'contributors',
        title: '3 · Experten hinzufügen',
        text: `
            <p>Hier stellst du das Team für dein Projekt zusammen. Pro Diskussion sind es <strong>mindestens 3 und höchstens 4 Experten</strong> – genug für echte Kontroverse, ohne unübersichtlich zu werden.</p>
            <p>Im Auswahldialog fügst du Experten per Klick hinzu; das ℹ️-Symbol jeder Karte zeigt die Details.</p>
        `,
        attachTo: anchor('contributors', 'bottom'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'expert-select',
        title: '4 · Experten auswählen',
        text: `
            <p>So sieht die Auswahl aus: Jede Karte ist eine Persona. Ein <strong>Klick</strong> fügt sie hinzu oder entfernt sie wieder; das <strong>ℹ️-Symbol</strong> öffnet die Detailansicht.</p>
            <p>Ist das Maximum von 4 Experten erreicht, werden weitere Karten ausgegraut.</p>
        `,
        attachTo: { element: () => byTour('expert-card'), on: 'left' },
        beforeShowPromise: () => {
            fluxModal('select-contributors', 'show');
            return waitForVisible('[data-tour="expert-card"]');
        },
        when: {
            show() {
                nudgeReposition();
            },
            hide() {
                fluxModal('select-contributors', 'close');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'chat-message',
        title: '5 · Ein Beitrag',
        text: `
            <p>Jede Sprechblase ist ein Diskussionsbeitrag. Unter der Blase siehst du den <strong>Avatar des Sprechers</strong>. Die Beiträge bauen logisch aufeinander auf.</p>
        `,
        attachTo: { element: () => tourMsg()?.bubble ?? byTour('chat-message'), on: 'right' },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'arrow',
        title: '6 · Die Pfeile',
        text: `
            <p>Ein <strong>Pfeil</strong> neben dem Avatar zeigt, <strong>an wen</strong> sich ein Beitrag richtet – am Ziel-Avatar erkennst du die adressierte Person.</p>
            <p>So entstehen sichtbare Gesprächsfäden: eine Frage an einen Experten, eine Reaktion auf eine Ansprache, oder – am Ende – eine Rückgabe <strong>an dich</strong> als Nutzer:in.</p>
        `,
        attachTo: { element: () => tourMsg()?.arrow ?? byTour('addressed-arrow'), on: 'bottom' },
        when: {
            show() {
                (tourMsg()?.arrow ?? byTour('addressed-arrow'))?.classList.add('wt-hl-arrow');
            },
            hide() {
                (tourMsg()?.arrow ?? byTour('addressed-arrow'))?.classList.remove('wt-hl-arrow');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'brain',
        title: '7 · In die Gedanken schauen',
        text: `
            <p>Das <strong>Gehirn-Symbol</strong> an einem Experten-Avatar öffnet sein <strong>Gedächtnis</strong>: was er über die anderen denkt, welche Fragen für ihn offen sind und wie er den Stand einschätzt.</p>
            <p>Klick auf „Weiter" – wir öffnen es für dich.</p>
        `,
        attachTo: { element: () => tourMsg()?.brain ?? byTour('brain-badge'), on: 'bottom' },
        when: {
            show() {
                (tourMsg()?.brain ?? byTour('brain-badge'))?.classList.add('wt-hl-brain');
            },
            hide() {
                (tourMsg()?.brain ?? byTour('brain-badge'))?.classList.remove('wt-hl-brain');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'thoughts',
        title: '8 · Das Gedächtnis',
        text: `
            <p>Das ist die innere Sicht des Experten auf die Diskussion – sein „Gedächtnis". Es wird bei jedem Denk-Schritt aktualisiert und steuert, was er als Nächstes beiträgt.</p>
        `,
        attachTo: { element: () => byTour('thoughts-content'), on: 'left' },
        // Open the flyout for the SAME expert whose brain icon we just highlighted,
        // then wait until the panel has fully slid in before anchoring — otherwise
        // Shepherd measures a mid-transition rect and drops the popover in a corner.
        beforeShowPromise: () => {
            (tourMsg()?.brain ?? byTour('brain-badge'))?.click();
            return waitForVisible('[data-tour="thoughts-content"]');
        },
        when: {
            show() {
                nudgeReposition();
            },
            hide() {
                fluxModal('expert-thoughts-flyout', 'close');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'composer',
        title: '9 · Selbst mitdiskutieren',
        text: `
            <p>Hier schreibst du eigene Nachrichten in die Diskussion. Mit <strong>@Name</strong> sprichst du einen Experten gezielt an – er wird dann bevorzugt antworten.</p>
        `,
        attachTo: anchor('composer', 'top'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'input-requested',
        title: '10 · Wenn du gefragt bist',
        text: `
            <p>Manchmal braucht die Runde eine Entscheidung von <strong>dir</strong> – dann hält die Diskussion an und übergibt an dich.</p>
            <p>Du erkennst das sofort: Das Eingabefeld bekommt einen <strong>pulsierenden Rahmen</strong> und darüber erscheint der Hinweis <em>„Deine Eingabe ist gefragt."</em>. Schreib einfach deine Antwort und die Runde läuft weiter.</p>
        `,
        attachTo: anchor('composer', 'top'),
        when: {
            show() {
                setupInputRequestedDemo();
            },
            hide() {
                teardownInputRequestedDemo();
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'generate',
        title: '11 · Nächsten Beitrag erzeugen',
        text: `
            <p>Mit dem <strong>✨-Knopf</strong> lässt du die Experten den nächsten Diskussionsbeitrag erzeugen – Schritt für Schritt, ganz nach deinem Tempo.</p>
            <p>Der Knopf ist erst aktiv, wenn <strong>mindestens 3 Experten</strong> im Projekt sind.</p>
        `,
        attachTo: anchor('generate', 'top'),
        when: {
            show() {
                byTour('generate')?.classList.add('wt-shimmer');
            },
            hide() {
                byTour('generate')?.classList.remove('wt-shimmer');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'autoplay',
        title: '12 · Automodus',
        text: `
            <p>Mit dem <strong>⏩-Knopf</strong> schaltest du den Automodus ein: Die Diskussion läuft dann von selbst weiter, Beitrag um Beitrag.</p>
            <p>Während die Experten „nachdenken", erscheint eine Schreib-Anzeige. Wird deine Entscheidung gebraucht, hält die Runde an und ein Hinweis fordert deine Eingabe an.</p>
        `,
        attachTo: anchor('autoplay', 'top'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'finish',
        title: 'Fertig! 🎉',
        text: `
            <p>Das war der Rundgang. Diese Beispiel-Diskussion ist <strong>schreibgeschützt</strong> – erkunde sie in Ruhe.</p>
            <p>Wenn du bereit bist, erstelle dein <strong>eigenes Projekt</strong> und leg los.</p>
        `,
        buttons: [
            backBtn,
            { text: 'Eigenes Projekt starten', action: () => { window.location.href = '/projects/new'; } },
            { text: 'Fertig', action: () => tour.complete() },
        ],
    });

    return tour;
}

// --- Entry points ------------------------------------------------------------

let activeTour = null;

function startWelcomeTour() {
    // Never stack two tours.
    if (activeTour && activeTour.isActive()) return;
    closeAnyOverlays();
    // Lift Flux flyouts/modals above the Shepherd overlay for the tour's duration
    // (scoped CSS in tour.css), so opened panels aren't dimmed or covered.
    document.body.classList.add('wt-active');
    activeTour = buildTour();
    activeTour.start();
}

window.startWelcomeTour = startWelcomeTour;
window.addEventListener('start-welcome-tour', startWelcomeTour);

// Auto-start when we arrived via the "Rundgang" link (?tour=1). Strip the param
// so a manual refresh doesn't restart the tour.
function maybeAutoStart() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tour') !== '1') return;

    params.delete('tour');
    const query = params.toString();
    window.history.replaceState(
        {},
        '',
        window.location.pathname + (query ? `?${query}` : '') + window.location.hash,
    );

    // Give Livewire/Flux a moment to finish morphing the chat before anchoring.
    setTimeout(startWelcomeTour, 400);
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    maybeAutoStart();
} else {
    window.addEventListener('DOMContentLoaded', maybeAutoStart);
}
