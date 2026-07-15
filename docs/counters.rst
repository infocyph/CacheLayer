===============
Atomic Counters
===============

Atomic counters are separate from the normal ``Cache`` facade. They are for
shared integer state where ``get()`` followed by ``set()`` would race across
PHP workers or application nodes: rate-limit windows, authentication lockout
attempts, quotas, and replay-attempt counts.

Use Redis or Valkey as the shared backend. Node Cache, APCu, and SQLite are not
distributed atomic-counter stores.

.. code-block:: php

   use Infocyph\CacheLayer\Counter\AtomicCounters;

   $counters = AtomicCounters::redis('authentication');
   $attempt = $counters->increment('login.203.0.113.10', ttlSeconds: 900);

   if ($attempt->value > 5) {
       denyLogin();
   }

``increment()`` and ``decrement()`` execute as one Redis/Valkey operation.
When a positive TTL is supplied, it is assigned only when that key is first
created; later increments do not extend the fixed window. Each operation
returns ``AtomicCounterValue`` with the resulting ``value`` and an
``initialized`` flag.

.. code-block:: php

   $first = $counters->increment('quota.42', 1, 3600);
   // $first->value === 1; $first->initialized === true

   $later = $counters->increment('quota.42', 3, 3600);
   // $later->value === 4; $later->initialized === false; TTL is unchanged.

Use ``get()`` for a read, ``delete()`` to remove a counter, and
``decrement()`` for an atomic decrement. Counter keys allow letters, digits,
``_``, ``.``, and ``-``. A provided TTL must be a positive integer.

Valkey uses the same Redis-compatible client contract:

.. code-block:: php

   $counters = AtomicCounters::valkey('rate-limits', 'valkey://127.0.0.1:6379');

Counters are not a general replacement for authoritative accounting. For
payment, entitlement, or security decisions requiring durable auditability,
keep the authoritative record in a transactional data store and use counters
only for the bounded coordination/window concern.
