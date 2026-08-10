.. _serializer:

=====================
Closure Serialization
=====================

CacheLayer uses native PHP serialization for ordinary cache records. The
specialized ``ClosureSerializer`` exists only because PHP cannot serialize a
``Closure`` directly. It does not expose a mixed-value serializer API and does
not support resource handlers or recursively wrapped values.

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
