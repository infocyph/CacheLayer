.. _functions:

==========================
Global Helper Functions
==========================

CacheLayer autoloads helper functions from ``src/functions.php``.

memoize()
---------

.. php:function:: memoize(?callable $callable = null, array $params = []): mixed

Two modes:

* ``memoize()`` returns the singleton ``Infocyph\CacheLayer\Memoize\Memoizer``
* ``memoize($callable, $params)`` executes memoized call lookup for global/static scope

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

* ``remember()`` returns the singleton ``Memoizer``
* ``remember($object, $callable, $params)`` caches value per object instance

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

Clears the process-local ``memoize()``, object ``remember()``, and ``once()``
state. Persistent workers should call it at a request boundary when values must
not leak into a later request.
