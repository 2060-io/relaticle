<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property CarbonImmutable $date
 * @property int $amount_micros
 * @property CarbonImmutable $fetched_at
 */
#[Fillable(['provider', 'date', 'amount_micros', 'fetched_at'])]
#[WithoutTimestamps]
final class AiProviderCost extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount_micros' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
