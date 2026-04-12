.. _cookbook:

==========================
Cookbook and Process Flows
==========================

This page shows practical end-to-end usage patterns so you can wire CacheLayer
into real application flows quickly and safely.

Flow 1: File Adapter (Single Host)
----------------------------------

Use this when your app runs on one machine (or shared filesystem) and you want
zero external dependencies.

Process flow:

1. Create the cache pool with a stable namespace and directory.
2. Read through cache using ``remember()``.
3. Tag related entries so updates can invalidate groups.
4. Export metrics for visibility.

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::file('shop', __DIR__ . '/storage/cache');

   // Read-through cache on miss with stampede protection.
   $product = $cache->remember('product:42', function ($item) {
       $item->expiresAfter(300);
       return loadProductFromDatabase(42);
   }, tags: ['products', 'product:42']);

   // On product update, invalidate only related cache.
   $cache->invalidateTags(['products', 'product:42']);

   // Optional: inspect adapter-level metrics.
   $metrics = $cache->exportMetrics();
   // ['file' => ['hit' => ..., 'miss' => ..., 'set' => ...]]

Flow 2: PDO Adapter (SQLite Default, MySQL/PostgreSQL Ready)
-------------------------------------------------------------

Use this when you want SQL-backed caching and easy portability across
SQLite/MySQL/MariaDB/PostgreSQL via PDO.

Process flow:

1. Start local/dev with default SQLite behavior (no DSN required).
2. Move to MySQL/PostgreSQL in staging/production by changing DSN only.
3. Keep the same cache API and tagging flow.
4. Keep stampede protection enabled via the auto-configured PDO lock provider.

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   // Development: defaults to sqlite:<temp-file>
   $cache = Cache::pdo('billing');

   // Production example (PostgreSQL):
   // $cache = Cache::pdo(
   //     'billing',
   //     'pgsql:host=127.0.0.1;port=5432;dbname=app',
   //     'postgres',
   //     'secret',
   // );

   $invoice = $cache->remember('invoice:2026-1001', function ($item) {
       $item->expiresAfter(180);
       return buildInvoicePayload(1001);
   }, tags: ['invoices', 'customer:77']);

   // Invalidate by business scope when source data changes.
   $cache->invalidateTag('customer:77');

Recommended Rollout Pattern
---------------------------

1. Start with ``Cache::local()`` or ``Cache::file()``.
2. Add tags to all business-domain cache keys.
3. Replace direct ``get()+set()`` misses with ``remember()``.
4. Watch ``exportMetrics()`` and tune TTL values.
5. Switch adapter backend only when scaling needs change.
