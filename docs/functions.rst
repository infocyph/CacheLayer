.. _functions:

==========================
Global Helper Functions
==========================

CacheLayer autoloads helper functions from ``src/functions.php``.

memoize()
---------

.. php:function:: memoize(?callable $callable = null, array $params = []): mixed

Two modes:

* ``memoize()`` returns the memoizer owned by the current execution scope
* ``memoize($callable, $params)`` executes memoized call lookup in that scope

Without Runwire, or in a non-concurrent runtime, the normal owner is the
process-local singleton. An active Runwire request uses a request-owned isolated
memoizer. A bound persistent concurrent Runwire runtime without a shared request
scope bypasses global memoization: callable form executes directly and the
zero-argument form returns a fresh isolated memoizer.

Example:

.. code-block:: php

   $f = fn (int $x): int => $x + 1;

   $a = memoize($f, [5]);
   $b = memoize($f, [5]);

   // same cached result

remember()
----------

.. php:function:: remember(?object $object = null, ?callable $callable = null, array $params = []): mixed

Object-scoped memoization helper.

Two modes:

* ``remember()`` returns the memoizer owned by the current execution scope
* ``remember($object, $callable, $params)`` caches value per object instance in that scope

Runwire ownership follows the same request-isolation and concurrent no-scope
bypass rules as ``memoize()``.

If object is provided but callable is missing, it throws ``InvalidArgumentException``.

once()
------

.. php:function:: once(callable $callback): mixed

Executes callback once per call site context using
``Infocyph\CacheLayer\Memoize\OnceMemoizer``.

Useful for one-time initialization inside request/process scope.

.. code-block:: php

   $config = once(function () {
       return loadLargeConfigArray();
   });

flush_memoizers()
-----------------

.. php:function:: flush_memoizers(): void

Clears memoizer state owned by the current execution context. Inside a shared
Runwire request it flushes only that request's ``memoize()``, object
``remember()``, and ``once()`` state; otherwise it flushes the normal
process-local singleton state. One request does not reset another live request's
callable identities or values.
