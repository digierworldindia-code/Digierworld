/**
 * Private object storage.
 *
 * Claim photographs are evidence in a warranty dispute and often show the
 * inside of someone's home, so they are never served from a public bucket or a
 * permanent URL. Two drivers, one contract:
 *
 *   local  development only. Writes beneath STORAGE_LOCAL_PATH, which is
 *          outside the application source tree and is not served by any web
 *          server.
 *   s3     production. A private S3-compatible bucket with no public read.
 *
 * Retrieval always goes through the API: the caller must hold a valid session,
 * must pass the authorization check for that claim, and must present a
 * signature that expires in minutes.
 */
import { createHash, createHmac, randomUUID } from 'node:crypto';
import { mkdir, writeFile, readFile, unlink } from 'node:fs/promises';
import path from 'node:path';
import { getConfig } from '@colifees/config';
import { signPayload, verifySignature } from '@colifees/auth';

export interface StoredObject {
  storageKey: string;
  byteSize: number;
  sha256: string;
}

export interface StorageDriver {
  put(key: string, body: Buffer, contentType: string): Promise<void>;
  get(key: string): Promise<Buffer>;
  remove(key: string): Promise<void>;
}

/**
 * Builds the storage key. The claim id partitions the namespace and a fresh
 * UUID names the object, so a caller-supplied filename never influences the
 * path — which is what stops `../../` from reaching outside the store.
 */
export function buildClaimMediaKey(claimId: string, extension: string): string {
  const safeExtension = /^[a-z0-9]{2,5}$/.test(extension) ? extension : 'bin';
  return `claims/${claimId}/${randomUUID()}.${safeExtension}`;
}

class LocalStorage implements StorageDriver {
  constructor(private readonly root: string) {}

  private resolve(key: string): string {
    const target = path.resolve(this.root, key);
    const root = path.resolve(this.root);
    // Defence in depth: keys are generated, never user-supplied, but a bug
    // upstream must not become a path traversal.
    if (target !== root && !target.startsWith(root + path.sep)) {
      throw new Error('storage key resolved outside the storage root');
    }
    return target;
  }

  async put(key: string, body: Buffer): Promise<void> {
    const target = this.resolve(key);
    await mkdir(path.dirname(target), { recursive: true });
    // 0600: readable only by the process owner.
    await writeFile(target, body, { mode: 0o600 });
  }

  async get(key: string): Promise<Buffer> {
    return readFile(this.resolve(key));
  }

  async remove(key: string): Promise<void> {
    await unlink(this.resolve(key)).catch(() => undefined);
  }
}

/**
 * S3-compatible driver using SigV4 over fetch, so the service carries no AWS
 * SDK. Works against AWS S3, Cloudflare R2, MinIO and DigitalOcean Spaces.
 */
class S3Storage implements StorageDriver {
  constructor(
    private readonly options: {
      endpoint: string;
      region: string;
      bucket: string;
      accessKey: string;
      secretKey: string;
      forcePathStyle: boolean;
    },
  ) {}

  private url(key: string): string {
    const base = this.options.endpoint.replace(/\/$/, '');
    return this.options.forcePathStyle
      ? `${base}/${this.options.bucket}/${key}`
      : `${base.replace('://', `://${this.options.bucket}.`)}/${key}`;
  }

  async put(key: string, body: Buffer, contentType: string): Promise<void> {
    const response = await this.signedFetch('PUT', key, body, contentType);
    if (!response.ok) {
      throw new Error(`object storage rejected the upload with status ${response.status}`);
    }
  }

  async get(key: string): Promise<Buffer> {
    const response = await this.signedFetch('GET', key);
    if (!response.ok) throw new Error(`object storage returned status ${response.status}`);
    return Buffer.from(await response.arrayBuffer());
  }

  async remove(key: string): Promise<void> {
    await this.signedFetch('DELETE', key);
  }

  private async signedFetch(method: string, key: string, body?: Buffer, contentType?: string): Promise<Response> {
    const url = new URL(this.url(key));
    const now = new Date();
    const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, '');
    const dateStamp = amzDate.slice(0, 8);
    const payloadHash = createHash('sha256').update(body ?? Buffer.alloc(0)).digest('hex');

    const headers: Record<string, string> = {
      host: url.host,
      'x-amz-content-sha256': payloadHash,
      'x-amz-date': amzDate,
    };
    if (contentType) headers['content-type'] = contentType;

    const signedHeaders = Object.keys(headers).sort();
    const canonicalHeaders = signedHeaders.map((h) => `${h}:${headers[h]}\n`).join('');
    const canonicalRequest = [
      method,
      url.pathname,
      '',
      canonicalHeaders,
      signedHeaders.join(';'),
      payloadHash,
    ].join('\n');

    const scope = `${dateStamp}/${this.options.region}/s3/aws4_request`;
    const stringToSign = [
      'AWS4-HMAC-SHA256',
      amzDate,
      scope,
      createHash('sha256').update(canonicalRequest).digest('hex'),
    ].join('\n');

    const signature = hmacChain(
      `AWS4${this.options.secretKey}`,
      [dateStamp, this.options.region, 's3', 'aws4_request'],
      stringToSign,
    );

    return fetch(url, {
      method,
      headers: {
        ...headers,
        Authorization: `AWS4-HMAC-SHA256 Credential=${this.options.accessKey}/${scope}, SignedHeaders=${signedHeaders.join(';')}, Signature=${signature}`,
      },
      body: body ?? null,
    });
  }
}

function hmacChain(seed: string, parts: string[], stringToSign: string): string {
  let key: Buffer = Buffer.from(seed, 'utf8');
  for (const part of parts) {
    key = createHmac('sha256', key).update(part).digest();
  }
  return createHmac('sha256', key).update(stringToSign).digest('hex');
}

let driver: StorageDriver | undefined;

export function getStorage(): StorageDriver {
  if (driver) return driver;
  const config = getConfig();

  if (config.env.STORAGE_DRIVER === 's3') {
    const { STORAGE_ENDPOINT, STORAGE_REGION, STORAGE_BUCKET, STORAGE_ACCESS_KEY, STORAGE_SECRET_KEY } = config.env;
    if (!STORAGE_ENDPOINT || !STORAGE_REGION || !STORAGE_BUCKET || !STORAGE_ACCESS_KEY || !STORAGE_SECRET_KEY) {
      throw new Error('STORAGE_DRIVER=s3 requires endpoint, region, bucket, access key and secret key');
    }
    driver = new S3Storage({
      endpoint: STORAGE_ENDPOINT,
      region: STORAGE_REGION,
      bucket: STORAGE_BUCKET,
      accessKey: STORAGE_ACCESS_KEY,
      secretKey: STORAGE_SECRET_KEY,
      forcePathStyle: config.env.STORAGE_FORCE_PATH_STYLE,
    });
  } else {
    driver = new LocalStorage(path.resolve(process.cwd(), config.env.STORAGE_LOCAL_PATH));
  }

  return driver;
}

export function resetStorage(): void {
  driver = undefined;
}

/**
 * Short-lived, tamper-proof reference to a stored object. The signature covers
 * both the media id and the expiry, so neither can be edited in the address
 * bar. Possession of the link is still not sufficient on its own: the download
 * route re-checks the session and the caller's right to that claim.
 */
export function signMediaReference(mediaId: string, expiresAtSeconds: number): string {
  const config = getConfig();
  return signPayload(`${mediaId}.${expiresAtSeconds}`, config.env.SIGNING_SECRET);
}

export function verifyMediaReference(mediaId: string, expiresAtSeconds: number, signature: string): boolean {
  const config = getConfig();
  if (!Number.isFinite(expiresAtSeconds) || expiresAtSeconds * 1000 < Date.now()) return false;
  return verifySignature(`${mediaId}.${expiresAtSeconds}`, signature, config.env.SIGNING_SECRET);
}

export function hashBytes(buffer: Buffer): string {
  return createHash('sha256').update(buffer).digest('hex');
}
