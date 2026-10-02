@props(['name', 'image' => 'null', 'online' => null, 'size' => null])

{{--
    Somebody's picture, with their initials underneath it.

    Underneath rather than instead of: the initials are always drawn and the
    photo sits on top, so a picture that cannot be fetched — a colleague in
    another portal whose photo is behind that portal's own sign-in — leaves
    initials rather than a broken-image icon. The error listener takes the
    failed image away and what was always there shows through.

    It is spelled `x-on:error` and not `@error`, because `@error` is one of
    Blade's own directives and Blade reaches the file first.

    Every argument is an Alpine expression, not a value: quoting one would pass
    the word rather than what it names.
--}}
<span class="rc-avatar{{ $size ? ' rc-avatar--'.$size : '' }}">
    <span x-text="$store.chat.initials({{ $name }})"></span>

    <template x-if="{{ $image }}">
        <img :src="{{ $image }}" alt="" x-on:error="$el.remove()">
    </template>

    {{-- Always drawn where presence is known at all, green or grey. Shown only
         when somebody is online, it says nothing about the other state: an
         empty corner reads as "not told" rather than as "not here". --}}
    @if ($online !== null)
        <span class="rc-dot" :class="{ 'rc-dot--off': ! ({{ $online }}) }"></span>
    @endif
</span>
