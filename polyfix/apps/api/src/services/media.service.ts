/**
 * Claim media handling.
 *
 * The threat model for an upload endpoint is: a file that is not what it claims
 * to be, a file large enough to exhaust disk or memory, a filename that escapes
 * the storage directory, and a file that becomes dangerous when served back.
 * Each is addressed explicitly here.
 *
 * Files are never written under the application's source tree and never given a
 * permanent public URL. Retrieval is through a signed, short-lived reference
 * that still requires an authenticated, authorized caller.
 */
import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { TransactionClient } from '@polyfix/database';
import { getConfig } from '@polyfix/config';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';
import { inspectUpload } from '../lib/image.js';
import { getStorage, buildClaimMediaKey, hashBytes, signMediaReference } from '../lib/storage.js';
import { recordAudit, actorFrom } from './audit.js';
import { refreshClaimRisk } from './risk.service.js';

export interface AttachedMedia {
  id: string;
  kind: string;
  mimeType: string;
  byteSize: number;
  width: number | null;
  height: number | null;
  uploadedAt: Date;
  url: string;
}

export async function attachClaimMedia(
  app: FastifyInstance,
  request: FastifyRequest,
  claimId: string,
  dealerId: string | null,
): Promise<{ uploaded: AttachedMedia[]; rejected: { filename: string; reason: string }[]; totalOnClaim: number }> {
  const config = getConfig();
  const storage = getStorage();

  // The claim is resolved inside the caller's scope, so a dealer cannot upload
  // to someone else's claim even with a valid claim id.
  const claim = await request.db(async (tx) =>
    tx.warrantyClaim.findFirst({
      where: { id: claimId, deletedAt: null, ...(dealerId ? { dealerId } : {}) },
      select: { id: true, dealerId: true, status: true, claimNumber: true, _count: { select: { media: true } } },
    }),
  );
  if (!claim) throw errors.notFound('claim');

  if (['REJECTED', 'CLOSED', 'WITHDRAWN', 'REPLACED'].includes(claim.status)) {
    throw errors.businessRule('This claim is closed, so no further files can be added to it.');
  }

  const remaining = config.env.MAX_UPLOADS_PER_CLAIM - claim._count.media;
  if (remaining <= 0) {
    throw errors.businessRule(`A claim can hold at most ${config.env.MAX_UPLOADS_PER_CLAIM} files.`);
  }

  const uploaded: AttachedMedia[] = [];
  const rejected: { filename: string; reason: string }[] = [];

  for await (const part of request.parts()) {
    if (part.type !== 'file') continue;
    if (uploaded.length >= remaining) {
      rejected.push({ filename: part.filename ?? 'file', reason: `only ${remaining} more file(s) can be added to this claim` });
      // Drain the stream so the connection is not left half-read.
      await part.toBuffer().catch(() => undefined);
      continue;
    }

    let buffer: Buffer;
    try {
      buffer = await part.toBuffer();
    } catch {
      rejected.push({ filename: part.filename ?? 'file', reason: 'the file exceeded the size limit' });
      continue;
    }

    if (buffer.length > config.env.MAX_UPLOAD_BYTES) {
      rejected.push({
        filename: part.filename ?? 'file',
        reason: `the file is larger than ${Math.round(config.env.MAX_UPLOAD_BYTES / 1024 / 1024)} MB`,
      });
      continue;
    }

    // The bytes decide what this file is — not the extension, not the header
    // the browser sent.
    const inspection = inspectUpload(buffer, part.mimetype ?? '', part.filename ?? '');
    if (!inspection.ok) {
      securityLog(
        'UPLOAD_REJECTED',
        {
          claimId,
          dealerId: claim.dealerId,
          failure: inspection.failure,
          declaredMime: part.mimetype,
          bytes: buffer.length,
        },
        inspection.failure === 'UNKNOWN_FORMAT' || inspection.failure === 'DECLARED_TYPE_MISMATCH' ? 'warn' : 'info',
      );
      rejected.push({ filename: part.filename ?? 'file', reason: inspection.detail });
      continue;
    }

    const sha256 = hashBytes(buffer);
    // The storage key is generated; the uploader's filename never reaches the
    // filesystem or the object key.
    const storageKey = buildClaimMediaKey(claim.id, inspection.file.extension);

    await storage.put(storageKey, buffer, inspection.file.mimeType);

    const media = await request.db(async (tx) => {
      const record = await tx.claimMedia.create({
        data: {
          claimId: claim.id,
          dealerId: claim.dealerId,
          kind: inspection.file.mimeType === 'application/pdf' ? 'CLAIM_INVOICE' : 'CLAIM_PHOTO',
          storageKey,
          mimeType: inspection.file.mimeType,
          byteSize: buffer.length,
          width: inspection.file.width,
          height: inspection.file.height,
          sha256,
          uploadedByUserId: request.auth?.userId ?? '',
        },
        select: { id: true, kind: true, mimeType: true, byteSize: true, width: true, height: true, createdAt: true },
      });

      await recordAudit(tx, actorFrom(request), {
        action: 'CLAIM_MEDIA_UPLOADED',
        entity: 'warranty_claim',
        entityId: claim.id,
        newValue: { claimNumber: claim.claimNumber, mediaId: record.id, mimeType: record.mimeType, byteSize: record.byteSize },
      });

      // A newly uploaded photograph can change the duplicate-image signal, so
      // the risk indicators are recomputed here rather than only at submission.
      await refreshClaimRisk(tx, claim.id);
      return record;
    });

    uploaded.push({
      id: media.id,
      kind: media.kind,
      mimeType: media.mimeType,
      byteSize: media.byteSize,
      width: media.width,
      height: media.height,
      uploadedAt: media.createdAt,
      url: buildSignedUrl(media.id),
    });
  }

  if (uploaded.length === 0 && rejected.length === 0) {
    throw errors.validation({ file: ['Attach at least one photograph or document.'] });
  }

  const totalOnClaim = await request.db((tx) => tx.claimMedia.count({ where: { claimId: claim.id } }));
  return { uploaded, rejected, totalOnClaim };
}

export async function listClaimMedia(tx: TransactionClient, claimId: string): Promise<AttachedMedia[]> {
  const rows = await tx.claimMedia.findMany({
    where: { claimId },
    orderBy: { createdAt: 'asc' },
    select: { id: true, kind: true, mimeType: true, byteSize: true, width: true, height: true, createdAt: true, caption: true },
  });

  return rows.map((row) => ({
    id: row.id,
    kind: row.kind,
    mimeType: row.mimeType,
    byteSize: row.byteSize,
    width: row.width,
    height: row.height,
    uploadedAt: row.createdAt,
    // The storage key is never returned. The client receives only a signed
    // reference that expires.
    url: buildSignedUrl(row.id),
  }));
}

export function buildSignedUrl(mediaId: string): string {
  const config = getConfig();
  const expiresAt = Math.floor(Date.now() / 1000) + config.env.MEDIA_SIGNED_URL_TTL_SECONDS;
  const signature = signMediaReference(mediaId, expiresAt);
  return `/media/${mediaId}?exp=${expiresAt}&sig=${encodeURIComponent(signature)}`;
}
