<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewSocialUser;
use App\Enums\SocialiteProvider;
use App\Filament\Pages\Auth\Login;
use App\Http\Controllers\Auth\CallbackController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Rules\InvitedEmail;
use App\Support\Auth\InvitationOnlySignup;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Features\SupportTesting\Testable;

mutates(Login::class, CallbackController::class, CreateNewSocialUser::class, InvitedEmail::class, InvitationOnlySignup::class);

function makeInvitedSocialiteUser(string $id, string $name, string $email): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

function signUpWithPassword(string $email): Testable
{
    return livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate');
}

// --- Password sign-up ---

it('lets anyone sign up with a password while invitation_only is off', function (): void {
    config()->set('relaticle.registration.invitation_only', false);

    signUpWithPassword('jane-open@gmail.com')->assertHasNoFormErrors();

    expect(User::query()->where('email', 'jane-open@gmail.com')->exists())->toBeTrue();
});

it('blocks password sign-up for an uninvited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    signUpWithPassword('jane-uninvited@gmail.com')->assertHasFormErrors(['email']);

    expect(User::query()->where('email', 'jane-uninvited@gmail.com')->exists())->toBeFalse();
});

it('lets an invited email sign up with a password when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    WorkspaceInvitation::factory()->create(['email' => 'jane-invited@gmail.com']);

    signUpWithPassword('jane-invited@gmail.com')->assertHasNoFormErrors();

    expect(User::query()->where('email', 'jane-invited@gmail.com')->exists())->toBeTrue();
});

it('blocks password sign-up when the only invitation has expired', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    WorkspaceInvitation::factory()->expired()->create(['email' => 'jane-expired@gmail.com']);

    signUpWithPassword('jane-expired@gmail.com')->assertHasFormErrors(['email']);

    expect(User::query()->where('email', 'jane-expired@gmail.com')->exists())->toBeFalse();
});

it('does not count an invitation without an expiry', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    WorkspaceInvitation::factory()->withoutExpiry()->create(['email' => 'jane-legacy@gmail.com']);

    signUpWithPassword('jane-legacy@gmail.com')->assertHasFormErrors(['email']);
});

it('matches the invitation on the canonical form of the email', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    WorkspaceInvitation::factory()->create(['email' => 'Jane.Case@gmail.com']);

    signUpWithPassword('jane.case@gmail.com')->assertHasNoFormErrors();
});

it('lets a visitor who arrived through an active invite link sign up', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    $workspace = Workspace::factory()->create();
    session(['url.intended' => route('workspaces.join', ['token' => $workspace->invite_link_token])]);

    signUpWithPassword('jane-link@gmail.com')->assertHasNoFormErrors();

    expect(User::query()->where('email', 'jane-link@gmail.com')->exists())->toBeTrue();
});

it('ignores an expired invite link in the session', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();
    session(['url.intended' => route('workspaces.join', ['token' => $workspace->invite_link_token])]);

    signUpWithPassword('jane-stale-link@gmail.com')->assertHasFormErrors(['email']);
});

// --- Social sign-up ---

it('blocks social sign-up for an uninvited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitedSocialiteUser('123', 'New User', 'new-social@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $this->assertGuest();
    expect(User::query()->where('email', 'new-social@example.com')->exists())->toBeFalse();
});

it('lets an invited email sign up through social login when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    WorkspaceInvitation::factory()->create(['email' => 'invited-social@example.com']);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitedSocialiteUser('456', 'Invited User', 'invited-social@example.com'),
    );

    $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    expect(User::query()->where('email', 'invited-social@example.com')->exists())->toBeTrue();
});

it('still signs in an existing social user when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    $user = User::factory()->withWorkspace()->create(['email' => 'existing-social@example.com']);
    $user->socialAccounts()->create([
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '789',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitedSocialiteUser('789', 'Existing User', 'existing-social@example.com'),
    );

    $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $this->assertAuthenticatedAs($user);
});
