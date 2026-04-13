# Security Guide

This document captures CacheLayer hardening guidance and rollout options.

## Threat Model

CacheLayer stores serialized payloads in backends that may be writable by local
or network-adjacent actors if infrastructure is misconfigured. Main risks:

- Deserialization abuse when payloads are tampered.
- Executable cache-file abuse in `phpFiles` adapter.
- Insecure default temp-directory usage in shared environments.

## Implemented Hardening

### 1) Serialization and Payload Hardening

- `CachePayloadCodec` supports signed payloads (HMAC-SHA256).
- Signed payloads are rejected when integrity verification fails.
- When an integrity key is configured, unsigned payloads are rejected.
- Maximum payload size can be enforced at decode time.
- `ValueSerializer` supports strict mode:
  - block closure payloads
  - block object payloads
- Native scalar/array serialization paths now decode with
  `allowed_classes => false`.

### Runtime API

```php
$cache
    ->configurePayloadSecurity(
        integrityKey: 'replace-with-strong-secret',
        maxPayloadBytes: 8_388_608,
    )
    ->configureSerializationSecurity(
        allowClosurePayloads: false,
        allowObjectPayloads: false,
    );
```

### Environment Variables

- `CACHELAYER_PAYLOAD_INTEGRITY_KEY`
- `CACHELAYER_MAX_PAYLOAD_BYTES`

### 2) `phpFiles` Adapter Guardrails

`phpFiles` keeps executable `.php` cache files for performance, so strict
directory controls are required. Runtime checks now reject:

- symlinked cache directories
- world-writable cache directories

Use `phpFiles` only on trusted hosts and private directories.

### 3) Temp-Directory Hardening

Default filesystem locations are now scoped under dedicated cachelayer temp
subdirectories:

- file adapter default base: `sys_get_temp_dir()/cachelayer/files`
- php-files adapter default base: `sys_get_temp_dir()/cachelayer/phpfiles`
- PDO SQLite default: `sys_get_temp_dir()/cachelayer/pdo/cache_<ns>.sqlite`

These paths are created with restrictive permissions and world-writable checks.

## Recommended Production Profile

1. Set `CACHELAYER_PAYLOAD_INTEGRITY_KEY` to a strong random secret.
2. Disable closure/object payloads unless explicitly required.
3. Use explicit, private cache directories outside shared temp space.
4. Prefer non-executable file storage adapters over `phpFiles` where possible.

## Disclosure

If you discover a security issue, please open a private report to project
maintainers before public disclosure.
