.. _serializer:

=====================
Closure Serialization
=====================

CacheLayer uses native PHP serialization for ordinary cache records. In 4.0,
cache-record deserialization rejects objects and Closures by default; opt in
explicitly with ``CacheOptions`` only for trusted data and trusted storage.
When ``integrityKey`` is configured, the record signature is bound to its
logical storage identity and cache key, so moving a signed blob to another key
or namespace is rejected.

The specialized ``ClosureSerializer`` exists only because PHP cannot serialize
a ``Closure`` directly. It does not expose a mixed-value serializer API and
does not support resource handlers or recursively wrapped values.

Public API
----------

* ``ClosureSerializer::serialize(Closure $closure): string``
* ``ClosureSerializer::unserialize(string $payload): Closure``
* ``ClosureSerializer::isSerialized(string $payload): bool``
* ``ClosureSerializer::signed(string $key): SignedClosureSerializer``

Signed Closures
---------------

.. code-block:: php

   use Infocyph\CacheLayer\Serializer\ClosureSerializer;

   $serializer = ClosureSerializer::signed('application-secret');
   $payload = $serializer->serialize(static fn (int $value): int => $value * 2);
   $closure = $serializer->unserialize($payload);

Unsigned and signed Closure payloads are separate formats. Signature failures,
malformed payloads, and payloads that do not contain a Closure throw
``InvalidArgumentException``.

Closures are executable code and may capture objects, credentials, or request
state. Only serialize trusted closures into a trusted backend, prefer signed
payloads, and do not treat a valid signature as proof that captured data is
safe to retain or reuse in another request.
