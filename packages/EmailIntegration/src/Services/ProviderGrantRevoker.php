<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Throwable;

final readonly class ProviderGrantRevoker
{
    public function revoke(EmailProvider $provider, ?string $token): void
    {
        if ($provider !== EmailProvider::GMAIL || blank($token)) {
            return;
        }

        try {
            Http::asForm()
                ->timeout(5)
                ->post('https://oauth2.googleapis.com/revoke', ['token' => $token])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Could not revoke the Google grant for a disconnected mailbox.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
