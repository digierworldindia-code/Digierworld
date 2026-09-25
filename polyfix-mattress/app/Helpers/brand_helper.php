<?php

/**
 * brand()            the Config\Brand instance
 * brand('name')      a property: name, shortName, legalName, locale, serialPrefix
 * brand('location')  the location array
 *
 * Views read the company's identity only through this helper, so a rename is a
 * one-file change in app/Config/Brand.php.
 */
if (! function_exists('brand')) {
    function brand(?string $key = null): mixed
    {
        $brand = config('Brand');

        return $key === null ? $brand : $brand->{$key};
    }
}

if (! function_exists('brand_wordmark')) {
    /** POLY<span>FIX</span> — escaped, ready to echo into markup. */
    function brand_wordmark(string $suffix = ''): string
    {
        $mark = brand('wordmark');
        $html = esc($mark['lead']) . '<span>' . esc($mark['trail']) . '</span>';

        return $suffix === '' ? $html : $html . ' ' . esc($suffix);
    }
}
