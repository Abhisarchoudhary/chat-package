import { StreamChat } from 'stream-chat';

/**
 * Chat, as the three portals run it.
 *
 * One connection per tab, held in an Alpine store, and two things drawn from
 * it: the dock — a launcher and the floating boxes somebody is talking in —
 * and the page, where channels are managed. They share the store because they
 * are the same conversations; two clients in one tab would be two websockets,
 * two unread counts and two versions of who is typing.
 *
 * The browser talks to Stream directly for everything it is allowed to do.
 * Creating a channel and changing who is in one go to the portal instead,
 * because those are the portal's permissions to decide and a token cannot be
 * trusted to ask itself.
 */

const ENDPOINTS = {
    token: '/chat/token',
    directory: '/chat/directory',
    abilities: '/chat/abilities',
    channels: '/chat/channels',
    members: '/chat/channels/members',
};

const DOCK_LIMIT = 3;
const OPEN_KEY = 'revun.chat.open';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function ask(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        ...options,
    });

    if (!response.ok) {
        throw new Error(`${options.method ?? 'GET'} ${url} answered ${response.status}`);
    }

    return response.json();
}

/** A conversation's title, which for a direct message is the other person. */
function titleOf(channel, me) {
    /*
     * A box restored from a previous page can be drawn before its channel has
     * been fetched, so every reader here survives a null rather than throwing
     * inside an Alpine effect — where the error is silent and the rest of the
     * component simply stops.
     */
    if (!channel) return '';

    if (channel.data?.name) {
        return channel.data.name;
    }

    const others = Object.values(channel.state?.members ?? {})
        .map((member) => member.user)
        .filter((user) => user && user.id !== me);

    return others.map((user) => user.name || user.id).join(', ') || 'Empty conversation';
}

function imageOf(channel, me) {
    if (!channel) return null;

    if (channel.data?.image) return channel.data.image;

    const other = Object.values(channel.state?.members ?? {})
        .map((member) => member.user)
        .find((user) => user && user.id !== me);

    return other?.image ?? null;
}

function initialsOf(name) {
    return (name || '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export function registerChat(Alpine) {
    Alpine.store('chat', {
        client: null,
        me: null,
        ready: false,
        failed: null,
        abilities: {},

        /** Every conversation this person is in, newest activity first. */
        channels: [],
        unread: 0,

        /** The conversations with a box open in the dock, by cid. */
        open: [],

        /** The one the page is showing. */
        active: null,

        async connect() {
            if (this.client || this.failed) return;

            try {
                const session = await ask(ENDPOINTS.token, { method: 'POST' });

                this.client = StreamChat.getInstance(session.api_key);
                this.me = session.user_id;

                await this.client.connectUser(session.user, session.token);

                this.abilities = await ask(ENDPOINTS.abilities);

                await this.loadChannels();
                this.listen();

                this.ready = true;
                this.restoreOpen();
            } catch (error) {
                // A portal without chat configured, or an account that may not
                // use it: the launcher says so rather than failing silently.
                this.failed = error.message ?? 'Chat could not start.';
            }
        },

        async loadChannels() {
            const channels = await this.client.queryChannels(
                { members: { $in: [this.me] } },
                [{ last_message_at: -1 }],
                { watch: true, state: true, limit: 30, message_limit: 30 },
            );

            this.channels = channels;
            this.countUnread();
        },

        listen() {
            /*
             * Stream's client keeps the channel objects themselves up to date;
             * this is here to tell Alpine something it draws has moved, and to
             * notice conversations that did not exist when the page loaded.
             *
             * It never rebuilds the list from `activeChannels`: that set is
             * empty until something is watched, and an early event would empty
             * somebody's chat list in front of them.
             */
            this.client.on(async (event) => {
                const isNew = ['notification.message_new', 'notification.added_to_channel'].includes(event.type);

                if (isNew && event.channel && !this.channelFor(event.channel.cid)) {
                    const channel = this.client.channel(event.channel.type, event.channel.id);
                    await channel.watch();
                    this.channels = [channel, ...this.channels];
                }

                if (['message.new', 'notification.message_new'].includes(event.type)) {
                    this.touch(event);
                }

                this.sort();
                this.countUnread();
            });
        },

        /** Most recent first, which is the only order a chat list is read in. */
        sort() {
            this.channels = [...this.channels].sort(
                (a, b) => new Date(b.state?.last_message_at ?? 0) - new Date(a.state?.last_message_at ?? 0),
            );
        },

        /** A message arrived: pop the box open if it is not already. */
        touch(event) {
            const cid = event.cid ?? event.channel?.cid;
            if (!cid || cid === this.active) return;

            const mine = event.user?.id === this.me;

            if (!mine && !this.open.includes(cid)) {
                this.openBox(cid);
            }
        },

        countUnread() {
            this.unread = this.channels.reduce((total, channel) => total + (channel.countUnread?.() ?? 0), 0);
        },

        channelFor(cid) {
            return this.channels.find((channel) => channel.cid === cid) ?? null;
        },

        title(channel) {
            return titleOf(channel, this.me);
        },

        image(channel) {
            return imageOf(channel, this.me);
        },

        initials(name) {
            return initialsOf(name);
        },

        /** Boxes stay open across pages, which is the whole point of a dock. */
        restoreOpen() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(OPEN_KEY) ?? '[]');
                this.open = saved.filter((cid) => this.channelFor(cid)).slice(0, DOCK_LIMIT);
            } catch {
                this.open = [];
            }
        },

        rememberOpen() {
            try {
                sessionStorage.setItem(OPEN_KEY, JSON.stringify(this.open));
            } catch {
                /* A private window: the boxes simply do not survive the page. */
            }
        },

        openBox(cid) {
            if (!this.open.includes(cid)) {
                this.open = [cid, ...this.open].slice(0, DOCK_LIMIT);
            }

            this.rememberOpen();
            this.markRead(cid);
        },

        closeBox(cid) {
            this.open = this.open.filter((open) => open !== cid);
            this.rememberOpen();
        },

        async markRead(cid) {
            const channel = this.channelFor(cid);

            if (channel && (channel.countUnread?.() ?? 0) > 0) {
                await channel.markRead();
                this.countUnread();
            }
        },

        /**
         * A direct message, which is the same conversation however many times
         * it is started: `distinct` means Stream hands back the one that
         * already exists rather than making a second.
         */
        async messagePerson(userId) {
            const channel = this.client.channel('messaging', { members: [this.me, userId] });
            await channel.watch();

            if (!this.channelFor(channel.cid)) {
                this.channels = [channel, ...this.channels];
            }

            this.openBox(channel.cid);

            return channel.cid;
        },

        async createChannel(payload) {
            const made = await ask(ENDPOINTS.channels, {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            const channel = this.client.channel(made.type, made.id);
            await channel.watch();

            this.channels = [channel, ...this.channels.filter((existing) => existing.cid !== channel.cid)];

            return channel.cid;
        },

        async changeMembers(channel, { add = [], remove = [] }) {
            await ask(ENDPOINTS.members, {
                method: 'POST',
                body: JSON.stringify({ type: channel.type, id: channel.id, add, remove }),
            });

            await channel.watch();
        },

        async directory(search = '') {
            const query = search ? `?q=${encodeURIComponent(search)}` : '';

            return ask(`${ENDPOINTS.directory}${query}`);
        },
    });

    /**
     * One conversation: the messages, the composer, and the small amount of
     * state a box or a pane needs. Used by both the dock and the page, so the
     * two cannot drift into rendering a message differently.
     */
    Alpine.data('chatConversation', (resolve) => ({
        get cid() {
            return typeof resolve === 'function' ? resolve() : resolve;
        },
        messages: [],
        text: '',
        typing: [],
        sending: false,
        uploading: false,
        error: null,
        channel: null,

        init() {
            this.channel = this.$store.chat.channelFor(this.cid);

            if (!this.channel) return;

            this.messages = [...(this.channel.state.messages ?? [])];
            this.scroll();

            this.channel.on((event) => {
                if (['message.new', 'message.updated', 'message.deleted', 'reaction.new', 'reaction.deleted'].includes(event.type)) {
                    this.messages = [...this.channel.state.messages];
                    this.scroll();
                }

                if (event.type === 'typing.start' && event.user?.id !== this.$store.chat.me) {
                    this.typing = [...new Set([...this.typing, event.user.name || event.user.id])];
                }

                if (event.type === 'typing.stop') {
                    this.typing = this.typing.filter((name) => name !== (event.user?.name || event.user?.id));
                }
            });

            this.$store.chat.markRead(this.cid);
        },

        async send() {
            const text = this.text.trim();

            if (text === '' || this.sending) return;

            this.sending = true;
            this.text = '';

            try {
                await this.channel.sendMessage({ text });
            } catch (error) {
                this.error = 'That message did not send.';
                this.text = text;
            } finally {
                this.sending = false;
            }
        },

        /** Typing tells the other side something is coming; it is not a message. */
        typingStarted() {
            this.channel?.keystroke().catch(() => {});
        },

        async attach(event) {
            const file = event.target.files?.[0];

            if (!file) return;

            const limit = Number(this.$el.dataset.maxMb ?? 25);

            if (file.size > limit * 1024 * 1024) {
                this.error = `That file is larger than ${limit} MB.`;
                event.target.value = '';

                return;
            }

            this.uploading = true;
            this.error = null;

            try {
                const image = file.type.startsWith('image/');
                const uploaded = image
                    ? await this.channel.sendImage(file)
                    : await this.channel.sendFile(file);

                await this.channel.sendMessage({
                    text: '',
                    attachments: [
                        image
                            ? { type: 'image', image_url: uploaded.file, fallback: file.name }
                            : { type: 'file', asset_url: uploaded.file, title: file.name, file_size: file.size, mime_type: file.type },
                    ],
                });
            } catch (error) {
                this.error = 'That file did not upload.';
            } finally {
                this.uploading = false;
                event.target.value = '';
            }
        },

        async remove(message) {
            if (message.user?.id !== this.$store.chat.me) return;

            await this.$store.chat.client.deleteMessage(message.id);
        },

        mine(message) {
            return message.user?.id === this.$store.chat.me;
        },

        when(message) {
            const at = new Date(message.created_at);

            return at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        /** A new message should not leave somebody reading yesterday's. */
        scroll() {
            this.$nextTick(() => {
                const list = this.$refs.list;

                if (list) list.scrollTop = list.scrollHeight;
            });
        },
    }));

    /** The floating launcher and the boxes beside it. */
    Alpine.data('chatDock', () => ({
        panel: false,
        search: '',

        init() {
            this.$store.chat.connect();
        },

        get conversations() {
            const term = this.search.trim().toLowerCase();
            const channels = this.$store.chat.channels;

            if (term === '') return channels;

            return channels.filter((channel) => this.$store.chat.title(channel).toLowerCase().includes(term));
        },

        /** Boxes whose channel is actually loaded: the rest are not drawable yet. */
        get boxes() {
            return this.$store.chat.open.filter((cid) => this.$store.chat.channelFor(cid));
        },

        preview(channel) {
            const last = channel.state?.messages?.[channel.state.messages.length - 1];

            if (!last) return 'No messages yet';

            const who = last.user?.id === this.$store.chat.me ? 'You: ' : '';

            return who + (last.text || (last.attachments?.length ? 'Sent a file' : ''));
        },
    }));

    /** The page: every conversation, and what it takes to manage them. */
    Alpine.data('chatPage', () => ({
        search: '',
        tab: 'all',
        creating: false,
        people: {},
        chosen: [],
        form: { name: '', type: 'team', description: '', private: false },
        error: null,

        init() {
            this.$store.chat.connect();
        },

        get conversations() {
            const term = this.search.trim().toLowerCase();

            return this.$store.chat.channels.filter((channel) => {
                if (this.tab === 'channels' && channel.type !== 'team') return false;
                if (this.tab === 'direct' && channel.type !== 'messaging') return false;
                if (this.tab === 'unread' && (channel.countUnread?.() ?? 0) === 0) return false;

                return term === '' || this.$store.chat.title(channel).toLowerCase().includes(term);
            });
        },

        open(cid) {
            this.$store.chat.active = cid;
            this.$store.chat.markRead(cid);
        },

        async loadPeople(search = '') {
            const answer = await this.$store.chat.directory(search);
            this.people = answer.groups ?? {};
        },

        toggle(id) {
            this.chosen = this.chosen.includes(id)
                ? this.chosen.filter((chosen) => chosen !== id)
                : [...this.chosen, id];
        },

        async create() {
            this.error = null;

            if (this.form.name.trim() === '') {
                this.error = 'A channel needs a name.';

                return;
            }

            try {
                const cid = await this.$store.chat.createChannel({ ...this.form, members: this.chosen });

                this.creating = false;
                this.chosen = [];
                this.form = { name: '', type: 'team', description: '', private: false };
                this.open(cid);
            } catch (error) {
                this.error = 'That channel could not be created.';
            }
        },

        async messagePerson(id) {
            const cid = await this.$store.chat.messagePerson(id);
            this.creating = false;
            this.open(cid);
        },
    }));
}
