<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocsRepository;

mutates(DocsRepository::class);

beforeEach(function (): void {
    $this->fixturePath = storage_path('framework/testing/docs-repository-'.Str::random(8));
});

afterEach(function (): void {
    File::deleteDirectory($this->fixturePath);
});

function writeFeatureGatedFixture(string $root, string $feature): void
{
    File::ensureDirectoryExists("{$root}/help/gated");
    File::ensureDirectoryExists("{$root}/help/open");
    File::put("{$root}/help/gated/_index.md", "---\ntitle: Gated\ndescription: A gated category.\norder: 1\nfeature: {$feature}\n---\n\nBody.\n");
    File::put("{$root}/help/gated/inside.md", "---\ntitle: Inside\ndescription: A page in the gated category.\norder: 1\n---\n\nBody.\n");
    File::put("{$root}/help/open/_index.md", "---\ntitle: Open\ndescription: An open category.\norder: 2\n---\n\nBody.\n");
    File::put("{$root}/help/open/outside.md", "---\ntitle: Outside\ndescription: A page in the open category.\norder: 1\n---\n\nBody.\n");

    Config::set('documentation.content_path', $root);
}

it('parses a content file into a page keyed by its path', function (): void {
    $page = app(DocsRepository::class)->find('help/getting-started/create-your-first-company');

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Create your first company')
        ->and($page->area)->toBe('help')
        ->and($page->category)->toBe('getting-started')
        ->and($page->order)->toBe(1)
        ->and($page->body)->not->toContain('---')
        ->and($page->updated?->format('Y-m-d'))->toBe('2026-08-12');
});

it('parses a category _index file', function (): void {
    $category = app(DocsRepository::class)->findCategory('help/getting-started');

    expect($category)->not->toBeNull()
        ->and($category->title)->not->toBeEmpty()
        ->and($category->description)->not->toBeEmpty();
});

it('orders pages within a category by their order key', function (): void {
    $orders = app(DocsRepository::class)->pagesIn('help/getting-started')->map(fn ($p): int => $p->order)->values()->all();

    expect($orders)->toBe(array_values(array_unique($orders)))
        ->and($orders)->toBe(collect($orders)->sort()->values()->all());
});

it('does not expose _index files as pages', function (): void {
    expect(app(DocsRepository::class)->pages()->keys()->filter(fn (string $k): bool => str_contains($k, '_index')))->toBeEmpty();
});

it('throws and names the offending file when a page is missing its title', function (): void {
    File::ensureDirectoryExists("{$this->fixturePath}/help/getting-started");
    File::put(
        "{$this->fixturePath}/help/getting-started/broken.md",
        "---\ndescription: A page with no title.\norder: 1\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $this->fixturePath);

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/getting-started/broken.md');
});

it('throws and names the offending file when a page is missing its order', function (): void {
    File::ensureDirectoryExists("{$this->fixturePath}/help/getting-started");
    File::put(
        "{$this->fixturePath}/help/getting-started/broken.md",
        "---\ntitle: Broken page\ndescription: A page with no order.\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $this->fixturePath);

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/getting-started/broken.md');
});

it('hides a feature-gated category and its pages while the feature is off', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'EmailIntegration');
    Feature::deactivate(EmailIntegration::class);

    $repository = new DocsRepository;

    expect($repository->categories()->keys()->all())->toBe(['help/open'])
        ->and($repository->pages()->keys()->all())->toBe(['help/open/outside'])
        ->and($repository->find('help/gated/inside'))->toBeNull()
        ->and($repository->findCategory('help/gated'))->toBeNull()
        ->and($repository->pagesIn('help/gated'))->toBeEmpty();
});

it('shows a feature-gated category and its pages while the feature is on', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'EmailIntegration');
    Feature::activate(EmailIntegration::class);

    $repository = new DocsRepository;

    expect($repository->categories()->keys()->all())->toBe(['help/gated', 'help/open'])
        ->and($repository->find('help/gated/inside'))->not->toBeNull();
});

it('throws and names the offending file when a category gates on an unknown feature', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'NoSuchFeature');

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/gated/_index.md');
});
