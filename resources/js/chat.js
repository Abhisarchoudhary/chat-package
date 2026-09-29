import { StreamChat } from 'stream-chat';

/**
 * Chat, as the three portals run it.
 *
 * **Nothing from Stream is ever put into Alpine's state.** A Stream channel
 * holds a reference to the client, which holds every channel, which holds the
 * client: handed to a reactive proxy it becomes a structure that cannot be
 * walked, and the first render effect that touches it throws — quietly, inside
 * Alpine, leaving an empty screen and no clue. So the client and the channels
 * live in this module, and what the interface sees is a plain snapshot of them.
 *
 * That is also the right shape on its own: the interface should depend on what
 * it draws, not on a vendor's object graph.
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

/** Outside Alpine, on purpose — see the note above. */
let client = null;
const channels = new Map();

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

function initialsOf(name) {
    return (name || '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export function registerChat(Alpine) {
    /** A conversation, as the interface needs it: strings, numbers and booleans. */
    function snapshot(channel, me) {
        const members = Object.values(channel.state?.members ?? {})
            .map((member) => member.user)
            .filter(Boolean);

        const others = members.filter((user) => user.id !== me);
        const last = channel.state?.messages?.[channel.state.messages.length - 1];
        const unread = channel.countUnread?.() ?? 0;

        return {
            cid: channel.cid,
            type: channel.type,
            id: channel.id,
            name: channel.data?.name ?? null,
            title: channel.data?.name || others.map((user) => user.name || user.id).join(', ') || 'Empty conversation',
            image: channel.data?.image ?? (others.length === 1 ? (others[0].image ?? null) : null),
            members: members.length,
            online: others.some((user) => user.online),
            other: others[0]?.name ?? null,
            email: others.length === 1 ? (others[0].email ?? null) : null,
            unread,
            preview: last
                ? (last.user?.id === me ? 'You: ' : '') + (last.text || (last.attachments?.length ? 'Sent a file' : ''))
                : 'No messages yet',
            at: channel.state?.last_message_at ? new Date(channel.state.last_message_at).getTime() : 0,
        };
    }

    /** One message, flattened to what a row draws. */
    function messageOf(message, me) {
        return {
            id: message.id,
            text: message.text ?? '',
            deleted: message.type === 'deleted',
            mine: message.user?.id === me,
            userId: message.user?.id ?? null,
            userName: message.user?.name ?? message.user?.id ?? 'Someone',
            userImage: message.user?.image ?? null,
            at: message.created_at ? new Date(message.created_at).getTime() : Date.now(),
            attachments: (message.attachments ?? []).map((attachment) => ({
                kind: attachment.type === 'image' ? 'image' : 'file',
                url: attachment.image_url ?? attachment.asset_url ?? null,
                title: attachment.title ?? attachment.fallback ?? 'File',
            })),
        };
    }

    Alpine.store('chat', {
        ready: false,
        failed: null,
        me: null,
        abilities: {},

        /** Plain snapshots, newest first. */
        conversations: [],
        unread: 0,

        /** Messages by cid, also plain. */
        messages: {},

        /** Conversations with a floating box open. */
        open: [],

        /** What the page is showing. */
        active: null,

        async connect() {
            if (client || this.failed) return;

            try {
                const session = await ask(ENDPOINTS.token, { method: 'POST' });

                client = StreamChat.getInstance(session.api_key);
                this.me = session.user_id;

                await client.connectUser(session.user, session.token);

                this.abilities = await ask(ENDPOINTS.abilities);

                const list = await client.queryChannels(
                    { members: { $in: [this.me] } },
                    [{ last_message_at: -1 }],
                    { watch: true, state: true, limit: 30, message_limit: 40 },
                );

                list.forEach((channel) => this.hold(channel));

                this.refresh();
                this.listen();

                this.ready = true;
                this.active ??= this.conversations[0]?.cid ?? null;
                this.restoreOpen();
            } catch (error) {
                this.failed = error.message ?? 'Chat could not start.';
            }
        },

        /** Keep the real channel here, out of anything reactive. */
        hold(channel) {
            channels.set(channel.cid, channel);
            this.messages[channel.cid] = (channel.state?.messages ?? [])
                .filter((message) => message.type !== 'deleted')
                .map((message) => messageOf(message, this.me));
        },

        raw(cid) {
            return channels.get(cid) ?? null;
        },

        listen() {
            client.on(async (event) => {
                const cid = event.cid ?? event.channel?.cid;

                if (['notification.message_new', 'notification.added_to_channel'].includes(event.type) && event.channel && !channels.has(event.channel.cid)) {
                    const channel = client.channel(event.channel.type, event.channel.id);
                    await channel.watch();
                    this.hold(channel);
                }

                if (cid && channels.has(cid)) {
                    this.hold(channels.get(cid));
                }

                if (event.type === 'message.new' && event.user?.id !== this.me && cid && cid !== this.active) {
                    this.openBox(cid);
                }

                this.refresh();
            });
        },

        /** Rebuild what the interface draws from what the client holds. */
        refresh() {
            this.conversations = [...channels.values()]
                .map((channel) => snapshot(channel, this.me))
                .sort((a, b) => b.at - a.at);

            this.unread = this.conversations.reduce((total, conversation) => total + conversation.unread, 0);
        },

        find(cid) {
            return this.conversations.find((conversation) => conversation.cid === cid) ?? null;
        },

        initials: initialsOf,

        /** The line under a conversation's name. */
        subtitle(conversation) {
            if (!conversation) return '';

            if (conversation.type === 'team') {
                return `${conversation.members} ${conversation.members === 1 ? 'member' : 'members'}`;
            }

            return conversation.online ? 'Online' : (conversation.email ?? 'Direct message');
        },

        /* ------------------------------------------------------- the dock */

        restoreOpen() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(OPEN_KEY) ?? '[]');
                this.open = saved.filter((cid) => channels.has(cid)).slice(0, DOCK_LIMIT);
            } catch {
                this.open = [];
            }
        },

        rememberOpen() {
            try {
                sessionStorage.setItem(OPEN_KEY, JSON.stringify(this.open));
            } catch {
                /* A private window: boxes simply do not survive the page. */
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
            const channel = this.raw(cid);

            if (channel && (channel.countUnread?.() ?? 0) > 0) {
                await channel.markRead();
                this.refresh();
            }
        },

        /* ---------------------------------------------------- starting one */

        /**
         * A direct message, which is the same conversation however many times
         * it is started: `distinct` means Stream hands back the one that
         * already exists rather than making a second.
         */
        async messagePerson(userId) {
            const channel = client.channel('messaging', { members: [this.me, userId] });
            await channel.watch();

            this.hold(channel);
            this.refresh();
            this.openBox(channel.cid);

            return channel.cid;
        },

        async createChannel(payload) {
            const made = await ask(ENDPOINTS.channels, { method: 'POST', body: JSON.stringify(payload) });
            const channel = client.channel(made.type, made.id);

            await channel.watch();

            this.hold(channel);
            this.refresh();

            return channel.cid;
        },

        async addMembers(cid, add) {
            const channel = this.raw(cid);

            await ask(ENDPOINTS.members, {
                method: 'POST',
                body: JSON.stringify({ type: channel.type, id: channel.id, add }),
            });

            await channel.watch();
            this.hold(channel);
            this.refresh();
        },

        async directory(search = '') {
            const query = search ? `?q=${encodeURIComponent(search)}` : '';

            return ask(`${ENDPOINTS.directory}${query}`);
        },

        /* ------------------------------------------------------- messaging */

        async send(cid, text) {
            await this.raw(cid)?.sendMessage({ text });
        },

        typing(cid) {
            this.raw(cid)?.keystroke().catch(() => {});
        },

        async remove(cid, messageId) {
            await client.deleteMessage(messageId);
        },

        async upload(cid, file) {
            const channel = this.raw(cid);
            const image = file.type.startsWith('image/');
            const uploaded = image ? await channel.sendImage(file) : await channel.sendFile(file);

            await channel.sendMessage({
                text: '',
                attachments: [
                    image
                        ? { type: 'image', image_url: uploaded.file, fallback: file.name }
                        : { type: 'file', asset_url: uploaded.file, title: file.name, file_size: file.size, mime_type: file.type },
                ],
            });
        },
    });

    /**
     * One conversation: the messages and the composer. The page's room and the
     * dock's floating box both use it, so a message cannot start looking
     * different depending on where it is read.
     */
    Alpine.data('chatConversation', (resolve) => ({
        text: '',
        sending: false,
        uploading: false,
        error: null,
        typing: [],

        get cid() {
            return typeof resolve === 'function' ? resolve() : resolve;
        },

        get messages() {
            return this.$store.chat.messages[this.cid] ?? [];
        },

        init() {
            this.$store.chat.markRead(this.cid);
            this.scroll();

            /* Somebody typing is worth showing and not worth storing. */
            const channel = this.$store.chat.raw(this.cid);

            channel?.on((event) => {
                if (event.type === 'typing.start' && event.user?.id !== this.$store.chat.me) {
                    this.typing = [...new Set([...this.typing, event.user.name || event.user.id])];
                }

                if (event.type === 'typing.stop') {
                    this.typing = this.typing.filter((name) => name !== (event.user?.name || event.user?.id));
                }

                if (event.type === 'message.new') {
                    this.scroll();
                }
            });
        },

        async send() {
            const text = this.text.trim();

            if (text === '' || this.sending) return;

            this.sending = true;
            this.text = '';

            const field = this.$refs.field;

            if (field) field.style.height = 'auto';

            try {
                await this.$store.chat.send(this.cid, text);
                this.scroll();
            } catch (error) {
                this.error = 'That message did not send.';
                this.text = text;
            } finally {
                this.sending = false;
            }
        },

        typingStarted() {
            this.$store.chat.typing(this.cid);
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
                await this.$store.chat.upload(this.cid, file);
                this.scroll();
            } catch (error) {
                this.error = 'That file did not upload.';
            } finally {
                this.uploading = false;
                event.target.value = '';
            }
        },

        remove(message) {
            if (message.mine) this.$store.chat.remove(this.cid, message.id);
        },

        at(message) {
            return new Date(message.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        /**
         * Whether this message starts a new run.
         *
         * A run is one person talking without interruption for a few minutes,
         * and it gets one avatar and one name. Repeating them on every line
         * says the same thing over and over and turns a conversation into a log.
         */
        startsRun(index) {
            const message = this.messages[index];
            const before = this.messages[index - 1];

            if (!before || before.userId !== message.userId) return true;
            if (this.opensDay(index)) return true;

            return message.at - before.at > 5 * 60 * 1000;
        },

        opensDay(index) {
            const day = (message) => new Date(message.at).toDateString();

            return index === 0 || day(this.messages[index]) !== day(this.messages[index - 1]);
        },

        dayOf(message) {
            const at = new Date(message.at);

            if (at.toDateString() === new Date().toDateString()) return 'Today';
            if (at.toDateString() === new Date(Date.now() - 86400000).toDateString()) return 'Yesterday';

            return at.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
        },

        grow(field) {
            field.style.height = 'auto';
            field.style.height = `${Math.min(field.scrollHeight, 160)}px`;
        },

        scroll() {
            this.$nextTick(() => {
                const list = this.$refs.list;

                if (list) list.scrollTop = list.scrollHeight;
            });
        },
    }));

    /** The launcher, the list behind it, and the floating boxes. */
    Alpine.data('chatDock', () => ({
        panel: false,
        search: '',

        init() {
            this.$store.chat.connect();
        },

        get conversations() {
            const term = this.search.trim().toLowerCase();
            const all = this.$store.chat.conversations;

            return term === '' ? all : all.filter((conversation) => conversation.title.toLowerCase().includes(term));
        },

        get boxes() {
            return this.$store.chat.open.filter((cid) => this.$store.chat.find(cid));
        },
    }));

    /** The page: every conversation, and what it takes to manage them. */
    Alpine.data('chatPage', () => ({
        search: '',
        unreadOnly: false,
        picking: false,
        people: {},
        chosen: [],
        form: { name: '', type: 'team' },
        error: null,
        loading: false,

        init() {
            this.$store.chat.connect();
        },

        get conversations() {
            const term = this.search.trim().toLowerCase();

            return this.$store.chat.conversations.filter((conversation) => {
                if (this.unreadOnly && conversation.unread === 0) return false;

                return term === '' || conversation.title.toLowerCase().includes(term);
            });
        },

        get channels() {
            return this.conversations.filter((conversation) => conversation.type === 'team');
        },

        get direct() {
            return this.conversations.filter((conversation) => conversation.type !== 'team');
        },

        get current() {
            return this.$store.chat.find(this.$store.chat.active);
        },

        open(cid) {
            this.$store.chat.active = cid;
            this.$store.chat.markRead(cid);
        },

        /* --------------------------------------------- starting something */

        async pick(mode = 'person') {
            this.picking = mode;
            this.error = null;
            this.chosen = [];
            this.form = { name: '', type: mode === 'channel' ? 'team' : 'messaging' };

            if (Object.keys(this.people).length === 0) await this.loadPeople();
        },

        async loadPeople(search = '') {
            this.loading = true;

            try {
                const answer = await this.$store.chat.directory(search);
                this.people = answer.groups ?? {};
            } finally {
                this.loading = false;
            }
        },

        toggle(id) {
            this.chosen = this.chosen.includes(id)
                ? this.chosen.filter((chosen) => chosen !== id)
                : [...this.chosen, id];
        },

        async messagePerson(id) {
            const cid = await this.$store.chat.messagePerson(id);

            this.picking = false;
            this.open(cid);
        },

        async create() {
            this.error = null;

            if (this.form.name.trim() === '') {
                this.error = 'Give it a name first.';

                return;
            }

            try {
                const cid = await this.$store.chat.createChannel({ ...this.form, members: this.chosen });

                this.picking = false;
                this.open(cid);
            } catch (error) {
                this.error = 'That could not be created.';
            }
        },

        /** Adding people to the conversation already open. */
        async addChosen() {
            if (this.chosen.length === 0) return;

            try {
                await this.$store.chat.addMembers(this.$store.chat.active, this.chosen);
                this.picking = false;
            } catch (error) {
                this.error = 'Those people could not be added.';
            }
        },
    }));
}
