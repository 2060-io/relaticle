<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;

trait HasConnectMailboxActions
{
    public function connectGmailAction(): Action
    {
        return ConnectMailboxAction::make('connectGmail');
    }

    public function connectAzureAction(): Action
    {
        return ConnectMailboxAction::microsoft();
    }
}
