
import { headers } from './chat.js';

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
 */

const CALLS_ENDPOINT = '/chat/calls';

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
            });

            this.client.on('call.ended', () => this.reset());
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

                await call.getOrCreate({
                    ring: true,
                    data: { members: answer.members.map((id) => ({ user_id: id })) },
                });

                await this.join(answer.recording);
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

        async accept() {
            try {
                await this.call.accept();
                await this.join(true);
            } catch (error) {
                this.error = 'That call could not be joined.';
                this.reset();
            }
        },

        async decline() {
            try {
                await this.call?.leave({ reject: true });
            } finally {
                this.reset();
            }
        },

        async join(record) {
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

        async toggleMute() {
            await this.call?.microphone.toggle();
            this.muted = !this.muted;
        },

        async hangUp() {
            try {
                if (this.recording) await this.call?.stopRecording();
                await this.call?.leave();
            } finally {
                this.reset();
            }
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
            this.state = null;
            this.call = null;
            this.muted = false;
            this.recording = false;
            this.seconds = 0;
        },
    });
}
