import type { Metadata, Viewport } from 'next';
import { Inter } from 'next/font/google';
import { SessionProvider } from '@/lib/session';
import { Shell } from '@/components/shell';
import './globals.css';

const ui = Inter({ subsets: ['latin'], weight: 'variable', variable: '--font-ui', display: 'swap' });

/**
 * Every page in this application is private. The metadata says so explicitly,
 * the middleware repeats it as a header, and robots.txt disallows everything —
 * three independent statements, because a single missed page appearing in a
 * search index is the kind of mistake that is discovered far too late.
 */
export const metadata: Metadata = {
  title: { default: 'COLIFEES Control', template: '%s | COLIFEES Control' },
  description: 'Private console for COLIFEES staff and dealers.',
  robots: {
    index: false,
    follow: false,
    nocache: true,
    noarchive: true,
    nosnippet: true,
    noimageindex: true,
    googleBot: { index: false, follow: false, noimageindex: true },
  },
  referrer: 'no-referrer',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#17181a',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en-IN" className={ui.variable}>
      <body>
        <SessionProvider>
          <Shell>{children}</Shell>
        </SessionProvider>
      </body>
    </html>
  );
}
