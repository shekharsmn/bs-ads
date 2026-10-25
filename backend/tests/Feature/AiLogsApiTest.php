<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdLibraryAudit;
use App\Models\AdLibraryAuditAiRequest;
use App\Models\GeminiRequestLog;
use App\Models\MetaAccount;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiLogsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_report_and_content_audit_ai_requests(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $account = MetaAccount::create([
            'user_id' => $user->id,
            'label' => 'Primary account',
            'account_id' => 'act_123',
            'app_id' => 'app-id',
            'app_secret' => 'secret',
            'access_token' => 'token',
        ]);
        $report = Report::create([
            'user_id' => $user->id,
            'meta_account_id' => $account->id,
            'status' => 'complete',
            'period' => 'last_30d',
        ]);
        GeminiRequestLog::create([
            'user_id' => $user->id,
            'report_id' => $report->id,
            'meta_account_id' => $account->id,
            'ad_name' => 'Legacy creative',
            'call_type' => 'static_analysis',
            'input_tokens' => 120,
            'output_tokens' => 30,
        ]);

        $audit = $this->createAudit($user, 'Content audit');
        AdLibraryAuditAiRequest::create([
            'ad_library_audit_id' => $audit->id,
            'stage' => 'creative',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'status' => 'complete',
            'input_tokens' => 900,
            'output_tokens' => 100,
            'estimated_cost' => 0.000098,
        ]);
        AdLibraryAuditAiRequest::create([
            'ad_library_audit_id' => $audit->id,
            'stage' => 'personas',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'status' => 'complete',
            'input_tokens' => 400,
            'output_tokens' => 50,
            'estimated_cost' => 0.000050,
        ]);

        $otherUser = User::factory()->create();
        $otherAudit = $this->createAudit($otherUser, 'Hidden audit');
        AdLibraryAuditAiRequest::create([
            'ad_library_audit_id' => $otherAudit->id,
            'stage' => 'context',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'status' => 'complete',
        ]);

        $this->actingAs($user)->getJson('/api/ai-logs')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonFragment([
                'log_source' => 'content_audit',
                'audit_id' => $audit->id,
                'scope_name' => 'Acme',
                'request_name' => 'Content audit',
                'call_type' => 'creative',
                'input_tokens' => 900,
                'output_tokens' => 100,
            ])
            ->assertJsonFragment([
                'log_source' => 'report',
                'report_id' => $report->id,
                'scope_name' => 'Primary account',
                'request_name' => 'Legacy creative',
            ]);

        $this->actingAs($user)->getJson('/api/ai-logs?account_id='.$account->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.log_source', 'report');

        $groupedResponse = $this->actingAs($user)->getJson('/api/ai-logs?view=grouped')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('summary.request_count', 4)
            ->assertJsonPath('summary.estimated_request_count', 1);

        $contentAudit = collect($groupedResponse->json('data'))->firstWhere('log_source', 'content_audit');
        $this->assertSame($audit->id, $contentAudit['audit_id']);
        $this->assertSame('Content audit', $contentAudit['group_name']);
        $this->assertSame(2, $contentAudit['request_count']);
        $this->assertSame(1300, $contentAudit['input_tokens']);
        $this->assertSame(150, $contentAudit['output_tokens']);
        $this->assertCount(2, $contentAudit['requests']);
        $this->assertEqualsWithDelta(0.000148, (float) $contentAudit['estimated_cost'], 0.000001);
        $this->assertEqualsWithDelta(0.000166, (float) $groupedResponse->json('summary.estimated_cost'), 0.000001);

        $this->actingAs(User::factory()->create(['role' => UserRole::Marketer]))->getJson('/api/ai-logs')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]))->getJson('/api/ai-logs')->assertForbidden();
    }

    private function createAudit(User $user, string $name): AdLibraryAudit
    {
        return AdLibraryAudit::create([
            'created_by' => $user->id,
            'company_name' => 'Acme',
            'company_type' => 'competitor',
            'name' => $name,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'model_key' => 'gemini:gemini-2.5-flash-lite',
            'status' => 'creative_review',
        ]);
    }
}
