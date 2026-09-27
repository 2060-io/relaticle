<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Closure;
use Illuminate\Support\Str;

final readonly class LocalCopy
{
    /**
     * @template TResult
     *
     * @param  resource|null  $stream
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public static function of(mixed $stream, Closure $callback): mixed
    {
        throw_unless(is_resource($stream), UploadException::notFound());

        $path = sys_get_temp_dir().'/local-copy-'.Str::ulid();

        try {
            touch($path);
            chmod($path, 0600);
            file_put_contents($path, $stream);

            return $callback($path);
        } finally {
            fclose($stream);
            @unlink($path);
        }
    }
}
