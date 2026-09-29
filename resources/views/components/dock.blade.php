{{--
    The dock: a launcher that follows somebody across every page, the list of
    who they are talking to, and the boxes those conversations open in.

    It lives in the layout rather than on the chat page, because the point of it
    is answering somebody while doing something else. Boxes survive a page
    change (sessionStorage), which is what makes it feel like one application
    instead of a page that has to be gone back to.
--}}
<x-chat::call />

<div class="rc-dock" x-data="chatDock()" x-cloak>
    {{-- Open conversations, newest on the right, nearest the launcher. --}}
    <template x-for="cid in boxes" :key="cid">
        <div class="rc-box" x-data="{ folded: false }" :class="folded && 'rc-box--folded'">
            <div class="rc-box__head" @click="folded = ! folded" style="cursor: pointer">
                <span class="rc-avatar rc-avatar--sm" style="background: #ffffff26; color: #fff">
                    <template x-if="$store.chat.image($store.chat.channelFor(cid))">
                        <img :src="$store.chat.image($store.chat.channelFor(cid))" alt="">
                    </template>
                    <template x-if="! $store.chat.image($store.chat.channelFor(cid))">
                        <span x-text="$store.chat.initials($store.chat.title($store.chat.channelFor(cid)))"></span>
                    </template>
                </span>

                <span class="rc-box__title" x-text="$store.chat.title($store.chat.channelFor(cid))"></span>

                <template x-if="$store.chat.abilities['call']">
                    <button
                        type="button"
                        class="rc-box__action"
                        title="{{ __('Call') }}"
                        @click.stop="$store.calls.start(cid, Object.keys($store.chat.channelFor(cid).state?.members ?? {}), $store.chat.title($store.chat.channelFor(cid)))"
                    >☎</button>
                </template>

                <button type="button" class="rc-box__action" @click.stop="folded = ! folded" x-text="folded ? '▴' : '▾'"></button>
                <button type="button" class="rc-box__action" @click.stop="$store.chat.closeBox(cid)">×</button>
            </div>

            <template x-if="! folded">
                <x-chat::conversation :compact="true" for="cid" />
            </template>
        </div>
    </template>

    <div style="position: relative">
        {{-- The list of conversations, above the launcher. --}}
        <div class="rc-panel" x-show="panel" x-transition.opacity @click.outside="panel = false">
            <div class="rc-panel__head">
                <span>{{ __('Chats') }}</span>
                <a href="{{ route('chat.index') }}" wire:navigate style="font-size: 13px; color: var(--rc-primary)">{{ __('Open chat') }}</a>
            </div>

            <div style="padding: 10px 12px; border-bottom: 1px solid var(--rc-line)">
                <input type="search" class="rc-panel__search" x-model="search" placeholder="{{ __('Search conversations') }}">
            </div>

            <div class="rc-panel__list">
                <template x-for="channel in conversations" :key="channel.cid">
                    <button type="button" class="rc-row" @click="$store.chat.openBox(channel.cid); panel = false">
                        <span class="rc-avatar">
                            <template x-if="$store.chat.image(channel)"><img :src="$store.chat.image(channel)" alt=""></template>
                            <template x-if="! $store.chat.image(channel)"><span x-text="$store.chat.initials($store.chat.title(channel))"></span></template>
                        </span>

                        <span class="rc-row__body">
                            <span class="rc-row__title" x-text="$store.chat.title(channel)"></span>
                            <span class="rc-row__preview" x-text="preview(channel)"></span>
                        </span>

                        <template x-if="channel.countUnread() > 0">
                            <span class="rc-badge rc-badge--quiet" x-text="channel.countUnread()"></span>
                        </template>
                    </button>
                </template>

                <template x-if="$store.chat.ready && conversations.length === 0">
                    <p style="padding: 20px; text-align: center; color: var(--rc-muted); font-size: 13px">
                        {{ __('No conversations yet.') }}
                        <a href="{{ route('chat.index') }}" wire:navigate style="color: var(--rc-primary)">{{ __('Start one') }}</a>.
                    </p>
                </template>

                <template x-if="$store.chat.failed">
                    <p style="padding: 20px; text-align: center; color: var(--rc-muted); font-size: 13px">
                        {{ __('Chat is not available on this account.') }}
                    </p>
                </template>
            </div>
        </div>

        <button type="button" class="rc-launcher" @click="panel = ! panel" data-test="chat-launcher">
            <span>{{ __('Chat') }}</span>
            <template x-if="$store.chat.unread > 0">
                <span class="rc-badge" x-text="$store.chat.unread"></span>
            </template>
        </button>
    </div>
</div>
