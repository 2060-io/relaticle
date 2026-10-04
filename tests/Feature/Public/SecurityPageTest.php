<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;

function securityPageText(string $html): string
{
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $text = strip_tags($text);

    return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? $text);
}

it('renders the security page with its structured data', function (): void {
    $html = $this->get('/security')->assertOk()->getContent();

    expect($html)->toContain(__('How Relaticle protects your data'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"')
        ->and($html)->toContain(route('security'));
});

it('names each service provider on a hosted install', function (string $provider): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/security')->assertOk()->assertSee($provider);
})->with(['Hetzner', 'Mailcoach', 'Postmark', 'Stripe', 'Sentry', 'Fathom Analytics', 'Anthropic, OpenAI', 'Google, DuckDuckGo', 'Maxforms', 'Oh Dear']);

it('leaves the provider table out where hosted billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/security')->assertOk()
        ->assertDontSee('Hetzner')
        ->assertDontSee(__('Service providers'));
});

it('says plainly that it holds no SOC 2 or ISO 27001 certification', function (): void {
    $text = securityPageText($this->get('/security')->assertOk()->getContent());

    expect($text)->toContain('Is Relaticle SOC 2 or ISO 27001 certified? No. Relaticle holds neither certification today.');
});

it('scopes the approval step to the built-in assistant and says connected assistants write directly', function (): void {
    $text = securityPageText($this->get('/security')->assertOk()->getContent());

    expect($text)->toContain(config('chat.assistant_name').' proposes every change as a card and waits for your approval.')
        ->and($text)->toContain('Their changes apply directly.');
});

it('links the privacy policy, the security contact and security.txt', function (): void {
    $html = $this->get('/security')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('policy.show').'"')
        ->and($html)->toContain('href="mailto:security@relaticle.com"')
        ->and($html)->toContain('href="'.route('securityTxt').'"');
});

it('describes mailbox handling where the email integration is on', function (): void {
    config()->set('relaticle.features.email_integration', true);
    Feature::for(null)->activate(EmailIntegration::class);

    $this->get('/security')->assertOk()->assertSee('never changes, labels or deletes messages in your mailbox');
});

it('leaves mailbox handling out where the email integration is off', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::for(null)->deactivate(EmailIntegration::class);

    $this->get('/security')->assertOk()->assertDontSee('never changes, labels or deletes messages in your mailbox');
});

it('is linked from the privacy policy and the footer', function (): void {
    $this->get('/privacy-policy')->assertOk()->assertSee('/security#providers', false);

    $home = $this->get('/')->assertOk()->getContent();

    preg_match('/<footer[\s\S]*?<\/footer>/', $home, $footer);

    expect($footer[0] ?? '')->toContain('href="'.route('security').'"');
});

it('names the built-in assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/security')->assertOk()->assertSee('Testbot');
});
