<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\MethodLengthRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<MethodLengthRule>
 */
abstract class MethodLengthRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new MethodLengthRule(
            maxLines: 6,
            grandfathered: [
                'App\Support\MethodLengthViolationsFixture::listedAndGrown' => 8,
                'App\Support\MethodLengthViolationsFixture::listedAndShrunk' => 12,
                'App\Support\MethodLengthViolationsFixture::listedAndNowWithinTheCap' => 9,
                'App\Support\MethodLengthAllowedFixture::listedAtItsRecordedSize' => 8,
            ],
        );
    }
}

pest()->extend(MethodLengthRuleTest::class);

it('flags a method over the cap and every listed method whose size changed', function (): void {
    $this->analyse([__DIR__.'/data/method-length-violations.php'], [
        ['App\Support\MethodLengthViolationsFixture::overTheCap() is 8 lines. Keep a method within 6: extract a step and name it for what it returns.', 9],
        ['App\Support\MethodLengthViolationsFixture::listedAndGrown() grew from 8 to 9 lines. It is on the shrink-only list in phpstan-method-length.php: extract a step instead.', 18],
        ['App\Support\MethodLengthViolationsFixture::listedAndShrunk() shrank from 12 to 8 lines. Lower its entry in phpstan-method-length.php to 8.', 28],
        ['App\Support\MethodLengthViolationsFixture::listedAndNowWithinTheCap() is now 4 lines, within the cap of 6. Remove its entry from phpstan-method-length.php.', 37],
    ]);
});

it('names a trait method by its trait, not by the class using it', function (): void {
    require_once __DIR__.'/data/method-length-trait.php';

    $this->analyse([__DIR__.'/data/method-length-trait.php'], [
        ['App\Support\MethodLengthTraitFixture::longInTrait() is 8 lines. Keep a method within 6: extract a step and name it for what it returns.', 9],
    ]);
});

it('allows short methods listed methods at their recorded size and anonymous classes', function (): void {
    $this->analyse([__DIR__.'/data/method-length-allowed.php'], []);
});
