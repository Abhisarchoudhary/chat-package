{{--
    The page: every conversation in one place, and where channels are made.

    Navigation is sections with headers — Channels, Direct messages — the way
    every chat application does it, because that is what people already know.
    Tabs would make somebody choose a category before they can see their own
    conversations.
--}}
<div class="rc-page" x-data="chatPage()" x-cloak>
    <aside class="rc-rail">
        <div class="rc-rail__head">
            <div class="rc-rail__title">
                <span>{{ __('Chat') }}</span>

                <button type="button" class="rc-icon-button rc-icon-button--dark" title="{{ __('New message') }}" @click="pick('person')">
                    <x-chat::icon name="plus" />
                </button>
            </div>

            <label class="rc-search">
                <x-chat::icon name="search" size="16" />
                <input type="search" x-model="search" placeholder="{{ __('Search') }}">
            </label>
        </div>

        <div class="rc-rail__list">
            <div class="rc-rail__section">
                <button type="button" class="rc-rail__header" @click="pick('channel')">
                    <span>{{ __('Channels') }}</span>
                    <template x-if="$store.chat.abilities['create-channel']">
                        <span class="rc-rail__add" title="{{ __('New channel') }}"><x-chat::icon name="plus" size="14" /></span>
                    </template>
                </button>

                <template x-for="conversation in channels" :key="conversation.cid">
                    <button
                        type="button"
                        class="rc-row"
                        :class="[$store.chat.active === conversation.cid && 'rc-row--on', conversation.unread > 0 && 'rc-row--unread']"
                        @click="open(conversation.cid)"
                    >
                        <span class="rc-row__icon"><x-chat::icon name="hash" size="16" /></span>
                        <span class="rc-row__title" x-text="conversation.title"></span>
                        <template x-if="conversation.unread > 0">
                            <span class="rc-pill" x-text="conversation.unread"></span>
                        </template>
                    </button>
                </template>

                <template x-if="channels.length === 0">
                    <p class="rc-rail__none">{{ __('No channels yet') }}</p>
                </template>
            </div>

            <div class="rc-rail__section">
                <button type="button" class="rc-rail__header" @click="pick('person')">
                    <span>{{ __('Direct messages') }}</span>
                    <span class="rc-rail__add" title="{{ __('Message someone') }}"><x-chat::icon name="plus" size="14" /></span>
                </button>

                <template x-for="conversation in direct" :key="conversation.cid">
                    <button
                        type="button"
                        class="rc-row"
                        :class="[$store.chat.active === conversation.cid && 'rc-row--on', conversation.unread > 0 && 'rc-row--unread']"
                        @click="open(conversation.cid)"
                    >
                        <span class="rc-avatar rc-avatar--sm">
                            <template x-if="conversation.image"><img :src="conversation.image" alt=""></template>
                            <template x-if="! conversation.image"><span x-text="$store.chat.initials(conversation.title)"></span></template>
                            <template x-if="conversation.online"><span class="rc-dot"></span></template>
                        </span>

                        <span class="rc-row__title" x-text="conversation.title"></span>

                        <template x-if="conversation.unread > 0">
                            <span class="rc-pill" x-text="conversation.unread"></span>
                        </template>
                    </button>
                </template>

                <template x-if="direct.length === 0">
                    <p class="rc-rail__none">{{ __('Nobody yet') }}</p>
                </template>
            </div>

            <template x-if="! $store.chat.ready && ! $store.chat.failed">
                <p class="rc-rail__none">{{ __('Connecting…') }}</p>
            </template>

            <template x-if="$store.chat.failed">
                <p class="rc-rail__none">{{ __('Chat is not available on this account.') }}</p>
            </template>
        </div>
    </aside>

    <section class="rc-main">
        <template x-if="current">
            <div class="rc-room">
                <header class="rc-main__head">
                    <template x-if="current.type === 'team'">
                        <span class="rc-room__hash"><x-chat::icon name="hash" size="20" /></span>
                    </template>

                    <template x-if="current.type !== 'team'">
                        <span class="rc-avatar">
                            <template x-if="current.image"><img :src="current.image" alt=""></template>
                            <template x-if="! current.image"><span x-text="$store.chat.initials(current.title)"></span></template>
                            <template x-if="current.online"><span class="rc-dot"></span></template>
                        </span>
                    </template>

                    <span class="rc-main__title">
                        <strong x-text="current.title"></strong>
                        <span x-text="$store.chat.subtitle(current)"></span>
                    </span>

                    <template x-if="current.type === 'team' && $store.chat.abilities['create-channel']">
                        <button type="button" class="rc-icon-button" title="{{ __('Add people') }}" @click="pick('members')">
                            <x-chat::icon name="people" />
                        </button>
                    </template>

                    <template x-if="$store.chat.abilities['call']">
                        <button
                            type="button"
                            class="rc-icon-button rc-icon-button--call"
                            title="{{ __('Start a call') }}"
                            @click="$store.calls.start(current.cid, [], current.title)"
                        >
                            <x-chat::icon name="phone" />
                        </button>
                    </template>

                    <button type="button" class="rc-icon-button" title="{{ __('Open in a floating window') }}" @click="$store.chat.openBox(current.cid)">
                        <x-chat::icon name="expand" />
                    </button>
                </header>

                {{-- Keyed on the conversation, so switching rebuilds it rather
                     than showing one channel's messages under another's name. --}}
                <template x-for="cid in [$store.chat.active]" :key="cid">
                    <x-chat::conversation for="cid" />
                </template>
            </div>
        </template>

        <template x-if="! current">
            <div class="rc-empty">
                <div>
                    <x-chat::icon name="message" size="28" />
                    <strong>{{ __('Nothing open') }}</strong>
                    {{ __('Pick a conversation, or start one.') }}
                    <button type="button" class="rc-button rc-button--primary" style="margin-top: 14px" @click="pick('person')">
                        {{ __('Message someone') }}
                    </button>
                </div>
            </div>
        </template>
    </section>

    {{-- One sheet for all three: message somebody, make a channel, add people. --}}
    <template x-if="picking">
        <div class="rc-modal" @keydown.escape.window="picking = false">
            <div class="rc-modal__card" @click.outside="picking = false">
                <div class="rc-modal__head">
                    <p class="rc-modal__title"
                       x-text="picking === 'channel' ? '{{ __('New channel') }}' : (picking === 'members' ? '{{ __('Add people') }}' : '{{ __('New message') }}')"></p>

                    <button type="button" class="rc-icon-button" @click="picking = false" title="{{ __('Close') }}">
                        <x-chat::icon name="close" />
                    </button>
                </div>

                <template x-if="picking === 'channel'">
                    <div class="rc-field">
                        <label for="rc-name">{{ __('Channel name') }}</label>
                        <input id="rc-name" type="text" x-model="form.name" placeholder="{{ __('maintenance, leasing-toronto…') }}" autofocus>
                    </div>
                </template>

                <label class="rc-search rc-search--light">
                    <x-chat::icon name="search" size="16" />
                    <input type="text" placeholder="{{ __('Search people by name or email') }}" @input.debounce.300ms="loadPeople($event.target.value)">
                </label>

                <div class="rc-people">
                    <template x-for="(group, key) in people" :key="key">
                        <div>
                            <p class="rc-people__group" x-text="group.label"></p>

                            <template x-for="person in group.people" :key="person.id">
                                <button
                                    type="button"
                                    class="rc-person"
                                    :class="chosen.includes(person.id) && 'rc-person--chosen'"
                                    @click="picking === 'person' ? messagePerson(person.id) : toggle(person.id)"
                                >
                                    <span class="rc-avatar rc-avatar--sm">
                                        <template x-if="person.image"><img :src="person.image" alt=""></template>
                                        <template x-if="! person.image"><span x-text="$store.chat.initials(person.name)"></span></template>
                                        <template x-if="person.online"><span class="rc-dot"></span></template>
                                    </span>

                                    <span class="rc-person__name">
                                        <strong x-text="person.name"></strong>
                                        <span x-text="[person.department, person.email].filter(Boolean).join(' · ')"></span>
                                    </span>

                                    <template x-if="chosen.includes(person.id)">
                                        <span class="rc-person__tick">✓</span>
                                    </template>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="loading">
                        <p class="rc-note" style="padding: 16px">{{ __('Looking…') }}</p>
                    </template>

                    <template x-if="! loading && Object.keys(people).length === 0">
                        <p class="rc-note" style="padding: 16px">{{ __('Nobody found.') }}</p>
                    </template>
                </div>

                <template x-if="error">
                    <p class="rc-error" style="padding: 0" x-text="error"></p>
                </template>

                <template x-if="picking === 'person'">
                    <p class="rc-note">{{ __('Click somebody to start a conversation with them.') }}</p>
                </template>

                <template x-if="picking !== 'person'">
                    <div class="rc-actions">
                        <button type="button" class="rc-button" @click="picking = false">{{ __('Cancel') }}</button>

                        <template x-if="picking === 'channel'">
                            <button type="button" class="rc-button rc-button--primary" @click="create()">{{ __('Create channel') }}</button>
                        </template>

                        <template x-if="picking === 'members'">
                            <button type="button" class="rc-button rc-button--primary" :disabled="chosen.length === 0" @click="addChosen()">
                                {{ __('Add') }}
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
