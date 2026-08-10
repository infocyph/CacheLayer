.. _adapters.serialization:

===================================
Serialization in Adapters
===================================

All adapters rely on ``CachePayloadCodec``. It uses PHP's native serialization
for ordinary values and delegates only top-level ``Closure`` values to
``ClosureSerializer``.

The payload format stores:

* value
* value encoding (native or Closure)
* absolute expiration timestamp (or null)
* internal format marker

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memory('serialize-demo');
   $cache->set('payload', ['a' => 1, 'b' => [2, 3]], 60);

   $payload = $cache->get('payload');

Resources and nested Closures are not supported. See :ref:`serializer` for the
Closure-only serializer API.
