{{--
    A picture, over everything.

    **One of these for the whole of chat, and it lives in the dock**, which is
    in the layout and persisted across page changes. So a picture opened from a
    floating box, from the full chat page, or from a thread all arrive here —
    and all cover the page rather than being clipped by the window they were
    opened from.

    It is an ordinary overlay and not a modal `<dialog>`. `showModal()` would
    put it in the browser's top layer, above every z-index on the page, which
    is tempting — but it also makes everything behind it inert, and everything
    behind it includes the card with Answer on it. A photograph must not be
    able to stop somebody taking a call. The layering is done with numbers
    instead, and the call card is given a larger one.

    Escape is not bound here. It belongs to the dock's one keyboard handler,
    which closes the innermost thing first — two listeners on the same key
    would race, and whichever ran second would act on a viewer the first had
    already closed.
--}}
<div
    class="rc-viewer"
    x-data
    x-show="$store.chat.viewing"
    x-cloak
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

                {{-- Only on your own: deleting somebody else's photograph out
                     of a conversation is not a thing a viewer should offer. --}}
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

            {{-- The backdrop closes, so the space around the picture closes
                 too: otherwise the one quiet strip on the screen is the only
                 place where pressing does nothing. --}}
            <div class="rc-viewer__stage" @click.self="$store.chat.unview()">
                <img :src="$store.chat.viewing.url" :alt="$store.chat.viewing.title">
            </div>
        </div>
    </template>
</div>
