{{--
    One conversation, wherever it is being read.

    The dock's floating box and the page's main pane render the same thing, from
    the same Alpine component, because the day they stop doing that is the day a
    message looks different depending on where you saw it.
--}}
@props(['for' => '$store.chat.active', 'compact' => false])

{{--
    `for` is an Alpine expression, not a value: inside an x-for it is the loop
    variable, and on the page it is the store's active conversation. Quoting it
    here would have passed the word rather than the channel — which is exactly
    the bug this comment exists to stop being written again.
--}}
<div
    x-data="chatConversation(() => ({{ $for }}))"
    data-max-mb="{{ config('chat.files.max_megabytes', 25) }}"
    class="rc-conversation"
    style="display: flex; flex-direction: column; flex: 1; min-height: 0;"
>
    <div class="rc-messages" x-ref="list">
        <template x-for="message in messages" :key="message.id">
            <div :class="mine(message) ? 'rc-message rc-message--mine' : 'rc-message'">
                <template x-if="! mine(message) && ! {{ $compact ? 'true' : 'false' }}">
                    <span class="rc-avatar rc-avatar--sm">
                        <template x-if="message.user?.image"><img :src="message.user.image" :alt="message.user.name"></template>
                        <template x-if="! message.user?.image"><span x-text="$store.chat.initials(message.user?.name)"></span></template>
                    </span>
                </template>

                <div>
                    <template x-if="message.type === 'deleted'">
                        <div class="rc-bubble rc-message__deleted">{{ __('This message was deleted') }}</div>
                    </template>

                    <template x-if="message.type !== 'deleted'">
                        <div>
                            <template x-if="message.text">
                                <div class="rc-bubble" x-text="message.text"></div>
                            </template>

                            <template x-for="attachment in (message.attachments ?? [])" :key="attachment.asset_url ?? attachment.image_url">
                                <div class="rc-bubble rc-attachment">
                                    <template x-if="attachment.type === 'image'">
                                        <a :href="attachment.image_url" target="_blank" rel="noopener">
                                            <img :src="attachment.image_url" :alt="attachment.fallback ?? 'Image'">
                                        </a>
                                    </template>
                                    <template x-if="attachment.type !== 'image'">
                                        <a :href="attachment.asset_url" target="_blank" rel="noopener" x-text="attachment.title ?? 'File'"></a>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="rc-message__meta">
                        <span x-text="(! mine(message) ? (message.user?.name ?? '') + ' · ' : '') + when(message)"></span>
                        <template x-if="mine(message) && message.type !== 'deleted'">
                            <button type="button" class="rc-box__action" style="color: inherit" @click="remove(message)" title="{{ __('Delete') }}">×</button>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="messages.length === 0">
            <p class="rc-empty" style="padding: 20px">{{ __('No messages yet. Say something.') }}</p>
        </template>
    </div>

    <div class="rc-typing" x-text="typing.length ? typing.join(', ') + ' {{ __('is typing…') }}' : ''"></div>

    <template x-if="error">
        <p class="rc-error" x-text="error"></p>
    </template>

    <form class="rc-composer" @submit.prevent="send()">
        <label class="rc-clip" :title="'{{ __('Attach a file') }}'">
            <span x-text="uploading ? '…' : '📎'"></span>
            <input
                type="file"
                class="sr-only"
                style="display: none"
                accept="{{ implode(',', (array) config('chat.files.types', [])) }}"
                @change="attach($event)"
            >
        </label>

        <textarea
            x-model="text"
            rows="1"
            placeholder="{{ __('Write a message…') }}"
            @input="typingStarted()"
            @keydown.enter.prevent="$event.shiftKey ? (text += '\n') : send()"
        ></textarea>

        <button type="submit" class="rc-send" :disabled="sending || text.trim() === ''">{{ __('Send') }}</button>
    </form>
</div>
