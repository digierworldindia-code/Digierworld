import { NextResponse, type NextRequest } from 'next/server';

/**
 * Backend-for-frontend proxy.
 *
 * The console's browser code calls this application; this application calls the
 * private API over the internal network. The consequences are the ones the
 * brief asks for:
 *
 *   - the API's address never appears in the client bundle or in a network tab
 *   - the API needs no public route and no public CORS allowance
 *   - session cookies are set by, and returned to, this origin only
 *
 * What this proxy deliberately does NOT do is make authorization decisions. It
 * forwards the session and returns the API's verdict unchanged. Every
 * permission check, every dealer-scope restriction and every rate limit lives
 * in the API, where it cannot be bypassed by calling a different front end.
 */
const API_URL = (process.env.APP_INTERNAL_API_URL ?? 'http://127.0.0.1:4000').replace(/\/$/, '');

/** Only these paths may be reached through the proxy. */
const ALLOWED_PREFIXES = ['auth/', 'ops/', 'dealer/', 'media/', 'health'];

/** Headers forwarded to the API. Anything else the browser sends is dropped. */
const FORWARD_REQUEST_HEADERS = ['content-type', 'cookie', 'x-csrf-token', 'accept'];

/** Headers returned to the browser. */
const FORWARD_RESPONSE_HEADERS = ['content-type', 'content-disposition', 'cache-control', 'x-request-id', 'retry-after'];

const MAX_BODY_BYTES = 12 * 1024 * 1024;

async function proxy(request: NextRequest, context: { params: Promise<{ path: string[] }> }): Promise<Response> {
  const { path } = await context.params;
  const target = path.join('/');

  if (!ALLOWED_PREFIXES.some((prefix) => target === prefix || target.startsWith(prefix))) {
    return NextResponse.json(
      { error: { code: 'NOT_FOUND', message: 'That endpoint does not exist.' } },
      { status: 404 },
    );
  }

  const url = new URL(`${API_URL}/${target}`);
  // Query parameters are copied wholesale; the API validates every one of them.
  request.nextUrl.searchParams.forEach((value, key) => url.searchParams.append(key, value));

  const headers = new Headers();
  for (const name of FORWARD_REQUEST_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }
  // Lets the API log the true client address when it is configured to trust
  // this hop. It is advisory: TRUST_PROXY decides whether the API believes it.
  const forwardedFor = request.headers.get('x-forwarded-for');
  if (forwardedFor) headers.set('x-forwarded-for', forwardedFor);

  let body: BodyInit | undefined;
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    const buffer = await request.arrayBuffer();
    if (buffer.byteLength > MAX_BODY_BYTES) {
      return NextResponse.json(
        { error: { code: 'PAYLOAD_TOO_LARGE', message: 'That upload is too large.' } },
        { status: 413 },
      );
    }
    body = buffer;
  }

  const upstream = await fetch(url, {
    method: request.method,
    headers,
    body,
    redirect: 'manual',
    cache: 'no-store',
  }).catch(() => null);

  if (!upstream) {
    return NextResponse.json(
      {
        error: {
          code: 'SERVICE_UNAVAILABLE',
          message: 'The service is not responding. Please try again in a moment.',
        },
      },
      { status: 503 },
    );
  }

  const response = new NextResponse(upstream.body, { status: upstream.status });

  for (const name of FORWARD_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) response.headers.set(name, value);
  }

  // Session cookies are issued by the API and re-emitted here so the browser
  // stores them against this origin. The API sets them host-only, HttpOnly and
  // SameSite=Strict; those attributes are preserved exactly as sent.
  const setCookie = upstream.headers.getSetCookie?.() ?? [];
  for (const cookie of setCookie) {
    response.headers.append('set-cookie', cookie);
  }

  response.headers.set('Cache-Control', 'no-store, private');
  response.headers.set('X-Robots-Tag', 'noindex, nofollow');
  return response;
}

export const GET = proxy;
export const POST = proxy;
export const PATCH = proxy;
export const PUT = proxy;
export const DELETE = proxy;

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';
