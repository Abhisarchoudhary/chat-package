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
```

The same key, secret and app id go in **all three** portals. That is what puts
everyone in one directory; nothing else is created per portal.

And on the User model:

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
