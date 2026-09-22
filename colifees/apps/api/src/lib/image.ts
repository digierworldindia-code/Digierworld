/**
 * Upload inspection.
 *
 * An uploaded file is untrusted input that will later be handed back to a
 * browser, so its declared type is checked against what the bytes actually are.
 * A file claiming to be `image/jpeg` whose header says otherwise is rejected —
 * the classic route to a stored cross-site scripting or a polyglot payload.
 *
 * Dimensions are read from the header rather than by decoding the image, so a
 * crafted file cannot turn validation itself into a decompression bomb.
 */

export interface InspectedFile {
  mimeType: 'image/jpeg' | 'image/png' | 'image/webp' | 'application/pdf';
  extension: 'jpg' | 'png' | 'webp' | 'pdf';
  width: number | null;
  height: number | null;
}

export type InspectionFailure =
  | 'EMPTY'
  | 'UNKNOWN_FORMAT'
  | 'DECLARED_TYPE_MISMATCH'
  | 'DIMENSIONS_TOO_SMALL'
  | 'DIMENSIONS_TOO_LARGE'
  | 'MALFORMED_HEADER';

export type InspectionResult =
  | { ok: true; file: InspectedFile }
  | { ok: false; failure: InspectionFailure; detail: string };

const MIN_DIMENSION = 80;
const MAX_DIMENSION = 12_000;

export function inspectUpload(buffer: Buffer, declaredMime: string, declaredName: string): InspectionResult {
  if (buffer.length === 0) return { ok: false, failure: 'EMPTY', detail: 'the file contained no data' };

  const detected = detectFormat(buffer);
  if (!detected) {
    return {
      ok: false,
      failure: 'UNKNOWN_FORMAT',
      detail: 'the file is not a JPEG, PNG, WebP or PDF',
    };
  }

  // The browser's Content-Type and the file extension are hints. The bytes are
  // the authority, and all three must agree.
  const normalisedDeclared = declaredMime.split(';')[0]?.trim().toLowerCase() ?? '';
  if (normalisedDeclared && normalisedDeclared !== detected.mimeType) {
    return {
      ok: false,
      failure: 'DECLARED_TYPE_MISMATCH',
      detail: `the file was sent as ${normalisedDeclared} but its contents are ${detected.mimeType}`,
    };
  }

  const extension = declaredName.toLowerCase().split('.').pop() ?? '';
  const acceptableExtensions = EXTENSIONS_FOR[detected.mimeType];
  if (extension && !acceptableExtensions.includes(extension)) {
    return {
      ok: false,
      failure: 'DECLARED_TYPE_MISMATCH',
      detail: `a ${detected.mimeType} file cannot have a .${extension} extension`,
    };
  }

  if (detected.mimeType !== 'application/pdf') {
    if (detected.width === null || detected.height === null) {
      return { ok: false, failure: 'MALFORMED_HEADER', detail: 'the image header could not be read' };
    }
    if (detected.width < MIN_DIMENSION || detected.height < MIN_DIMENSION) {
      return {
        ok: false,
        failure: 'DIMENSIONS_TOO_SMALL',
        detail: `the image is ${detected.width}x${detected.height}; at least ${MIN_DIMENSION}x${MIN_DIMENSION} is needed to show the issue`,
      };
    }
    if (detected.width > MAX_DIMENSION || detected.height > MAX_DIMENSION) {
      return {
        ok: false,
        failure: 'DIMENSIONS_TOO_LARGE',
        detail: `the image is ${detected.width}x${detected.height}, which exceeds the ${MAX_DIMENSION}px limit`,
      };
    }
  }

  return { ok: true, file: detected };
}

const EXTENSIONS_FOR: Record<InspectedFile['mimeType'], string[]> = {
  'image/jpeg': ['jpg', 'jpeg'],
  'image/png': ['png'],
  'image/webp': ['webp'],
  'application/pdf': ['pdf'],
};

function detectFormat(buffer: Buffer): InspectedFile | null {
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) {
    const size = readJpegDimensions(buffer);
    return { mimeType: 'image/jpeg', extension: 'jpg', width: size?.width ?? null, height: size?.height ?? null };
  }

  if (
    buffer.length >= 24 &&
    buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47 &&
    buffer[4] === 0x0d && buffer[5] === 0x0a && buffer[6] === 0x1a && buffer[7] === 0x0a
  ) {
    // IHDR must be the first chunk of a valid PNG.
    if (buffer.toString('ascii', 12, 16) !== 'IHDR') return null;
    return {
      mimeType: 'image/png',
      extension: 'png',
      width: buffer.readUInt32BE(16),
      height: buffer.readUInt32BE(20),
    };
  }

  if (
    buffer.length >= 16 &&
    buffer.toString('ascii', 0, 4) === 'RIFF' &&
    buffer.toString('ascii', 8, 12) === 'WEBP'
  ) {
    const size = readWebpDimensions(buffer);
    return { mimeType: 'image/webp', extension: 'webp', width: size?.width ?? null, height: size?.height ?? null };
  }

  if (buffer.length >= 5 && buffer.toString('ascii', 0, 5) === '%PDF-') {
    return { mimeType: 'application/pdf', extension: 'pdf', width: null, height: null };
  }

  return null;
}

/** Walks JPEG segment markers to the first start-of-frame. */
function readJpegDimensions(buffer: Buffer): { width: number; height: number } | null {
  let offset = 2;
  while (offset + 9 < buffer.length) {
    if (buffer[offset] !== 0xff) {
      offset += 1;
      continue;
    }
    const marker = buffer[offset + 1]!;
    // SOF0..SOF15, excluding the non-frame markers DHT (c4), JPG (c8), DAC (cc)
    if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
      return { height: buffer.readUInt16BE(offset + 5), width: buffer.readUInt16BE(offset + 7) };
    }
    const segmentLength = buffer.readUInt16BE(offset + 2);
    if (segmentLength < 2) return null;
    offset += 2 + segmentLength;
  }
  return null;
}

function readWebpDimensions(buffer: Buffer): { width: number; height: number } | null {
  const format = buffer.toString('ascii', 12, 16);

  if (format === 'VP8 ' && buffer.length >= 30) {
    return {
      width: buffer.readUInt16LE(26) & 0x3fff,
      height: buffer.readUInt16LE(28) & 0x3fff,
    };
  }

  if (format === 'VP8L' && buffer.length >= 25) {
    const bits = buffer.readUInt32LE(21);
    return {
      width: (bits & 0x3fff) + 1,
      height: ((bits >> 14) & 0x3fff) + 1,
    };
  }

  if (format === 'VP8X' && buffer.length >= 30) {
    const width = 1 + (buffer[24]! | (buffer[25]! << 8) | (buffer[26]! << 16));
    const height = 1 + (buffer[27]! | (buffer[28]! << 8) | (buffer[29]! << 16));
    return { width, height };
  }

  return null;
}
