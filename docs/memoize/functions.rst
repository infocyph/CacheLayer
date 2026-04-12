.. _memoize.functions:

=========================
Memoize Function Helpers
=========================

memoize(callable, params)
-------------------------

`memoize($callable, $params)` caches return values by:

* callable signature
* normalized parameters hash

Internally this uses `Memoizer::get()`.

.. code-block:: php

   $sum = memoize(fn (int $a, int $b) => $a + $b, [2, 3]);

remember(object, callable, params)
----------------------------------

`remember($object, $callable, $params)` caches values per object instance
(using `WeakMap` inside `Memoizer`).

When the object is garbage-collected, its memoized bucket is removable.

.. code-block:: php

   $svc = new MyService();

   $value = remember($svc, fn () => expensiveLookup());

once(callback)
--------------

`once($callback)` is call-site based memoization via `OnceMemoizer`.

Key details:

* cache key includes caller context + callback fingerprint
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
