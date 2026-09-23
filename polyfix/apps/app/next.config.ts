import path from 'node:path';
import type { NextConfig } from 'next';

/**
 * Private console configuration.
 *
 * This application is the opposite of the public website in almost every
 * respect: nothing here should be cached, indexed, embedded or shared. The
 * headers below say so, and the middleware repeats the noindex instruction on
 * every response so a single missed page cannot end up in a search index.
 */
const config: NextConfig = {
  reactStrictMode: true,
  // Emits .next/standalone: a self-contained server with only the
  // dependencies this app actually imports. It is what the container
  // image copies, and it keeps `next start` working unchanged locally.
  output: 'standalone',
  // The workspace root, so the standalone tracer follows symlinked
  // pnpm dependencies out of the app directory instead of stopping at it.
  outputFileTracingRoot: path.join(import.meta.dirname, '../..'),
  poweredByHeader: false,

  async headers() {
    const csp = [
      "default-src 'self'",
      "script-src 'self' 'unsafe-inline'",
      "style-src 'self' 'unsafe-inline'",
      "font-src 'self' data:",
      "img-src 'self' data: blob:",
      // The browser talks to this origin only. The private API is reached
      // through this app's own proxy routes, never directly.
      "connect-src 'self'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "object-src 'none'",
    ].join('; ');

    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: csp },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Referrer-Policy', value: 'no-referrer' },
          { key: 'Permissions-Policy', value: 'camera=(self), geolocation=(), microphone=(), payment=()' },
          { key: 'Strict-Transport-Security', value: 'max-age=63072000; includeSubDomains; preload' },
          // Belt and braces alongside the middleware and the robots route.
          { key: 'X-Robots-Tag', value: 'noindex, nofollow, noarchive, nosnippet, noimageindex' },
          { key: 'Cache-Control', value: 'no-store, no-cache, must-revalidate, private' },
        ],
      },
    ];
  },
};

export default config;
