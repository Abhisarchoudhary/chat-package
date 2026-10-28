<?php

namespace Revun\Chat\Contracts;

/**
 * A portal that can say something about its own people, beyond their name.
 *
 * Optional, like `RestrictsChat`: a portal that does not implement this loses
 * nothing — pressing a colleague still opens the conversation, which is what
 * every portal did before any of this existed.
 *
 * **Only about this portal's own people.** A directory is shared by four
 * portals and each of them holds a different half of a person: whether
 * somebody is at their desk, what hours they work today and whether they are
 * on leave are answers only the portal they work in has. Asked about anybody
 * else, `find()` answers null and no card is drawn rather than a card of
 * guesses — and nothing here ever reaches across to another portal to ask,
 * because that would be one portal's session deciding another's answer.
 */
interface DescribesPeople
{
    /**
     * What to show about this person, or null where there is nothing to say.
     *
     * The shape is the portal's own and is rendered as it arrives, so it
     * carries words rather than codes: *On annual leave until 3 Nov* rather
     * than a type and a date for a browser to make a sentence out of. A
     * translation belongs on the side that knows the language of the business.
     *
     * @return array<string, mixed>|null
     */
    public function card(ChatParticipant $person): ?array;
}
