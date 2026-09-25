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
    /** Full name. Exact spelling, everywhere. */
    public string $name = 'POLYFIX MATTRESS';

    /** Tight spaces: headings, the wordmark, product names. */
    public string $shortName = 'POLYFIX';

    /** Registered business name, for schema.org and documents. */
    public string $legalName = 'POLYFIX MATTRESS';

    /**
     * The two halves of the logotype, styled differently. Rendered as one word:
     * POLY + FIX. Never "POLY FIX".
     */
    public array $wordmark = ['lead' => 'POLY', 'trail' => 'FIX'];

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
    public string $serialPrefix = 'CLF';

    /** Suffix for page titles: "Contact | POLYFIX MATTRESS". */
    public function titleSuffix(string $text): string
    {
        return $text . ' | ' . $this->name;
    }

    /** "POLYFIX Aurea Hybrid". */
    public function productName(string $model): string
    {
        return $this->shortName . ' ' . $model;
    }
}
