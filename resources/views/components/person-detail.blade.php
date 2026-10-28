@props(['detail'])

{{--
    What the portal can say about a colleague: whether they are at work, the
    hours they are working today, and the words about them.

    `detail` is an Alpine expression rather than a value — `who` in a
    conversation's header — so this stays one file when a second place needs
    the same four facts under a different name.

    Nothing here is assembled from a code. The portal sends the sentence,
    because only it knows whether a quiet hour is a break, a day off or leave.
--}}

<template x-if="{{ $detail }}?.status">
    <span class="rc-who__state" :class="{{ $detail }}.status.in && 'rc-who__state--in'" x-text="{{ $detail }}.status.label"></span>
</template>

<template x-if="{{ $detail }}?.shift">
    <p class="rc-who__fact">
        <span class="rc-who__factName">{{ __('Today') }}</span>
        <span x-text="{{ $detail }}.shift"></span>
    </p>
</template>

<template x-if="{{ $detail }}?.leave">
    <p class="rc-who__fact">
        <span class="rc-who__factName">{{ __('Leave') }}</span>
        <span x-text="{{ $detail }}.leave"></span>
    </p>
</template>

{{-- The words about them, and under those the two facts that are read fresh
     every time this opens. They are kept apart because only one of them stays
     true: a manager written into prose a year ago is a card naming somebody
     this person no longer reports to. --}}
<template x-if="{{ $detail }}?.bio || {{ $detail }}?.facts">
    <div class="rc-who__about">
        <template x-if="{{ $detail }}?.bio">
            <p class="rc-who__bio" x-text="{{ $detail }}.bio"></p>
        </template>

        <template x-if="{{ $detail }}?.facts">
            <p class="rc-who__facts" x-text="{{ $detail }}.facts"></p>
        </template>
    </div>
</template>
