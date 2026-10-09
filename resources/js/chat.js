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

import * as noise from './sound.js';

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

/**
 * Proving the request came from the page and not from another site.
 *
 * Both forms, because three portals lay their pages out three ways: the meta
 * tag when the layout renders one, and Laravel's own XSRF-TOKEN cookie when it
 * does not — this portal's layout does not, and the package cannot make every
 * portal add one before chat will post anything.
 */
export function headers() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const cookie = document.cookie.split('; ').find((pair) => pair.startsWith('XSRF-TOKEN='));

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(meta ? { 'X-CSRF-TOKEN': meta } : {}),
        ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {}),
    };
}

async function ask(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: headers(),
        ...options,
    });

    if (!response.ok) {
        throw new Error(`${options.method ?? 'GET'} ${url} answered ${response.status}`);
    }

    return response.json();
}

/**
 * After the page somebody asked for has finished.
 *
 * `requestIdleCallback` where there is one; a timeout where there is not,
 * which is Safari. Either way the work happens -- it simply stops competing
 * with the page for the first second of it.
 */
function whenIdle(work) {
    if (typeof requestIdleCallback === 'function') {
        requestIdleCallback(work, { timeout: 4000 });
    } else {
        setTimeout(work, 1200);
    }
}

function initialsOf(name) {
    return (name || '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export function registerChat(Alpine) {
    /*
     * A browser grants sound to a page somebody has touched, so the first
     * touch anywhere is what chat waits for. Asked for now rather than when
     * the first message lands, because by then it is already too late to be
     * heard.
     */
    noise.unlock();

    /** A conversation, as the interface needs it: strings, numbers and booleans. */
    function snapshot(channel, me) {
        const members = Object.values(channel.state?.members ?? {})
            .map((member) => member.user)
            .filter(Boolean);

        const others = members.filter((user) => user.id !== me);

        /* A deleted message is not the last thing anybody said, so it is not
           the preview either — otherwise a conversation reads "This message
           was deleted" long after that stopped being the news. */
        const said = (channel.state?.messages ?? []).filter((message) => message.type !== 'deleted');
        const last = said[said.length - 1];
        const unread = channel.countUnread?.() ?? 0;

        /* How busy it is, not how recent: the messages in the window we hold
           over the days that window spans. It is the window and not all of
           history, which is why the sort it feeds is called "most active" and
           not "frequent" — we can honestly measure the first. */
        const from = said[0] ? new Date(said[0].created_at).getTime() : 0;
        const to = last ? new Date(last.created_at).getTime() : 0;
        const days = Math.max(1, (to - from) / 86400000);

        return {
            cid: channel.cid,
            type: channel.type,
            id: channel.id,
            name: channel.data?.name ?? null,
            title: channel.data?.name || others.map((user) => user.name || user.id).join(', ') || 'Empty conversation',
            image: channel.data?.image ?? (others.length === 1 ? (others[0].image ?? null) : null),
            members: members.length,
            /* Who they are, not how many. A channel that says "2 members" and
               cannot say which two is telling somebody to go and ask. */
            people: members
                .map((user) => ({
                    id: user.id,
                    name: user.name || user.id,
                    image: user.image ?? null,
                    email: user.email ?? null,
                    online: !!user.online,
                    you: user.id === me,
                }))
                .sort((a, b) => (a.you === b.you ? a.name.localeCompare(b.name) : a.you ? 1 : -1)),
            /* Everybody but the caller: who a call has to ring. */
            memberIds: others.map((user) => user.id),
            online: others.some((user) => user.online),
            other: others[0]?.name ?? null,
            email: others.length === 1 ? (others[0].email ?? null) : null,
            unread,
            /* Somebody's own pin, kept by Stream against their membership, so
               it follows them to their phone rather than living in this tab. */
            pinned: !!channel.state?.membership?.pinned_at,
            activity: said.length / days,
            preview: last
                ? (last.user?.id === me ? 'You: ' : '') + (last.text || (last.attachments?.length ? 'Sent a file' : ''))
                : 'No messages yet',
            at: channel.state?.last_message_at ? new Date(channel.state.last_message_at).getTime() : 0,
        };
    }

    /**
     * Who else has read up to this point in the conversation.
     *
     * Stream keeps `channel.state.read` as a mark per person — the moment they
     * last read — rather than a flag per message, which is the right shape:
     * reading is a position in a conversation, not an opinion about each line.
     * So "seen" is everybody whose mark is at or past this message.
     *
     * Only other people count. Your own mark moves the instant you send, and a
     * message that says it has been seen because you sent it is a lie with a
     * tick next to it.
     */
    function marksFor(channel, message, me, field) {
        const at = message.created_at ? new Date(message.created_at).getTime() : null;

        if (at === null) {
            return 0;
        }

        return Object.values(channel?.state?.read ?? {}).filter((mark) => {
            if ((mark.user?.id ?? mark.user_id) === me) return false;

            const when = mark[field] ? new Date(mark[field]).getTime() : 0;

            return when >= at;
        }).length;
    }

    /** What somebody is called in a line of conversation: the first word of it. */
    function shortNameOf(name) {
        const first = String(name ?? '').trim().split(/\s+/)[0];

        return first === '' ? String(name ?? '') : first;
    }

    /** Sent, delivered or read: how far one of your own messages got. */
    function receiptFor(channel, message, me) {
        const others = Math.max(Object.keys(channel?.state?.members ?? {}).length - 1, 1);

        if (marksFor(channel, message, me, 'last_read') >= others) {
            return 'read';
        }

        if (marksFor(channel, message, me, 'last_delivered_at') >= others) {
            return 'delivered';
        }

        return 'sent';
    }

    /** One message, flattened to what a row draws. */
    function messageOf(message, me, channel = null) {
        return {
            id: message.id,
            text: message.text ?? '',
            deleted: message.type === 'deleted',
            mine: message.user?.id === me,
            /* Only worked out for your own: nobody needs telling that they
               have read the message they are looking at. */
            /*
             * How far your own message got, worked out per message rather than
             * once for the conversation: a mark at the foot of the list sits
             * under whatever came last, so the moment somebody replied it was
             * reading as a mark on *their* message.
             *
             * All three mean everybody in the room. One person out of eight
             * having read it is not a blue tick.
             */
            receipt: message.user?.id === me && channel ? receiptFor(channel, message, me) : null,
            userId: message.user?.id ?? null,
            /* The whole name, which is what the initials are taken from. */
            userName: message.user?.name ?? message.user?.id ?? 'Someone',
            /*
             * And the short one, which is what gets written above every
             * message. In a direct conversation the header already says who
             * you are talking to, so the full name over each line is the same
             * eighteen characters again; in a room a first name is what people
             * call each other anyway. Your own says "You" — a name nobody uses
             * for themselves, repeated down a column of their own messages.
             */
            userLabel: message.user?.id === me
                ? 'You'
                : shortNameOf(message.user?.name ?? message.user?.id ?? 'Someone'),
            userImage: message.user?.image ?? null,
            at: message.created_at ? new Date(message.created_at).getTime() : Date.now(),
            /* A message with replies is the top of a thread, and the count is
               what the line under it offers to open. */
            replies: message.reply_count ?? 0,
            parentId: message.parent_id ?? null,
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

        /** The one connection attempt, and the session it was made with. */
        connecting: null,
        session: null,

        /** Plain snapshots, newest first. */
        conversations: [],
        unread: 0,

        /** Messages by cid, also plain. */
        messages: {},

        /** Conversations with a floating box open. */
        open: [],

        /** Conversations currently drawn on screen, by cid. */
        watching: {},

        /**
         * How many unread messages each conversation holds.
         *
         * Kept here rather than asked of the channel. `channel.countUnread()`
         * answers from the read state the channel was handed when it was
         * queried, and a channel the browser is already watching is never
         * handed a new one — it answers nought while Stream's own event says
         * two. The event and `getUnreadCount()` are the authority; this is
         * where their answer is kept.
         */
        unreadOf: {},

        /** What the page is showing. */
        active: null,

        /**
         * Pins pressed since the page loaded, by cid.
         *
         * The truth is the membership `snapshot()` reads, which is Stream's and
         * therefore the same on somebody's phone. This map is what has been
         * pressed here, and it wins over that truth until the next query — so
         * a pin moves when it is pressed rather than a round trip later.
         */
        pinnedOf: {},

        /** Threads this person is in, flattened to what a row draws. */
        threads: [],
        threadsReady: false,

        /** The thread being read, if any. */
        thread: null,

        /**
         * Whether chat makes a noise, which is this browser's business.
         *
         * Not a portal setting and not a column on the user: somebody in an
         * open-plan office turns it off for the afternoon, and somebody at
         * home wants it on, and they are the same person on two machines. It
         * lives in `localStorage`, so the answer stays with the machine that
         * was asked.
         */
        sound: ! noise.muted(),

        toggleSound() {
            this.sound = ! this.sound;
            noise.mute(! this.sound);
        },

        /**
         * Connecting, once.
         *
         * The dock and the page both ask for it — the dock is in the layout and
         * the page is on the page — and the guard has to hold from the first
         * call, not from the first answer. Checking a client that only exists
         * after the round trip lets both through, and then the same person
         * connects twice and every request is made twice.
         */
        connect() {
            this.connecting ??= this.begin().catch((error) => {
                this.failed = error.message ?? 'Chat could not start.';
            });

            return this.connecting;
        },

        async begin() {
            /*
             * Stream's client is fetched here rather than imported at the top,
             * for the same reason as the call client: imported normally it is
             * part of the bundle every page loads, and chat is not what
             * somebody opening a contact record is waiting for.
             */
            const [{ StreamChat }, session] = await Promise.all([
                import('stream-chat'),
                ask(ENDPOINTS.token, { method: 'POST' }),
            ]);

            /* Kept so the call client does not go and ask for a second one. */
            this.session = session;

            client = StreamChat.getInstance(session.api_key);
            this.me = session.user_id;

            await client.connectUser(session.user, session.token);

            /* What somebody may do and what they are already in are two
               different questions; asking them one after the other adds a
               round trip to a screen that is still saying "Connecting". */
            const [abilities, list] = await Promise.all([
                ask(ENDPOINTS.abilities),
                client.queryChannels(
                    { members: { $in: [this.me] } },
                    [{ last_message_at: -1 }],
                    { watch: true, state: true, limit: 30, message_limit: 40 },
                ),
            ]);

            this.abilities = abilities;

            list.forEach((channel) => this.hold(channel));

            await this.syncUnread();

            this.refresh();
            this.listen();

            this.ready = true;
            this.active ??= this.conversations[0]?.cid ?? null;
            this.restoreOpen();

            /*
             * Build the call client while nobody is waiting -- but genuinely
             * while nobody is waiting. It has to exist before a call comes in,
             * or the phone never rings; it does not have to exist before the
             * page somebody actually asked for has finished drawing.
             */
            whenIdle(() => Alpine.store('calls')?.ready?.().catch(() => {}));
        },

        /** Keep the real channel here, out of anything reactive. */
        hold(channel) {
            channels.set(channel.cid, channel);
            this.messages[channel.cid] = (channel.state?.messages ?? [])
                .filter((message) => message.type !== 'deleted')
                .map((message) => messageOf(message, this.me, channel));
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

                /*
                 * A new message arrives under two names: `message.new` for a
                 * conversation the browser is watching, and
                 * `notification.message_new` for one it is not. Listening for
                 * only the first is listening for only the conversations
                 * already on screen — which are precisely the ones that do not
                 * need a badge.
                 */
                const arrived = ['message.new', 'notification.message_new'].includes(event.type);
                const from = event.message?.user?.id ?? event.user?.id;

                if (arrived && from !== this.me && cid) {
                    /*
                     * A message that lands in a conversation somebody is
                     * looking at has been read. Counting it unread leaves a
                     * badge on the window they are already sitting in, and
                     * nothing they can do clears it — the count is cleared
                     * when a conversation is opened, and this one never was.
                     */
                    if (this.reading(cid)) {
                        this.markRead(cid);
                    } else {
                        this.unreadOf = { ...this.unreadOf, [cid]: (this.unreadOf[cid] ?? 0) + 1 };

                        /*
                         * A badge and a sound, and not a window.
                         *
                         * This used to open the conversation by itself, which
                         * put it in front of somebody mid-sentence in another
                         * record — and, because a drawn conversation reports
                         * itself read, cleared the badge it had just set and
                         * told the sender it had been read. Nobody had read
                         * it. Saying a message is here is the job; deciding
                         * what somebody should be looking at is not.
                         */
                        noise.message();
                    }
                }

                /*
                 * A reply in the thread somebody is reading.
                 *
                 * Stream sends it as an ordinary new message carrying a
                 * parent, and it is deliberately not in the channel's own
                 * messages — so without this the thread only grows when it is
                 * closed and opened again.
                 */
                if (event.type === 'message.new' && event.message?.parent_id && this.thread?.parentId === event.message.parent_id) {
                    const already = this.thread.replies.some((reply) => reply.id === event.message.id);

                    if (!already) {
                        this.thread = { ...this.thread, replies: [...this.thread.replies, messageOf(event.message, this.me)] };
                    }
                }

                /* Read somewhere else: the same person on their phone, or in
                   another window. Their own account reading it is a read. */
                if (['message.read', 'notification.mark_read'].includes(event.type) && cid && event.user?.id === this.me) {
                    this.unreadOf = { ...this.unreadOf, [cid]: 0 };
                }

                /* Somebody else reading or receiving is what moves the ticks
                   under what you sent, so the list is built again from the
                   marks rather than waiting for the next message. */
                if (['message.read', 'message.delivered'].includes(event.type) && cid && event.user?.id !== this.me && channels.has(cid)) {
                    this.hold(channels.get(cid));
                }

                /*
                 * And this end reports the same thing back. A tick that only
                 * ever moves for one of the two people in a conversation is a
                 * tick that is wrong for the other one — delivery is told, not
                 * deduced, and nobody tells it unless we do.
                 */
                if (arrived && from !== this.me && cid && event.message?.id) {
                    this.confirm(cid, event.message.id);
                }

                this.refresh();
            });
        },

        /**
         * Whether this conversation is on screen in front of somebody.
         *
         * On screen is not enough on its own: a conversation open in a tab
         * nobody is looking at has not been read, and marking it read there is
         * how a message gets missed.
         */
        reading(cid) {
            return (this.watching[cid] ?? 0) > 0 && document.visibilityState === 'visible';
        },

        /**
         * Tell Stream this browser has the message.
         *
         * Separate from reading it: arriving on a machine nobody is looking at
         * is still delivery, and saying so is what puts the second tick under
         * it at the other end.
         */
        confirm(cid, messageId) {
            client.markChannelsDelivered?.({
                latest_delivered_messages: [{ cid, id: messageId }],
            }).catch(() => {});
        },

        /** A conversation component saying it is drawing this one, or has stopped. */
        watch(cid, on) {
            const count = (this.watching[cid] ?? 0) + (on ? 1 : -1);

            this.watching = { ...this.watching, [cid]: Math.max(0, count) };
        },

        /** Rebuild what the interface draws from what the client holds. */
        refresh() {
            this.conversations = [...channels.values()]
                .map((channel) => {
                    const conversation = snapshot(channel, this.me);

                    conversation.unread = this.unreadOf[conversation.cid] ?? 0;
                    conversation.pinned = this.pinnedOf[conversation.cid] ?? conversation.pinned;

                    return conversation;
                })
                .sort((a, b) => b.at - a.at);

            this.unread = this.conversations.reduce((total, conversation) => total + conversation.unread, 0);
        },

        /**
         * Ask Stream what was unread when we arrived.
         *
         * Once, on connect, and never again while the page is open — because
         * Stream marks a conversation read the moment a message reaches a
         * browser that is watching it, and this browser watches every
         * conversation somebody is in. Asked a second time it answers nought
         * to everything, which is how a badge that had just gone up came
         * straight back down. What happened while the page was shut is the
         * server's to tell us; what happens while it is open, we can see.
         */
        async syncUnread() {
            try {
                const counts = await client.getUnreadCount();
                const map = {};

                (counts.channels ?? []).forEach((channel) => {
                    map[channel.channel_id] = channel.unread_count;
                });

                this.unreadOf = map;
            } catch {
                /* Keep what we have rather than wiping the badges. */
            }
        },

        find(cid) {
            return this.conversations.find((conversation) => conversation.cid === cid) ?? null;
        },

        /* ----------------------------------------------------------- pins */

        /**
         * Pinning, and putting the pin back if Stream refuses.
         *
         * The interface answers immediately because a pin is a small decision
         * nobody should wait for — but an interface that says "pinned" when
         * nothing was pinned is worse than a slow one, so a refusal undoes it.
         */
        async togglePin(cid) {
            const channel = this.raw(cid);

            if (!channel) return;

            const on = !this.find(cid)?.pinned;

            this.pinnedOf = { ...this.pinnedOf, [cid]: on };
            this.refresh();

            try {
                await (on ? channel.pin() : channel.unpin());
            } catch {
                this.pinnedOf = { ...this.pinnedOf, [cid]: !on };
                this.refresh();
            }
        },

        /* -------------------------------------------------------- threads */

        /**
         * Every thread this person is part of.
         *
         * Asked for rather than kept in step: a thread list is something
         * somebody goes to look at, not something that has to be correct while
         * nobody is looking. Stream's `Thread` holds a channel, which holds the
         * client — so, like everything else here, only a flat reading of it
         * crosses into Alpine.
         */
        async loadThreads() {
            try {
                const { threads } = await client.queryThreads({ limit: 25, reply_limit: 1 });

                this.threads = threads
                    .map((thread) => {
                        const state = thread.state.getLatestValue();
                        const parent = state.parentMessage;
                        const last = state.replies[state.replies.length - 1] ?? parent;
                        const cid = state.channel?.cid ?? null;

                        return {
                            id: thread.id,
                            cid,
                            where: state.channel?.data?.name ?? this.find(cid)?.title ?? 'Conversation',
                            channel: state.channel?.type ?? 'messaging',
                            text: parent?.text || (parent?.attachments?.length ? 'Sent a file' : 'A message'),
                            who: parent?.user?.name ?? parent?.user?.id ?? 'Someone',
                            replies: state.replyCount ?? 0,
                            unread: state.read?.[this.me]?.unreadMessageCount ?? 0,
                            people: (state.participants ?? []).length,
                            at: last?.created_at ? new Date(last.created_at).getTime() : 0,
                        };
                    })
                    .filter((thread) => thread.cid !== null)
                    .sort((a, b) => b.at - a.at);
            } catch {
                /* Say none rather than leaving a spinner: a thread list that
                   cannot be fetched is a thread list nobody can act on. */
                this.threads = [];
            }

            this.threadsReady = true;
        },

        /**
         * Opening a thread: the message that started it and every reply.
         *
         * The parent usually comes from the window we already hold, which is
         * why a thread opens with no round trip when it is opened from the
         * conversation it is in. From the Threads tab the parent may be older
         * than that window, so it is fetched.
         */
        async openThread(cid, parentId) {
            this.thread = { cid, parentId, title: this.find(cid)?.title ?? '', loading: true, failed: false, parent: null, replies: [] };

            try {
                const channel = this.raw(cid);
                const held = (this.messages[cid] ?? []).find((message) => message.id === parentId);
                const [answer, parent] = await Promise.all([
                    channel.getReplies(parentId, { limit: 50 }),
                    held ? Promise.resolve(held) : client.getMessage(parentId).then((got) => messageOf(got.message, this.me)),
                ]);

                this.thread = {
                    cid,
                    parentId,
                    title: this.find(cid)?.title ?? '',
                    loading: false,
                    failed: false,
                    parent,
                    replies: (answer.messages ?? [])
                        .filter((message) => message.type !== 'deleted')
                        .map((message) => messageOf(message, this.me)),
                };
            } catch {
                this.thread = { ...this.thread, loading: false, failed: true };
            }
        },

        closeThread() {
            this.thread = null;
        },

        /**
         * A reply that stays in its thread.
         *
         * `show_in_channel: false` is the whole point of a thread: a side
         * conversation about one message does not push the room's own
         * conversation up the screen.
         */
        async reply(cid, parentId, text) {
            const sent = await this.raw(cid)?.sendMessage({ text, parent_id: parentId, show_in_channel: false });

            if (this.thread?.parentId === parentId && sent?.message) {
                this.thread = { ...this.thread, replies: [...this.thread.replies, messageOf(sent.message, this.me)] };
            }
        },

        initials: initialsOf,

        /** The line under a conversation's name. */
        subtitle(conversation) {
            if (!conversation) return '';

            if (conversation.type === 'team') {
                return `${conversation.members} ${conversation.members === 1 ? 'member' : 'members'}`;
            }

            /* Not their address: a directory of colleagues does not need to
               publish everybody's email on every row to say who they are. */
            return conversation.online ? 'Online' : 'Offline';
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

        /**
         * Reading a conversation.
         *
         * Only while the tab is in front of somebody: a conversation open in a
         * window nobody is looking at has not been read, and marking it read
         * there is how a message gets missed.
         */
        async markRead(cid) {
            const channel = this.raw(cid);

            if (!channel || document.visibilityState !== 'visible') {
                return;
            }

            this.unreadOf = { ...this.unreadOf, [cid]: 0 };
            this.refresh();

            await channel.markRead().catch(() => {});
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
            /* Say we are drawing it, so a message arriving in front of
               somebody is not counted as unread. */
            this.$store.chat.watch(this.cid, true);
            this.$store.chat.markRead(this.cid);
            this.scroll();

            /* Coming back to the tab is reading it too. */
            /* Coming back to the tab is reading what is in front of them. */
            this.onVisible = () => {
                if (document.visibilityState === 'visible') this.$store.chat.markRead(this.cid);
            };

            document.addEventListener('visibilitychange', this.onVisible);

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

        destroy() {
            this.$store.chat.watch(this.cid, false);
            document.removeEventListener('visibilitychange', this.onVisible);
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

                /* Answering is reading. Leaving a badge on a conversation
                   somebody just replied in is telling them about their own
                   message. */
                this.$store.chat.markRead(this.cid);
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

    /**
     * The bar along the bottom of every page, and the panel above it.
     *
     * Chat is a bar and not a page, because the point of it is answering
     * somebody while doing something else: a page means leaving what you were
     * doing and then finding your way back to it. The five tabs are five
     * questions somebody actually has -- what did I keep, who is talking to
     * me, which rooms am I in, what am I replying to, who else is here -- and
     * each answers in the same column, so nothing moves under the cursor.
     *
     * Expanded, the same panel fills the window and shows the full chat, which
     * is why there is no separate chat page that could be out of step with it.
     */
    Alpine.data('chatBar', () => ({
        /** Which tab is open. Null is the bar on its own. */
        tab: null,

        /** Filling the window: the whole chat rather than a column. */
        wide: false,

        search: '',
        order: 'recent',
        unreadOnly: false,
        ordering: false,

        /** The directory, once somebody asks for it. */
        people: null,
        peopleFailed: false,
        searching: null,

        init() {
            /* Connecting is a websocket and a vendor handshake, and nobody
               opened the portal to look at the chat bar. It happens once the
               page they did ask for has drawn. */
            whenIdle(() => this.$store.chat.connect());

            /* Somewhere else asking for chat. */
            window.addEventListener('chat:open', (event) => {
                this.$store.chat.connect();
                this.wide = !! event.detail?.wide;
                this.tab = event.detail?.tab ?? this.tab ?? 'chats';
                this.load();
            });

            window.addEventListener('chat:close', () => this.close());

            /*
             * Full screen means full screen: the page underneath stops
             * scrolling, so a wheel over the chat does not move a list nobody
             * can see behind it.
             */
            this.$watch('wide', (value) => document.body.classList.toggle('rc-locked', value));

            /* Escape closes what is in front of somebody, innermost first. */
            window.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || this.tab === null) return;

                if (this.$store.chat.thread) return this.$store.chat.closeThread();
                if (this.wide) return (this.wide = false);

                this.close();
            });
        },

        /* ------------------------------------------------------- the tabs */

        show(tab) {
            this.$store.chat.connect();

            /* Pressing the tab that is already open closes the panel, which is
               what a bar is for: one press out, one press back. */
            if (this.tab === tab && ! this.wide) {
                return this.close();
            }

            this.tab = tab;
            this.search = '';
            this.$store.chat.closeThread();
            this.load();
        },

        close() {
            this.tab = null;
            this.wide = false;
            this.$store.chat.closeThread();
        },

        /** What a tab needs that is not already here. */
        load() {
            if (this.tab === 'threads') this.$store.chat.loadThreads();
            if (this.tab === 'people' && this.people === null) this.loadPeople();
        },

        async loadPeople() {
            this.peopleFailed = false;

            try {
                const answer = await this.$store.chat.directory(this.search.trim());

                this.people = Object.entries(answer.groups ?? {}).map(([key, group]) => ({
                    key,
                    label: group.label,
                    people: group.people ?? [],
                }));
            } catch {
                this.people = [];
                this.peopleFailed = true;
            }
        },

        /**
         * Searching the directory, which is Stream's and not this browser's,
         * so it is one request behind the typing rather than one per keystroke.
         */
        searchPeople() {
            clearTimeout(this.searching);
            this.searching = setTimeout(() => this.loadPeople(), 250);
        },

        /* ------------------------------------------------------ the lists */

        get rows() {
            const term = this.search.trim().toLowerCase();

            let rows = this.$store.chat.conversations;

            if (this.tab === 'pins') rows = rows.filter((row) => row.pinned);
            if (this.tab === 'chats') rows = rows.filter((row) => row.type !== 'team');
            if (this.tab === 'channels') rows = rows.filter((row) => row.type === 'team');

            if (this.unreadOnly) rows = rows.filter((row) => row.unread > 0);
            if (term !== '') rows = rows.filter((row) => row.title.toLowerCase().includes(term));

            return this.order === 'active' ? [...rows].sort((a, b) => b.activity - a.activity) : rows;
        },

        get threads() {
            const term = this.search.trim().toLowerCase();
            const rows = this.$store.chat.threads;

            if (term === '') return rows;

            return rows.filter((row) => `${row.text} ${row.where} ${row.who}`.toLowerCase().includes(term));
        },

        /** The directory, minus anybody the filters have ruled out. */
        get groups() {
            const rows = (this.people ?? []).map((group) => ({
                ...group,
                people: this.unreadOnly ? group.people.filter((person) => person.online) : group.people,
            }));

            return rows.filter((group) => group.people.length > 0);
        },

        /** The label on the order button says what it sorted by. */
        get orderLabel() {
            return this.order === 'active' ? 'Most active' : 'Recent';
        },

        /* ------------------------------------------------- opening things */

        /**
         * A conversation opens in its own window, beside the panel.
         *
         * Never inside it: the panel is the summary -- the lists, the pins, the
         * people -- and a conversation read in the same column replaces the
         * thing somebody was using to find the next one. Two windows is also
         * two conversations at once, which is the point of a dock.
         */
        open(cid) {
            this.$store.chat.openBox(cid);

            /* Expanded, the full chat is already showing it; shrink back so
               the window that just opened is not behind the panel. */
            this.wide = false;
        },

        back() {
            this.$store.chat.closeThread();
        },

        async message(userId) {
            const cid = await this.$store.chat.messagePerson(userId);

            this.tab = 'chats';
            this.open(cid);
        },

        get boxes() {
            return this.$store.chat.open.filter((cid) => this.$store.chat.find(cid));
        },

        get heading() {
            return this.$store.chat.thread ? 'Thread' : 'Chat';
        },

        get subheading() {
            return this.$store.chat.thread ? `in ${this.$store.chat.thread.title}` : this.status.label;
        },

        /**
         * The number on a tab.
         *
         * Unread, never a total: a tab that says "12" when twelve channels
         * exist and nothing has happened in any of them is a badge nobody can
         * ever clear, so people stop reading badges.
         */
        countFor(tab) {
            const unread = (rows) => rows.reduce((total, row) => total + row.unread, 0);
            const rows = this.$store.chat.conversations;

            if (tab === 'pins') return unread(rows.filter((row) => row.pinned));
            if (tab === 'chats') return unread(rows.filter((row) => row.type !== 'team'));
            if (tab === 'channels') return unread(rows.filter((row) => row.type === 'team'));
            if (tab === 'threads') return unread(this.$store.chat.threads);

            return 0;
        },

        /** Where the bar stands: connected, trying, or not available here. */
        get status() {
            if (this.$store.chat.failed) return { label: 'Unavailable', tone: 'off' };
            if (! this.$store.chat.ready) return { label: 'Connecting', tone: 'wait' };

            return { label: 'Available', tone: 'on' };
        },
    }));

    /**
     * A thread: the message that started it, its replies, and a box to add one.
     *
     * Separate from `chatConversation` because a thread is not a conversation.
     * It has no unread count of its own to clear, no typing indicator worth the
     * traffic, and its replies deliberately never appear in the room -- which
     * is the only reason to start one instead of just answering.
     */
    Alpine.data('chatThread', () => ({
        text: '',
        sending: false,
        error: null,

        get thread() {
            return this.$store.chat.thread;
        },

        async send() {
            const text = this.text.trim();
            const thread = this.thread;

            if (text === '' || this.sending || ! thread) return;

            this.sending = true;
            this.text = '';
            this.error = null;

            try {
                await this.$store.chat.reply(thread.cid, thread.parentId, text);
                this.scroll();
            } catch {
                this.error = 'That reply did not send.';
                this.text = text;
            } finally {
                this.sending = false;
            }
        },

        scroll() {
            this.$nextTick(() => {
                const list = this.$refs.replies;

                if (list) list.scrollTop = list.scrollHeight;
            });
        },

        at(message) {
            return new Date(message.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
    }));

    /** The page: every conversation, and what it takes to manage them. */
    Alpine.data('chatPage', () => ({
        search: '',
        unreadOnly: false,
        picking: false,
        /** The side panel that says who is in the conversation. */
        details: false,
        /** Folded sections of the rail, as every chat application has. */
        folded: { channels: false, direct: false },
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
            if (this.already(id)) return;

            this.chosen = this.chosen.includes(id)
                ? this.chosen.filter((chosen) => chosen !== id)
                : [...this.chosen, id];
        },

        /** Already in the conversation being added to — offering them again
            is offering something that does nothing. */
        already(id) {
            return this.picking === 'members'
                && !!this.current?.people.some((person) => person.id === id);
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
