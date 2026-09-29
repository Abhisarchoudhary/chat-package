{{--
    The page somebody goes to when the dock is not enough: every conversation
    in one place, and where channels are made and managed.
--}}
<div class="rc-page" x-data="chatPage()" x-cloak>
    <aside class="rc-rail">
        <div class="rc-rail__head">
            <input type="search" class="rc-panel__search" x-model="search" placeholder="{{ __('Search') }}">

            <div class="rc-tabs">
                <button type="button" class="rc-tab" :class="tab === 'all' && 'rc-tab--on'" @click="tab = 'all'">{{ __('All') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'unread' && 'rc-tab--on'" @click="tab = 'unread'">{{ __('Unread') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'channels' && 'rc-tab--on'" @click="tab = 'channels'">{{ __('Channels') }}</button>
                <button type="button" class="rc-tab" :class="tab === 'direct' && 'rc-tab--on'" @click="tab = 'direct'">{{ __('Direct') }}</button>
            </div>

            <button type="button" class="rc-button rc-button--primary" @click="creating = true; loadPeople()">
                {{ __('New conversation') }}
            </button>
        </div>

        <div class="rc-rail__list">
            <template x-for="channel in conversations" :key="channel.cid">
                <button type="button" class="rc-row" :class="$store.chat.active === channel.cid && 'rc-row--on'" @click="open(channel.cid)">
                    <span class="rc-avatar">
                        <template x-if="$store.chat.image(channel)"><img :src="$store.chat.image(channel)" alt=""></template>
                        <template x-if="! $store.chat.image(channel)"><span x-text="$store.chat.initials($store.chat.title(channel))"></span></template>
                    </span>

                    <span class="rc-row__body">
                        <span class="rc-row__title" x-text="$store.chat.title(channel)"></span>
                        <span class="rc-row__preview" x-text="channel.type === 'team' ? '{{ __('Channel') }} · ' + Object.keys(channel.state?.members ?? {}).length + ' {{ __('people') }}' : '{{ __('Direct message') }}'"></span>
                    </span>

                    <template x-if="channel.countUnread() > 0">
                        <span class="rc-badge rc-badge--quiet" x-text="channel.countUnread()"></span>
                    </template>
                </button>
            </template>

            <template x-if="$store.chat.ready && conversations.length === 0">
                <p class="rc-empty" style="padding: 24px">{{ __('Nothing here yet.') }}</p>
            </template>

            <template x-if="! $store.chat.ready && ! $store.chat.failed">
                <p class="rc-empty" style="padding: 24px">{{ __('Connecting…') }}</p>
            </template>

            <template x-if="$store.chat.failed">
                <p class="rc-empty" style="padding: 24px">{{ __('Chat is not available on this account.') }}</p>
            </template>
        </div>
    </aside>

    <section class="rc-main">
        <template x-if="$store.chat.active && $store.chat.channelFor($store.chat.active)">
            <div style="display: flex; flex-direction: column; flex: 1; min-height: 0">
                <header class="rc-main__head">
                    <span class="rc-avatar">
                        <template x-if="$store.chat.image($store.chat.channelFor($store.chat.active))">
                            <img :src="$store.chat.image($store.chat.channelFor($store.chat.active))" alt="">
                        </template>
                        <template x-if="! $store.chat.image($store.chat.channelFor($store.chat.active))">
                            <span x-text="$store.chat.initials($store.chat.title($store.chat.channelFor($store.chat.active)))"></span>
                        </template>
                    </span>

                    <span class="rc-main__title">
                        <strong x-text="$store.chat.title($store.chat.channelFor($store.chat.active))"></strong>
                        <span x-text="Object.values($store.chat.channelFor($store.chat.active).state?.members ?? {}).map(m => m.user?.name ?? m.user_id).join(', ')"></span>
                    </span>

                    <button
                        type="button"
                        class="rc-button"
                        @click="$store.chat.openBox($store.chat.active)"
                        title="{{ __('Open this in a floating box') }}"
                    >{{ __('Pop out') }}</button>

                    <template x-if="$store.chat.channelFor($store.chat.active).type === 'team'">
                        <button type="button" class="rc-button" @click="creating = true; loadPeople()">{{ __('Add people') }}</button>
                    </template>
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
                    <p><strong>{{ __('Pick a conversation') }}</strong></p>
                    <p style="margin-top: 6px">{{ __('Or start a new one — a person, a group, or a channel your team can join.') }}</p>
                </div>
            </div>
        </template>
    </section>

    {{-- New conversation: a person to message, or a channel to make. --}}
    <template x-if="creating">
        <div class="rc-modal" @keydown.escape.window="creating = false">
            <div class="rc-modal__card" @click.outside="creating = false">
                <h2 style="font-size: 19px; font-weight: 700">{{ __('New conversation') }}</h2>

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
                                    </span>

                                    <span class="rc-person__name">
                                        <span style="font-weight: 600; color: var(--rc-text)" x-text="person.name"></span>
                                        <span x-text="[person.department, person.email].filter(Boolean).join(' · ')"></span>
                                    </span>

                                    <template x-if="chosen.includes(person.id)"><span style="color: var(--rc-primary)">✓</span></template>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="Object.keys(people).length === 0">
                        <p style="padding: 16px; color: var(--rc-muted); font-size: 13px">{{ __('Nobody found.') }}</p>
                    </template>
                </div>

                <p style="font-size: 12px; color: var(--rc-muted)">
                    {{ __('Click a person to message them. To make a group or a channel, tick the people and give it a name.') }}
                </p>

                <template x-if="$store.chat.abilities['create-channel']">
                    <div style="display: grid; gap: 12px; border-top: 1px solid var(--rc-line); padding-top: 14px">
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
                            <p class="rc-error" x-text="error"></p>
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
