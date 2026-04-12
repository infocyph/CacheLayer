.. _adapters.serialization:

===================================
Serialization in Adapters
===================================

All adapters rely on `CachePayloadCodec` and `ValueSerializer` to persist
arbitrary values consistently.

The payload format stores:

* value
* absolute expiration timestamp (or null)
* internal format marker

See :ref:`serializer` for resource handlers, closure support, and serializer API details.
