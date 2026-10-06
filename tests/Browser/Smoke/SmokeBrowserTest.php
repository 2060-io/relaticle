<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withVite();
});

it('public pages have no javascript errors', function (): void {
    $this->visit('/')
        ->assertNoJavaScriptErrors();
});

// Shiki highlights code by shelling out to node against node_modules/shiki, so
// this lives in the browser suite, the only CI job with the JS toolchain
// installed. The Feature-level documentation tests run with highlighting off.
it('highlights fenced code blocks on documentation pages', function (): void {
    $this->visit('/developers/contributing')
        ->assertPresent('pre.shiki');
});

it('loads every hero screenshot from the built assets', function (): void {
    $page = $this->visit('/');

    foreach (['pipeline', 'companies', 'custom-fields'] as $tab) {
        $page->click("#tab-{$tab}")
            ->assertScript("(() => { const img = document.querySelector('#panel-{$tab} img'); return img.complete && img.naturalWidth > 0; })()");
    }

    $page->assertNoJavaScriptErrors();
});

it('serves every press screenshot download from the built assets', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 42])]);

    $document = HTMLDocument::createFromString((string) $this->get('/press')->assertOk()->getContent(), LIBXML_NOERROR);
    $downloads = $document->querySelectorAll('#screenshots a[download]');

    expect($downloads->length)->toBe(6);

    foreach ($downloads as $download) {
        $path = public_path(ltrim((string) parse_url($download->getAttribute('href'), PHP_URL_PATH), '/'));

        expect($path)->toBeFile();
        expect(filesize($path))->toBeGreaterThan(0);
    }
});

it('links the screenshot downloads in the press markdown', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 42])]);

    $this->get('/press', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->assertSee('app-pipeline-preview-dark');
});
