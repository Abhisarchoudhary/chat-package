{{--
    The dock: a launcher that follows somebody across every page, the list of
    who they are talking to, and the boxes those conversations open in.

    It lives in the layout rather than on the chat page, because the point of it
    is answering somebody while doing something else. Boxes survive a page
    change, which is what makes it one application instead of a page to go back
    to.

    **One root element, because the layout persists it.** A dock rebuilt on
    every page change is a dock that vanishes for as long as the new page takes
    to wake Alpine up, and takes the half-written message in the box with it.
    `@persist` moves this node instead of re-creating it, and `@persist` takes
    exactly one child — so the call card lives inside the dock rather than
    beside it.
--}}
<div class="rc-chat" x-data="chatDock()" x-cloak>
{{-- The call card rings everywhere, including on the chat page. --}}
<x-chat::call />

{{-- The launcher and the boxes are for the rest of the portal: on the chat
     page they would be a small window of a conversation standing in front of
     the large one already showing it. --}}
<div class="rc-dock" x-show="! onPage">
    <template x-for="cid in boxes" :key="cid">
        <div class="rc-box" x-data="{ folded: false }" :class="folded && 'rc-box--folded'">
            <div class="rc-box__head" @click="folded = ! folded">
                <span class="rc-avatar rc-avatar--sm">
                    <template x-if="$store.chat.find(cid)?.image"><img :src="$store.chat.find(cid).image" alt=""></template>
                    <template x-if="! $store.chat.find(cid)?.image"><span x-text="$store.chat.initials($store.chat.find(cid)?.title)"></span></template>
                </span>

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

    <div style="position: relative">
        <div class="rc-panel" x-show="panel" x-transition.opacity @click.outside="panel = false">
            <div class="rc-panel__head">
                <span>{{ __('Chats') }}</span>
                <a href="{{ route('chat.index') }}" wire:navigate>{{ __('Open chat') }}</a>
            </div>

            <div class="rc-panel__search">
                <label class="rc-search">
                    <x-chat::icon name="search" size="16" />
                    <input type="search" x-model="search" placeholder="{{ __('Search') }}">
                </label>
            </div>

            <div class="rc-panel__list">
                <template x-for="conversation in conversations" :key="conversation.cid">
                    <button
                        type="button"
                        class="rc-row"
                        :class="conversation.unread > 0 && 'rc-row--unread'"
                        @click="$store.chat.openBox(conversation.cid); panel = false"
                    >
                        <template x-if="conversation.type === 'team'">
                            <span class="rc-row__icon"><x-chat::icon name="hash" size="16" /></span>
                        </template>

                        <template x-if="conversation.type !== 'team'">
                            <span class="rc-avatar rc-avatar--sm">
                                <template x-if="conversation.image"><img :src="conversation.image" alt=""></template>
                                <template x-if="! conversation.image"><span x-text="$store.chat.initials(conversation.title)"></span></template>
                                <template x-if="conversation.online"><span class="rc-dot"></span></template>
                            </span>
                        </template>

                        <span class="rc-row__body">
                            <span class="rc-row__title" x-text="conversation.title"></span>
                            <span class="rc-row__preview" x-text="conversation.preview"></span>
                        </span>

                        <template x-if="conversation.unread > 0">
                            <span class="rc-pill" x-text="conversation.unread"></span>
                        </template>
                    </button>
                </template>

                <template x-if="$store.chat.ready && conversations.length === 0">
                    <p class="rc-panel__empty">
                        {{ __('No conversations yet.') }}
                        <a href="{{ route('chat.index') }}" wire:navigate>{{ __('Start one') }}</a>.
                    </p>
                </template>

                <template x-if="$store.chat.failed">
                    <p class="rc-panel__empty">{{ __('Chat is not available on this account.') }}</p>
                </template>
            </div>
        </div>

        <button type="button" class="rc-launcher" @click="panel = ! panel" data-test="chat-launcher">
            <x-chat::icon name="message" size="17" />
            <span>{{ __('Chat') }}</span>
            <template x-if="$store.chat.unread > 0">
                <span class="rc-badge" x-text="$store.chat.unread"></span>
            </template>
        </button>
    </div>
</div>
</div>
