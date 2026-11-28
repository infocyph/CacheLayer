<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

final class RedisNativeFailureTest extends TestCase
{
    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws RuntimeException
     */
    public function testClearAndDeleteReportNativeRefusal(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('scan')->willReturnCallback(static function (mixed &$cursor): array {
            $cursor = 0;

            return ['refused:d:key'];
        });
        $client->method('del')->willReturn(false);
        $adapter = new RedisCacheAdapter('refused', client: $client);

        expect($adapter->deleteItem('key'))->toBeFalse()
            ->and($adapter->deleteItems(['key']))->toBeFalse()
            ->and($adapter->clear())->toBeFalse();
    }

    /**
     * @throws CacheBackendException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws RuntimeException
     */
    public function testIncompleteBulkResponsesAreBackendFailures(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('mget')->willReturn([false]);
        $adapter = new RedisCacheAdapter('incomplete', client: $client);
        $closed = new Cache($adapter, options: new CacheOptions(failOpen: false));
        expect(fn (): iterable => $closed->getMultiple(['one', 'two']))
            ->toThrow(CacheBackendException::class);
        expect(fn (): array => $adapter->getTagGenerations(['one', 'two']))
            ->toThrow(RuntimeException::class);

        $open = new Cache(new RedisCacheAdapter('incomplete-open', client: $client), options: new CacheOptions(failOpen: true));
        expect($open->getMultiple(['one', 'two'], 'fallback'))->toBe(['one' => 'fallback', 'two' => 'fallback']);
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    public function testExpiredEntryDeletionFailurePreventsSuccessfulPersistence(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('del')->willReturn(false);
        $adapter = new RedisCacheAdapter('expired-batch', client: $client);
        $item = $adapter->createItem('expired')->set('old')->expiresAfter(-1);
        expect($adapter->saveItems(['expired' => $item]))->toBeFalse();
    }

    /**
     * @throws \Infocyph\CacheLayer\Counter\Exception\AtomicCounterException
     */
    public function testCounterDeleteReportsNativeRefusal(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('del')->willReturn(false);
        self::assertFalse(AtomicCounters::redis('delete-contract', client: $client)->delete('counter'));
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    public function testPipelineStartFailurePreventsSuccessfulPersistence(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('multi')->willReturn(false);
        $adapter = new RedisCacheAdapter('pipeline-refused', client: $client);
        $item = $adapter->createItem('expires')->set('value')->expiresAfter(60);

        self::assertFalse($adapter->saveItems(['expires' => $item]));
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \LogicException
     * @throws RuntimeException
     */
    public function testAtomicConsumeDistinguishesMissFromBackendFailure(): void
    {
        $client = $this->createMock(Redis::class);
        $client->method('eval')->willReturnOnConsecutiveCalls(0, false);
        $cache = new Cache(new RedisCacheAdapter('atomic-refused', client: $client), options: new CacheOptions(failOpen: false));
        $atomic = $cache->atomic() ?? throw new RuntimeException('Atomic capability required.');
        expect($atomic->getAndDelete('missing', 'default'))->toBe('default')
            ->and(fn (): mixed => $atomic->getAndDelete('failure'))->toThrow(CacheBackendException::class);
    }

}
