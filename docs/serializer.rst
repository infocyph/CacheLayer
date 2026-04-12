.. _serializer:

=====================
Value Serialization
=====================

`Infocyph\CacheLayer\Serializer\ValueSerializer` is used by adapters to encode
and decode cached payloads.

What it handles
---------------

* scalar values and arrays
* closures (via `opis/closure`)
* registered resource types

Core Methods
------------

* `serialize(mixed $value): string`
* `unserialize(string $blob): mixed`
* `encode(mixed $value, bool $base64 = true): string`
* `decode(string $payload, bool $base64 = true): mixed`
* `wrap(mixed $value): mixed`
* `unwrap(mixed $value): mixed`
* `registerResourceHandler(string $type, callable $wrapFn, callable $restoreFn): void`
* `clearResourceHandlers(): void`

Resource Handler Example
------------------------

.. code-block:: php

   use Infocyph\CacheLayer\Serializer\ValueSerializer;

   ValueSerializer::registerResourceHandler(
       'stream',
       function ($res): array {
           $meta = stream_get_meta_data($res);
           rewind($res);

           return [
               'mode' => $meta['mode'],
               'content' => stream_get_contents($res),
           ];
       },
       function (array $data) {
           $s = fopen('php://memory', $data['mode']);
           fwrite($s, $data['content']);
           rewind($s);

           return $s;
       },
   );

Notes
-----

* Registering the same resource type twice throws `InvalidArgumentException`.
* Wrapping/serializing unregistered resources throws `InvalidArgumentException`.
* Closure detection has an internal bounded memo cache.
