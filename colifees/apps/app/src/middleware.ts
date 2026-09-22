import { NextResponse, type NextRequest } from 'next/server';

/**
 * Applies to every response this application produces.
 *
 * Two jobs:
 *   1. repeat the noindex instruction, so no route can be indexed even if a
 *      future page forgets its own metadata
 *   2. forbid caching, because every page here is specific to one signed-in
 *      person and a shared cache holding one would be a data leak
 *
 * Authentication is NOT done here. The API is the authority on whether a
 * session is valid, and a middleware check against a cookie's mere presence
 * would be theatre — a cookie can be fabricated, and only the API can verify
 * the signature and the session record.
 */
export function middleware(request: NextRequest) {
  const response = NextResponse.next();

  response.headers.set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
  response.headers.set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
  response.headers.set('Pragma', 'no-cache');

  void request;
  return response;
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
