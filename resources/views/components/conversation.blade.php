@props(['for' => '$store.chat.active'])

{{--
    One conversation, wherever it is read: the page's room and the dock's
    floating box render this, so a message cannot start looking different
    depending on where somebody saw it.

    `for` is an Alpine expression, not a value — inside an x-for it is the loop
    variable. Quoting it would pass the word rather than the conversation.
--}}
<div
    x-data="chatConversation(() => ({{ $for }}))"
    data-max-mb="{{ config('chat.files.max_megabytes', 25) }}"
    class="rc-talk"
>
    <div class="rc-messages" x-ref="list">
        <template x-for="(message, index) in messages" :key="message.id">
            <div>
                <template x-if="opensDay(index)">
                    <div class="rc-day"><span x-text="dayOf(message)"></span></div>
                </template>

                <div :class="startsRun(index) ? 'rc-msg rc-msg--first' : 'rc-msg'">
                    <div class="rc-msg__gutter">
                        <template x-if="startsRun(index)">
                            <x-chat::avatar name="message.userName" image="message.userImage" />
                        </template>

                        <template x-if="! startsRun(index)">
                            <span class="rc-msg__time" x-text="at(message)"></span>
                        </template>
                    </div>

                    <div class="rc-msg__body">
                        <template x-if="startsRun(index)">
                            <div class="rc-msg__who">
                                <span class="rc-msg__name" x-text="message.userName"></span>
                                <span class="rc-msg__at" x-text="at(message)"></span>
                            </div>
                        </template>

                        <template x-if="message.text">
                            <div class="rc-msg__text" x-text="message.text"></div>
                        </template>

                        <template x-if="message.replies > 0">
                            <button
                                type="button"
                                class="rc-msg__thread"
                                @click="$store.chat.openThread(cid, message.id)"
                            >
                                <x-chat::icon name="thread" />
                                <span x-text="message.replies === 1 ? '1 {{ __('reply') }}' : message.replies + ' {{ __('replies') }}'"></span>
                            </button>
                        </template>

                        <template x-for="file in message.attachments" :key="file.url">
                            <div>
                                <template x-if="file.kind === 'image'">
                                    <a :href="file.url" target="_blank" rel="noopener"><img class="rc-shot" :src="file.url" :alt="file.title"></a>
                                </template>

                                <template x-if="file.kind !== 'image'">
                                    <a class="rc-file" :href="file.url" target="_blank" rel="noopener">
                                        <x-chat::icon name="file" />
                                        <span x-text="file.title"></span>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>

                    <template x-if="! message.deleted">
                        <div class="rc-msg__tools">
                            <button
                                type="button"
                                class="rc-icon-button rc-icon-button--sm"
                                @click="$store.chat.openThread(cid, message.id)"
                                title="{{ __('Reply in thread') }}"
                            >
                                <x-chat::icon name="reply" />
                            </button>

                            <template x-if="message.mine">
                                <button type="button" class="rc-icon-button rc-icon-button--sm" @click="remove(message)" title="{{ __('Delete') }}">
                                    <x-chat::icon name="trash" />
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="messages.length === 0">
            <div class="rc-empty">
                <div>
                    <x-chat::icon name="message" size="26" />
                    <strong>{{ __('No messages yet') }}</strong>
                    {{ __('Say something — they will see it straight away.') }}
                </div>
            </div>
        </template>
    </div>

    {{-- Where your last one got to, and who is writing back. One strip for
         both, because they are the same question asked from either end and
         two strips would make the composer jump by a line. --}}
    <div class="rc-foot">
        <span class="rc-typing" x-text="typing.length ? typing.join(', ') + ' {{ __('is typing…') }}' : ''"></span>
        {{-- One tick sent, two delivered, two in colour read — the shape
             everybody already knows, and it costs a glyph rather than a word
             in a strip that has room for neither. --}}
        <span
            class="rc-receipt"
            :class="receipt === 'read' && 'rc-receipt--read'"
            x-show="! typing.length && receipt"
            :title="{ sent: '{{ __('Sent') }}', delivered: '{{ __('Delivered') }}', read: '{{ __('Read') }}' }[receipt]"
        >
            <template x-if="receipt === 'sent'"><x-chat::icon name="tick" /></template>
            <template x-if="receipt !== 'sent'"><x-chat::icon name="tick-double" /></template>
        </span>
    </div>

    <template x-if="error">
        <p class="rc-error" x-text="error"></p>
    </template>

    <form class="rc-composer" @submit.prevent="send()">
        <div class="rc-composer__box">
            <textarea
                x-ref="field"
                x-model="text"
                rows="1"
                placeholder="{{ __('Write a message…') }}"
                @input="typingStarted(); grow($event.target)"
                @keydown.enter.prevent="$event.shiftKey ? (text += '\n') : send()"
            ></textarea>

            <div class="rc-composer__bar">
                <label class="rc-icon-button" title="{{ __('Attach a file') }}">
                    <x-chat::icon name="paperclip" />
                    <input
                        type="file"
                        style="display: none"
                        accept="{{ implode(',', (array) config('chat.files.types', [])) }}"
                        @change="attach($event)"
                    >
                </label>

                {{-- The key, not the word for it. Drawn rather than typed as
                     ⏎ for the same reason every other glyph here is drawn:
                     on a machine without a font carrying it, it is a box. --}}
                <span class="rc-composer__hint">
                    <template x-if="uploading">
                        <span>{{ __('Uploading…') }}</span>
                    </template>

                    <template x-if="! uploading">
                        <span class="rc-composer__keys">
                            {{ __('Shift') }} +
                            <x-chat::icon name="enter" size="13" />
                            {{ __('for a new line') }}
                        </span>
                    </template>
                </span>

                <button type="submit" class="rc-icon-button rc-icon-button--send" :disabled="sending || text.trim() === ''" title="{{ __('Send') }}">
                    <x-chat::icon name="send" />
                </button>
            </div>
        </div>
    </form>
</div>
