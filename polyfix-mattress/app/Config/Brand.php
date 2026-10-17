<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The single source of truth for who the company is.
 *
 * Every view, email, PDF, page title and structured-data block reads the
 * company's name and location from here, through brand() in the brand helper.
 * There is deliberately no second constant anywhere holding the same value.
 *
 * Three tiers, and a value belongs in exactly one:
 *   here             structural, changes with a deploy: name, wordmark, location
 *   .env             per deployment: app.baseURL
 *   system_settings  editable by an administrator at runtime: phone, support
 *                    email, the address shown in the footer, social links
 */
class Brand extends BaseConfig
{
    /**
     * Full name. Exact spelling, everywhere: P-O-L-Y-F-I-Z-Z.
     *
     * Never POLYFIX, POLY FIZZ, Polyfizz Mattress or POLYFIZZZ. The previous
     * spelling POLYFIX appears only where it is a historical fact — the
     * database name chosen at install, and records written before the
     * correction. Those are left alone deliberately; see docs/BRANDING.md.
     */
    public string $name = 'POLYFIZZ';

    /** Tight spaces: headings, the wordmark, product names. */
    public string $shortName = 'POLYFIZZ';

    /** Registered business name, for schema.org and documents. */
    public string $legalName = 'POLYFIZZ';

    /**
     * The two halves of the logotype, styled differently. Rendered as one
     * word: POLY + FIZZ. Never "POLY FIZZ".
     */
    public array $wordmark = ['lead' => 'POLY', 'trail' => 'FIZZ'];

    /** The public website, shown in the footer and in documents. */
    public string $website = 'www.polyfizz.in';

    /** What the company makes. Used in meta descriptions and structured data. */
    public string $business = 'Mattress and Foam Manufacturing';

    /** The company's own location. Never written over dealer or customer addresses. */
    public array $location = [
        'line'        => 'Manesar, Noranpur Chowk, Haryana, India',
        'locality'    => 'Manesar',
        'region'      => 'Haryana',
        'country'     => 'India',
        'countryCode' => 'IN',
    ];

    public string $locale = 'en_IN';

    /**
     * Serial prefix for mattresses. Deliberately NOT the brand name: it is
     * printed on physical labels already in customers' homes, encoded in QR
     * codes, and referenced by warranties, claims and dispatches. Changing it
     * would orphan every mattress made to date. A future change applies to new
     * production only and never rewrites an existing serial.
     */
    public string $serialPrefix = 'PFZ';

    /**
     * Serial prefixes this installation has ever issued, oldest first.
     *
     * Lookups, QR verification and reports must accept every one of them, and
     * the database CHECK constraint is written from this list. CLF is the
     * prefix the previous platform used; those labels are on mattresses in
     * customers' homes and their serials are never rewritten.
     *
     * @var list<string>
     */
    public array $legacySerialPrefixes = ['CLF'];

    /** Suffix for page titles: "Contact | POLYFIX MATTRESS". */
    public function titleSuffix(string $text): string
    {
        return $text . ' | ' . $this->name;
    }

    /** "POLYFIZZ Aurea Hybrid". */
    public function productName(string $model): string
    {
        return $this->shortName . ' ' . $model;
    }
}
