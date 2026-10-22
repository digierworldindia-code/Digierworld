<?php

namespace App\Libraries;

/**
 * Splits long printed text into pieces that each fit comfortably on a page.
 *
 * Dompdf never splits a table row across pages: a row taller than the space
 * left moves to the next page, and whatever does not fit there is cut off.
 * Long line descriptions are therefore printed as one row per piece, so
 * pages can break between pieces and no text is lost.
 */
final class PdfText
{
    /** @return list<string> pieces in order; [] for empty text */
    public static function chunks(string $text, int $maxChars = 420, int $maxLines = 10): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            // a single very long line is cut at a space (or hard, if it has none)
            while (mb_strlen($line) > $maxChars) {
                $head = mb_substr($line, 0, $maxChars);
                $cut  = mb_strrpos($head, ' ');
                $cut  = $cut !== false && $cut > $maxChars / 2 ? $cut : $maxChars;
                $lines[] = rtrim(mb_substr($line, 0, $cut));
                $line    = ltrim(mb_substr($line, $cut));
            }
            $lines[] = $line;
        }

        $chunks = [];
        $cur    = [];
        $len    = 0;
        foreach ($lines as $line) {
            $l = mb_strlen($line);
            if ($cur !== [] && ($len + $l > $maxChars || count($cur) >= $maxLines)) {
                $chunks[] = implode("\n", $cur);
                $cur      = [];
                $len      = 0;
            }
            $cur[] = $line;
            $len += $l;
        }
        $chunks[] = implode("\n", $cur);

        return array_values(array_filter(array_map('trim', $chunks), static fn (string $c) => $c !== ''));
    }
}
