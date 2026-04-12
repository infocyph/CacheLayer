.. _adapters.apcu:

=======================
APCu Adapter (``apcu``)
=======================

Factory: ``Cache::apcu(string $namespace = 'default')``

Requirements:

* ``ext-apcu``
* APCu enabled (``apcu_enabled()``)
* for CLI usage/tests: ``apcu.enable_cli=1``

Highlights:

* in-memory shared cache in the PHP runtime environment
* namespace-prefixed keys (``<ns>:<key>``)
* efficient bulk fetch through APCu array fetch path

``Cache::local()`` will choose APCu automatically when available.

Use When
--------

Use APCu when your cache can stay in local runtime memory and you want very
low latency without network calls.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::apcu('app');
   $cache->set('feature_flag:new_checkout', true, 60);

   if ($cache->has('feature_flag:new_checkout')) {
       // fast local hit
   }
