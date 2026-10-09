/**
 * The noise chat makes, which is the difference between a message arriving and
 * a message being noticed.
 *
 * **Synthesised rather than played.** Two tones from an oscillator instead of
 * an mp3, because the alternative is a binary file in a package the portals
 * consume out of `vendor/` — which means publishing it into three `public/`
 * directories, keeping those copies in step with this one, and a 404 that is
 * silent in exactly the way a notification must not be. Nothing to publish
 * cannot fall out of step.
 *
 * **A browser will not make a sound until somebody has touched the page.**
 * That is not a bug to work around; it is the rule, and every audio context
 * starts suspended until a gesture. So the first click anywhere in the portal
 * wakes it, and until then the badge is doing the telling on its own.
 */

const KEY = 'revun.chat.sound';

let context = null;
let unlocked = false;

/** What is currently making a noise, so it can be stopped mid-pattern. */
let voices = [];
let pattern = null;

export function muted() {
    try {
        return localStorage.getItem(KEY) === 'off';
    } catch {
        /* A private window has no opinion about this, so neither do we. */
        return false;
    }
}

export function mute(off) {
    try {
        localStorage.setItem(KEY, off ? 'off' : 'on');
    } catch {
        /* It simply does not survive the page. */
    }

    if (off) stop();
}

function audio() {
    if (context !== null) return context;

    const Context = window.AudioContext ?? window.webkitAudioContext;

    if (typeof Context !== 'function') return null;

    try {
        context = new Context();
    } catch {
        return null;
    }

    return context;
}

/**
 * The first gesture anywhere in the portal, which is what permits sound.
 *
 * Attached once and removed the moment it fires: a listener on every click for
 * the rest of the session, to do something that can only happen once, is the
 * kind of thing that ends up in a performance report.
 */
export function unlock() {
    if (unlocked) return;

    unlocked = true;

    const wake = () => {
        audio()?.resume?.().catch(() => {});

        ['pointerdown', 'keydown', 'touchstart'].forEach((event) => {
            window.removeEventListener(event, wake);
        });
    };

    ['pointerdown', 'keydown', 'touchstart'].forEach((event) => {
        window.addEventListener(event, wake, { once: false, passive: true });
    });
}

/**
 * One note.
 *
 * The envelope is the whole difference between a note and a click: a gain that
 * jumps from nothing to full and back leaves a step in the waveform, and a step
 * is a tick in the speaker. So it is ramped in over a few milliseconds and
 * down over the tail.
 */
function note(from, { frequency, seconds, volume }) {
    const where = audio();

    if (where === null) return;

    const oscillator = where.createOscillator();
    const gain = where.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;

    gain.gain.setValueAtTime(0, from);
    gain.gain.linearRampToValueAtTime(volume, from + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, from + seconds);

    oscillator.connect(gain).connect(where.destination);
    oscillator.start(from);
    oscillator.stop(from + seconds + 0.02);

    voices.push(oscillator);
    oscillator.onended = () => {
        voices = voices.filter((voice) => voice !== oscillator);
    };
}

function play(notes) {
    const where = audio();

    if (where === null || muted()) return;

    /*
     * Resumed here as well as on the first gesture: a tab left alone long
     * enough has its context suspended again by the browser, and a suspended
     * context accepts everything scheduled below and plays none of it.
     */
    if (where.state === 'suspended') {
        where.resume().catch(() => {});
    }

    const start = where.currentTime + 0.02;

    notes.forEach(({ at = 0, ...rest }) => note(start + at, rest));
}

/** A message, from somebody else, in a conversation nobody is looking at. */
export function message() {
    play([
        { frequency: 784, seconds: 0.1, volume: 0.16 },
        { at: 0.1, frequency: 1047, seconds: 0.16, volume: 0.13 },
    ]);
}

/** Somebody has been added to a conversation, or a call has just connected. */
export function connected() {
    play([
        { frequency: 523, seconds: 0.09, volume: 0.12 },
        { at: 0.09, frequency: 784, seconds: 0.14, volume: 0.1 },
    ]);
}

/**
 * A phone, until somebody deals with it.
 *
 * Two bursts and a gap, repeated — the shape every telephone has had for sixty
 * years, because it is the one people recognise without being taught.
 */
export function ring() {
    stop();

    if (muted()) return;

    const burst = () => play([
        { frequency: 480, seconds: 0.38, volume: 0.2 },
        { at: 0.5, frequency: 480, seconds: 0.38, volume: 0.2 },
    ]);

    burst();
    pattern = setInterval(burst, 2600);
}

/**
 * The caller's own end of the same thing, quieter.
 *
 * It is quieter on purpose: the person who pressed the button knows a call is
 * happening and is holding a phone to their ear, while the person being rung
 * may be three rooms away.
 */
export function ringback() {
    stop();

    if (muted()) return;

    const burst = () => play([{ frequency: 420, seconds: 0.6, volume: 0.07 }]);

    burst();
    pattern = setInterval(burst, 3000);
}

/** Nobody answered, or they said no. */
export function ended() {
    stop();
    play([
        { frequency: 400, seconds: 0.14, volume: 0.12 },
        { at: 0.16, frequency: 300, seconds: 0.22, volume: 0.1 },
    ]);
}

/** Silence, now rather than at the end of the burst already scheduled. */
export function stop() {
    clearInterval(pattern);
    pattern = null;

    voices.forEach((voice) => {
        try {
            voice.stop();
        } catch {
            /* Already finished on its own. */
        }
    });

    voices = [];
}
