<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum CreatedPeriod: string implements HasLabel
{
    case Today = 'today';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case ThisYear = 'this_year';
    case Earlier = 'earlier';

    public static function of(CarbonInterface $createdAt, CarbonImmutable $now): self
    {
        foreach (self::cases() as $period) {
            $start = $period->startsAt($now);

            if (! $start instanceof CarbonImmutable) {
                continue;
            }

            if ($createdAt->greaterThanOrEqualTo($start)) {
                return $period;
            }
        }

        return self::Earlier;
    }

    public function startsAt(CarbonImmutable $now): ?CarbonImmutable
    {
        return match ($this) {
            self::Today => $now->startOfDay(),
            self::ThisWeek => $now->startOfWeek(),
            self::ThisMonth => $now->startOfMonth(),
            self::ThisYear => $now->startOfYear(),
            self::Earlier => null,
        };
    }

    public function getLabel(): string
    {
        return __("filament/resources/note.created_periods.{$this->value}");
    }
}
