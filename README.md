# revun/chat

Chat for the Royal York, MSR and candidate portals: one Stream application, one
identity, one archive. Installed by all three; the code that decides who a
person is exists once.

The plan this is built from lives in the Royal York portal at `docs/CHAT.md`.

---

## What it does, and what it deliberately does not

**Does:** derives a person's chat identity from their email address, keeps
Stream's copy of them in step, mints the token their browser connects with, and
(in one portal) archives every message so the record outlives the vendor's
retention.

**Does not:** decide who may create a channel, start a call or hear a recording.
Those are permissions in each portal's own registry, where a super admin can see
and change them. Stream's own permissions are set so an ordinary user's token
cannot do those things directly — a rule that lives in two places eventually
disagrees with itself, and the copy people can change has to be the one that
decides.

---

## Installing it

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Abhisarchoudhary/chat-package.git" }
]
```

```bash
composer require revun/chat:dev-main
```

The repository is public, so nothing needs a key or a token. `dev-main` is
named explicitly because the portals keep `minimum-stability: stable`; the
commit is pinned in `composer.lock` like any other dependency, so a deploy
installs exactly what was tested.

**A path repository is not an alternative.** `{"type": "path", "url":
"../chat-package"}` writes that path into the lock file, and a lock file that
points at a sibling folder installs on the machine that has one and nowhere
else — the server does not, and `composer install` fails there before anything
else can go wrong. Develop against the repository and push.

Then in `.env`:

```bash
STREAM_KEY=
STREAM_SECRET=
STREAM_APP_ID=
CHAT_ORGANISATION=rypm          # rypm | otr | crp — which business this portal is

# Royal York only: the portal that keeps the record and receives the webhook.
CHAT_ARCHIVE=true
CHAT_WEBHOOK_SECRET=

# Optional: who may chat here, and where to look them up.
CHAT_DIRECTORY=
CHAT_MODEL=
```

The same key, secret and app id go in **all three** portals. That is what puts
everyone in one directory; nothing else is created per portal.

And on whatever model this portal keeps its people in:

```php
use Revun\Chat\Contracts\ChatParticipant;
use Revun\Chat\TalksInChat;

class User extends Authenticatable implements ChatParticipant
{
    use TalksInChat;

    // Override only what this portal keeps somewhere else:
    public function chatDepartment(): ?string
    {
        return $this->department?->name;
    }
}
```

### Who may chat is the portal's answer, not a table name

The three portals do not agree on where their people are — Royal York has
`users`, the recruitment portal has recruiters and must keep applicants out,
MSR has its own arrangement — and it is never only a table anyway: it is a
table with conditions on it. Active. Not a client. In a role that was given
chat.

So the package asks. A portal writes a dozen lines and names it in
`config/chat.php`:

```php
'directory' => App\Modules\Chat\Employees::class,
```

```php
final class Employees implements ParticipantDirectory
{
    public function current(): ?ChatParticipant
    {
        $user = auth()->user();

        return $user?->isActive() && $user->can('chat.use') ? $user : null;
    }

    public function all(): iterable
    {
        return User::query()->active()->cursor();      // whatever "may chat" means here
    }

    public function find(string $streamId): ?ChatParticipant
    {
        return User::query()->get()->first(fn ($u) => Identity::forEmail($u->email) === $streamId);
    }
}
```

Leave it unset and the package uses the signed-in user, which is right for a
portal where everybody active may chat. `current()` returning null is the whole
of "this person may not chat" — one place to change it, in the portal, next to
every other rule about who may do what.

```bash
php artisan chat:sync            # fill the directory; nightly afterwards
php artisan chat:sync --limit=5  # a first look before letting it loose
```

---

## Adding the other two portals

Nothing new is created for a portal — no second Stream application, no second
directory. It installs the package, answers who its people are, and its users
appear in the same address book as Royal York's.

**1. Install it.**

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Abhisarchoudhary/chat-package.git" }
]
```

```bash
composer require revun/chat:dev-main
```

**2. The same three Stream values, and its own name.**

```bash
STREAM_KEY=            # identical in all three portals
STREAM_SECRET=         # identical
STREAM_APP_ID=         # identical
CHAT_ORGANISATION=otr  # or crp — this is the only line that differs
CHAT_ARCHIVE=false     # Royal York keeps the record; nobody else writes to it
```

**3. Say who may chat.** This is the only code a portal writes, because the
three do not agree on where their people live — MSR has its staff, the
recruitment portal has recruiters and must keep applicants out.

```php
// app/Chat/Employees.php
final class Employees implements ParticipantDirectory
{
    public function current(): ?ChatParticipant
    {
        $user = auth()->user();

        return $user?->is_active && $user->canChat() ? $user : null;   // your rule
    }

    public function may(string $ability): bool
    {
        // create-channel, manage-channels, call, read-archive,
        // play-recording, purge — answered in this portal's permissions.
        return $this->current() !== null && $ability !== 'purge';
    }

    public function all(): iterable
    {
        return Staff::query()->active()->cursor();
    }

    public function find(string $streamId): ?ChatParticipant
    {
        return Staff::query()->get()->first(fn ($s) => Identity::forEmail($s->email) === $streamId);
    }
}
```

```php
// config/chat.php (published), or a service provider
'directory' => App\Chat\Employees::class,
```

**4. The model implements `ChatParticipant`** — `use TalksInChat` answers most
of it from what a Laravel user already has; override `chatDepartment()` and
anything that portal keeps elsewhere.

**5. Mount the interface.**

```blade
{{-- The layout, so a conversation follows people across the portal.
     `@persist` is not optional where the portal uses `wire:navigate`: without
     it every page change rebuilds the dock, which means the launcher is not on
     the screen until the new page wakes Alpine up, and whatever somebody had
     half-written in an open box is gone. --}}
@persist('chat')
    <x-chat::dock />
@endpersist

{{-- a page of its own --}}
<x-chat::page />
```

```js
// resources/js/app.js
import { registerChat } from '../../vendor/revun/chat/resources/js/chat';
import { registerCalls } from '../../vendor/revun/chat/resources/js/calls';

document.addEventListener('alpine:init', () => {
    registerChat(window.Alpine);
    registerCalls(window.Alpine);
});
```

```css
/* resources/css/app.css */
@import '../../vendor/revun/chat/resources/css/chat.css';
```

```bash
npm install stream-chat @stream-io/video-client
npm run build                       # the styles and the components live in vendor/
php artisan chat:sync --limit=5     # a first look, then without the limit
```

**Afterwards, whenever this package changes**, pull the new commit into that
portal and rebuild — the interface is compiled from `vendor/revun/chat`, so a
`composer update` without a build leaves the old one on the screen:

```bash
composer update revun/chat && npm run build
```

**What that portal does not get, on purpose:** the archive and the webhook.
Both belong to the one portal that keeps the record, and three writers would be
three archives that disagree about the same conversation.

---

## The archive

One portal keeps the record: `CHAT_ARCHIVE=true` there and nowhere else, because
three writers would be three archives that disagree. Stream posts every message,
edit, deletion and membership change to `/chat/webhook/<secret>`, signed with the
API secret — an endpoint that cannot be behind a login and an archive that
believed anybody who posted to it would be an archive of whatever they felt like
writing.

### Pointing Stream at it

In the Stream dashboard, one app has **one list of events** — Chat, Video and
moderation all in it, grouped into cards with a *Select All* on each. There is
no "chat only" switch to look for, and the `call.*` events that record a call
are in that same list rather than somewhere separate.

The URL is the endpoint plus the secret that is its whole authentication:

```
https://<domain>/chat/webhook/<CHAT_WEBHOOK_SECRET>
```

**Select every event.** This endpoint reads the nine it keeps and ignores the
rest, and Stream only sends newly introduced event types to a hook that asked
for everything — picking a list now means going back to the dashboard the day
Stream adds an event we want. If a shorter list is wanted anyway, these are the
ones that are read:

| Card | Events |
| --- | --- |
| `message` | `message.new`, `message.updated`, `message.deleted` |
| `channel` | `channel.created`, `channel.updated`, `channel.deleted` |
| `member` | `member.added`, `member.updated`, `member.removed` |
| `call` | `call.session_started`, `call.session_ended`, `call.recording_ready` |

**There is nothing to set up on a laptop.** Stream posts from the internet to a
public HTTPS address; it cannot reach `localhost`, so this is a step for the
server and chat works locally without it. What the webhook would have written,
`chat:sweep` writes an hour later anyway.

**Webhooks are not the guarantee.** A deploy, a restart or a network minute
loses events, so `chat:sweep` re-reads each conversation hourly and backfills
what is missing. It says how much it filled, because that number is what decides
whether the vendor's own retention may be switched on: while it is still finding
gaps they are recoverable, and the day Stream starts deleting its copy they are
not.

```bash
php artisan chat:sweep              # hourly; --days and --channels widen it
```

A person deleting their own message hides it from the conversation and keeps the
words here, with who removed them. Erasing them for good is a different act, a
different column (`purged_at`) and a permission the portal decides.

## Calls

Audio today, video when the same component is asked for it. The call id is
derived from the conversation, so two people pressing call land in one call
rather than two beside each other, and the card says the call is recorded while
it rings rather than in a policy nobody read.

**A ringing call is not a call yet.** The caller joins when somebody accepts
and not before — which is what keeps the microphone off, keeps the recording
from starting into an empty line, and makes the card say "Calling…" instead of
counting a duration nobody is on the other end of. It matters most at the
hanging-up end: Stream's `leave()` does not stop a call ringing, so cancelling
an unanswered call is `leave({ reject: true, reason: 'cancel' })` and leaving a
live one is `leave()`. The two are not interchangeable, and using the second
for the first leaves somebody's phone ringing after the person who rang it has
walked away.

A call that nobody answers gives up after forty-five seconds rather than
ringing until the tab is closed. That timeout is the floor and not the
mechanism: a decline has to end the call when it is declined, and being told
"no answer" forty-five seconds after somebody pressed Decline is both late and
untrue.

Which takes two things, because neither can be relied on alone. The refusal
has to leave the browser — `leave({ reject: true })` wrapped so that a version
which refuses it falls back to `reject()`, rather than a `finally` that takes
the card down and lets the message go nowhere. And the caller has to hear it,
which is `call.state.session$` where the documentation points and the
`call.rejected` event where the SDK sends one; whichever arrives first ends
the call, and the second finds it already over.

Who was rung is the list the server returned, not `call.state.members`. Local
state is filled in when the vendor gets round to it and a moment after
`getOrCreate` it can still be empty — so "has everybody declined" became a
question about nobody, and a decline did nothing at all.

---

## Sounds

Chat makes a noise, because a badge somebody is not looking at is not a
notification. A two-note blip for a message that arrives in a conversation
nobody is reading, a telephone pattern for an incoming call, and a quieter one
back to the caller while it rings.

**Synthesised, not played.** `resources/js/sound.js` builds the tones with the
Web Audio API rather than shipping an mp3 — a package consumed out of
`vendor/` would otherwise have to publish audio files into three `public/`
directories and keep all three in step, and the failure mode of getting that
wrong is a 404 that is silent in exactly the way a notification must not be.
Nothing to publish cannot fall out of step, and there is nothing to add to a
portal: `registerChat` pulls it in.

**A browser will not make a sound until somebody has touched the page**, which
is the rule rather than a bug to route around. The first click anywhere in the
portal wakes the audio context; until then the badge on the bar does the
telling on its own.

The bell in the panel header turns it off, per machine, in `localStorage` —
because somebody in an open-plan office wants it off for the afternoon and the
same person at home wants it on.

**A new message does not open a window.** It did once, and the window arrived
in front of whatever somebody was in the middle of; worse, a conversation on
screen reports itself read, so opening it cleared the badge and told the sender
their message had been read when nobody had read it. Saying a message is here
is the job. Deciding what somebody should be looking at is not.

---

## Links, pictures and previews

**An address somebody types becomes one they can press**, opening in a new tab
so the conversation is still there when they come back. The escaping happens
before the linking and not after — the message is turned into entities first
and anchors are wrapped round what is left, because the other order means the
escaping eats the anchors, and no escaping at all means a chat message is a
place to put a script tag. Only `http`, `https` and a bare `www.` become
links: `javascript:` is an address too, and a message box is exactly where
somebody would try one.

**A scraped link is a reference, not a photograph.** Stream reads any address
in a message and hands back what the page says about itself, including its
`og:image` — which for most sites is the company logo. Drawn as an attachment
that is a three-hundred-pixel mark sitting under a one-line message. It is
drawn as a card instead: a forty-pixel thumbnail, the title, and the host, the
whole of it a link to the page.

**A picture opens over the page, not in a tab.** Leaving chat to look at
something somebody sent, and then finding the way back, is not looking at it.
There is one viewer for the whole of chat and it lives in the dock, which is
what lets a picture opened from a floating box cover the screen instead of
being clipped by a three-hundred-pixel window. It closes on the button, on the
backdrop and on escape — and escape stops there rather than also shutting the
panel behind it. Saving is a fetch and a blob rather than a `download`
attribute, which browsers ignore across origins: the files are on Stream's
CDN, so the attribute alone would turn Save into Leave The Page.

**Two numbers, and they only mean anything together:** the picture sits above
the chat panel, and the call card sits above the picture. A photograph must
never be the reason somebody cannot press Answer.

They are larger than they look as though they need to be. A portal raises the
dock with `.rc-chat .rc-dock`, a two-class selector that beats this package's
own one-class rule, and one of them puts it at 150 — so the sixty, seventy-
eight and eighty chat used to layer itself with are all underneath it. The
picture opened below the panel, and the call card has been hiding behind the
expanded panel for as long as both have existed. The picker that starts a conversation was under it
too, so choosing somebody to write to could happen behind the panel that
offered to start it. `--rc-viewer-layer`, `--rc-call-layer` and
`--rc-modal-layer` are there so a portal can reach over them without starting
another specificity war.

A modal `<dialog>` would side-step the argument entirely — `showModal()` puts
an element in the browser's top layer, above every stacking context whatever
anybody's stylesheet says — and it was written that way first. It also makes
everything behind it inert, the call card included, which is a worse problem
than the one it solves.

---

## The identity rule

A person is their email address, everywhere — the same rule the three portals
already use for signing in. A Stream id cannot contain a dot, so the id is
derived from the address:

```php
Identity::forEmail('priya@royalyorkpm.com');   // u_9f2c…  (30 hex characters)
```

Three codebases, nothing shared and no call to make, arrive at the same id for
the same person. That is what makes one account across three portals work — and
it is why `Identity` lives in one package rather than being copied into three.

---

## Environments

One Stream application per environment, not per portal:

| App | Used by |
| --- | --- |
| dev | every developer machine and the test suites, all three portals |
| production | the three production portals |

A test suite creates users by the hundred. Pointed at the production app, every
one of them joins the real company directory and their messages land in the
history a super admin reads.

Every archived row records which app it came from, because an app can be
replaced — and the day it is, conversations from the old one and the new one are
otherwise indistinguishable in the same table.
