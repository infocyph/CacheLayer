.. _memoize:

===================
Memoization
===================

CacheLayer includes in-process memoization primitives for fast repeated calls.
The normal path is process-local; an active Runwire request receives isolated
request-owned memoizers, while a persistent concurrent Runwire runtime without a
shared request scope bypasses global memoization.

Available components:

* ``Infocyph\CacheLayer\Memoize\Memoizer``
* ``Infocyph\CacheLayer\Memoize\OnceMemoizer``
* ``Infocyph\CacheLayer\Memoize\MemoizeTrait``
* global helpers ``memoize()``, ``remember()``, and ``once()``

.. toctree::
   :maxdepth: 1

   memoize/functions
   memoize/trait
