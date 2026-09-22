import Script from 'next/script';

/**
 * Google Analytics 4.
 *
 * Loaded only when a measurement ID is configured, and only on the public
 * website — the private console has its own layout and never imports this. If
 * the ID is blank, no script tag is emitted at all, so a default install ships
 * no third-party JavaScript.
 */
export function Analytics() {
  const measurementId = process.env.NEXT_PUBLIC_GA4_MEASUREMENT_ID;
  if (!measurementId) return null;

  return (
    <>
      <Script
        src={`https://www.googletagmanager.com/gtag/js?id=${measurementId}`}
        strategy="afterInteractive"
      />
      <Script id="ga4-init" strategy="afterInteractive">
        {`
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', '${measurementId}', {
            anonymize_ip: true,
            allow_google_signals: false,
            allow_ad_personalization_signals: false
          });
        `}
      </Script>
    </>
  );
}
