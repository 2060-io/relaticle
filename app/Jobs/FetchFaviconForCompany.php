<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Support\Http\SsrfGuard;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use finfo;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

#[DeleteWhenMissingModels]
#[Timeout(30)]
#[Tries(1)]
#[UniqueFor(600)]
final class FetchFaviconForCompany implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly Company $company) {}

    public function handle(): void
    {
        try {
            $domainName = self::sourceUrl($this->company);

            if ($domainName === null) {
                return;
            }

            $this->company->getMedia(Company::LOGO_MEDIA_COLLECTION)
                ->reject(fn (Media $logo): bool => self::fetchedFrom($logo, $domainName))
                ->each
                ->delete();

            $favicon = Favicon::driver('high-quality')->fetch($domainName);
            $url = $favicon?->getFaviconUrl();

            if ($url === null) {
                return;
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                return;
            }

            if (! SsrfGuard::isAllowed($url)) {
                return;
            }

            $response = SsrfGuard::guard(Http::timeout(15))->get($url);

            if (! $response->successful()) {
                return;
            }

            $body = $response->body();
            $extension = Company::LOGO_MIME_TYPES[new finfo(FILEINFO_MIME_TYPE)->buffer($body)] ?? null;

            if ($extension === null) {
                return;
            }

            $logo = $this->company
                ->addMediaFromString($body)
                ->usingFileName("logo.{$extension}")
                ->usingName('company_logo')
                ->withCustomProperties([
                    'domain' => $domainName,
                    'original_size' => $favicon->getIconSize(),
                    'icon_type' => $favicon->getIconType(),
                    'fetched_at' => now()->toIso8601String(),
                ])
                ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

            $this->company->clearMediaCollectionExcept(Company::LOGO_MEDIA_COLLECTION, $logo);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function sourceUrl(Company $company): ?string
    {
        // The custom-fields package registers the tenant relation under the name
        // `team`, so the relation has to be named rather than guessed.
        $domainsField = $company->customFields()
            ->whereBelongsTo($company->workspace, 'team')
            ->where('code', CompanyField::DOMAINS->value)
            ->first();

        if ($domainsField === null) {
            return null;
        }

        // Reading a value walks every custom field value on the company, and a company
        // with more than one of them trips strict lazy loading outside production.
        $company->load('customFieldValues.customField.options');

        $domains = $company->getCustomFieldValue($domainsField);
        $domain = is_array($domains) ? ($domains[0] ?? null) : $domains;

        if (blank($domain)) {
            return null;
        }

        return Str::startsWith($domain, ['http://', 'https://']) ? $domain : "https://{$domain}";
    }

    public static function fetchedFrom(Media $logo, string $sourceUrl): bool
    {
        return $logo->getCustomProperty('domain', $sourceUrl) === $sourceUrl;
    }

    public function uniqueId(): string
    {
        return (string) $this->company->getKey();
    }
}
