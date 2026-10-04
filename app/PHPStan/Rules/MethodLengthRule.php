<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<InClassMethodNode>
 */
final readonly class MethodLengthRule implements Rule
{
    private const string LIST_FILE = 'phpstan-method-length.php';

    /**
     * @param  array<string, int>  $grandfathered
     */
    public function __construct(
        private int $maxLines,
        private array $grandfathered,
    ) {}

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->getClassReflection()->isAnonymous()) {
            return [];
        }

        $method = $node->getOriginalNode();

        if ($method->stmts === null) {
            return [];
        }

        $owner = $scope->getTraitReflection() ?? $node->getClassReflection();
        $lines = $method->getEndLine() - $method->name->getStartLine() + 1;

        $violation = $this->violation("{$owner->getName()}::{$method->name->toString()}", $lines);

        if ($violation === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message($violation)
                ->identifier('app.shape.methodLength')
                ->build(),
        ];
    }

    private function violation(string $method, int $lines): ?string
    {
        $recorded = $this->grandfathered[$method] ?? null;

        if ($recorded === null) {
            return $lines > $this->maxLines
                ? "{$method}() is {$lines} lines. Keep a method within {$this->maxLines}: extract a step and name it for what it returns."
                : null;
        }

        $list = self::LIST_FILE;

        return match (true) {
            $lines > $recorded => "{$method}() grew from {$recorded} to {$lines} lines. It is on the shrink-only list in {$list}: extract a step instead.",
            $lines <= $this->maxLines => "{$method}() is now {$lines} lines, within the cap of {$this->maxLines}. Remove its entry from {$list}.",
            $lines < $recorded => "{$method}() shrank from {$recorded} to {$lines} lines. Lower its entry in {$list} to {$lines}.",
            default => null,
        };
    }
}
