<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\ComposeRecordRecipientResolver;

trait HasEmailComposeActions
{
    /**
     * Return the CRM record these emails belong to (People, Company, or Opportunity)
     */
    abstract protected function getCrmRecord(): Model;

    abstract public function hidesRecordMailbox(): bool;

    protected function composeEmailAction(): Action
    {
        return Action::make('composeEmail')
            ->label(__('filament/concerns/email-compose.actions.compose.label'))
            ->icon('heroicon-o-pencil-square')
            ->tooltip(__('filament/concerns/email-compose.actions.compose.tooltip'))
            ->visible(fn (): bool => $this->hasActiveConnectedAccount() && ! $this->hidesRecordMailbox())
            ->action(function (): void {
                $record = $this->getCrmRecord();

                $this->dispatch('composer:open', payload: [
                    'linkRecordType' => $record::class,
                    'linkRecordId' => (string) $record->getKey(),
                    'to' => resolve(ComposeRecordRecipientResolver::class)->toAddressesFor($record),
                ]);
            });
    }

    /**
     * Drives the compose launcher and the "connect a mailbox" empty states,
     * so it is read from blades as a computed property.
     */
    #[Computed]
    public function hasActiveConnectedAccount(): bool
    {
        /** @var Workspace|null $team */
        $team = filament()->getTenant();

        return ConnectedAccount::hasConnectedFor($this->getAuthenticatedUser(), $team);
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
