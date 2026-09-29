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

All three portals are on one server, so either way works:

```jsonc
// composer.json — the version-controlled way
"repositories": [
    { "type": "vcs", "url": "git@github.com:Abhisarchoudhary/chat-package.git" }
]
```

```jsonc
// or, while developing: one checked-out folder, no repository at all
"repositories": [
    { "type": "path", "url": "../chat-package", "options": { "symlink": true } }
]
```

```bash
composer require revun/chat
```

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
    { "type": "vcs", "url": "git@github.com:Abhisarchoudhary/chat-package.git" }
]
```

```bash
composer require revun/chat
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
{{-- the layout, so a conversation follows people across the portal --}}
<x-chat::dock />

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
php artisan chat:sync --limit=5     # a first look, then without the limit
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
