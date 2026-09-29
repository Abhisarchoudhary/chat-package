<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Revun\Chat\Archive\Archive;
use Revun\Chat\Stream;

/**
 * Stream telling us what happened, so the record can keep it.
 *
 * **Signed, because the body is a private conversation.** Stream signs the raw
 * payload with the API secret; this endpoint cannot sit behind a login, and an
 * archive that believed anybody who posted to it would be an archive of
 * whatever they felt like writing. A bad signature is a 401 and nothing is
 * written.
 *
 * **Answered immediately.** Stream retries anything slow or failed, and a
 * retried event is a duplicate to be de-duplicated rather than a message
 * saved twice — every write here is an upsert on Stream's own id for exactly
 * that reason.
 *
 * **It is not the only path.** A deploy, a restart or a network minute loses
 * events, and past thirty days Stream has deleted its copy and the gap is
 * permanent. `chat:sweep` re-reads and backfills; this is the fast path, not
 * the guarantee.
 */
final class WebhookController
{
    public function __invoke(Request $request, Stream $stream, Archive $archive): Response
    {
        $signature = (string) $request->header('X-Signature', '');

        if (! $stream->signatureIsValid($request->getContent(), $signature)) {
            return response('Unauthorised.', 401);
        }

        $archive->record($request->json()->all());

        return response('', 204);
    }
}
