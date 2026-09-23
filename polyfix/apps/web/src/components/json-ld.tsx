import { jsonLd } from '@/lib/structured-data';

/**
 * Embeds a Schema.org block.
 *
 * The payload is serialised by `jsonLd`, which escapes `<`, so a value that
 * happens to contain a closing script tag cannot break out of the element.
 */
export function JsonLd({ schema }: { schema: unknown }) {
  if (!schema) return null;
  return (
    <script
      type="application/ld+json"
      // eslint-disable-next-line react/no-danger
      dangerouslySetInnerHTML={{ __html: jsonLd(schema) }}
    />
  );
}
