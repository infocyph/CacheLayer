.. _security:

========
Security
========

CacheLayer includes optional hardening controls for payload integrity,
serialization policy, and filesystem defaults.

Quick hardening setup:

.. code-block:: php

   $cache
       ->configurePayloadSecurity(
           integrityKey: 'replace-with-strong-secret',
           maxPayloadBytes: 8_388_608,
       )
       ->configureSerializationSecurity(
           allowClosurePayloads: false,
           allowObjectPayloads: false,
       );

Environment variables:

* ``CACHELAYER_PAYLOAD_INTEGRITY_KEY``
* ``CACHELAYER_MAX_PAYLOAD_BYTES``

For detailed policy and rollout guidance, see project root ``SECURITY.md``.
