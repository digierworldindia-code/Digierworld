<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Settings;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * The two forms anonymous visitors can submit: a contact enquiry and a
 * dealership application. Both are rate limited at the route, CSRF-protected,
 * and carry a honeypot field.
 *
 * Nothing is rejected for looking like spam — the score only orders the
 * review queue. A filled honeypot is still stored (score 80+) and answered as
 * a success so an automated sender cannot tell it was caught.
 */
final class PublicFormService
{
    public const REQUIREMENTS = [
        'PRODUCT_ENQUIRY'  => 'Product enquiry',
        'WARRANTY_SUPPORT' => 'Warranty support',
        'DEALERSHIP'       => 'Dealership',
        'BULK_ORDER'       => 'Bulk order',
        'OTHER'            => 'Something else',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function instance(): self
    {
        return new self(db_connect());
    }

    /** @param array{name:string, phone:string, email?:string, city?:string, requirement:string, message:string} $in */
    public function submitLead(array $in, bool $honeypotFilled): string
    {
        if (! Settings::flag('website.contact_form_enabled')) {
            throw AppException::rule('The contact form is currently closed. Please call us instead.');
        }
        $spam = self::spamScore($in['message'], $in['name'], $honeypotFilled);
        $id   = uuid4();

        Tx::run(function (BaseConnection $db) use ($in, $spam, $id): void {
            $db->table('contact_submissions')->insert([
                'id'          => $id,
                'name'        => $in['name'],
                'phone'       => $in['phone'],
                'email'       => ($in['email'] ?? '') === '' ? null : strtolower($in['email']),
                'city'        => ($in['city'] ?? '') === '' ? null : $in['city'],
                'requirement' => $in['requirement'],
                'message'     => $in['message'],
                'source'      => 'website',
                'ip_hash'     => ip_hash(),
                'user_agent'  => mb_substr((string) service('request')->getUserAgent(), 0, 400) ?: null,
                'spam_score'  => $spam,
            ]);
            Audit::instance()->record('LEAD_SUBMITTED', 'contact_submission', $id, null, ['requirement' => $in['requirement'], 'spamScore' => $spam]);
        }, $this->db);

        if ($spam >= 50) {
            log_message('info', 'security.LEAD_FLAGGED_SPAM score={score}', ['score' => $spam]);
        }

        return $id;
    }

    /** @param array<string,mixed> $in */
    public function submitApplication(array $in, bool $honeypotFilled): string
    {
        if (! Settings::flag('website.dealer_application_enabled')) {
            throw AppException::rule('Dealership applications are closed at the moment.');
        }
        $spam = self::spamScore((string) ($in['message'] ?? ''), $in['owner_name'], $honeypotFilled);
        $id   = uuid4();

        Tx::run(function (BaseConnection $db) use ($in, $spam, $id): void {
            $db->table('dealer_applications')->insert([
                'id'                        => $id,
                'business_name'             => $in['business_name'],
                'owner_name'                => $in['owner_name'],
                'mobile'                    => $in['mobile'],
                'email'                     => strtolower($in['email']),
                'city'                      => $in['city'],
                'state'                     => $in['state'],
                'address'                   => $in['address'],
                'gst_number'                => ($in['gst_number'] ?? '') === '' ? null : strtoupper($in['gst_number']),
                'has_existing_business'     => ! empty($in['has_existing_business']) ? 1 : 0,
                'existing_business_details' => ($in['existing_business_details'] ?? '') === '' ? null : $in['existing_business_details'],
                'message'                   => ($in['message'] ?? '') === '' ? null : $in['message'],
                'ip_hash'                   => ip_hash(),
                'user_agent'                => mb_substr((string) service('request')->getUserAgent(), 0, 400) ?: null,
                'spam_score'                => $spam,
            ]);
            Audit::instance()->record('DEALER_APPLICATION_SUBMITTED', 'dealer_application', $id, null, ['city' => $in['city'], 'state' => $in['state']]);
        }, $this->db);

        return $id;
    }

    /** Cheap, explainable heuristics — unchanged from the previous platform. */
    public static function spamScore(string $message, string $name, bool $honeypotFilled): int
    {
        $score = $honeypotFilled ? 80 : 0;
        $score += min(30, preg_match_all('~https?://~i', $message) * 15);
        if (preg_match('/\b(casino|crypto|loan|seo services|backlink|viagra)\b/i', $message)) {
            $score += 30;
        }
        if (mb_strlen($message) < 15) {
            $score += 10;
        }
        if (preg_match('/(.)\1{6,}/u', $message) || preg_match('/(.)\1{6,}/u', $name)) {
            $score += 15;
        }
        if (! preg_match('/\s/', trim($message))) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * Listed dealers for the public locator: active, opted in, and only the
     * fields a customer needs to visit them.
     *
     * @return list<array<string,mixed>>
     */
    public function dealers(?string $state, ?string $city, ?string $pincode): array
    {
        $builder = $this->db->table('dealers')
            ->select('business_name, address_line1, address_line2, city, state, pincode, phone, is_showroom')
            ->where(['status' => 'ACTIVE', 'public_listed' => 1, 'deleted_at' => null]);
        if ($state !== null) {
            $builder->where('state', $state);
        }
        if ($city !== null) {
            $builder->where('city', $city);
        }
        if ($pincode !== null) {
            $builder->where('pincode', $pincode);
        }

        return $builder->orderBy('state')->orderBy('city')->orderBy('business_name')->limit(100)->get()->getResultArray();
    }

    /** @return list<array{state:string, city:string, dealers:int}> */
    public function regions(): array
    {
        return $this->db->table('dealers')->select('state, city, COUNT(*) AS dealers')
            ->where(['status' => 'ACTIVE', 'public_listed' => 1, 'deleted_at' => null])
            ->groupBy(['state', 'city'])->orderBy('state')->orderBy('city')->get()->getResultArray();
    }
}
