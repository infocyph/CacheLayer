.. _adapters.apcu:

=======================
APCu Adapter (`apcu`)
=======================

Factory: `Cache::apcu(string $namespace = 'default')`

Requirements:

* `ext-apcu`
* APCu enabled (`apcu_enabled()`)
* for CLI usage/tests: `apcu.enable_cli=1`

Highlights:

* in-memory shared cache in the PHP runtime environment
* namespace-prefixed keys (`<ns>:<key>`)
* efficient bulk fetch through APCu array fetch path

`Cache::local()` will choose APCu automatically when available.
