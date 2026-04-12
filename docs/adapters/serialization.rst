.. _adapters.serialization:

===================================
Serialization in Adapters
===================================

All adapters rely on ``CachePayloadCodec`` and ``ValueSerializer`` to persist
arbitrary values consistently.

The payload format stores:

* value
* absolute expiration timestamp (or null)
* internal format marker

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memory('serialize-demo');
   $cache->set('payload', ['a' => 1, 'b' => [2, 3]], 60);

   $payload = $cache->get('payload');

See :ref:`serializer` for resource handlers, closure support, and serializer API details.
