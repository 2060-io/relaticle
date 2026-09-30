<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Exceptions;

use RuntimeException;

final class ImportStoreException extends RuntimeException
{
    private bool $notFound = false;

    private bool $lockTimeout = false;

    private bool $uploadFailed = false;

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
        $exception = new self("Could not lock import store {$importId} within {$waitSeconds}s.");
        $exception->lockTimeout = true;

        return $exception;
    }

    public function isLockTimeout(): bool
    {
        return $this->lockTimeout;
    }

    public static function lockLost(string $importId): self
    {
        $exception = new self("The write lock on import store {$importId} expired before the upload.");
        $exception->uploadFailed = true;

        return $exception;
    }

    public static function snapshotFailed(string $importId, string $reason): self
    {
        $exception = new self("Could not upload import store {$importId}: {$reason}");
        $exception->uploadFailed = true;

        return $exception;
    }

    public static function downloadFailed(string $importId): self
    {
        return new self("Could not download import store {$importId}.");
    }

    public function isUploadFailure(): bool
    {
        return $this->uploadFailed;
    }
}
