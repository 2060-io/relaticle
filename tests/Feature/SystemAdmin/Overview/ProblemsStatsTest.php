<?php

declare(strict_types=1);

use App\Features\Billing;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ProblemsStats;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(ProblemsStats::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    Feature::define(Billing::class, true);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function rateDown(Workspace $workspace, User $user, string $key, CarbonImmutable $at): ChatMessageFeedback
{
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => "Feedback {$key}",
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'assistant',
        'content' => 'answer',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return ChatMessageFeedback::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'rating' => ChatMessageFeedback::RATING_DOWN,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('renders on an empty database', function (): void {
    livewire(ProblemsStats::class)
        ->assertOk()
        ->assertSee("What's going wrong?")
        ->assertSee('Trial abuse suspects')
        ->assertSee('Stuck after setup')
        ->assertSee('Left the setup wizard')
        ->assertSee('Thumbs down this week');
});

it('counts stuck owners exactly as the stuck list shows them', function (): void {
    $stuck = OverviewData::owner(CarbonImmutable::parse('2026-10-05 10:00:00'));
    $active = OverviewData::owner(CarbonImmutable::parse('2026-10-05 10:00:00'));
    OverviewData::typedMessage(OverviewData::workspaceOf($active), $active, CarbonImmutable::parse('2026-10-06 10:00:00'));
    $tooNew = OverviewData::owner(CarbonImmutable::parse('2026-10-14 10:00:00'));

    livewire(ProblemsStats::class)->assertSee('50%');

    livewire(ListWorkspaces::class)
        ->filterTable('stuck_after_setup')
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($stuck)])
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($active), OverviewData::workspaceOf($tooNew)]);
});

it('counts genuine signups who never made a workspace', function (): void {
    $left = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    $stayed = OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)->assertSee('50%');

    livewire(ListUsers::class)
        ->filterTable('no_workspace')
        ->assertCanSeeTableRecords([$left])
        ->assertCanNotSeeTableRecords([$stayed]);
});

it('counts wizard leavers exactly as the user list opened by the tile shows them', function (): void {
    $left = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    $tooOld = User::factory()->create(['created_at' => now()->subDays(40), 'email_verified_at' => now()->subDays(40)]);
    $unverified = User::factory()->unverified()->create(['created_at' => now()->subDays(5)]);
    $stayed = OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)->assertSee('1 of 2 signups in 30 days');

    livewire(ListUsers::class)
        ->filterTable('genuine_signup')
        ->filterTable('no_workspace')
        ->filterTable('signed_up', [
            'from' => ViewerTime::today()->subDays(30)->toDateString(),
            'until' => ViewerTime::today()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$left])
        ->assertCanNotSeeTableRecords([$tooOld, $unverified, $stayed]);
});

it('reports how many of the wizard leavers signed up through Google or Microsoft', function (): void {
    $viaOauth = User::factory()->create(['created_at' => now()->subDays(5), 'email_verified_at' => now()->subDays(5)]);
    UserSocialAccount::factory()->create(['user_id' => $viaOauth->getKey(), 'provider_name' => 'google']);
    User::factory()->create(['created_at' => now()->subDays(6), 'email_verified_at' => now()->subDays(6)]);
    OverviewData::owner(now()->subDays(5));

    livewire(ProblemsStats::class)
        ->assertSee('67%')
        ->assertSee('2 of 3 signups in 30 days, 1 via Google or Microsoft');
});

it('counts trial abuse suspects as the workspace list shows them and notes who is still spending', function (): void {
    config()->set('system-admin.abuse_timezones', ['Asia/Tehran']);

    $suspect = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran'])));
    $genuineOwner = OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran']);
    $genuine = OverviewData::trial(OverviewData::workspaceOf($genuineOwner));
    OverviewData::ownRecord($genuine, $genuineOwner, now());

    AiCreditTransaction::query()->create([
        'workspace_id' => $suspect->getKey(), 'user_id' => $suspect->user_id, 'idempotency_key' => 'p-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => 1_000, 'metadata' => [], 'created_at' => now()->subDay(),
    ]);

    livewire(ProblemsStats::class)
        ->assertSeeInOrder(['Trial abuse suspects', '1', 'Some are still spending credits'])
        ->assertSee('filters%5Babuse_suspect%5D%5BisActive%5D=1', escape: false);

    livewire(ListWorkspaces::class)
        ->filterTable('abuse_suspect')
        ->assertCanSeeTableRecords([$suspect])
        ->assertCanNotSeeTableRecords([$genuine]);
});

it('says no suspect spent credits this week when their spending is older', function (): void {
    config()->set('system-admin.abuse_timezones', ['Asia/Tehran']);

    $suspect = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran'])));
    AiCreditTransaction::query()->create([
        'workspace_id' => $suspect->getKey(), 'user_id' => $suspect->user_id, 'idempotency_key' => 'p-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => 1_000, 'metadata' => [], 'created_at' => now()->subDays(8),
    ]);

    livewire(ProblemsStats::class)->assertSee('None spent credits this week');
});

it('counts thumbs down from this week only', function (): void {
    $user = OverviewData::owner();
    $workspace = OverviewData::workspaceOf($user);

    rateDown($workspace, $user, 'today', now());
    rateDown($workspace, $user, 'yesterday', now()->subDay());
    rateDown($workspace, $user, 'old', now()->subWeeks(2));

    livewire(ProblemsStats::class)->assertSee('filters%5Brating%5D%5Bvalue%5D=down', escape: false);
    expect(ProblemsStats::thumbsDownThisWeek())->toBe(2);
});

it('hides the abuse tile when billing is off', function (): void {
    Feature::define(Billing::class, false);

    livewire(ProblemsStats::class)->assertDontSee('Trial abuse suspects');
});
