<?php

namespace App\Libraries;

use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

/**
 * Server-side text extraction for uploaded CVs (PDF and DOCX only).
 * No macros, scripts or embedded objects are executed; DOCX is read as
 * plain XML from the zip container. Scanned (image-only) PDFs yield no
 * text and are reported as such — nothing is guessed.
 */
class CvTextExtractor
{
    public const MAX_CHARS = 60000;

    public static function extract(string $bytes, string $mime): string
    {
        $text = match ($mime) {
            'application/pdf' => self::pdf($bytes),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => self::docx($bytes),
            default => throw new RuntimeException('Unsupported file type for text extraction.'),
        };
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\R/u", "\n", $text) ?? '') ?? '');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        if (mb_strlen($text) < 40) {
            throw new RuntimeException('No readable text found (the file may be a scanned image). Ask the candidate for a text-based PDF or DOCX, or assess manually.');
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    private static function pdf(string $bytes): string
    {
        try {
            $pdf = (new Parser())->parseContent($bytes);
        } catch (\Throwable $e) {
            throw new RuntimeException('The PDF could not be read: ' . mb_substr($e->getMessage(), 0, 120));
        }
        $pages = $pdf->getPages();
        if (count($pages) > 30) {
            throw new RuntimeException('The PDF has more than 30 pages; upload the CV only.');
        }

        return $pdf->getText();
    }

    private static function docx(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('The DOCX file could not be opened.');
            }
            $stat = $zip->statName('word/document.xml');
            if ($stat === false || $stat['size'] > 20 * 1024 * 1024) {
                throw new RuntimeException('The DOCX file has no readable document body.');
            }
            $xml = (string) $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
            @unlink($tmp);
        }
        // Paragraph/line/tab markers to whitespace, then drop all markup.
        $xml = preg_replace(['#</w:p>#', '#<w:br[^>]*/>#', '#<w:tab[^>]*/>#'], ["\n", "\n", "\t"], $xml) ?? '';

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Heuristic section split (Skills / Education / Experience / Projects) so
     * HR can see what was found. Lines are copied verbatim, never invented.
     *
     * @return array{skills: list<string>, education: list<string>, experience: list<string>, projects: list<string>}
     */
    public static function sections(string $text): array
    {
        $heads = [
            'skills'     => '/^(key\s+|technical\s+|core\s+)?(skills|competencies|tools|technologies)\b/i',
            'education'  => '/^(education|academic|qualifications?)\b/i',
            'experience' => '/^(work\s+|professional\s+)?(experience|employment|work history|career)\b/i',
            'projects'   => '/^(key\s+|academic\s+)?projects?\b/i',
        ];
        $out     = ['skills' => [], 'education' => [], 'experience' => [], 'projects' => []];
        $current = null;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line, " \t•·-*–:");
            if ($line === '') {
                continue;
            }
            $matched = null;
            if (mb_strlen($line) <= 40) {
                foreach ($heads as $k => $re) {
                    if (preg_match($re, $line)) {
                        $matched = $k;
                        break;
                    }
                }
            }
            if ($matched !== null) {
                $current = $matched;

                continue;
            }
            if ($current !== null && count($out[$current]) < 15) {
                $out[$current][] = mb_substr($line, 0, 200);
            }
        }

        return $out;
    }
}
