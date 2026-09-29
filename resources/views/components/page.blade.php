{{--
    The page somebody goes to when the dock is not enough: every conversation in
    one place, and where channels are made and managed.

    A dark rail and a white room, because that is the shape people already know
    from Slack and Cliq — chat is the one thing in a portal nobody reads
    instructions for.
--}}
<div class="rc-page" x-data="chatPage()" x-cloak>
    <aside class="rc-rail">
        <div class="rc-rail__head">
            <div class="rc-rail__title">
                <span>{{ __('Chat') }}</span>
                <button type="button" class="rc-rail__new" title="{{ __('New conversation') }}" @click="creating = true; loadPeople()">+</button>
            </div>

            <input type="search" class="rc-search" x-model="search" placeholder="{{ __('Search conversations') }}">

            <div class="rc-tabs">
                <button type="button" class="rc-tab" :class="tab === 'all' && 'rc-tab--on'" @click="tab = 'all'">{{ __('All') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'unread' && 'rc-tab--on'" @click="tab = 'unread'">{{ __('Unread') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'channels' && 'rc-tab--on'" @click="tab = 'channels'">{{ __('Channels') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'direct' && 'rc-tab--on'" @click="tab = 'direct'">{{ __('People') }}</button>
            </div>
        </div>

        <div class="rc-rail__list">
            <template x-if="grouped.channels.length">
                <div>
                    <p class="rc-rail__group">{{ __('Channels') }}</p>

                    <template x-for="channel in grouped.channels" :key="channel.cid">
                        <button
                            type="button"
                            class="rc-row"
                            :class="[$store.chat.active === channel.cid && 'rc-row--on', channel.countUnread() > 0 && 'rc-row--unread']"
                            @click="open(channel.cid)"
                        >
                            <span class="rc-hash">#</span>

                            <span class="rc-row__body">
                                <span class="rc-row__title" x-text="$store.chat.title(channel)"></span>
                                <span class="rc-row__preview" x-text="Object.keys(channel.state?.members ?? {}).length + ' {{ __('members') }}'"></span>
                            </span>

                            <template x-if="channel.countUnread() > 0">
                                <span class="rc-pill" x-text="channel.countUnread()"></span>
                            </template>
                        </button>
                    </template>
                </div>
            </template>

            <template x-if="grouped.direct.length">
                <div>
                    <p class="rc-rail__group">{{ __('Direct messages') }}</p>

                    <template x-for="channel in grouped.direct" :key="channel.cid">
                        <button
                            type="button"
                            class="rc-row"
                            :class="[$store.chat.active === channel.cid && 'rc-row--on', channel.countUnread() > 0 && 'rc-row--unread']"
                            @click="open(channel.cid)"
                        >
                            <span class="rc-avatar rc-avatar--sm">
                                <template x-if="$store.chat.image(channel)"><img :src="$store.chat.image(channel)" alt=""></template>
                                <template x-if="! $store.chat.image(channel)"><span x-text="$store.chat.initials($store.chat.title(channel))"></span></template>
                                <template x-if="others(channel).some(user => user.online)"><span class="rc-dot"></span></template>
                            </span>

                            <span class="rc-row__body">
                                <span class="rc-row__title" x-text="$store.chat.title(channel)"></span>
                                <span class="rc-row__preview" x-text="preview(channel)"></span>
                            </span>

                            <template x-if="channel.countUnread() > 0">
                                <span class="rc-pill" x-text="channel.countUnread()"></span>
                            </template>
                        </button>
                    </template>
                </div>
            </template>

            <template x-if="$store.chat.ready && conversations.length === 0">
                <p class="rc-panel__empty">
                    {{ __('Nothing here yet.') }}<br>
                    <a href="#" @click.prevent="creating = true; loadPeople()">{{ __('Start a conversation') }}</a>
                </p>
            </template>

            {{-- Only while there is nothing to show: a list that says it is
                 still connecting under the conversations it has already drawn
                 reads as broken. --}}
            <template x-if="! $store.chat.ready && ! $store.chat.failed && conversations.length === 0">
                <p class="rc-panel__empty">{{ __('Connecting…') }}</p>
            </template>

            <template x-if="$store.chat.failed">
                <p class="rc-panel__empty">{{ __('Chat is not available on this account.') }}</p>
            </template>
        </div>
    </aside>

    <section class="rc-main">
        <template x-if="$store.chat.active && $store.chat.channelFor($store.chat.active)">
            <div style="display: flex; flex-direction: column; flex: 1; min-height: 0">
                <header class="rc-main__head">
                    <template x-if="$store.chat.channelFor($store.chat.active).type === 'team'">
                        <span class="rc-hash" style="font-size: 20px">#</span>
                    </template>

                    <template x-if="$store.chat.channelFor($store.chat.active).type !== 'team'">
                        <span class="rc-avatar">
                            <template x-if="$store.chat.image($store.chat.channelFor($store.chat.active))">
                                <img :src="$store.chat.image($store.chat.channelFor($store.chat.active))" alt="">
                            </template>
                            <template x-if="! $store.chat.image($store.chat.channelFor($store.chat.active))">
                                <span x-text="$store.chat.initials($store.chat.title($store.chat.channelFor($store.chat.active)))"></span>
                            </template>
                        </span>
                    </template>

                    <span class="rc-main__title">
                        <strong x-text="$store.chat.title($store.chat.channelFor($store.chat.active))"></strong>
                        <span x-text="headline($store.chat.channelFor($store.chat.active))"></span>
                    </span>

                    <template x-if="$store.chat.abilities['call']">
                        <button
                            type="button"
                            class="rc-button rc-button--primary"
                            @click="$store.calls.start(
                                $store.chat.active,
                                Object.keys($store.chat.channelFor($store.chat.active).state?.members ?? {}),
                                $store.chat.title($store.chat.channelFor($store.chat.active)),
                            )"
                        >{{ __('Call') }}</button>
                    </template>

                    <template x-if="$store.chat.channelFor($store.chat.active).type === 'team'">
                        <button type="button" class="rc-button" @click="creating = true; loadPeople()">{{ __('Add people') }}</button>
                    </template>

                    <button type="button" class="rc-button" @click="$store.chat.openBox($store.chat.active)" title="{{ __('Open this in a floating box') }}">
                        {{ __('Pop out') }}
                    </button>
                </header>

                {{-- Keyed on the conversation, so switching rebuilds it rather than
                     showing one channel's messages under another's name. --}}
                <template x-for="cid in [$store.chat.active]" :key="cid">
                    <x-chat::conversation for="cid" />
                </template>
            </div>
        </template>

        <template x-if="! $store.chat.active">
            <div class="rc-empty">
                <div>
                    <strong>{{ __('Pick a conversation') }}</strong>
                    {{ __('Or start a new one — a person, a group, or a channel your team can join.') }}
                </div>
            </div>
        </template>
    </section>

    {{-- New conversation: a person to message, or a channel to make. --}}
    <template x-if="creating">
        <div class="rc-modal" @keydown.escape.window="creating = false">
            <div class="rc-modal__card" @click.outside="creating = false">
                <p class="rc-modal__title">{{ __('New conversation') }}</p>

                <div class="rc-field">
                    <label for="rc-search-people">{{ __('People') }}</label>
                    <input id="rc-search-people" type="text" placeholder="{{ __('Search by name or email') }}" @input.debounce.300ms="loadPeople($event.target.value)">
                </div>

                <div class="rc-people">
                    <template x-for="(group, key) in people" :key="key">
                        <div>
                            <p class="rc-people__group" x-text="group.label"></p>
                            <template x-for="person in group.people" :key="person.id">
                                <button
                                    type="button"
                                    class="rc-person"
                                    :class="chosen.includes(person.id) && 'rc-person--chosen'"
                                    @click="form.name.trim() === '' && chosen.length === 0 ? messagePerson(person.id) : toggle(person.id)"
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

                                    <template x-if="chosen.includes(person.id)"><span style="color: var(--rc-primary)">✓</span></template>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="Object.keys(people).length === 0">
                        <p class="rc-note" style="padding: 16px">{{ __('Nobody found.') }}</p>
                    </template>
                </div>

                <p class="rc-note">
                    {{ __('Click a person to message them. To make a group or a channel, tick the people and give it a name.') }}
                </p>

                <template x-if="$store.chat.abilities['create-channel']">
                    <div style="display: grid; gap: 14px; border-top: 1px solid var(--rc-line); padding-top: 16px">
                        <div class="rc-field">
                            <label for="rc-name">{{ __('Name') }}</label>
                            <input id="rc-name" type="text" x-model="form.name" placeholder="{{ __('maintenance, leasing-toronto…') }}">
                        </div>

                        <div class="rc-field">
                            <label for="rc-type">{{ __('Kind') }}</label>
                            <select id="rc-type" x-model="form.type">
                                <option value="team">{{ __('Channel — anyone added can join the conversation') }}</option>
                                <option value="messaging">{{ __('Group — just these people') }}</option>
                            </select>
                        </div>

                        <template x-if="error">
                            <p class="rc-error" style="padding: 0" x-text="error"></p>
                        </template>

                        <div class="rc-actions">
                            <button type="button" class="rc-button" @click="creating = false">{{ __('Cancel') }}</button>
                            <button type="button" class="rc-button rc-button--primary" @click="create()">{{ __('Create') }}</button>
                        </div>
                    </div>
                </template>

                <template x-if="! $store.chat.abilities['create-channel']">
                    <div class="rc-actions">
                        <button type="button" class="rc-button" @click="creating = false">{{ __('Close') }}</button>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
