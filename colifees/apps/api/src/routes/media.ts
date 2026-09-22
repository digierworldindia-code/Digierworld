/**
 * Private media delivery.
 *
 * Three independent checks before a byte is returned:
 *   1. a valid session (the route is behind `authenticate`)
 *   2. authorization for that specific claim, applied through the caller's
 *      database scope — a dealer simply cannot read another dealer's media row
 *   3. a signature over the media id and expiry, so a link cannot be edited or
 *      forwarded to work indefinitely
 *
 * Responses are sent with a download disposition and a nosniff header, so a
 * file can never be interpreted as a document in the browser's origin.
 */
import type { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { parse, idParam } from '../lib/http.js';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';
import { getStorage, verifyMediaReference } from '../lib/storage.js';

const querySchema = z.object({
  exp: z.coerce.number().int().positive(),
  sig: z.string().min(10).max(200),
});

export async function registerMediaRoutes(app: FastifyInstance): Promise<void> {
  app.get<{ Params: { id: string }; Querystring: { exp: string; sig: string } }>(
    '/:id',
    {
      preHandler: app.requirePermission('claim:media:view'),
      config: { rateLimit: { max: 120, timeWindow: '1 minute' } },
    },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const query = parse(querySchema, request.query);

      if (!verifyMediaReference(id, query.exp, query.sig)) {
        securityLog('MEDIA_SIGNATURE_REJECTED', { mediaId: id, userId: request.auth?.userId ?? null });
        throw errors.forbidden('media signature invalid or expired', 'That link has expired. Reload the page to get a fresh one.');
      }

      // Read inside the caller's scope: Row Level Security decides whether this
      // media row exists for them at all.
      const media = await request.db((tx) =>
        tx.claimMedia.findFirst({
          where: { id },
          select: { id: true, storageKey: true, mimeType: true, byteSize: true, claimId: true },
        }),
      );

      if (!media) {
        securityLog('MEDIA_ACCESS_DENIED', { mediaId: id, userId: request.auth?.userId ?? null });
        throw errors.notFound('file');
      }

      const body = await getStorage()
        .get(media.storageKey)
        .catch((error: unknown) => {
          request.log.error({ err: error, mediaId: id }, 'stored object could not be read');
          throw errors.internal('media object missing from storage');
        });

      return reply
        .header('Content-Type', media.mimeType)
        .header('Content-Length', String(body.length))
        // Never rendered inline: a download cannot execute in the page origin.
        .header('Content-Disposition', `attachment; filename="colifees-${media.id}"`)
        .header('X-Content-Type-Options', 'nosniff')
        .header('Cache-Control', 'private, no-store')
        .header('Content-Security-Policy', "default-src 'none'; sandbox")
        .send(body);
    },
  );
}
