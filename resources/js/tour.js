import Shepherd from 'shepherd.js';
import { offset } from '@floating-ui/dom';
import 'shepherd.js/dist/css/shepherd.css';
import '../css/tour.css';

// --- Small helpers -----------------------------------------------------------

const byTour = (name) => document.querySelector(`[data-tour="${name}"]`);

/** Resolve a [data-tour] anchor lazily so Shepherd re-reads the DOM each step. */
const anchor = (name, on) => ({ element: () => byTour(name), on });

/**
 * Push a popover further away from its anchor edge (extra floating-ui offset,
 * stacked on top of Shepherd's default gap). Used on the flyout steps so the
 * box clears the "Choose Contributors" / thoughts flyout that slides in on the
 * right instead of being overlapped by it.
 */
const shiftAway = (px) => ({ floatingUIOptions: { middleware: [offset(px)] } });

/**
 * The one demo message used for the "anatomy of a message" steps (5–8): the
 * first message that carries BOTH an addressed arrow and a brain badge, so the
 * bubble, arrow, brain icon and thoughts all belong to the SAME message and the
 * tour never jumps between messages.
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

// Every highlight class the steps toggle. Stripped on teardown so a tour that
// is cancelled mid-step (Esc / ×) never leaves an element frozen in a lit state.
const WT_HIGHLIGHTS = ['wt-hl-frame', 'wt-hl-arrow', 'wt-hl-brain', 'wt-hl-ring', 'wt-shimmer'];

const clearHighlights = () => {
    document
        .querySelectorAll('.' + WT_HIGHLIGHTS.join(',.'))
        .forEach((el) => el.classList.remove(...WT_HIGHLIGHTS));
};

const closeAnyOverlays = () => {
    fluxModal('select-contributors', 'close');
    fluxModal('expert-thoughts-flyout', 'close');
    // Drop the tour-scoped z-index lift and any leftover simulated states.
    document.body.classList.remove('wt-active');
    teardownInputRequestedDemo();
    clearHighlights();
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

// #region agent log
/** Debug: measure popover vs attachTo placement and flag visual issues. */
function wtDebugStep(tour, hypothesisId) {
    try {
        const step = tour.getCurrentStep?.();
        const id = step?.id ?? 'unknown';
        const pop = [...document.querySelectorAll('.shepherd-element')]
            .find((el) => !el.hidden && el.getAttribute('data-shepherd-step-id') === id)
            || [...document.querySelectorAll('.shepherd-element')]
                .find((el) => !el.hidden)
            || document.querySelector('.shepherd-element');
        const pr = pop?.getBoundingClientRect();
        const popStyle = pop ? getComputedStyle(pop) : null;
        const attach = step?.options?.attachTo;
        let anchorEl = null;
        if (attach?.element) {
            anchorEl = typeof attach.element === 'function' ? attach.element() : attach.element;
        }
        const ar = anchorEl?.getBoundingClientRect?.();
        const issues = [];
        if (!pr) issues.push('no-popover');
        if (pr && (pr.width < 4 || pr.height < 4)) issues.push('popover-tiny');
        if (pr && (pr.right < 0 || pr.bottom < 0 || pr.left > innerWidth || pr.top > innerHeight)) {
            issues.push('popover-offscreen');
        }
        if (attach && !anchorEl) issues.push('missing-anchor');
        if (ar && (ar.width < 4 || ar.height < 4)) issues.push('anchor-tiny');
        if (ar && (ar.right < 0 || ar.bottom < 0 || ar.left > innerWidth || ar.top > innerHeight)) {
            issues.push('anchor-offscreen');
        }
        // Corner-drop heuristic: popover near (0,0) while an attach target existed
        if (pr && attach && ar && pr.x < 8 && pr.y < 8 && (ar.x > 40 || ar.y > 40)) {
            issues.push('popover-corner-drop');
        }
        const payload = {
            sessionId: 'aba80e',
            runId: 'tour-qa',
            hypothesisId,
            location: 'tour.js:wtDebugStep',
            message: `tour-step:${id}`,
            data: {
                stepId: id,
                attachOn: attach?.on ?? null,
                popover: pr ? { x: Math.round(pr.x), y: Math.round(pr.y), w: Math.round(pr.width), h: Math.round(pr.height) } : null,
                popStyle: popStyle ? { display: popStyle.display, visibility: popStyle.visibility, opacity: popStyle.opacity, cls: pop.className } : null,
                shepherdCount: document.querySelectorAll('.shepherd-element').length,
                anchor: ar ? { x: Math.round(ar.x), y: Math.round(ar.y), w: Math.round(ar.width), h: Math.round(ar.height) } : null,
                vw: innerWidth,
                vh: innerHeight,
                issues,
                highlights: {
                    frame: !!document.querySelector('.wt-hl-frame'),
                    arrow: !!document.querySelector('.wt-hl-arrow'),
                    brain: !!document.querySelector('.wt-hl-brain'),
                    ring: !!document.querySelector('.wt-hl-ring'),
                    shimmer: !!document.querySelector('.wt-shimmer'),
                    banner: !!document.getElementById('wt-input-banner'),
                },
            },
            timestamp: Date.now(),
        };
        fetch('http://127.0.0.1:7618/ingest/1c93f1ef-a021-45ff-a0b8-0f1babb7ed76', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Debug-Session-Id': 'aba80e' },
            body: JSON.stringify(payload),
        }).catch(() => {});
    } catch (_) { /* ignore */ }
}
// #endregion

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

    // #region agent log
    tour.on('show', () => {
        // Wait a tick so floating-ui finishes positioning.
        // Settle after scrollTo (smooth) + floating-ui; message steps can scroll far.
        setTimeout(() => {
            const id = tour.getCurrentStep()?.id;
            const hyp =
                id === 'expert-select' || id === 'thoughts' ? 'H1' :
                id === 'chat-message' || id === 'arrow' || id === 'brain' ? 'H2' :
                id === 'new-project' || id === 'experts-nav' || id === 'contributors' ? 'H3' :
                id === 'composer' || id === 'generate' || id === 'autoplay' ? 'H4' :
                id === 'input-requested' ? 'H5' : 'H0';
            wtDebugStep(tour, hyp);
        }, 900);
    });
    // #endregion

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
        attachTo: anchor('new-project', 'right-start'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'experts-nav',
        title: '2 · Experten kennenlernen',
        text: `
            <p>Unter <strong>„Experts"</strong> findest du alle verfügbaren Personas. Über das <strong>ℹ️-Symbol</strong> siehst du jede Persona im Detail: Profil, Kernüberzeugungen, Wissensgrenzen und Sprachstil.</p>
        `,
        attachTo: anchor('experts-nav', 'right-start'),
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'contributors',
        title: '3 · Experten hinzufügen',
        text: `
            <p>Hier stellst du das Team für dein Projekt zusammen. Pro Diskussion sind es <strong>mindestens 3 und höchstens 4 Experten</strong>.</p>
            <p>Im Auswahldialog fügst du Experten per Klick hinzu; das ℹ️-Symbol jeder Karte zeigt die Details.</p>
        `,
        attachTo: anchor('set-contributors', 'bottom'),
        when: {
            show() {
                byTour('set-contributors')?.classList.add('wt-hl-ring');
            },
            hide() {
                byTour('set-contributors')?.classList.remove('wt-hl-ring');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'expert-select',
        title: '4 · Experten auswählen',
        text: `
            <p>So sieht die Auswahl aus: Jede Karte ist eine Persona. Ein <strong>Klick</strong> fügt sie hinzu oder entfernt sie wieder; das <strong>ℹ️-Symbol</strong> öffnet die Detailansicht.</p>
            <p>Mit <strong>„Suggest experts"</strong> schlägt dir das System automatisch passende Experten zu deinem Thema vor. Ist das Maximum von 4 Experten erreicht, werden weitere Karten ausgegraut.</p>
        `,
        attachTo: { element: () => byTour('expert-card'), on: 'left' },
        // Don't scroll-center a target inside a freshly-opened flyout — that scroll
        // triggers a second positioning pass and the popover appears to flash twice.
        scrollTo: false,
        ...shiftAway(40),
        beforeShowPromise: () => {
            fluxModal('select-contributors', 'show');
            return waitForVisible('[data-tour="expert-card"]');
        },
        when: {
            show() {
                byTour('expert-details')?.classList.add('wt-hl-ring');
                byTour('suggest-experts')?.classList.add('wt-hl-ring');
            },
            hide() {
                byTour('expert-details')?.classList.remove('wt-hl-ring');
                byTour('suggest-experts')?.classList.remove('wt-hl-ring');
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
        attachTo: { element: () => tourMsg()?.root ?? byTour('chat-message'), on: 'right' },
        when: {
            show() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.add('wt-hl-frame');
            },
            hide() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.remove('wt-hl-frame');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'arrow',
        title: '6 · Die Pfeile',
        text: `
            <p>Ein <strong>Pfeil</strong> neben dem Avatar zeigt, <strong>an wen</strong> sich ein Beitrag richtet – am Ziel-Avatar erkennst du die adressierte Person.</p>
        `,
        // Anchor the popover to the bottom of the message (right-end) so it sits
        // close to the arrow, while the frame still wraps the whole contribution.
        attachTo: { element: () => tourMsg()?.root ?? byTour('chat-message'), on: 'right-end' },
        when: {
            show() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.add('wt-hl-frame');
                (tourMsg()?.arrow ?? byTour('addressed-arrow'))?.classList.add('wt-hl-arrow');
            },
            hide() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.remove('wt-hl-frame');
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
        `,
        // Anchor the popover to the bottom of the message (right-end) so it sits
        // close to the brain badge, while the frame still wraps the whole message.
        attachTo: { element: () => tourMsg()?.root ?? byTour('chat-message'), on: 'right-end' },
        when: {
            show() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.add('wt-hl-frame');
                (tourMsg()?.brain ?? byTour('brain-badge'))?.classList.add('wt-hl-brain');
            },
            hide() {
                (tourMsg()?.bubble ?? byTour('chat-message'))?.classList.remove('wt-hl-frame');
                (tourMsg()?.brain ?? byTour('brain-badge'))?.classList.remove('wt-hl-brain');
            },
        },
        buttons: [backBtn, nextBtn],
    });

    tour.addStep({
        id: 'thoughts',
        title: '8 · Das Gedächtnis',
        text: `
            <p>Das ist die innere Sicht des Experten auf die Diskussion – sein „Gedächtnis".</p>
        `,
        attachTo: { element: () => byTour('thoughts-content'), on: 'left' },
        // Don't scroll-center a target inside a freshly-opened flyout — that scroll
        // triggers a second positioning pass and the popover appears to flash twice.
        scrollTo: false,
        ...shiftAway(40),
        // Open the flyout for the SAME expert whose brain icon we just highlighted,
        // then wait until the panel has fully slid in before anchoring — otherwise
        // Shepherd measures a mid-transition rect and drops the popover in a corner.
        beforeShowPromise: () => {
            (tourMsg()?.brain ?? byTour('brain-badge'))?.click();
            return waitForVisible('[data-tour="thoughts-content"]');
        },
        when: {
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
            <p>Hier schreibst du eigene Nachrichten in die Diskussion. Mit <strong>@Name</strong> sprichst du einen Experten gezielt an.</p>
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
