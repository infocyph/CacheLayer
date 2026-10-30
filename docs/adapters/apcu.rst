.. _adapters.apcu:

=======================
APCu Adapter (``apcu``)
=======================

Factory: ``Cache::apcu(string $namespace = 'default')``

Requirements:

* ``ext-apcu``
* APCu enabled (``apcu_enabled()``)
* for CLI usage/tests: start PHP with ``-d apc.enable_cli=1`` or configure
  ``apc.enable_cli=1`` in the CLI INI file; this setting cannot be enabled with
  ``ini_set()`` after startup

Highlights:

* in-memory shared cache in the PHP runtime environment
* physically separated data and metadata keys (``<ns>:d:<key>`` and ``<ns>:m:<name>``)
* efficient bulk fetch through APCu array fetch path

Use When
--------

Use APCu when your cache can stay in local runtime memory and you want very
low latency without network calls.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::apcu('app');
   $cache->set('feature_flag.new_checkout', true, 60);

   if ($cache->has('feature_flag.new_checkout')) {
       // fast local hit
   }
