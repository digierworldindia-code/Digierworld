<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Services\SurveyService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The field agent panel over HTTP.
 *
 * The services are tested on their own; this is about the doors. An agent
 * must reach their own round and nothing else, and every screen they use in
 * a showroom has to render — including on the phone, where a view that
 * throws is indistinguishable from no signal.
 *
 * @internal
 */
final class AgentPanelTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    private string $agentId;
    private string $dealerId;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealerId = $this->makeDealer();
        $this->agentId  = $this->makeUser(['FIELD_AGENT']);
        $this->variant  = $this->makeProduct()['variant'];

        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId,
            'survey_interval_days' => SurveyService::DEFAULT_INTERVAL_DAYS, 'active' => 1, 'created_at' => utc_now(),
        ]);
    }

    // =====================================================================
    // Who gets in
    // =====================================================================

    /** @dataProvider agentAddresses */
    public function testAnAnonymousVisitorIsSentToSignIn(string $path): void
    {
        $result = $this->get($path);

        $this->assertTrue($result->isRedirect(), "{$path} must not answer an anonymous request");
        $this->assertStringContainsString('login', $result->getRedirectUrl());
    }

    public static function agentAddresses(): array
    {
        return [
            'round'         => ['agent'],
            'dealers'       => ['agent/dealers'],
            'surveys'       => ['agent/surveys'],
            'discrepancies' => ['agent/discrepancies'],
            'reviews'       => ['agent/reviews'],
        ];
    }

    public function testAWarehouseOperatorIsSentBackToTheirOwnPanel(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $result = $this->get('agent');

        $this->assertTrue($result->isRedirect());
        $this->assertStringContainsString('/warehouse', (string) $result->getRedirectUrl());
    }

    public function testADealerCannotReachTheAgentPanel(): void
    {
        $this->signedInAs($this->makeUser(['DEALER'], $this->makeDealer()));

        $result = $this->get('agent');

        $this->assertTrue($result->isRedirect());
        $this->assertStringNotContainsString('/agent', (string) $result->getRedirectUrl());
    }

    /**
     * The round is the agent's own. A dealer they do not cover answers
     * exactly as a dealer that does not exist, so the refusal tells them
     * nothing about the rest of the network.
     */
    public function testAnAgentCannotOpenADealerTheyDoNotCover(): void
    {
        $this->signedInAs($this->agentId);
        $elsewhere = $this->makeDealer();

        // The framework turns this into a 404 page in a real request; in the
        // test harness it arrives as the exception that causes it.
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('could not find that dealer');

        $this->get('agent/dealers/' . $elsewhere);
    }

    public function testAnAgentCannotReadAnotherAgentsSurvey(): void
    {
        $otherAgent = $this->makeUser(['FIELD_AGENT']);
        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $otherAgent, 'dealer_id' => $this->dealerId,
            'survey_interval_days' => 182, 'active' => 1, 'created_at' => utc_now(),
        ]);

        $this->actingAs($otherAgent);
        $theirs = (new SurveyService($this->db, service('requestContext')))->open($this->dealerId);

        $this->signedInAs($this->agentId);

        $this->expectException(PageNotFoundException::class);
        $this->get('agent/surveys/' . $theirs['id'] . '/report');
    }

    // =====================================================================
    // The screens
    // =====================================================================

    public function testEveryAgentScreenRenders(): void
    {
        $this->signedInAs($this->agentId);

        $unit    = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);
        $service = new SurveyService($this->db, service('requestContext'));
        $survey  = $service->open($this->dealerId);
        $service->record($survey['id'], $unit['serial'], 'NOT_FOUND');

        foreach ([
            'agent',
            'agent/dealers',
            'agent/dealers/' . $this->dealerId,
            'agent/dealers/' . $this->dealerId . '?status=DEALER_RECEIVED&warranty=NONE',
            'agent/surveys',
            'agent/surveys/' . $survey['id'],
            'agent/surveys/' . $survey['id'] . '/report',
            'agent/discrepancies',
            'agent/reviews',
            'agent/reviews/new',
            'agent/reviews/new?code=' . rawurlencode($unit['serial']),
        ] as $path) {
            $result = $this->get($path);
            $this->assertSame(200, $result->response()->getStatusCode(), "{$path} did not render");
        }
    }

    /** The agent's own identifier is on their screen, as the business asked. */
    public function testTheAgentIdIsShownOnTheirRound(): void
    {
        $this->signedInAs($this->agentId);

        $body = (string) $this->get('agent')->response()->getBody();

        $this->assertStringContainsString('AGT-', $body);
    }

    public function testTheSurveyScreenCarriesTheScannerAndNoInlineScript(): void
    {
        $this->signedInAs($this->agentId);
        $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);
        $survey = (new SurveyService($this->db, service('requestContext')))->open($this->dealerId);

        $body = (string) $this->get('agent/surveys/' . $survey['id'])->response()->getBody();

        $this->assertStringContainsString('assets/js/qr-scanner.js', $body);
        $this->assertStringContainsString('data-scanner-field="observation"', $body,
            'the observation travels with each scan, so a shelf is one tap per mattress');

        preg_match_all('/<script\b([^>]*)>/i', $body, $tags);
        foreach ($tags[1] as $attributes) {
            $this->assertStringContainsString('src=', $attributes, 'an inline script would be blocked by the policy');
        }
    }

    /** The report has to carry what a verification report is for. */
    public function testTheReportCarriesTheDealerTheAgentAndTheFigures(): void
    {
        $this->signedInAs($this->agentId);

        $units   = [
            $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId),
            $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId),
        ];
        $service = new SurveyService($this->db, service('requestContext'));
        $survey  = $service->open($this->dealerId);
        $service->record($survey['id'], $units[0]['serial'], 'PRESENT');
        $service->record($survey['id'], $units[1]['serial'], 'NOT_FOUND');
        $service->submit($survey['id'], 'Stockroom half emptied for painting.');

        $body = (string) $this->get('agent/surveys/' . $survey['id'] . '/report')->response()->getBody();

        $this->assertStringContainsString($survey['reference'], $body);
        $this->assertStringContainsString('AGT-', $body);
        $this->assertStringContainsString(brand('name'), $body);
        $this->assertStringContainsString('Stockroom half emptied', $body);
        $this->assertStringContainsString($units[0]['serial'], $body);
        $this->assertStringContainsString($units[1]['serial'], $body);
        $this->assertStringContainsString('Stock discrepancy', $body);
    }

    // =====================================================================
    // Recording, over HTTP
    // =====================================================================

    public function testAScanPostedFromTheSurveyScreenRecordsTheObservation(): void
    {
        $this->signedInAs($this->agentId);
        $unit   = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);
        $survey = (new SurveyService($this->db, service('requestContext')))->open($this->dealerId);

        $result = $this->post('agent/surveys/' . $survey['id'] . '/record', $this->signed([
            'code' => $unit['serial'], 'source' => 'CAMERA', 'observation' => 'DEALER_SAYS_SOLD',
        ]));

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertTrue($body['ok']);
        $this->assertSame('POSSIBLE_UNRECORDED_SALE', $body['outcome']);
        $this->assertArrayHasKey('csrf', $body);

        // And still nothing was sold.
        $this->assertSame(0, $this->db->table('sales')->where('mattress_id', $unit['id'])->countAllResults());
    }

    public function testAScanWithNoObservationRecordsNothing(): void
    {
        $this->signedInAs($this->agentId);
        $unit   = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);
        $survey = (new SurveyService($this->db, service('requestContext')))->open($this->dealerId);

        $result = $this->post('agent/surveys/' . $survey['id'] . '/record', $this->signed([
            'code' => $unit['serial'], 'source' => 'CAMERA',
        ]));

        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame(0, $this->db->table('dealer_survey_items')->countAllResults());
    }

    public function testRaisingAWarrantyReviewThroughTheFormLeavesTheWarrantyAlone(): void
    {
        $this->signedInAs($this->agentId);

        $unit = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);

        $result = $this->post('agent/reviews', $this->signed([
            'code' => $unit['serial'], 'reason' => 'INVOICE_NOT_UPLOADED', 'remark' => 'Nothing on file.',
        ]));

        $this->assertTrue($result->isRedirect());

        $case = $this->db->table('warranty_review_cases')->where('mattress_id', $unit['id'])->get()->getRowArray();
        $this->assertNotNull($case);
        $this->assertSame('RECOMMENDED', $case['status']);
        $this->assertNull($case['decided_by'], 'an agent recommends; nobody has decided');
    }

    /**
     * The limits stated in the brief, checked at the door rather than in a
     * service: an agent must not be able to reach the screens that change
     * manufacturing, dispatch or stock.
     *
     * @dataProvider forbiddenToAgents
     */
    public function testAnAgentCannotReachTheScreensThatChangeStock(string $path): void
    {
        $this->signedInAs($this->agentId);

        $result = $this->get($path);
        $code   = $result->response()->getStatusCode();

        $this->assertNotSame(200, $code, "{$path} must not answer a field agent");
    }

    public static function forbiddenToAgents(): array
    {
        return [
            'warehouse panel'    => ['warehouse'],
            'new batch'          => ['warehouse/batches/new'],
            'prepare dispatch'   => ['warehouse/dispatch'],
            'admin console'      => ['admin'],
            'admin dispatches'   => ['admin/dispatches'],
            'admin users'        => ['admin/users'],
            'admin settings'     => ['admin/settings'],
            'audit trail'        => ['admin/audit'],
        ];
    }

    /**
     * Posts carry a CSRF token, because the application requires one.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function signed(array $fields): array
    {
        return $fields + [csrf_token() => csrf_hash()];
    }
}
