import type { NextConfig } from 'next';

/**
 * Public website configuration.
 *
 * The browser never talks to the private API. Pages render on the server,
 * which calls the API over the private network; form submissions go to this
 * app's own route handlers, which forward them. Nothing in the client bundle
 * knows an internal address.
 */
const config: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  compress: true,

  // Modern formats, sized to the layout's actual breakpoints so the browser
  // never downloads more pixels than it paints.
  images: {
    formats: ['image/avif', 'image/webp'],
    deviceSizes: [420, 640, 828, 1080, 1280, 1600, 1920],
    imageSizes: [80, 160, 240, 320, 420],
  },

  async headers() {
    // A public marketing site needs a looser policy than the API, but every
    // directive here is still an allow-list. Analytics hosts appear only when
    // an ID is configured.
    const analyticsEnabled = Boolean(process.env.NEXT_PUBLIC_GA4_MEASUREMENT_ID);
    const scriptSrc = [
      "'self'",
      // Next.js inlines a small bootstrap script per route.
      "'unsafe-inline'",
      ...(analyticsEnabled ? ['https://www.googletagmanager.com'] : []),
    ].join(' ');
    const connectSrc = [
      "'self'",
      ...(analyticsEnabled ? ['https://www.google-analytics.com', 'https://region1.google-analytics.com'] : []),
    ].join(' ');

    const csp = [
      "default-src 'self'",
      `script-src ${scriptSrc}`,
      "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
      "font-src 'self' https://fonts.gstatic.com data:",
      "img-src 'self' data: blob:",
      `connect-src ${connectSrc}`,
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "object-src 'none'",
      'upgrade-insecure-requests',
    ].join('; ');

    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: csp },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          {
            key: 'Permissions-Policy',
            value: 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
          },
          { key: 'Strict-Transport-Security', value: 'max-age=63072000; includeSubDomains; preload' },
        ],
      },
    ];
  },

  async redirects() {
    // 301 support for URLs that change. Add entries here rather than letting a
    // renamed page 404 and lose its ranking.
    return [
      { source: '/products', destination: '/mattresses', permanent: true },
      { source: '/mattress', destination: '/mattresses', permanent: true },
      { source: '/warranty-check', destination: '/warranty', permanent: true },
      { source: '/become-a-dealer', destination: '/dealers#apply', permanent: true },
    ];
  },
};

export default config;
