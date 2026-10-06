<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\OutlinedIcons;
use Filament\Facades\Filament;
use Symfony\Component\DomCrawler\Crawler;

mutates(OutlinedIcons::class);

it('draws the theme switcher buttons as outline icons', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->personalWorkspace());

    $response = $this->get(Dashboard::getUrl(tenant: $user->personalWorkspace()))->assertOk();

    $icons = (new Crawler((string) $response->getContent()))->filter('.fi-theme-switcher-btn svg');

    expect($icons->count())->toBe(3);

    $icons->each(function (Crawler $icon): void {
        expect($icon->attr('stroke'))->toBe('currentColor')
            ->and($icon->attr('viewBox'))->toBe('0 0 24 24');
    });
});
