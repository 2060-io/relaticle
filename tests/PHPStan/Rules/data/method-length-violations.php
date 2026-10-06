<?php

declare(strict_types=1);

namespace App\Support;

final class MethodLengthViolationsFixture
{
    public function overTheCap(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;

        return $first + $second + $third;
    }

    public function listedAndGrown(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;
        $fourth = 4;

        return $first + $second + $third + $fourth;
    }

    public function listedAndShrunk(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;

        return $first + $second + $third;
    }

    public function listedAndNowWithinTheCap(): int
    {
        return 1;
    }
}
