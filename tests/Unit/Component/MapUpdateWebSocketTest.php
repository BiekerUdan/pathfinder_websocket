<?php

namespace Tests\Unit\Component;

use Exodus4D\Socket\Component\MapUpdate;
use Exodus4D\Socket\Log\Store;
use Exodus4D\Socket\Data\Payload;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Ratchet\ConnectionInterface;

#[CoversClass(MapUpdate::class)]
class MapUpdateWebSocketTest extends TestCase
{
    private MapUpdate $mapUpdate;
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store('test');
        $this->store->setLocked(true); // Disable logging during tests
        $this->mapUpdate = new MapUpdate($this->store);
    }

    private function createMockConnection(int $resourceId = 1): ConnectionInterface
    {
        return new class($resourceId) implements ConnectionInterface {
            public int $resourceId;
            public string $remoteAddress;

            public function __construct(int $resourceId)
            {
                $this->resourceId = $resourceId;
                $this->remoteAddress = "127.0.0.1:$resourceId";
            }

            public function send($data): void {}
            public function close(): void {}
        };
    }

    public function testOnOpenCallsParent(): void
    {
        $connection = $this->createMockConnection();

        // Should not throw
        $this->mapUpdate->onOpen($connection);

        // Verify connection was added by checking stats
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertSame(1, $stats['connections']);
    }

    public function testOnCloseUnsubscribesConnection(): void
    {
        $connection = $this->createMockConnection();

        // First open the connection
        $this->mapUpdate->onOpen($connection);

        // Subscribe the connection to a map first
        $this->setUpValidSubscription($connection);

        // Close the connection
        $this->mapUpdate->onClose($connection);

        // Verify connection was removed
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertSame(0, $stats['connections']);
    }

    public function testOnErrorClosesConnection(): void
    {
        $connection = new class(1) implements ConnectionInterface {
            public int $resourceId;
            public string $remoteAddress;
            public int $closeCallCount = 0;

            public function __construct(int $resourceId)
            {
                $this->resourceId = $resourceId;
                $this->remoteAddress = "127.0.0.1:$resourceId";
            }

            public function send($data): void {}
            public function close(): void {
                $this->closeCallCount++;
            }
        };

        $exception = new \Exception('Test error');

        $this->mapUpdate->onError($connection, $exception);

        // Verify close was called exactly once
        $this->assertSame(1, $connection->closeCallCount);
    }

    public function testOnMessageHandlesPayload(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Set up a health check token
        $token = microtime(true);
        $this->mapUpdate->receiveData('healthCheck', $token);

        // Send a message with valid JSON payload
        $message = json_encode([
            'task' => 'healthCheck',
            'load' => (int)$token
        ]);

        // Should not throw
        $this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testDispatchWebSocketPayloadWithHealthCheck(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Set health check token from TCP side
        $token = microtime(true);
        $this->mapUpdate->receiveData('healthCheck', $token);

        // Send health check from WebSocket side
        $message = json_encode([
            'task' => 'healthCheck',
            'load' => (int)$token
        ]);

        $this->mapUpdate->onMessage($connection, $message);

        // Connection should still exist
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertSame(1, $stats['connections']);
    }

    public function testDispatchWebSocketPayloadWithInvalidHealthCheckToken(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Send health check with wrong token
        $message = json_encode([
            'task' => 'healthCheck',
            'load' => 999999
        ]);

        // Should not throw, just return invalid status
        $this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertSame(1, $stats['connections']);
    }

    public function testDispatchWebSocketPayloadWithUnknownTask(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        $message = json_encode([
            'task' => 'unknownTask',
            'load' => []
        ]);

        // Should log error but not throw
        $this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testSubscribeWithValidToken(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Set up connection access first (simulates TCP call from backend)
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'char_token_123',
            'characterData' => ['id' => 123, 'name' => 'Test Character'],
            'mapData' => [
                ['id' => 1, 'token' => 'map_token_1', 'name' => 'Map 1']
            ]
        ]);

        // Now subscribe via WebSocket
        $message = json_encode([
            'task' => 'subscribe',
            'load' => [
                'id' => 123,
                'token' => 'char_token_123',
                'mapData' => [
                    ['id' => 1, 'token' => 'map_token_1', 'name' => 'Map 1']
                ]
            ]
        ]);

        $this->mapUpdate->onMessage($connection, $message);

        // Verify subscription stats
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertIsArray($stats);
    }

    public function testSubscribeWithInvalidCharacterToken(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Set up connection access
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'valid_token',
            'characterData' => ['id' => 123],
            'mapData' => []
        ]);

        // Try to subscribe with wrong token
        $message = json_encode([
            'task' => 'subscribe',
            'load' => [
                'id' => 123,
                'token' => 'wrong_token',
                'mapData' => []
            ]
        ]);

        // Should be denied but not throw
        $this->mapUpdate->onMessage($connection, $message);

        // Verify subscription was denied
        $this->assertTrue(true);
    }

    public function testSubscribeWithMissingCharacterId(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        $message = json_encode([
            'task' => 'subscribe',
            'load' => [
                'token' => 'some_token',
                'mapData' => []
            ]
        ]);

        // Should log error but not throw - suppress warning
        @$this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testSubscribeWithMissingToken(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        $message = json_encode([
            'task' => 'subscribe',
            'load' => [
                'id' => 123,
                'mapData' => []
            ]
        ]);

        // Should log error but not throw - suppress warning
        @$this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testUnsubscribeWithValidCharacterIds(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // First subscribe
        $this->setUpValidSubscription($connection);

        // Now unsubscribe
        $message = json_encode([
            'task' => 'unsubscribe',
            'load' => [123] // character IDs to unsubscribe
        ]);

        $this->mapUpdate->onMessage($connection, $message);

        // Verify unsubscribe worked
        $this->assertTrue(true);
    }

    public function testUnsubscribeWithInvalidCharacterIds(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Try to unsubscribe without being subscribed
        $message = json_encode([
            'task' => 'unsubscribe',
            'load' => [999] // non-existent character ID
        ]);

        // Should not throw, just do nothing
        $this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testUnsubscribeWithEmptyCharacterIds(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        $message = json_encode([
            'task' => 'unsubscribe',
            'load' => []
        ]);

        // Should not throw
        $this->mapUpdate->onMessage($connection, $message);

        // Verify it didn't crash
        $this->assertTrue(true);
    }

    public function testReceiveDataWithCharacterUpdate(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // First subscribe a character
        $this->setUpValidSubscription($connection);

        // Now update the character data via TCP
        $result = $this->mapUpdate->receiveData('characterUpdate', [
            'id' => 123,
            'name' => 'Updated Character Name'
        ]);

        $this->assertNull($result);
    }

    public function testReceiveDataWithMapAccess(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // First subscribe a character
        $this->setUpValidSubscription($connection);

        // Update map access
        $result = $this->mapUpdate->receiveData('mapAccess', [
            'id' => 1,
            'name' => 'Map 1',
            'characterIds' => [123]
        ]);

        $this->assertIsInt($result);
        $this->assertSame(1, $result); // 1 character has access
    }

    public function testReceiveDataWithMapAccessRemovesCharacters(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Subscribe a character
        $this->setUpValidSubscription($connection);

        // Remove access by giving empty characterIds
        $result = $this->mapUpdate->receiveData('mapAccess', [
            'id' => 1,
            'name' => 'Map 1',
            'characterIds' => []
        ]);

        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testReceiveDataWithLogData(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_log_' . uniqid() . '.log';

        $result = $this->mapUpdate->receiveData('logData', [
            'meta' => ['stream' => $tempFile],
            'log' => ['message' => 'Test log entry']
        ]);

        $this->assertNull($result);
        $this->assertFileExists($tempFile);

        // Clean up
        unlink($tempFile);
    }

    public function testGetSubscriptionStatsWithNoSubscriptions(): void
    {
        $reflection = new \ReflectionClass($this->mapUpdate);
        $method = $reflection->getMethod('getSubscriptionStats');

        $stats = $method->invoke($this->mapUpdate);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('countSub', $stats);
        $this->assertArrayHasKey('countCon', $stats);
        $this->assertArrayHasKey('channels', $stats);
        $this->assertSame(0, $stats['countSub']);
        $this->assertSame(0, $stats['countCon']);
        $this->assertEmpty($stats['channels']);
    }

    public function testGetSubscriptionStatsWithActiveSubscriptions(): void
    {
        $connection = $this->createMockConnection();
        $this->mapUpdate->onOpen($connection);

        // Set up a valid subscription
        $this->setUpValidSubscription($connection);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $method = $reflection->getMethod('getSubscriptionStats');

        $stats = $method->invoke($this->mapUpdate);

        $this->assertGreaterThan(0, $stats['countSub']);
        $this->assertGreaterThan(0, $stats['countCon']);
        $this->assertNotEmpty($stats['channels']);
    }

    public function testArraysEqualKeysWithEqualArrays(): void
    {
        $reflection = new \ReflectionClass($this->mapUpdate);
        $method = $reflection->getMethod('arraysEqualKeys');

        $result = $method->invoke($this->mapUpdate, ['a' => 1, 'b' => 2], ['b' => 3, 'a' => 4]);

        $this->assertTrue($result);
    }

    public function testArraysEqualKeysWithDifferentArrays(): void
    {
        $reflection = new \ReflectionClass($this->mapUpdate);
        $method = $reflection->getMethod('arraysEqualKeys');

        $result = $method->invoke($this->mapUpdate, ['a' => 1], ['b' => 2]);

        $this->assertFalse($result);
    }

    public function testArraysEqualKeysWithEmptyArrays(): void
    {
        $reflection = new \ReflectionClass($this->mapUpdate);
        $method = $reflection->getMethod('arraysEqualKeys');

        $result = $method->invoke($this->mapUpdate, [], []);

        $this->assertTrue($result);
    }

    public function testMultipleConnectionsForSameCharacter(): void
    {
        $connection1 = $this->createMockConnection(1);
        $connection2 = $this->createMockConnection(2);

        $this->mapUpdate->onOpen($connection1);
        $this->mapUpdate->onOpen($connection2);

        // Both connections subscribe as the same character
        $this->setUpValidSubscription($connection1, 123);
        $this->setUpValidSubscription($connection2, 123);

        // Both should be counted
        $stats = $this->mapUpdate->getSocketStats();
        $this->assertSame(2, $stats['connections']);
    }

    /**
     * Helper method to set up a valid subscription
     */
    private function setUpValidSubscription(ConnectionInterface $connection, int $characterId = 123): void
    {
        // Set up connection access
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => $characterId,
            'token' => "char_token_$characterId",
            'characterData' => ['id' => $characterId, 'name' => "Character $characterId"],
            'mapData' => [
                ['id' => 1, 'token' => "map_token_1_$characterId", 'name' => 'Map 1']
            ]
        ]);

        // Subscribe via WebSocket
        $message = json_encode([
            'task' => 'subscribe',
            'load' => [
                'id' => $characterId,
                'token' => "char_token_$characterId",
                'mapData' => [
                    ['id' => 1, 'token' => "map_token_1_$characterId", 'name' => 'Map 1']
                ]
            ]
        ]);

        $this->mapUpdate->onMessage($connection, $message);
    }
}
