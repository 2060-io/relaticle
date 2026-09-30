<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Exceptions;

use RuntimeException;

final class ImportStoreException extends RuntimeException
{
    private bool $notFound = false;

    public static function notFound(string $importId): self
    {
        $exception = new self("Import store {$importId} does not exist.");
        $exception->notFound = true;

        return $exception;
    }

    public function isNotFound(): bool
    {
        return $this->notFound;
    }

    public static function lockTimeout(string $importId, int $waitSeconds): self
    {
        return new self("Could not lock import store {$importId} within {$waitSeconds}s.");
    }

    public static function lockLost(string $importId): self
    {
        return new self("The write lock on import store {$importId} expired before the upload.");
    }

    public static function snapshotFailed(string $importId, string $reason): self
    {
        return new self("Could not upload import store {$importId}: {$reason}");
    }
}
