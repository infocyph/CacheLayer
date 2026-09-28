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
        $stack = [[$value, 0, []]];
        $nodes = 0;

        while ($stack !== []) {
            [$current, $depth, $references] = array_pop($stack);
            if (++$nodes > self::MAX_NODES) {
                throw new InvalidArgumentException('The value graph exceeds the supported traversal budget.');
            }
            if (!is_array($current)) {
                continue;
            }
            if ($depth >= self::MAX_DEPTH && $current !== []) {
                throw new InvalidArgumentException('The value graph exceeds the supported nesting depth.');
            }

            foreach ($current as $key => $item) {
                $childReferences = $references;
                $reference = ReflectionReference::fromArrayElement($current, $key);
                if ($reference instanceof ReflectionReference) {
                    $id = bin2hex($reference->getId());
                    if (isset($childReferences[$id])) {
                        throw new InvalidArgumentException('Recursive array references are not supported.');
                    }
                    $childReferences[$id] = true;
                }
                $stack[] = [$item, $depth + 1, $childReferences];
            }
        }
    }
}
