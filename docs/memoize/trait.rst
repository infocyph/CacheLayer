.. _memoize.trait:

=====================
``MemoizeTrait``
=====================

``Infocyph\CacheLayer\Memoize\MemoizeTrait`` provides lightweight per-object
memoization for class internals.

API
---

* ``memoize(string $key, callable $producer): mixed``
* ``memoizeClear(?string $key = null): void``

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Memoize\MemoizeTrait;

   final class ReportService
   {
       use MemoizeTrait;

       public function expensiveCount(): int
       {
           return $this->memoize(__METHOD__, function (): int {
               return computeCount();
           });
       }

       public function clearMemoizedCount(): void
       {
           $this->memoizeClear(__METHOD__);
       }
   }
