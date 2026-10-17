<?php

namespace Tests\Support;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;

/** Test double: records exactly what would be sent and returns a scripted reply. */
final class FakeAiProvider implements AiProvider
{
    public ?string $system = null;

    public ?string $user = null;

    public int $calls = 0;

    /** @param array<string, mixed>|null $reply null = throw */
    public function __construct(public ?array $reply = null, public string $error = 'The AI provider did not answer in time.')
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function structured(string $system, string $user, array $schema, int $maxTokens = 8000): array
    {
        $this->calls++;
        $this->system = $system;
        $this->user   = $user;
        if ($this->reply === null) {
            throw new AiProviderException($this->error, true);
        }

        return ['data' => $this->reply, 'input_tokens' => 1200, 'output_tokens' => 300, 'model' => 'fake-model'];
    }

    /** A schema-valid reply quoting $quote from the CV. */
    public static function reply(string $quote, array $override = []): array
    {
        $cat = static fn ($s, $r) => ['score' => $s, 'rationale' => $r];

        return $override + [
            'category_scores' => [
                'essential_skills' => $cat(8, 'Runs Google Ads and Meta campaigns for clients.'), 'relevant_experience' => $cat(7, 'About three years in an agency.'),
                'project_evidence' => $cat(6, 'Multi-city lead generation campaign.'), 'qualifications' => $cat(null, 'No certification evidence.'), 'other_evidence' => $cat(5, 'Reports results.'),
            ],
            'matched_requirements' => [['requirement' => 'Google Ads campaigns for clients', 'evidence' => $quote], ['requirement' => 'GA4', 'evidence' => 'Expert in GA4 server-side tagging for 50 brands']],
            'unmet_requirements' => [], 'unclear_requirements' => [['requirement' => 'Google Ads certification', 'note' => 'Not mentioned']],
            'interview_questions' => ['Walk through a campaign where cost per lead improved.'], 'summary' => 'Relevant paid-ads experience; certification unclear.',
            'explanation' => 'Evidence of campaign management for agency clients.', 'confidence' => 'medium', 'possible_manipulation' => true, 'proposed_timeline_event' => null,
        ];
    }
}
