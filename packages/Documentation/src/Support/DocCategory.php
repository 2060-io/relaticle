<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

final readonly class DocCategory
{
    /** @param class-string|null $feature */
    public function __construct(
        public string $path,
        public string $area,
        public string $title,
        public string $description,
        public int $order,
        public string $body,
        public ?string $feature = null,
    ) {}
}
