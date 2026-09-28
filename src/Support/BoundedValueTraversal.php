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
        /** @var list<array{value:mixed,depth:int,references:array<string,true>}> $stack */
        $stack = [['value' => $value, 'depth' => 0, 'references' => []]];
        $nodes = 0;

        while (($frame = array_pop($stack)) !== null) {
            self::assertNodeBudget(++$nodes);
            $current = $frame['value'];
            if (!is_array($current)) {
                continue;
            }

            self::assertDepth($frame['depth'], $current);
            self::appendChildren($stack, $current, $frame['depth'], $frame['references']);
        }
    }

    /** @param array<mixed> $value */
    /**
     * @param list<array{value:mixed,depth:int,references:array<string,true>}> $stack
     * @param array<mixed> $current
     * @param array<string,true> $references
     */
    private static function appendChildren(
        array &$stack,
        array $current,
        int $depth,
        array $references,
    ): void {
        foreach ($current as $key => $item) {
            $childReferences = self::childReferences($current, $key, $references);
            $stack[] = [
                'value' => $item,
                'depth' => $depth + 1,
                'references' => $childReferences,
            ];
        }
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
}
