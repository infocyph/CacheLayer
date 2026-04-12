.. _memoize:

===================
Memoization
===================

CacheLayer includes process-local memoization primitives for fast repeated
in-process calls.

Available components:

* ``Infocyph\CacheLayer\Memoize\Memoizer``
* ``Infocyph\CacheLayer\Memoize\OnceMemoizer``
* ``Infocyph\CacheLayer\Memoize\MemoizeTrait``
* global helpers ``memoize()``, ``remember()``, and ``once()``

.. toctree::
   :maxdepth: 1

   memoize/functions
   memoize/trait
