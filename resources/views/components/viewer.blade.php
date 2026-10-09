{{--
    A picture, full screen, over everything.

    **One of these for the whole of chat, and it lives in the dock.** The dock
    is in the layout and persisted across page changes, so a picture opened
    from a floating box, from the full chat page, or from a thread all arrive
    here — and all cover the page rather than being clipped by the
    three-hundred-pixel window they were opened from. A viewer per conversation
    would be a dozen of them, each the size of its own container.

    Three ways out, because somebody who wants a picture gone wants it gone:
    the button, the backdrop, and escape.
--}}
<div
    class="rc-viewer"
    x-data
    x-show="$store.chat.viewing"
    x-cloak
    @keydown.escape.window="$store.chat.unview()"
    @click.self="$store.chat.unview()"
>
    <template x-if="$store.chat.viewing">
        <div class="rc-viewer__frame">
            <header class="rc-viewer__head">
                <span class="rc-viewer__title" x-text="$store.chat.viewing.title"></span>

                <button
                    type="button"
                    class="rc-icon-button rc-icon-button--dark"
                    @click="$store.chat.save()"
                    title="{{ __('Download') }}"
                >
                    <x-chat::icon name="download" />
                </button>

                {{-- Only on your own, and only ever from here: deleting
                     somebody else's photograph out of a conversation is not
                     a thing a viewer should offer. --}}
                <template x-if="$store.chat.viewing.mine">
                    <button
                        type="button"
                        class="rc-icon-button rc-icon-button--dark"
                        @click="$store.chat.unsend()"
                        title="{{ __('Delete') }}"
                    >
                        <x-chat::icon name="trash" />
                    </button>
                </template>

                <button
                    type="button"
                    class="rc-icon-button rc-icon-button--dark"
                    @click="$store.chat.unview()"
                    title="{{ __('Close') }}"
                >
                    <x-chat::icon name="close" />
                </button>
            </header>

            {{-- The backdrop closes, so the area around the picture has to
                 close too — otherwise the only quiet strip on the screen is
                 the one place pressing does nothing. --}}
            <div class="rc-viewer__stage" @click.self="$store.chat.unview()">
                <img :src="$store.chat.viewing.url" :alt="$store.chat.viewing.title">
            </div>
        </div>
    </template>
</div>
