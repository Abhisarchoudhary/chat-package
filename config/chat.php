<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The Stream application
    |--------------------------------------------------------------------------
    |
    | One application for all three portals — two people can only talk if they
    | are users of the same one. The same key, secret and app id go in every
    | portal's .env; nothing else is needed to put them in the same room.
    |
    | One application per environment, though: a test suite that creates users
    | by the hundred would otherwise join the real company directory, and its
    | messages would land in the history a super admin reads.
    |
    */

    'key' => env('STREAM_KEY'),
    'secret' => env('STREAM_SECRET'),
    'app_id' => env('STREAM_APP_ID'),

    /*
     * Which business this portal is, written on every user it syncs.
     *
     * It is how the directory groups people and how a channel says where it
     * came from. A person who works for two of them ends up with both, because
     * each portal adds itself without touching what the others wrote.
     */
    'organisation' => env('CHAT_ORGANISATION', 'rypm'),

    'organisations' => [
        'rypm' => 'Royal York Property Management',
        'otr' => 'MSR',
        'crp' => 'Recruitment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Who this portal's chat people are
    |--------------------------------------------------------------------------
    |
    | Not a table name — a small class in the portal that answers "who may
    | chat", because it is never only a table. Royal York keeps employees in
    | `users`; the recruitment portal has recruiters and must not let applicants
    | in; MSR has its own arrangement. And each of them will grow a condition:
    | active, in a role that was given chat, not a client.
    |
    | Leave it null and the package uses the signed-in user where the user model
    | implements ChatParticipant. Set `model` as well and `chat:sync` and the
    | audit page can look people up.
    |
    */

    'directory' => env('CHAT_DIRECTORY'),

    'model' => env('CHAT_MODEL'),

    /*
     * How many people the directory will show at most.
     *
     * Stream answers a hundred users to a request and no more, so this is read
     * a page at a time until it is reached — the number is here because the
     * cost of raising it is requests, and a portal with four thousand people
     * knows better than the package whether it wants forty of them in front of
     * somebody looking for a colleague, or whether that is what the search box
     * is for.
     */
    'directory_limit' => (int) env('CHAT_DIRECTORY_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | The archive
    |--------------------------------------------------------------------------
    |
    | Stream carries the conversation; we keep the record. One portal writes it
    | — the one that receives the webhook — and the others leave it alone, so
    | there is one archive rather than three disagreeing ones.
    |
    | Switched on in Royal York and off in the other two. Where it is off, the
    | package still mints tokens and syncs users; it just does not listen.
    |
    */

    'archive' => [
        'enabled' => (bool) env('CHAT_ARCHIVE', false),

        /* Null means this portal's own database. */
        'connection' => env('CHAT_ARCHIVE_CONNECTION'),

        /*
         * The Stream app the rows came from.
         *
         * Written on every archived row because an app can be replaced — the
         * plan is to throw the first one away once it works — and the day that
         * happens, test conversations and real ones are otherwise
         * indistinguishable in the same table.
         */
        'app' => env('STREAM_APP_ID'),

        /*
         * How long Stream keeps a message before it is deleted there for cost.
         *
         * Nothing here enforces it — it is set on the Stream app — but the
         * archive needs to know, because past this age we are the only copy and
         * a gap can no longer be repaired from the vendor. The reconciliation
         * sweep refuses to stay quiet about holes inside this window.
         */
        'vendor_retention_days' => (int) env('CHAT_VENDOR_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Enforced in three places on purpose: the composer, so somebody is told
    | before they wait for an upload; the Stream app settings, because the
    | browser is where a limit is bypassed; and the webhook, which is the only
    | one of the three that cannot be talked out of it.
    |
    */

    'files' => [
        'max_megabytes' => (int) env('CHAT_MAX_FILE_MB', 25),

        'types' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'text/plain', 'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calls
    |--------------------------------------------------------------------------
    |
    | Audio today, video when the same component is asked for it. Every call is
    | recorded and the card says so while it rings — a recording people find
    | out about afterwards is a different thing entirely.
    |
    */

    'calls' => [
        'record' => (bool) env('CHAT_RECORD_CALLS', true),

        /*
         * Where a finished recording is kept.
         *
         * Stream holds it for a while and then does not; a call worth recording
         * is worth keeping, so it is copied into our own bucket under this
         * portal's prefix — beside the phone recordings, and apart from every
         * other application writing to the same bucket.
         */
        'disk' => env('CHAT_RECORDINGS_DISK', 's3'),

        'prefix' => trim((string) env('CHAT_RECORDINGS_PREFIX', 'royalyork/chat-calls'), '/'),

        /*
         * And the words read off it, the same way the phone calls are read.
         *
         * `worker` hands the bucket key to our own transcription service, which
         * answers later with speakers and times; `openai` sends the audio to
         * the paid endpoint and answers in the same request. Either way the
         * transcript lands on `chat_calls` and is read behind the same
         * permission as hearing the recording.
         */
        'transcription' => [
            'enabled' => (bool) env('CHAT_TRANSCRIPTION_ENABLED', false),

            'driver' => env('CHAT_TRANSCRIPTION_DRIVER', env('CALL_TRANSCRIPTION_DRIVER', 'worker')),

            'max_megabytes' => (int) env('CHAT_TRANSCRIPTION_MAX_MB', 24),

            'summarise' => (bool) env('CHAT_TRANSCRIPTION_SUMMARISE', true),

            /*
             * The same worker the phone recordings go to, with its own return
             * address: a chat call and a phone call can both be number seven,
             * and one webhook answering for both would write the wrong words on
             * the wrong conversation.
             */
            'worker' => [
                'url' => env('TRANSCRIPTION_WORKER_URL'),
                'key' => env('TRANSCRIPTION_WORKER_KEY'),
                'secret' => env('TRANSCRIPTION_WEBHOOK_SECRET'),
                'timeout' => (int) env('TRANSCRIPTION_WORKER_TIMEOUT', 20),
                'retry_after_hours' => (int) env('TRANSCRIPTION_WORKER_RETRY_HOURS', 6),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'prefix' => 'chat',

        /* The portal's own middleware; the token endpoint is not public. */
        'middleware' => ['web', 'auth'],

        /*
         * The webhook cannot be behind a login — Stream has no session — so a
         * secret in the path is its authentication, exactly as the RingCentral
         * one works. Empty leaves the endpoint closed.
         */
        'webhook_secret' => env('CHAT_WEBHOOK_SECRET'),
    ],

];
