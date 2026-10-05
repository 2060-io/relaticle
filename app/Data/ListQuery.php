<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

final readonly class ListQuery
{
    /**
     * @param  array<int|string, mixed>|string|null  $include
     * @param  array<int|string, mixed>|string|null  $fields
     */
    public function __construct(
        public mixed $filter = null,
        public ?string $sort = null,
        public array|string|null $include = null,
        public array|string|null $fields = null,
        public int $perPage = 15,
        public ?int $page = null,
        public bool $cursor = false,
        public ?string $viewerZone = null,
    ) {}

    public function toRequest(): Request
    {
        return new Request(array_filter([
            'filter' => $this->filter,
            'sort' => $this->sort,
            'include' => $this->include,
            'fields' => $this->fields,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
