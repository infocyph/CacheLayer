.. _memoize.functions:

=========================
Memoize Function Helpers
=========================

memoize(callable, params)
-------------------------

``memoize($callable, $params)`` caches return values by:

* callable identity, including Closure instance/source metadata and bound-object identity
* normalized parameters hash

Closure capture graphs are not traversed for identity; this avoids recursive-capture exhaustion while distinct live Closure instances remain isolated.

Internally this uses ``Memoizer::get()``.

.. code-block:: php

   $sum = memoize(fn (int $a, int $b) => $a + $b, [2, 3]);

remember(object, callable, params)
----------------------------------

``remember($object, $callable, $params)`` caches values per object instance
(using ``WeakMap`` inside ``Memoizer``).

When the object is garbage-collected, its memoized bucket is removable.

.. code-block:: php

   $svc = new MyService();

   $value = remember($svc, fn () => expensiveLookup());

once(callback)
--------------

``once($callback)`` is call-site based memoization via ``OnceMemoizer``.

Key details:

* cache key includes the exact caller file/line/context + callback fingerprint
* closure source fingerprinting is memoized
* bounded cache size (2048 entries), oldest entry evicted

.. code-block:: php

   $token = once(fn () => bin2hex(random_bytes(16)));

Inspecting/Resetting Memoizer State
-----------------------------------

.. code-block:: php

   $memo = memoize();
   $stats = $memo->stats(); // ['hits' => ..., 'misses' => ..., 'total' => ...]

   $memo->flush();
   flush_memoizers(); // also resets once() and is suitable at request boundaries

Without Runwire request ownership, memoized state is process-local. In PHP-FPM
it normally follows the process lifecycle, while event loops and persistent
workers can reuse it across requests. When an active Runwire request is shared,
the helpers use request-owned memoizers and ``flush_memoizers()`` flushes only
that request. In a persistent concurrent Runwire runtime with no shared request
scope, helper calls bypass global memoization rather than risk cross-request
leakage.
