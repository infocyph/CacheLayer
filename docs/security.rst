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
* Per-cache codec policy can:

  * block top-level Closure payloads
  * block native object payloads

* Native payload decoding uses ``allowed_classes => false`` when objects are
  disabled.
* ``ClosureSerializer`` accepts only ``Closure`` and
  ``SignedClosureSerializer`` verifies HMAC-SHA256 before decoding.
* Closure payloads remain executable and may retain captured objects or request
  state. Signing proves integrity, not that captures are safe for another
  process or request.

Per-instance construction API:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\CacheLayer\Cache\CacheOptions;

   function createCache(string $integrityKey): Cache
   {
       return Cache::redis('app', options: new CacheOptions(
           integrityKey: $integrityKey,
           maxPayloadBytes: 8_388_608,
           allowClosures: false,
           allowObjects: false,
       ));
   }

Pass secret material from the application's composition root. CacheLayer does
not read process environment state.

2) ``phpFiles`` Adapter Guardrails
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``phpFiles`` keeps executable ``.php`` cache files for performance, so strict
directory controls are required. Runtime checks now reject:

* symlinked cache directories, namespace roots, ancestors, and lock paths
* world-writable cache directories

The adapter executes the cache PHP file to obtain its encoded payload before
payload HMAC verification can occur. HMAC protects the encoded cache record; it
does not make an attacker-controlled executable cache directory safe. Use
``phpFiles`` only on trusted hosts and private directories whose path
components cannot be replaced by an untrusted user.

3) Temp-Directory Hardening
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Default filesystem locations are now scoped under dedicated cachelayer temp
subdirectories:

* file adapter default base: ``sys_get_temp_dir()/cachelayer/files``
* php-files adapter default base: ``sys_get_temp_dir()/cachelayer/phpfiles``
* PDO SQLite default: ``sys_get_temp_dir()/cachelayer/pdo/cache_<ns>.sqlite``

Filesystem adapters create private directories and reject symlinked or
world-writable cache directories. Network/database adapters rely on the
deployment's service permissions rather than local directory checks.

The shared-memory adapter also stores its ``ftok`` token in a private
``cachelayer/shared-memory`` directory, rejects symlinked token paths,
creates the segment for the current user only, and serializes read-modify-write
operations with a filesystem lock. File locks and SQLite cache paths likewise
reject symlinked path components before opening storage.

4) Containment Failure Semantics
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

CacheLayer 4.0 treats the following conditions as explicit backend or
configuration failures rather than continuing with ambiguous state:

* Recursive or over-budget array graphs are rejected before recursive
  serialization-policy or memoization normalization can exhaust the worker.
* Rebinding one adapter to conflicting ``CacheOptions`` is rejected before
  storage use. Composite Node and tiered adapters preflight every child before
  applying a new policy.
* File and PHP-files atomic get-and-delete returns a value only after the
  backing file was successfully deleted. Strict mode reports the backend
  failure; fail-open mode returns the configured miss/default and never the
  unconsumed value.
* Node SQLite mutations reject caller-owned transactions. CacheLayer does not
  commit or roll back application work that it did not start.
* Redis/Valkey, PDO, MongoDB, payload-integrity, and closure-signing secrets are
  treated as sensitive parameters; connection/configuration errors avoid
  echoing secret-bearing DSNs or URIs.

These checks reduce accidental trust-boundary violations but do not eliminate
filesystem races on every platform. Deploy writable cache roots as private,
application-owned directories.

5) Network Timeouts
~~~~~~~~~~~~~~~~~~~

Redis/Valkey connections created from a DSN use bounded one-second connect and
read timeouts. Inject a preconfigured client when an application needs
different timeout, TLS, retry, or socket-context settings.

Recommended Production Profile
------------------------------

1. Pass a strong random secret as ``CacheOptions::$integrityKey`` from the
   application's composition root.
2. Disable closure/object payloads unless explicitly required.
3. Use explicit, private cache directories outside shared temp space.
4. Prefer non-executable file storage adapters over ``phpFiles`` where
   possible.

Authentication State
--------------------

For replay counters, one-time consumption, authorization state, or another
security decision, require all of the following:

* ``isFailOpen() === false``;
* ``hasPayloadIntegrity() === true``;
* ``isAuthoritative() === true``; and
* ``authenticationStateLock()`` returns a provider in the same coordination
  domain as the state backend.

Use one direct primary backend. Do not use tiered/local-L1 reads, replica reads,
or an eventually consistent cache for monotonic authentication state. The
capability API exposes CacheLayer's effective local policy; deployment topology
such as replica routing remains the application's responsibility.

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
