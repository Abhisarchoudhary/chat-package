@props(['for' => '$store.chat.active', 'compact' => false])

{{--
    One conversation, wherever it is being read: the page's room and the dock's
    floating box render this same component, because the day they stop doing
    that is the day a message looks different depending on where you saw it.

    `for` is an Alpine expression, not a value — inside an x-for it is the loop
    variable, on the page it is the active conversation. Quoting it would pass
    the word rather than the channel.

    Messages are grouped the way Slack groups them: one avatar and name for a
    run of messages from the same person, the rest indented with the time on
    hover. Reading a conversation is reading who said what, and repeating a
    name nine times says nothing nine times.
--}}
<div
    x-data="chatConversation(() => ({{ $for }}))"
    data-max-mb="{{ config('chat.files.max_megabytes', 25) }}"
    class="rc-conversation"
    style="display: flex; flex-direction: column; flex: 1; min-height: 0;"
>
    <div class="rc-messages" x-ref="list">
        <template x-for="(message, index) in messages" :key="message.id">
            <div>
                <template x-if="opensDay(index)">
                    <div class="rc-day" x-text="dayOf(message)"></div>
                </template>

                <div :class="startsRun(index) ? 'rc-msg rc-msg--first' : 'rc-msg'">
                    <div class="rc-msg__gutter">
                        <template x-if="startsRun(index)">
                            <span class="rc-avatar">
                                <template x-if="message.user?.image"><img :src="message.user.image" :alt="message.user.name"></template>
                                <template x-if="! message.user?.image"><span x-text="$store.chat.initials(message.user?.name)"></span></template>
                            </span>
                        </template>

                        <template x-if="! startsRun(index)">
                            <div class="rc-msg__time" x-text="at(message)"></div>
                        </template>
                    </div>

                    <div class="rc-msg__body">
                        <template x-if="startsRun(index)">
                            <div class="rc-msg__who">
                                <span class="rc-msg__name" x-text="message.user?.name ?? '{{ __('Someone') }}'"></span>
                                <span class="rc-msg__at" x-text="at(message)"></span>
                            </div>
                        </template>

                        <template x-if="message.type === 'deleted'">
                            <div class="rc-msg__gone">{{ __('This message was deleted') }}</div>
                        </template>

                        <template x-if="message.type !== 'deleted'">
                            <div>
                                <template x-if="message.text">
                                    <div class="rc-msg__text" x-text="message.text"></div>
                                </template>

                                <template x-for="attachment in (message.attachments ?? [])" :key="attachment.asset_url ?? attachment.image_url">
                                    <div>
                                        <template x-if="attachment.type === 'image'">
                                            <a :href="attachment.image_url" target="_blank" rel="noopener">
                                                <img class="rc-shot" :src="attachment.image_url" :alt="attachment.fallback ?? 'Image'">
                                            </a>
                                        </template>
                                        <template x-if="attachment.type !== 'image'">
                                            <a class="rc-file" :href="attachment.asset_url" target="_blank" rel="noopener">
                                                <span>📄</span>
                                                <span x-text="attachment.title ?? '{{ __('File') }}'"></span>
                                            </a>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Only what this person may actually do to this message. --}}
                    <template x-if="mine(message) && message.type !== 'deleted'">
                        <div class="rc-msg__tools">
                            <button type="button" class="rc-msg__tool" @click="remove(message)" title="{{ __('Delete') }}">🗑</button>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="messages.length === 0">
            <div class="rc-empty">
                <div>
                    <strong>{{ __('Nothing said yet') }}</strong>
                    {{ __('Say something — they will see it straight away.') }}
                </div>
            </div>
        </template>
    </div>

    <div class="rc-typing" x-text="typing.length ? typing.join(', ') + ' {{ __('is typing…') }}' : ''"></div>

    <template x-if="error">
        <p class="rc-error" x-text="error"></p>
    </template>

    <form class="rc-composer" @submit.prevent="send()">
        <div class="rc-composer__box">
            <textarea
                x-model="text"
                rows="1"
                placeholder="{{ __('Write a message…') }}"
                @input="typingStarted(); grow($event.target)"
                @keydown.enter.prevent="$event.shiftKey ? (text += '\n') : send()"
            ></textarea>

            <div class="rc-composer__bar">
                <label class="rc-tool" title="{{ __('Attach a file') }}">
                    <span x-text="uploading ? '⏳' : '📎'"></span>
                    <input
                        type="file"
                        style="display: none"
                        accept="{{ implode(',', (array) config('chat.files.types', [])) }}"
                        @change="attach($event)"
                    >
                </label>

                <span class="rc-composer__hint">{{ __('Enter to send · Shift + Enter for a new line') }}</span>

                <button type="submit" class="rc-send" :disabled="sending || text.trim() === ''">{{ __('Send') }}</button>
            </div>
        </div>
    </form>
</div>
