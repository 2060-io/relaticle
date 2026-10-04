<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads {area}/{category}/{slug}.md content files into DocPage/DocCategory
 * value objects, keyed by their path. Nav, search, sitemap entries, and the
 * `.md` variant of a page all derive from this manifest. The content files
 * are the single source of truth, never front matter.
 *
 * Resolved as a container singleton, so the manifest is parsed once, eagerly,
 * here in the constructor. A caller resolving this class already intends to
 * use it, and there is no cheaper "maybe I won't need it" case to defer for.
 */
final readonly class DocsRepository
{
    private const string CACHE_KEY = 'documentation.manifest';

    /** @var Collection<string, DocPage> */
    private Collection $pages;

    /** @var Collection<string, DocCategory> */
    private Collection $categories;

    public function __construct()
    {
        [$pages, $categories] = $this->resolve();

        $this->categories = $categories->filter(
            fn (DocCategory $category): bool => $category->feature === null || Feature::active($category->feature),
        );

        $gated = $categories->diffKeys($this->categories);

        $this->pages = $pages->reject(fn (DocPage $page): bool => $gated->has("{$page->area}/{$page->category}"));
    }

    /** @return Collection<string, DocPage> */
    public function pages(): Collection
    {
        return $this->pages;
    }

    /** @return Collection<string, DocCategory> */
    public function categories(): Collection
    {
        return $this->categories;
    }

    public function find(string $path): ?DocPage
    {
        return $this->pages->get($path);
    }

    public function findCategory(string $path): ?DocCategory
    {
        return $this->categories->get($path);
    }

    /** @return Collection<string, DocPage> */
    public function pagesIn(string $categoryPath): Collection
    {
        return $this->pages
            ->filter(fn (DocPage $page): bool => "{$page->area}/{$page->category}" === $categoryPath)
            ->sortBy(fn (DocPage $page): int => $page->order);
    }

    /** @return array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} */
    private function resolve(): array
    {
        $root = (string) config('documentation.content_path');

        if (! $this->cachingEnabled()) {
            return $this->build($root);
        }

        $signature = $this->signature($root);

        /** @var array{signature: string, pages: Collection<string, DocPage>, categories: Collection<string, DocCategory>}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached['signature'] === $signature) {
            return [$cached['pages'], $cached['categories']];
        }

        [$pages, $categories] = $this->build($root);

        // One stable key holding the current manifest, not one key per content
        // state. Otherwise every edit abandons the previous key forever with
        // no TTL to reclaim it.
        Cache::forever(self::CACHE_KEY, [
            'signature' => $signature,
            'pages' => $pages,
            'categories' => $categories,
        ]);

        return [$pages, $categories];
    }

    /**
     * Local dev edits content constantly, so `app.debug` always bypasses the
     * cache. `documentation.cache.enabled` (env DOCUMENTATION_CACHE_ENABLED)
     * is the operator-facing override for forcing a rebuild in any
     * environment without waiting for a file to change.
     */
    private function cachingEnabled(): bool
    {
        if ((bool) config('app.debug')) {
            return false;
        }

        return (bool) config('documentation.cache.enabled', true);
    }

    /** @return array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} */
    private function build(string $root): array
    {
        /** @var Collection<string, DocPage> $pages */
        $pages = collect();

        /** @var Collection<string, DocCategory> $categories */
        $categories = collect();

        if (! File::isDirectory($root)) {
            return [$pages, $categories];
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relative = str($file->getRelativePathname())->replace(DIRECTORY_SEPARATOR, '/')->beforeLast('.md')->value();
            $segments = explode('/', $relative);

            throw_if(count($segments) !== 3, RuntimeException::class, "Content file [{$relative}.md] must be nested as {area}/{category}/{slug}.md.");

            [$area, $category, $slugOrIndex] = $segments;
            [$meta, $body] = $this->parseFrontMatter($file->getContents(), $relative);

            if ($slugOrIndex === '_index') {
                $categories->put("{$area}/{$category}", $this->category($area, $category, $meta, $body, $relative));

                continue;
            }

            $pages->put($relative, $this->page($area, $category, $slugOrIndex, $meta, $body));
        }

        /** @var array<string, int> $categoryOrder */
        $categoryOrder = $categories->mapWithKeys(
            fn (DocCategory $category): array => [$category->path => $category->order],
        )->all();

        // Grouped by category, alphabetical within, which is a sane default listing.
        // This is deliberately NOT sorted by $page->order: pagesIn() is the
        // one place that contract lives, so a regression there can't hide
        // behind an accidentally-already-sorted pages() collection.
        return [
            $pages->sortBy(fn (DocPage $page): array => [
                $categoryOrder["{$page->area}/{$page->category}"] ?? PHP_INT_MAX,
                $page->title,
            ]),
            $categories->sortBy(fn (DocCategory $category): int => $category->order),
        ];
    }

    /** @param array<string, mixed> $meta */
    private function page(string $area, string $category, string $slug, array $meta, string $body): DocPage
    {
        $path = "{$area}/{$category}/{$slug}";

        return new DocPage(
            path: $path,
            area: $area,
            category: $category,
            slug: $slug,
            title: $this->requiredString($meta, 'title', $path),
            description: $this->requiredString($meta, 'description', $path),
            order: $this->requiredInt($meta, 'order', $path),
            related: array_values((array) ($meta['related'] ?? [])),
            body: $body,
            updated: $this->parseUpdated($meta['updated'] ?? null),
        );
    }

    /** @param array<string, mixed> $meta */
    private function category(string $area, string $category, array $meta, string $body, string $relative): DocCategory
    {
        return new DocCategory(
            path: "{$area}/{$category}",
            area: $area,
            title: $this->requiredString($meta, 'title', $relative),
            description: $this->requiredString($meta, 'description', $relative),
            order: $this->requiredInt($meta, 'order', $relative),
            body: $body,
            feature: $this->optionalFeature($meta, $relative),
        );
    }

    /**
     * A hash of every content file's name and modification time. An edit,
     * addition, or removal changes the hash, so a stale cached manifest is
     * detected by comparing signatures rather than by a separate bust signal.
     */
    private function signature(string $root): string
    {
        if (! File::isDirectory($root)) {
            return hash('sha256', $root);
        }

        $fileList = collect(File::allFiles($root))
            ->map(fn (SplFileInfo $file): string => $file->getRelativePathname().':'.$file->getMTime())
            ->implode('|');

        return hash('sha256', $root.'|'.$fileList);
    }

    /**
     * An unquoted YAML date resolves to a Unix timestamp, not a string, so
     * the front matter's `updated:` has to be accepted in both shapes.
     */
    private function parseUpdated(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function parseFrontMatter(string $raw, string $path): array
    {
        throw_unless(str_starts_with($raw, "---\n"), RuntimeException::class, "Content file [{$path}.md] is missing its front-matter block.");

        $remainder = substr($raw, 4);

        throw_unless(str_contains($remainder, "\n---\n"), RuntimeException::class, "Content file [{$path}.md] has an unterminated front-matter block.");

        [$yaml, $body] = explode("\n---\n", $remainder, 2);

        /** @var array<string, mixed> $meta */
        $meta = (array) Yaml::parse($yaml);

        return [$meta, ltrim($body)];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return class-string|null
     */
    private function optionalFeature(array $meta, string $path): ?string
    {
        $name = $meta['feature'] ?? null;

        if ($name === null) {
            return null;
        }

        $feature = is_string($name) ? "App\\Features\\{$name}" : '';

        throw_unless(class_exists($feature), RuntimeException::class, "Content file [{$path}.md] gates on an unknown feature.");

        return $feature;
    }

    /** @param array<string, mixed> $meta */
    private function requiredString(array $meta, string $key, string $path): string
    {
        $value = $meta[$key] ?? null;

        throw_if(! is_string($value) || $value === '', RuntimeException::class, "Content file [{$path}.md] is missing required front-matter key \"{$key}\".");

        return $value;
    }

    /** @param array<string, mixed> $meta */
    private function requiredInt(array $meta, string $key, string $path): int
    {
        $value = $meta[$key] ?? null;

        throw_unless(is_int($value), RuntimeException::class, "Content file [{$path}.md] is missing required front-matter key \"{$key}\".");

        return $value;
    }
}
