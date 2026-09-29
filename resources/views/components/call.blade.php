{{--
    The call card: ringing out, ringing in, live — or the reason none of that
    happened.

    It sits in the dock, which is in the layout, so a call rings wherever
    somebody is working rather than only on the chat page. A refusal is shown
    here too: a card that disappears the moment something goes wrong leaves
    somebody pressing a button that seems to do nothing.
--}}
<div class="rc-call" x-data x-show="$store.calls?.state || $store.calls?.error" x-cloak>
    <div class="rc-call__card">
        <template x-if="$store.calls.state">
            <div class="rc-call__who">
                <span class="rc-avatar" style="background:#ffffff26;color:#fff">
                    <span x-text="$store.chat.initials($store.calls.title)"></span>
                </span>

                <div>
                    <strong x-text="$store.calls.title || '{{ __('Call') }}'"></strong>
                    <span
                        x-text="$store.calls.state === 'ringing-in'
                            ? '{{ __('Incoming call') }}'
                            : ($store.calls.state === 'ringing-out' ? '{{ __('Calling…') }}' : $store.calls.clock)"
                    ></span>
                </div>
            </div>
        </template>

        {{-- Said while it rings, not in a policy nobody read. --}}
        <template x-if="$store.calls.state && ($store.calls.recording || $store.calls.state !== 'live')">
            <p class="rc-call__note">{{ __('Calls are recorded.') }}</p>
        </template>

        <template x-if="$store.calls.error">
            <p class="rc-call__error" x-text="$store.calls.error"></p>
        </template>

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

            <template x-if="$store.calls.state">
                <button
                    type="button"
                    class="rc-call__button rc-call__button--stop"
                    @click="$store.calls.state === 'ringing-in' ? $store.calls.decline() : $store.calls.hangUp()"
                    x-text="$store.calls.state === 'ringing-in' ? '{{ __('Decline') }}' : '{{ __('Hang up') }}'"
                ></button>
            </template>

            <template x-if="! $store.calls.state">
                <button type="button" class="rc-call__button" @click="$store.calls.error = null">{{ __('Close') }}</button>
            </template>
        </div>
    </div>
</div>
