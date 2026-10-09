import { headers } from './chat.js';
import * as sound from './sound.js';

/**
 * Audio calls, from the same conversation people are already in.
 *
 * **One call per conversation.** The id is derived from the channel on the
 * server, so pressing call twice joins the call that is running rather than
 * starting a second one next to it — the failure that makes a team of four
 * end up in three calls.
 *
 * **Audio only, on purpose.** The camera is disabled before joining, not
 * hidden afterwards: a browser that has asked for a camera has a light on
 * somewhere, and people notice. Video is the same component with that line
 * removed, which is when it will be added.
 *
 * **Every call is recorded**, and the interface says so while it rings rather
 * than in a policy nobody read.
 *
 * **A ringing call is not a call yet.** Stream's model is that the caller
 * joins once somebody accepts, and the difference matters in four places at
 * once: what the card says, when the microphone goes live, when the recording
 * starts, and — the one that embarrasses — what hanging up does. Leaving a
 * call does not stop it ringing; cancelling it does. So nothing here joins
 * until the session says somebody picked up.
 */

const CALLS_ENDPOINT = '/chat/calls';

/** Nobody waits for a phone forever. */
const NO_ANSWER_SECONDS = 45;

/*
 * Outside Alpine, for the reason chat.js gives at length: a subscription holds
 * the call, which holds the client, which holds every call. Handed to a
 * reactive proxy that is a graph which cannot be walked.
 */
let watcher = null;
let recorder = null;
let timeout = null;

export function registerCalls(Alpine) {
    Alpine.store('calls', {
        client: null,
        building: null,
        call: null,

        /** ringing-out | ringing-in | live | null */
        state: null,
        title: '',
        muted: false,
        seconds: 0,
        recording: false,
        error: null,
        ticker: null,

        /** Whether this call is meant to be recorded, as the server decided. */
        wanted: false,

        /**
         * Who this is, taken from the session the client was built with.
         *
         * Needed to work out whether everybody who was rung has said no, and
         * taken from here rather than from chat's store because a null there —
         * a store not yet connected, a page where only calls are registered —
         * does not announce itself: it quietly makes "everybody declined" a
         * thing that can never be true, and an outgoing call then rings until
         * it times out however firmly it was refused.
         */
        mine: null,

        /**
         * The video client shares chat's token: the same person, one identity.
         *
         * Built once and remembered — including while it is still being built,
         * so two presses of the call button do not open two clients. Chat calls
         * this as soon as it connects, so by the time anybody presses call the
         * handshake has already happened.
         */
        ready() {
            this.building ??= (async () => {
                /*
                 * The video SDK is fetched here and not at the top of the file.
                 *
                 * It is eight hundred kilobytes, and imported normally it lands
                 * in the bundle every page of the portal loads -- so every list,
                 * every record and the sign-in screen paid for a call client
                 * before anybody had made a call. Asked for here, it is fetched
                 * once, when a call is first wanted or when the browser is idle.
                 */
                const { StreamVideoClient } = await import('@stream-io/video-client');

                /* Chat has already been given one; the same person does not
                   need a second token to make a call. */
                const session = Alpine.store('chat')?.session ?? await fetch('/chat/token', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers(),
                }).then((response) => {
                    if (!response.ok) throw new Error('no token');

                    return response.json();
                });

                this.mine = session.user_id ?? session.user?.id ?? null;

                this.client = new StreamVideoClient({
                    apiKey: session.api_key,
                    user: session.user,
                    token: session.token,
                });

                this.listen();

                return this.client;
            })().catch((error) => {
                // Let the next attempt try again rather than failing for good.
                this.building = null;

                throw error;
            });

            return this.building;
        },

        listen() {
            /*
             * Somebody is calling. The card appears wherever the person is in
             * the portal, because the dock is in the layout — a call that only
             * rings on the chat page is a call most people miss.
             */
            this.client.on('call.ring', async (event) => {
                if (this.state) return;

                const call = this.client.call(event.call.type, event.call.id);
                this.call = call;
                this.title = event.call.created_by?.name ?? 'Incoming call';
                this.state = 'ringing-in';

                /* A phone that does not ring is a missed call. */
                sound.ring();
            });

            this.client.on('call.ended', () => this.finish());
        },

        /**
         * Pressing call.
         *
         * The card goes up on the same tick as the press — before the token,
         * the server and the vendor have said anything. A button that looks
         * dead for two seconds gets pressed again, and then there are two
         * calls; showing "Calling…" immediately is what stops that.
         */
        async start(cid, members, title) {
            if (this.state) return;

            this.error = null;
            this.title = title || 'Call';
            this.state = 'ringing-out';

            try {
                const client = await this.ready();

                const answer = await fetch(CALLS_ENDPOINT, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers(),
                    body: JSON.stringify({ cid, members: members ?? [] }),
                }).then((response) => {
                    if (response.status === 403) throw new Error('not-allowed');
                    if (!response.ok) throw new Error('refused');

                    return response.json();
                });

                const call = client.call(answer.call_type, answer.call_id);

                this.call = call;
                this.wanted = answer.recording;

                await call.getOrCreate({
                    ring: true,
                    data: { members: answer.members.map((id) => ({ user_id: id })) },
                });

                /*
                 * And now wait, which is the whole of the fix: the card says
                 * "Calling…", the microphone is still off, and nothing is
                 * being recorded until somebody is there to be recorded.
                 */
                sound.ringback();
                this.watch(call);
                this.giveUpEventually();
            } catch (error) {
                /* The card says one sentence; the console says which one of a
                   token, a permission, a network and a vendor it was. */
                console.error('[chat] the call did not start', error);

                this.error = error.message === 'not-allowed'
                    ? 'You do not have permission to start calls.'
                    : 'That call could not be started.';

                this.reset();
            }
        },

        /**
         * What the other end did about it.
         *
         * Stream keeps this on the call's session rather than sending it as an
         * event: `accepted_by`, `rejected_by` and `missed_by`, each keyed by
         * the user it happened to. Watching it is how the caller finds out
         * anything at all — without it an outgoing call sits on "Calling…"
         * through a decline, through a timeout, and through the other person
         * putting their phone back in their pocket.
         */
        watch(call) {
            this.unwatch();

            const session = call?.state?.session$;

            if (typeof session?.subscribe !== 'function') {
                /*
                 * An SDK without the observable. Rather than guess at an event
                 * name, fall back to what cannot be missed: the client's own
                 * `call.ended`, which `listen()` already holds. A decline then
                 * still ends the card, one round trip later than it could.
                 */
                return;
            }

            watcher = session.subscribe((state) => {
                if (!state) return;

                const any = (of) => Object.keys(state[of] ?? {}).length > 0;

                /* Somebody picked up: this is where the caller joins. */
                if (this.state === 'ringing-out' && any('accepted_by')) {
                    this.answered();

                    return;
                }

                if (this.state === 'ringing-out' && any('missed_by')) {
                    this.giveUp('No answer.');

                    return;
                }

                /*
                 * Rejected by everybody who was rung. One person declining a
                 * call to four is not the call being over, and saying so would
                 * hang up on the three who are still deciding.
                 */
                if (this.state === 'ringing-out' && any('rejected_by')) {
                    const rang = (call.state.members ?? []).filter((member) => member.user_id !== this.me());

                    if (rang.length > 0 && rang.every((member) => (state.rejected_by ?? {})[member.user_id])) {
                        this.giveUp('They declined.');
                    }
                }
            });
        },

        unwatch() {
            [watcher, recorder].forEach((subscription) => {
                try {
                    subscription?.unsubscribe?.();
                } catch {
                    /* Already gone. */
                }
            });

            watcher = null;
            recorder = null;
        },

        me() {
            return this.mine ?? Alpine.store('chat')?.me ?? null;
        },

        /** Picked up at the other end. */
        async answered() {
            if (this.state !== 'ringing-out') return;

            clearTimeout(timeout);
            timeout = null;

            await this.join(this.wanted);
        },

        /**
         * Nobody is coming.
         *
         * Cancelled rather than left, because leaving a call does not stop it
         * ringing — the signalling runs on, and somebody's phone goes on
         * buzzing for a call that nobody is waiting on the other end of.
         */
        async giveUp(why) {
            this.error = why ?? null;

            try {
                await this.call?.leave({ reject: true, reason: 'cancel' });
            } catch {
                /* It may already be over; the card still has to come down. */
            }

            sound.ended();
            this.reset();
        },

        giveUpEventually() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (this.state === 'ringing-out') this.giveUp('No answer.');
            }, NO_ANSWER_SECONDS * 1000);
        },

        async accept() {
            sound.stop();

            /* Accepting tells the caller, which is what turns their card
               from "Calling…" into a call. Joining is what connects it, and
               joining on its own is enough where an SDK has no `accept`. */
            try {
                await this.call?.accept?.();
            } catch {
                /* Said by joining instead. */
            }

            try {
                /* Only the caller records: two ends both starting a recording
                   is one recording and one swallowed error, and the end that
                   swallowed it then says the call is not being recorded. */
                await this.join(false);
            } catch (error) {
                this.error = 'That call could not be joined.';
                this.reset();
            }
        },

        async decline() {
            sound.stop();

            try {
                await this.call?.leave({ reject: true, reason: 'decline' });
            } finally {
                this.reset();
            }
        },

        async join(record) {
            sound.stop();

            // Audio only: the camera is never turned on, rather than turned off.
            await this.call.camera.disable().catch(() => {});
            await this.call.join();

            /*
             * A refused microphone is not a refused call. Somebody on a machine
             * without one, or who said no to the browser, should still hear
             * what is being said — and be told plainly that nobody can hear
             * them, rather than watch the call die with "could not be started".
             */
            try {
                await this.call.microphone.enable();
                this.muted = false;
            } catch (error) {
                this.muted = true;
                this.error = 'No microphone — you can hear them, they cannot hear you.';
            }

            this.state = 'live';
            this.count();
            sound.connected();

            /* Whether it is being recorded is the call's answer, not this
               end's: both ends then say the same thing, and the end that did
               not start it stops claiming it is not happening. */
            this.follow();

            if (record) {
                try {
                    await this.call.startRecording();
                    this.recording = true;
                } catch (error) {
                    // A plan without recording, or an app that has it off: the
                    // call is worth having either way, and the card stops
                    // claiming it is being recorded.
                    this.recording = false;
                }
            }
        },

        /** The call's own recording flag, where the SDK publishes one. */
        follow() {
            const recording = this.call?.state?.recording$;

            if (typeof recording?.subscribe !== 'function') return;

            recorder = recording.subscribe((on) => {
                this.recording = !! on;
            });
        },

        async toggleMute() {
            await this.call?.microphone.toggle();
            this.muted = !this.muted;
        },

        /**
         * Hanging up, which is two different things.
         *
         * While it is still ringing the call has to be cancelled — `leave()`
         * does not stop the signalling, so the other phone would go on ringing
         * after the person who rang it had walked away. Once it is live,
         * leaving is exactly right.
         */
        async hangUp() {
            if (this.state === 'ringing-out') {
                await this.giveUp(null);

                return;
            }

            /*
             * Stopping the recording must not be able to stop the hanging up.
             * These were one `try` with one `finally`, so a recording that was
             * already over — or that this end never had the right to stop —
             * threw on the way past and left somebody still in the call,
             * pressing a button that had already done the only part of its job
             * that did not matter.
             */
            try {
                if (this.recording) await this.call?.stopRecording();
            } catch {
                /* It stops when the call does. */
            }

            try {
                await this.call?.leave();
            } finally {
                sound.stop();
                this.reset();
            }
        },

        /** Over at the other end. */
        finish() {
            if (this.state === null) return;

            sound.ended();
            this.reset();
        },

        count() {
            this.seconds = 0;
            clearInterval(this.ticker);
            this.ticker = setInterval(() => this.seconds++, 1000);
        },

        get clock() {
            const minutes = Math.floor(this.seconds / 60).toString();
            const seconds = (this.seconds % 60).toString().padStart(2, '0');

            return `${minutes}:${seconds}`;
        },

        reset() {
            clearInterval(this.ticker);
            clearTimeout(timeout);
            timeout = null;

            this.unwatch();
            sound.stop();

            this.state = null;
            this.call = null;
            this.muted = false;
            this.recording = false;
            this.seconds = 0;
            this.wanted = false;
        },
    });
}
