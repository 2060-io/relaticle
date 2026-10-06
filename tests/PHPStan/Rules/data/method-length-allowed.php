<?php

declare(strict_types=1);

namespace App\Support;

abstract class MethodLengthAllowedFixture
{
    abstract public function withoutABody(): int;

    public function atTheCap(): int
    {
        $first = 1;

        return $first;
    }

    public function listedAtItsRecordedSize(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;

        return $first + $second + $third;
    }

    #[\Deprecated]
    #[\NoDiscard]
    public function attributesDoNotCount(): int
    {
        $first = 1;

        return $first;
    }
}

return new class
{
    public function up(): int
    {
        $first = 1;
        $second = 2;
        $third = 3;

        return $first + $second + $third;
    }
};
