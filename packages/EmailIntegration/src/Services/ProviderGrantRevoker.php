<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class ProviderGrantRevoker
{
    public function revoke(ConnectedAccount $account, ?string $token): void
    {
        if ($account->provider !== EmailProvider::GMAIL || blank($token) || $this->grantIsStillInUse($account)) {
            return;
        }

        try {
            Http::asForm()
                ->timeout(5)
                ->post('https://oauth2.googleapis.com/revoke', ['token' => $token])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Could not revoke the Google grant for a disconnected mailbox.', [
                'connected_account_id' => $account->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    // Google revokes the whole grant for the user and client, which every workspace
    // connecting the same mailbox shares.
    private function grantIsStillInUse(ConnectedAccount $account): bool
    {
        return ConnectedAccount::query()
            ->whereKeyNot($account->getKey())
            ->where('provider', $account->provider)
            ->where(fn (Builder $query): Builder => filled($account->provider_account_id)
                ? $query->where('provider_account_id', $account->provider_account_id)
                : $query->where('email_address', $account->email_address))
            ->exists();
    }
}
