.. _adapters.dynamodb:

================================
DynamoDB Adapter (``dynamoDb``)
================================

Factory:

``Cache::dynamoDb(string $namespace = 'default', string $table = 'cachelayer_entries', ?object $client = null, array $config = [])``

Requirements:

* ``aws/aws-sdk-php`` for default client path, or
* injected client implementing required DynamoDB methods

Highlights:

* namespace-scoped row storage
* clear via scan + chunked ``batchWriteItem`` delete requests
* TTL stored as absolute timestamp in ``expires``

Supported injected client methods:

* ``getItem``
* ``putItem``
* ``deleteItem``
* ``scan``
* ``batchWriteItem``
