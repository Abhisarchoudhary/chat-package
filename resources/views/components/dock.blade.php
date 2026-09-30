{{--
    Chat, as a bar along the bottom of every page.

    It is a bar and not a page because the point of chat in a CRM is answering
    somebody while doing something else — and a page means leaving the record
    you were working on and then finding your way back to it. The five tabs are
    five questions somebody actually has (what did I keep, who is talking to
    me, which rooms am I in, what am I replying to, who else is here).

    **The panel is the summary; a conversation is its own window.** Everything
    above the bar is lists — pins, chats, channels, threads, people — and
    pressing one opens a window beside the panel rather than replacing the list
    inside it. That is what makes the panel worth having: it is how somebody
    finds the next conversation, so reading one must not close it. Two windows
    is also two conversations at once.

    Expanded, the same panel fills the window and shows the full chat. That is
    deliberately the *same* panel: a separate chat page would be a second
    implementation of every list here, and the two would drift.

    **One root element, because the layout persists it.** A dock rebuilt on
    every page change is a dock that vanishes for as long as the new page takes
    to wake Alpine up, and takes the half-written message with it. The layout
    moves this node instead of re-creating it, and it can only move one — so
    the call card lives inside the bar rather than beside it.
--}}
@php
    $tabs = [
        ['key' => 'pins', 'icon' => 'pin', 'label' => __('My Pins')],
        ['key' => 'chats', 'icon' => 'message', 'label' => __('Chats')],
        ['key' => 'channels', 'icon' => 'hash', 'label' => __('Channels')],
        ['key' => 'threads', 'icon' => 'thread', 'label' => __('Threads')],
        ['key' => 'people', 'icon' => 'people', 'label' => __('People')],
    ];
@endphp

<div class="rc-chat" x-data="chatBar()" x-cloak>
{{-- The call card rings everywhere, whatever the bar is showing. --}}
<x-chat::call />

{{-- Conversation windows, beginning after the bar's own column so they never
     sit on top of the lists that opened them. --}}
<div class="rc-boxes">
    <template x-for="cid in boxes" :key="cid">
        <div class="rc-box" x-data="{ folded: false }" :class="folded && 'rc-box--folded'">
            <div class="rc-box__head" @click="folded = ! folded">
                <x-chat::avatar name="$store.chat.find(cid)?.title" image="$store.chat.find(cid)?.image" size="sm" />

                <span class="rc-box__title">
                    <strong x-text="$store.chat.find(cid)?.title"></strong>
                    <span x-text="$store.chat.subtitle($store.chat.find(cid))"></span>
                </span>

                <template x-if="$store.chat.abilities['call']">
                    <button
                        type="button"
                        class="rc-icon-button rc-icon-button--dark"
                        title="{{ __('Start a call') }}"
                        @click.stop="$store.calls.start(cid, $store.chat.find(cid)?.memberIds ?? [], $store.chat.find(cid)?.title)"
                    >
                        <x-chat::icon name="phone" size="16" />
                    </button>
                </template>

                <button
                    type="button"
                    class="rc-icon-button rc-icon-button--dark"
                    :class="$store.chat.find(cid)?.pinned && 'rc-icon-button--on'"
                    :title="$store.chat.find(cid)?.pinned ? '{{ __('Unpin') }}' : '{{ __('Pin') }}'"
                    @click.stop="$store.chat.togglePin(cid)"
                >
                    <x-chat::icon name="pin" size="16" />
                </button>

                <button type="button" class="rc-icon-button rc-icon-button--dark" @click.stop="folded = ! folded" :title="folded ? '{{ __('Open') }}' : '{{ __('Minimise') }}'">
                    <template x-if="folded"><x-chat::icon name="chevron-up" size="16" /></template>
                    <template x-if="! folded"><x-chat::icon name="minimise" size="16" /></template>
                </button>

                <button type="button" class="rc-icon-button rc-icon-button--dark" @click.stop="$store.chat.closeBox(cid)" title="{{ __('Close') }}">
                    <x-chat::icon name="close" size="16" />
                </button>
            </div>

            <template x-if="! folded">
                <x-chat::conversation for="cid" />
            </template>
        </div>
    </template>
</div>

<div class="rc-dock" :class="{ 'rc-dock--wide': wide, 'rc-dock--open': tab !== null }">
    {{-- Everything a tab opens, above the bar. --}}
    <div class="rc-mini" x-show="tab !== null" x-transition.opacity.duration.120ms>
        <header class="rc-mini__head">
            {{-- Back where there is somewhere to go back to, and the mark
                 where there is not: the same slot, so the title never moves. --}}
            <template x-if="$store.chat.thread">
                <button type="button" class="rc-icon-button" @click="back()" title="{{ __('Back') }}">
                    <x-chat::icon name="back" size="17" />
                </button>
            </template>

            <template x-if="! $store.chat.thread">
                <span class="rc-mini__mark"><x-chat::icon name="message" size="15" /></span>
            </template>

            <span class="rc-mini__title">
                <strong x-text="heading"></strong>
                <span class="rc-mini__status" :class="`rc-mini__status--${status.tone}`">
                    <template x-if="! $store.chat.thread"><i></i></template>
                    <span x-text="subheading"></span>
                </span>
            </span>

            <button
                type="button"
                class="rc-icon-button"
                @click="wide = ! wide"
                :title="wide ? '{{ __('Shrink') }}' : '{{ __('Expand') }}'"
            >
                <template x-if="wide"><x-chat::icon name="collapse" size="16" /></template>
                <template x-if="! wide"><x-chat::icon name="expand" size="16" /></template>
            </button>

            <button type="button" class="rc-icon-button" @click="close()" title="{{ __('Close') }}">
                <x-chat::icon name="close" size="16" />
            </button>
        </header>

        {{-- Expanded: the full chat, which is the same conversations seen wide
             rather than a different screen. --}}
        <template x-if="wide">
            <div class="rc-mini__page">
                <x-chat::page />
            </div>
        </template>

        <template x-if="! wide">
            <div class="rc-mini__body">
                {{-- A thread: the message that started it, and its replies. --}}
                <template x-if="$store.chat.thread">
                    <div class="rc-mini__pane" x-data="chatThread()">
                        <template x-if="thread.loading">
                            <p class="rc-mini__note">{{ __('Loading the replies…') }}</p>
                        </template>

                        <template x-if="thread.failed">
                            <p class="rc-mini__note">{{ __('Those replies could not be loaded.') }}</p>
                        </template>

                        <template x-if="! thread.loading && ! thread.failed">
                            <div class="rc-thread">
                                <div class="rc-thread__list" x-ref="replies">
                                    <template x-if="thread.parent">
                                        <article class="rc-thread__parent">
                                            <x-chat::avatar name="thread.parent.userName" image="thread.parent.userImage" size="sm" />

                                            <div>
                                                <div class="rc-msg__who">
                                                    <span class="rc-msg__name" x-text="thread.parent.userName"></span>
                                                    <span class="rc-msg__at" x-text="at(thread.parent)"></span>
                                                </div>
                                                <div class="rc-msg__text" x-text="thread.parent.text"></div>
                                            </div>
                                        </article>
                                    </template>

                                    <p class="rc-thread__rule">
                                        <span x-text="thread.replies.length === 1 ? '1 {{ __('reply') }}' : thread.replies.length + ' {{ __('replies') }}'"></span>
                                    </p>

                                    <template x-for="reply in thread.replies" :key="reply.id">
                                        <article class="rc-thread__reply">
                                            <x-chat::avatar name="reply.userName" image="reply.userImage" size="sm" />

                                            <div>
                                                <div class="rc-msg__who">
                                                    <span class="rc-msg__name" x-text="reply.userName"></span>
                                                    <span class="rc-msg__at" x-text="at(reply)"></span>
                                                </div>
                                                <div class="rc-msg__text" x-text="reply.text"></div>
                                            </div>
                                        </article>
                                    </template>

                                    <template x-if="thread.replies.length === 0">
                                        <p class="rc-mini__note">{{ __('Nobody has replied yet. Yours will be the first.') }}</p>
                                    </template>
                                </div>

                                <template x-if="error">
                                    <p class="rc-error" x-text="error"></p>
                                </template>

                                <form class="rc-composer rc-composer--thread" @submit.prevent="send()">
                                    <div class="rc-composer__box">
                                        <textarea
                                            x-model="text"
                                            rows="1"
                                            placeholder="{{ __('Reply in this thread…') }}"
                                            @keydown.enter.prevent="$event.shiftKey ? (text += '\n') : send()"
                                        ></textarea>

                                        <div class="rc-composer__bar">
                                            <span class="rc-composer__hint">{{ __('Only this thread sees it') }}</span>

                                            <button type="submit" class="rc-icon-button rc-icon-button--send" :disabled="sending || text.trim() === ''" title="{{ __('Send') }}">
                                                <x-chat::icon name="send" />
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Otherwise: whichever list the open tab is for. --}}
                <template x-if="! $store.chat.thread">
                    <div class="rc-mini__pane">
                        <div class="rc-mini__search">
                            <label class="rc-search rc-search--light">
                                <x-chat::icon name="search" size="15" />
                                <input
                                    type="search"
                                    x-model="search"
                                    @input="tab === 'people' && searchPeople()"
                                    :placeholder="tab === 'people' ? '{{ __('Search people') }}' : '{{ __('Search') }}'"
                                >
                            </label>
                        </div>

                        {{-- What the list is sorted by, and whether it is
                             narrowed. Both say what they do rather than being
                             an icon somebody has to press to find out. --}}
                        <template x-if="tab !== 'threads'">
                            <div class="rc-mini__filters">
                                <template x-if="tab !== 'people'">
                                    <div class="rc-mini__order" @click.outside="ordering = false">
                                        <button type="button" class="rc-chip" @click="ordering = ! ordering">
                                            <span x-text="orderLabel"></span>
                                            <x-chat::icon name="chevron-down" size="12" />
                                        </button>

                                        <div class="rc-menu" x-show="ordering" x-transition.opacity.duration.100ms>
                                            <button type="button" @click="order = 'recent'; ordering = false">{{ __('Recent') }}</button>
                                            <button type="button" @click="order = 'active'; ordering = false">{{ __('Most active') }}</button>
                                        </div>
                                    </div>
                                </template>

                                <button
                                    type="button"
                                    class="rc-chip"
                                    :class="unreadOnly && 'rc-chip--on'"
                                    @click="unreadOnly = ! unreadOnly"
                                    x-text="tab === 'people' ? '{{ __('Online') }}' : '{{ __('Unread') }}'"
                                ></button>
                            </div>
                        </template>

                        <div class="rc-mini__list">
                            {{-- Conversations: pins, chats and channels are
                                 the same row asked three different ways. --}}
                            <template x-if="tab !== 'people' && tab !== 'threads'">
                                <div>
                                    <template x-for="row in rows" :key="row.cid">
                                        <div class="rc-row" :class="row.unread > 0 && 'rc-row--unread'">
                                            <button type="button" class="rc-row__open" @click="open(row.cid)">
                                                <template x-if="row.type === 'team'">
                                                    <span class="rc-row__icon"><x-chat::icon name="hash" size="16" /></span>
                                                </template>

                                                <template x-if="row.type !== 'team'">
                                                    <x-chat::avatar name="row.title" image="row.image" online="row.online" size="sm" />
                                                </template>

                                                <span class="rc-row__body">
                                                    <span class="rc-row__title" x-text="row.title"></span>
                                                    <span class="rc-row__preview" x-text="row.preview"></span>
                                                </span>

                                                <template x-if="row.unread > 0">
                                                    <span class="rc-pill" x-text="row.unread"></span>
                                                </template>
                                            </button>

                                            <button
                                                type="button"
                                                class="rc-row__pin"
                                                :class="row.pinned && 'rc-row__pin--on'"
                                                :title="row.pinned ? '{{ __('Unpin') }}' : '{{ __('Pin') }}'"
                                                @click="$store.chat.togglePin(row.cid)"
                                            >
                                                <x-chat::icon name="pin" size="14" />
                                            </button>
                                        </div>
                                    </template>

                                    {{-- Each empty list says why it is empty
                                         and what to do about it. --}}
                                    <template x-if="$store.chat.ready && rows.length === 0">
                                        <p class="rc-mini__note">
                                            <template x-if="tab === 'pins'">
                                                <span>{{ __('Nothing pinned. Pin a conversation and it stays here — on this machine and on your phone.') }}</span>
                                            </template>
                                            <template x-if="tab === 'chats'">
                                                <span>{{ __('No direct messages. Find somebody under People.') }}</span>
                                            </template>
                                            <template x-if="tab === 'channels'">
                                                <span>{{ __('You are not in any channel yet.') }}</span>
                                            </template>
                                        </p>
                                    </template>
                                </div>
                            </template>

                            {{-- Threads. --}}
                            <template x-if="tab === 'threads'">
                                <div>
                                    <template x-for="thread in threads" :key="thread.id">
                                        <button
                                            type="button"
                                            class="rc-row rc-row--thread"
                                            :class="thread.unread > 0 && 'rc-row--unread'"
                                            @click="$store.chat.openThread(thread.cid, thread.id)"
                                        >
                                            <span class="rc-row__icon"><x-chat::icon name="thread" size="16" /></span>

                                            <span class="rc-row__body">
                                                <span class="rc-row__title" x-text="thread.text"></span>
                                                <span class="rc-row__preview">
                                                    <span x-text="thread.where"></span>
                                                    <span> · </span>
                                                    <span x-text="thread.replies === 1 ? '1 {{ __('reply') }}' : thread.replies + ' {{ __('replies') }}'"></span>
                                                </span>
                                            </span>

                                            <template x-if="thread.unread > 0">
                                                <span class="rc-pill" x-text="thread.unread"></span>
                                            </template>
                                        </button>
                                    </template>

                                    <template x-if="! $store.chat.threadsReady">
                                        <p class="rc-mini__note">{{ __('Looking for your threads…') }}</p>
                                    </template>

                                    <template x-if="$store.chat.threadsReady && threads.length === 0">
                                        <p class="rc-mini__note">
                                            {{ __('No threads yet. Reply to a single message — the arrow beside it — and the side conversation lives here instead of pushing the room along.') }}
                                        </p>
                                    </template>
                                </div>
                            </template>

                            {{-- People: the shared directory, this portal first. --}}
                            <template x-if="tab === 'people'">
                                <div>
                                    <template x-for="group in groups" :key="group.key">
                                        <div class="rc-mini__group">
                                            <p class="rc-mini__groupName" x-text="group.label"></p>

                                            <template x-for="person in group.people" :key="person.id">
                                                <button type="button" class="rc-row rc-row--person" @click="message(person.id)">
                                                    <x-chat::avatar name="person.name" image="person.image" online="person.online" size="sm" />

                                                    <span class="rc-row__body">
                                                        <span class="rc-row__title" x-text="person.name"></span>
                                                        {{-- Their team or whether they are here. Never their
                                                             address: a colleague list is not a mailing list. --}}
                                                        <span class="rc-row__preview" x-text="person.department || (person.online ? '{{ __('Online') }}' : '{{ __('Offline') }}')"></span>
                                                    </span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="people === null">
                                        <p class="rc-mini__note">{{ __('Loading the directory…') }}</p>
                                    </template>

                                    <template x-if="peopleFailed">
                                        <p class="rc-mini__note">{{ __('The directory could not be loaded.') }}</p>
                                    </template>

                                    <template x-if="people !== null && ! peopleFailed && groups.length === 0">
                                        <p class="rc-mini__note">{{ __('Nobody matches that.') }}</p>
                                    </template>
                                </div>
                            </template>

                            <template x-if="$store.chat.failed">
                                <p class="rc-mini__note" x-text="$store.chat.failed"></p>
                            </template>
                        </div>

                        {{-- Starting something, from wherever somebody is. --}}
                        <div class="rc-mini__new" @click.outside="adding = false">
                            <div class="rc-menu rc-menu--up" x-show="adding" x-transition.opacity.duration.100ms>
                                <button type="button" @click="adding = false; show('people')">{{ __('New message') }}</button>

                                <template x-if="$store.chat.abilities['create-channel']">
                                    <button type="button" @click="newChannel()">{{ __('New channel') }}</button>
                                </template>
                            </div>

                            <button type="button" class="rc-new" @click="adding = ! adding" title="{{ __('Start a conversation') }}">
                                <x-chat::icon name="plus" size="17" />
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- The bar. Icons only, because five words along the bottom of every page
         is a strip of furniture somebody reads once and then looks past; the
         name arrives on hover, before it is needed and not before that. --}}
    <nav class="rc-bar" aria-label="{{ __('Chat') }}">
        @foreach ($tabs as $tab)
            <button
                type="button"
                class="rc-tab"
                :class="tab === '{{ $tab['key'] }}' && 'rc-tab--on'"
                @click="show('{{ $tab['key'] }}')"
                aria-label="{{ $tab['label'] }}"
                data-test="chat-tab-{{ $tab['key'] }}"
            >
                <span class="rc-tab__icon">
                    <x-chat::icon name="{{ $tab['icon'] }}" size="18" />

                    <template x-if="countFor('{{ $tab['key'] }}') > 0">
                        <span class="rc-badge" x-text="countFor('{{ $tab['key'] }}')"></span>
                    </template>
                </span>

                <span class="rc-tab__label">{{ $tab['label'] }}</span>
            </button>
        @endforeach
    </nav>
</div>
</div>
