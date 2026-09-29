{{--
    The call card: ringing out, ringing in, or live.

    It sits in the dock, which is in the layout, so a call rings wherever
    somebody is working rather than only on the chat page.
--}}
<div class="rc-call" x-data x-show="$store.calls?.state" x-cloak>
    <div class="rc-call__card">
        <div class="rc-call__who">
            <span class="rc-avatar" style="background:#ffffff26;color:#fff">
                <span x-text="$store.chat.initials($store.calls.title)"></span>
            </span>

            <div>
                <strong x-text="$store.calls.title || '{{ __('Call') }}'"></strong>
                <span
                    x-text="$store.calls.state === 'ringing-in'
                        ? '{{ __('Incoming call') }}'
                        : ($store.calls.state === 'ringing-out' ? '{{ __('Ringing…') }}' : $store.calls.clock)"
                ></span>
            </div>
        </div>

        {{-- Said while it rings, not in a policy nobody read. --}}
        <p class="rc-call__note" x-show="$store.calls.recording || $store.calls.state !== 'live'">
            {{ __('Calls are recorded.') }}
        </p>

        <div class="rc-call__actions">
            <template x-if="$store.calls.state === 'ringing-in'">
                <button type="button" class="rc-call__button rc-call__button--go" @click="$store.calls.accept()">
                    {{ __('Answer') }}
                </button>
            </template>

            <template x-if="$store.calls.state === 'live'">
                <button type="button" class="rc-call__button" @click="$store.calls.toggleMute()"
                        x-text="$store.calls.muted ? '{{ __('Unmute') }}' : '{{ __('Mute') }}'"></button>
            </template>

            <button
                type="button"
                class="rc-call__button rc-call__button--stop"
                @click="$store.calls.state === 'ringing-in' ? $store.calls.decline() : $store.calls.hangUp()"
                x-text="$store.calls.state === 'ringing-in' ? '{{ __('Decline') }}' : '{{ __('Hang up') }}'"
            ></button>
        </div>

        <template x-if="$store.calls.error">
            <p class="rc-error" x-text="$store.calls.error"></p>
        </template>
    </div>
</div>
