<?php

namespace App\Libraries;

/**
 * Prepares untrusted document text (CVs) before it leaves the server.
 *
 *  - replaces the candidate's own name with [candidate];
 *  - strips control, zero-width and bidi-override characters (hidden text tricks);
 *  - removes lines that only carry protected / irrelevant personal attributes
 *    (age, date of birth, gender, marital status, religion, caste, photo …)
 *    and direct contact details, so they can never influence a score;
 *  - flags instruction-like phrases (prompt injection) and neutralises them,
 *    so the model sees "[instruction-like text removed]" instead of the order;
 *  - neutralises our own delimiter so the text cannot "close" its data block.
 *
 * Flags are stored on the evaluation for HR review; nothing is silently dropped
 * without a marker.
 */
class UntrustedText
{
    /** Phrases that try to address or instruct the model. */
    private const INJECTION = [
        'ignore_instructions' => '/\b(ignore|disregard|forget|override|bypass)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|any|the|your|system)\b[^.\n]{0,30}\b(instructions?|prompts?|rules?|directions?|guidelines?|messages?)\b/i',
        'role_override'       => '/\b(you are now|act as|pretend to be|from now on you|new instructions?|system prompt|developer mode|jailbreak)\b/i',
        'score_demand'        => '/\b(giv(e|es|ing)|assign\w*|rat(e|es|ed|ing)|scor(e|es|ed|ing)|award\w*|output\w*|return\w*|mark(s|ed)?)\b[^.\n]{0,30}\b(10\s*\/\s*10|10 out of 10|full marks|perfect score|highest (score|rating)|score of 10|maximum score)\b/i',
        'chat_markup'         => '/(<\/?\s*(system|assistant|user|instructions?|prompt|candidate_cv)[^>]*>|\[\/?(INST|SYS)\]|^\s*(system|assistant|human|user)\s*:)/im',
        'hidden_channel'      => '/\b(as an ai|language model|llm|chatgpt|claude|gpt-?\d)\b[^.\n]{0,60}\b(must|should|will|need to|have to)\b/i',
        'exfiltration'        => '/\b(reveal|print|show|repeat|leak|send)\b[^.\n]{0,40}\b(system prompt|instructions|api key|password|database|salary|other candidates)\b/i',
    ];

    /** Lines whose label is a protected or irrelevant attribute. */
    private const PROTECTED_LINE = '/^\s*(date of birth|d\.?\s?o\.?\s?b\.?|birth ?date|age|gender|sex|marital status|married|religion|caste|sub-?caste|community|category \(sc|nationality|blood group|height|weight|father\'?s name|mother\'?s name|husband\'?s name|spouse|children|photo(graph)?|passport (no|number)|aadhaa?r|pan (no|number)|disability|health)\b[^\n]{0,120}$/im';

    /**
     * @return array{text: string, flags: list<array{type: string, sample: string}>, redactions: int}
     */
    public static function sanitize(string $text, string $delimiter = 'candidate_cv', array $names = []): array
    {
        $flags = [];
        // 1. invisible / control characters
        $before = $text;
        $text   = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text) ?? '';
        $text   = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
        if ($text !== $before && mb_strlen($before) - mb_strlen($text) > 0) {
            $flags[] = ['type' => 'hidden_characters', 'sample' => (mb_strlen($before) - mb_strlen($text)) . ' invisible/control characters removed'];
        }
        // 2. protected attributes and direct contact details
        $redactions = 0;
        $text = preg_replace_callback(self::PROTECTED_LINE, static function () use (&$redactions) {
            $redactions++;

            return '[personal detail removed — not job-relevant]';
        }, $text) ?? $text;
        $text = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static function () use (&$redactions) {
            $redactions++;

            return '[email removed]';
        }, $text) ?? $text;
        $text = preg_replace_callback('/(?<!\d)(\+?91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}(?!\d)/', static function () use (&$redactions) {
            $redactions++;

            return '[phone removed]';
        }, $text) ?? $text;
        // 2b. the person's own name (can signal gender, religion or community)
        $parts = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $parts[] = $n;
                foreach (preg_split('/\s+/u', $n) ?: [] as $p) {
                    if (mb_strlen($p) >= 3) {
                        $parts[] = $p;
                    }
                }
            }
        }
        usort($parts, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_unique($parts) as $p) {
            $text = preg_replace('/\b' . preg_quote($p, '/') . '\b/iu', '[candidate]', $text) ?? $text;
        }
        // 3. instruction-like content
        foreach (self::INJECTION as $type => $re) {
            $text = preg_replace_callback($re, static function (array $m) use (&$flags, $type) {
                $flags[] = ['type' => $type, 'sample' => mb_substr(trim($m[0]), 0, 80)];

                return '[instruction-like text removed]';
            }, $text) ?? $text;
        }
        // 4. our own delimiter
        $text = str_ireplace(['<' . $delimiter, '</' . $delimiter], ['‹' . $delimiter, '‹/' . $delimiter], $text);

        return ['text' => trim($text), 'flags' => $flags, 'redactions' => $redactions];
    }
}
