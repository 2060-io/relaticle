<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

mutates(ChatSidePanel::class);

it('renders the side panel on the dashboard', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertSourceHas('data-chat-side-panel');
});

it('stays closed when the browser goes back to a page where it was closed', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $assistantName = config('chat.assistant_name');

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->click('a.fi-sidebar-item-btn[href$="/people"]')
        ->waitForText('No people')
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]')
        ->click('[data-chat-side-panel] button[aria-label="Close chat panel"]')
        ->wait(0.5)
        ->assertMissing('[data-chat-side-panel]')
        ->click('a.fi-sidebar-item-btn[href$="/companies"]')
        ->waitForText('No companies')
        ->back()
        ->waitForText('No people')
        ->wait(0.5);

    $page->assertMissing('[data-chat-side-panel]');
});

it('does not restore an open panel when the browser goes back', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $assistantName = config('chat.assistant_name');

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->click('a.fi-sidebar-item-btn[href$="/people"]')
        ->waitForText('No people')
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]');

    $page->script("window.Livewire.navigate('/app/{$workspace->slug}/companies')");

    $page->waitForText('No companies')
        ->back()
        ->waitForText('No people')
        ->wait(0.5);

    $page->assertMissing('[data-chat-side-panel]');
});

it('stays open, docked beside the page, when a chat is picked from its history', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $assistantName = config('chat.assistant_name');

    DB::table('agent_conversations')->insert([
        'id' => 'side-panel-pick',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Acme onboarding',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contentMeetsPanel = <<<'JS'
        (() => {
            const wrapper = document.querySelector('.fi-app-main-wrapper');
            const contentEnd = wrapper.getBoundingClientRect().right - parseFloat(getComputedStyle(wrapper).paddingRight);

            return Math.round(contentEnd) === Math.round(document.querySelector('[data-chat-side-panel]').getBoundingClientRect().left);
        })()
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people")
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]')
        ->assertScript($contentMeetsPanel)
        ->click('[data-chat-side-panel] button[aria-label="View history"]')
        ->click('[data-chat-side-panel] span[title="Acme onboarding"]');

    $isOpenAfterPick = $page->script(<<<'JS'
        async () => {
            const panel = document.querySelector('[data-chat-side-panel]');
            const deadline = Date.now() + 10_000;

            while (Date.now() < deadline && Alpine.$data(panel).$wire.conversationId !== 'side-panel-pick') {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            await new Promise((resolve) => setTimeout(resolve, 500));

            return Alpine.$data(panel).open && getComputedStyle(panel).display !== 'none';
        }
    JS);

    expect($isOpenAfterPick)->toBeTrue();

    $page->assertScript($contentMeetsPanel)
        ->assertNoJavaScriptErrors();
});

it('covers the page instead of docking below the xl breakpoint', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $assistantName = config('chat.assistant_name');

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1100, 900)
        ->navigate("/app/{$workspace->slug}/people")
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]')
        ->assertScript('getComputedStyle(document.querySelector(".fi-app-main-wrapper")).paddingRight', '0px');
});
