import { StreamVideoClient } from '@stream-io/video-client';

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

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export function registerCalls(Alpine) {
    Alpine.store('calls', {
        client: null,
        call: null,

        /** ringing-out | ringing-in | live | null */
        state: null,
        title: '',
        muted: false,
        seconds: 0,
        recording: false,
        error: null,
        ticker: null,

        /** The video client shares chat's token: the same person, one identity. */
        async ready() {
            if (this.client) return this.client;

            const chat = Alpine.store('chat');

            if (!chat.ready) return null;

            const session = await fetch('/chat/token', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            }).then((response) => response.json());

            this.client = new StreamVideoClient({
                apiKey: session.api_key,
                user: session.user,
                token: session.token,
            });

            this.listen();

            return this.client;
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

        async start(cid, members, title) {
            this.error = null;

            try {
                const client = await this.ready();

                if (!client) return;

                const answer = await fetch(CALLS_ENDPOINT, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ cid, members }),
                }).then((response) => {
                    if (!response.ok) throw new Error('refused');

                    return response.json();
                });

                const call = client.call(answer.call_type, answer.call_id);

                this.call = call;
                this.title = title;
                this.state = 'ringing-out';

                await call.getOrCreate({
                    ring: true,
                    data: { members: answer.members.map((id) => ({ user_id: id })) },
                });

                await this.join(answer.recording);
            } catch (error) {
                this.error = 'That call could not be started.';
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
            await this.call.camera.disable();
            await this.call.join();
            await this.call.microphone.enable();

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
