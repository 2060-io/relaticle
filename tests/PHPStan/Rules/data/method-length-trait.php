<?php

declare(strict_types=1);

namespace App\Support;

trait MethodLengthTraitFixture
{
    public function longInTrait(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;

        return $first + $second + $third;
    }
}

final class MethodLengthTraitUserFixture
{
    use MethodLengthTraitFixture;
}
