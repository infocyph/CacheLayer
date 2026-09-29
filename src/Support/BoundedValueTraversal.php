<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

use InvalidArgumentException;
use ReflectionReference;

final class BoundedValueTraversal
{
    private const int MAX_DEPTH = 128;

    private const int MAX_NODES = 65_536;

    public static function assertSafe(mixed $value): void
    {
        $nodes = 0;
        self::visit($value, 0, [], $nodes);
    }

    /** @param array<mixed> $value */
    private static function assertDepth(int $depth, array $value): void
    {
        if ($depth >= self::MAX_DEPTH && $value !== []) {
            throw new InvalidArgumentException('The value graph exceeds the supported nesting depth.');
        }
    }

    private static function assertNodeBudget(int $nodes): void
    {
        if ($nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('The value graph exceeds the supported traversal budget.');
        }
    }

    /**
     * @param array<mixed> $current
     * @param array<string,true> $references
     * @return array<string,true>
     */
    private static function childReferences(array $current, int|string $key, array $references): array
    {
        $reference = ReflectionReference::fromArrayElement($current, $key);
        if (!$reference instanceof ReflectionReference) {
            return $references;
        }

        $id = bin2hex($reference->getId());
        if (isset($references[$id])) {
            throw new InvalidArgumentException('Recursive array references are not supported.');
        }
        $references[$id] = true;

        return $references;
    }

    /**
     * @param array<string,true> $references
     */
    private static function visit(
        mixed $value,
        int $depth,
        array $references,
        int &$nodes,
    ): void {
        self::assertNodeBudget(++$nodes);
        if (!is_array($value)) {
            return;
        }

        self::assertDepth($depth, $value);
        foreach ($value as $key => $item) {
            self::assertNodeBudget($nodes + 1);
            self::visit(
                $item,
                $depth + 1,
                self::childReferences($value, $key, $references),
                $nodes,
            );
        }
    }
}
