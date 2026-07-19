.. _security:

==============
Security Guide
==============

This document captures CacheLayer hardening guidance and rollout options.

Threat Model
------------

CacheLayer stores serialized payloads in backends that may be writable by local
or network-adjacent actors if infrastructure is misconfigured. Main risks:

* Deserialization abuse when payloads are tampered.
* Executable cache-file abuse in ``phpFiles`` adapter.
* Insecure default temp-directory usage in shared environments.

Implemented Hardening
---------------------

1) Serialization and Payload Hardening
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

* ``CachePayloadCodec`` supports signed payloads (HMAC-SHA256).
* Signed payloads are rejected when integrity verification fails.
* When an integrity key is configured, unsigned payloads are rejected.
* Maximum payload size can be enforced at decode time.
* Compressed payload expansion is capped before deserialization.
* ``ValueSerializer`` supports strict mode:

  * block closure payloads
  * block object payloads

* Native scalar/array serialization paths now decode with
  ``allowed_classes => false``.

Runtime API:

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

Environment Variables:

* ``CACHELAYER_PAYLOAD_INTEGRITY_KEY``
* ``CACHELAYER_MAX_PAYLOAD_BYTES``

2) ``phpFiles`` Adapter Guardrails
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``phpFiles`` keeps executable ``.php`` cache files for performance, so strict
directory controls are required. Runtime checks now reject:

* symlinked cache directories
* world-writable cache directories

Use ``phpFiles`` only on trusted hosts and private directories.

3) Temp-Directory Hardening
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Default filesystem locations are now scoped under dedicated cachelayer temp
subdirectories:

* file adapter default base: ``sys_get_temp_dir()/cachelayer/files``
* php-files adapter default base: ``sys_get_temp_dir()/cachelayer/phpfiles``
* PDO SQLite default: ``sys_get_temp_dir()/cachelayer/pdo/cache_<ns>.sqlite``

These paths are created with restrictive permissions and world-writable checks.

The shared-memory adapter also stores its ``ftok`` token in a private
``cachelayer/shared-memory`` directory, creates the segment for the current
user only, and serializes read-modify-write operations with a filesystem lock.

4) Network Timeouts
~~~~~~~~~~~~~~~~~~~

Redis/Valkey connections created from a DSN use bounded one-second connect and
read timeouts. Inject a preconfigured client when an application needs
different timeout, TLS, retry, or socket-context settings.

Recommended Production Profile
------------------------------

1. Set ``CACHELAYER_PAYLOAD_INTEGRITY_KEY`` to a strong random secret.
2. Disable closure/object payloads unless explicitly required.
3. Use explicit, private cache directories outside shared temp space.
4. Prefer non-executable file storage adapters over ``phpFiles`` where
   possible.

Backend-Specific Notes
----------------------

Redis / Valkey
~~~~~~~~~~~~~~

* Require authentication and network-level access controls.
* Prefer TLS-enabled connections when crossing host boundaries.
* Avoid exposing Redis/Valkey ports directly to public networks.

MongoDB / ScyllaDB / SQL Backends
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

* Use least-privilege database credentials scoped to cache
  tables/collections.
* Enforce transport security (TLS) where supported.
* Keep cache schema/table permissions separate from application primary data.

Tiered Cache Deployments (L1/L2/DB)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

For ``Cache::tiered()`` production setups:

* keep L1 (APCu) local-process only
* protect L2 (Redis/Valkey) as a private service
* treat DB fallback resolvers as trusted code paths only
* configure bounded TTLs to reduce stale or poisoned cache lifetime

Disclosure
----------

If you discover a security issue, please open a private report to project
maintainers before public disclosure.
