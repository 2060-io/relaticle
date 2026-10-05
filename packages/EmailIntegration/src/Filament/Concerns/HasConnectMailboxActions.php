<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\Workspace;
use Filament\Actions\Action;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use RuntimeException;

trait HasConnectMailboxActions
{
    public function connectGmailAction(): Action
    {
        return ConnectMailboxAction::make('connectGmail');
    }

    public function connectAzureAction(): Action
    {
        return Action::make('connectAzure')
            ->label(__('filament/pages/email-accounts.actions.connect_azure'))
            ->icon(EmailProvider::AZURE->getIcon())
            ->color('gray')
            ->outlined()
            ->visible(fn (): bool => filled(config('services.azure.client_id')))
            ->url(fn (): string => MailboxOAuthWorkspace::redirectUrl('azure', $this->mailboxOAuthWorkspace(), Livewire::originalUrl()));
    }

    private function mailboxOAuthWorkspace(): Workspace
    {
        $workspace = filament()->getTenant();

        throw_unless($workspace instanceof Workspace, RuntimeException::class, 'Mailbox OAuth requires an active workspace.');

        return $workspace;
    }
}
