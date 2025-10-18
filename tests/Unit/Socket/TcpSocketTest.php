<?php

namespace Tests\Unit\Socket;

use Exodus4D\Socket\Socket\TcpSocket;
use Exodus4D\Socket\Log\Store;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use React\EventLoop;
use React\Socket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// Test helper interface that includes the methods TcpSocket expects
interface TestMessageHandler extends MessageComponentInterface
{
    public function receiveData(string $task, mixed $load): mixed;
    public function getSocketStats(): array;
}

#[CoversClass(TcpSocket::class)]
class TcpSocketTest extends TestCase
{
    private EventLoop\LoopInterface $loop;
    private TestMessageHandler $handler;
    private Store $store;
    private TcpSocket $tcpSocket;

    protected function setUp(): void
    {
        $this->loop = $this->createMock(EventLoop\LoopInterface::class);

        // Create a mock of our test interface that includes all required methods
        $this->handler = $this->createMock(TestMessageHandler::class);

        $this->store = new Store('test');
        $this->store->setLocked(true); // Disable logging

        $this->tcpSocket = new TcpSocket($this->loop, $this->handler, $this->store);
    }

    public function testConstructorSetsDefaults(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);

        $acceptType = $reflection->getProperty('acceptType');
        $this->assertSame('json', $acceptType->getValue($this->tcpSocket));

        $waitTimeout = $reflection->getProperty('waitTimeout');
        $this->assertSame(3.0, $waitTimeout->getValue($this->tcpSocket));

        $endWithResponse = $reflection->getProperty('endWithResponse');
        $this->assertTrue($endWithResponse->getValue($this->tcpSocket));
    }

    public function testConstructorSetsCustomValues(): void
    {
        $customSocket = new TcpSocket(
            $this->loop,
            $this->handler,
            $this->store,
            'custom',
            5.0,
            false
        );

        $reflection = new \ReflectionClass($customSocket);

        $acceptType = $reflection->getProperty('acceptType');
        $this->assertSame('custom', $acceptType->getValue($customSocket));

        $waitTimeout = $reflection->getProperty('waitTimeout');
        $this->assertSame(5.0, $waitTimeout->getValue($customSocket));

        $endWithResponse = $reflection->getProperty('endWithResponse');
        $this->assertFalse($endWithResponse->getValue($customSocket));
    }

    public function testConstructorInitializesConnectionStorage(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);

        $connections = $reflection->getProperty('connections');

        $this->assertInstanceOf(\SplObjectStorage::class, $connections->getValue($this->tcpSocket));
    }

    public function testOnConnectWithInvalidConnectionClosesIt(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);

        // Invalid connection (not readable and not writable)
        $connection->method('isReadable')->willReturn(false);
        $connection->method('isWritable')->willReturn(false);

        $connection->expects($this->once())->method('close');

        $this->tcpSocket->onConnect($connection);
    }

    public function testOnConnectWithValidConnectionAddsToPool(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isReadable')->willReturn(true);
        $connection->method('isWritable')->willReturn(true);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        // Track which events were registered
        $registeredEvents = [];
        $connection->method('on')->willReturnCallback(
            function ($event, $callback) use (&$registeredEvents) {
                $registeredEvents[] = $event;
            }
        );

        // Loop should add a timer
        $this->loop->expects($this->once())
            ->method('addTimer')
            ->willReturn($this->createMock(EventLoop\TimerInterface::class));

        $this->tcpSocket->onConnect($connection);

        // Verify connection was added to pool
        $reflection = new \ReflectionClass($this->tcpSocket);
        $connections = $reflection->getProperty('connections');
        $storage = $connections->getValue($this->tcpSocket);

        $this->assertTrue($storage->contains($connection));

        // Verify event listeners were registered (should include 'end', 'close', 'error')
        $this->assertContains('end', $registeredEvents);
        $this->assertContains('close', $registeredEvents);
        $this->assertContains('error', $registeredEvents);
    }

    public function testDispatchWithHealthCheckTask(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $deferred = new \React\Promise\Deferred();

        $reflection = new \ReflectionClass($this->tcpSocket);
        $dispatch = $reflection->getMethod('dispatch');

        $dispatch->invoke($this->tcpSocket, $connection, $deferred, 'getStats', null);

        // Should resolve with payload
        $resolved = false;
        $deferred->promise()->then(function ($payload) use (&$resolved) {
            $resolved = true;
            $this->assertIsArray($payload);
            $this->assertSame('getStats', $payload['task']);
        });

        $this->assertTrue($resolved);
    }

    public function testDispatchWithHealthCheckTaskIncludesStats(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $deferred = new \React\Promise\Deferred();

        $reflection = new \ReflectionClass($this->tcpSocket);
        $dispatch = $reflection->getMethod('dispatch');

        $dispatch->invoke($this->tcpSocket, $connection, $deferred, 'getStats', null);

        $resolved = false;
        $deferred->promise()->then(function ($payload) use (&$resolved) {
            $resolved = true;
            $this->assertArrayHasKey('stats', $payload);
        });

        $this->assertTrue($resolved);
    }

    public function testDispatchWithMapUpdateTask(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $deferred = new \React\Promise\Deferred();
        $load = ['mapId' => 123, 'data' => []];

        $this->handler->expects($this->once())
            ->method('receiveData')
            ->with('mapUpdate', $load)
            ->willReturn(5); // 5 connections notified

        $reflection = new \ReflectionClass($this->tcpSocket);
        $dispatch = $reflection->getMethod('dispatch');

        $dispatch->invoke($this->tcpSocket, $connection, $deferred, 'mapUpdate', $load);

        $resolved = false;
        $deferred->promise()->then(function ($payload) use (&$resolved) {
            $resolved = true;
            $this->assertSame('mapUpdate', $payload['task']);
            $this->assertSame(5, $payload['load']);
        });

        $this->assertTrue($resolved);
    }

    public function testDispatchWithCharacterLogoutTask(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $deferred = new \React\Promise\Deferred();
        $characterIds = [1, 2, 3];

        $this->handler->expects($this->once())
            ->method('receiveData')
            ->with('characterLogout', $characterIds)
            ->willReturn(true);

        $reflection = new \ReflectionClass($this->tcpSocket);
        $dispatch = $reflection->getMethod('dispatch');

        $dispatch->invoke($this->tcpSocket, $connection, $deferred, 'characterLogout', $characterIds);

        $resolved = false;
        $deferred->promise()->then(function ($payload) use (&$resolved) {
            $resolved = true;
            $this->assertSame('characterLogout', $payload['task']);
        });

        $this->assertTrue($resolved);
    }

    public function testDispatchWithUnknownTaskRejects(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $deferred = new \React\Promise\Deferred();

        $reflection = new \ReflectionClass($this->tcpSocket);
        $dispatch = $reflection->getMethod('dispatch');

        $dispatch->invoke($this->tcpSocket, $connection, $deferred, 'unknownTask', null);

        $rejected = false;
        $deferred->promise()->then(
            null,
            function ($error) use (&$rejected) {
                $rejected = true;
                $this->assertInstanceOf(\InvalidArgumentException::class, $error);
                $this->assertStringContainsString('Unknown', $error->getMessage());
            }
        );

        $this->assertTrue($rejected);
    }

    public function testConnectionErrorWithWritableConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isWritable')->willReturn(true);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $exception = new \Exception('Test error');

        $connection->expects($this->once())
            ->method('end');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $connectionError = $reflection->getMethod('connectionError');

        $connectionError->invoke($this->tcpSocket, $connection, $exception);
    }

    public function testConnectionErrorWithNonWritableConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isWritable')->willReturn(false);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $exception = new \Exception('Test error');

        $connection->expects($this->once())
            ->method('close');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $connectionError = $reflection->getMethod('connectionError');

        $connectionError->invoke($this->tcpSocket, $connection, $exception);
    }

    public function testAddConnectionIncreasesCount(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $connections = $reflection->getProperty('connections');

        $this->assertSame(0, $connections->getValue($this->tcpSocket)->count());

        $addConnection->invoke($this->tcpSocket, $connection);

        $this->assertSame(1, $connections->getValue($this->tcpSocket)->count());
    }

    public function testAddConnectionUpdatesMaxConnections(): void
    {
        $connection1 = $this->createMock(Socket\ConnectionInterface::class);
        $connection1->method('getRemoteAddress')->willReturn('127.0.0.1:1');

        $connection2 = $this->createMock(Socket\ConnectionInterface::class);
        $connection2->method('getRemoteAddress')->willReturn('127.0.0.1:2');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $maxConnections = $reflection->getProperty('maxConnections');

        $this->assertSame(0, $maxConnections->getValue($this->tcpSocket));

        $addConnection->invoke($this->tcpSocket, $connection1);
        $this->assertSame(1, $maxConnections->getValue($this->tcpSocket));

        $addConnection->invoke($this->tcpSocket, $connection2);
        $this->assertSame(2, $maxConnections->getValue($this->tcpSocket));
    }

    public function testRemoveConnectionDecreasesCount(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $removeConnection = $reflection->getMethod('removeConnection');

        $connections = $reflection->getProperty('connections');

        $addConnection->invoke($this->tcpSocket, $connection);
        $this->assertSame(1, $connections->getValue($this->tcpSocket)->count());

        $removeConnection->invoke($this->tcpSocket, $connection);
        $this->assertSame(0, $connections->getValue($this->tcpSocket)->count());
    }

    public function testHasConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $hasConnection = $reflection->getMethod('hasConnection');

        $this->assertFalse($hasConnection->invoke($this->tcpSocket, $connection));

        $addConnection->invoke($this->tcpSocket, $connection);

        $this->assertTrue($hasConnection->invoke($this->tcpSocket, $connection));
    }

    public function testGetSocketStatsReturnsStructure(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);
        $getSocketStats = $reflection->getMethod('getSocketStats');

        $stats = $getSocketStats->invoke($this->tcpSocket);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('startup', $stats);
        $this->assertArrayHasKey('connections', $stats);
        $this->assertArrayHasKey('maxConnections', $stats);
        $this->assertArrayHasKey('logs', $stats);

        $this->assertSame(0, $stats['connections']);
        $this->assertSame(0, $stats['maxConnections']);
    }

    public function testGetSocketStatsIncludesUptime(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);
        $getSocketStats = $reflection->getMethod('getSocketStats');

        sleep(1);

        $stats = $getSocketStats->invoke($this->tcpSocket);

        $this->assertGreaterThanOrEqual(1, $stats['startup']);
    }

    public function testGetSocketStatsIncludesConnectionCount(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $getSocketStats = $reflection->getMethod('getSocketStats');

        $addConnection->invoke($this->tcpSocket, $connection);

        $stats = $getSocketStats->invoke($this->tcpSocket);

        $this->assertSame(1, $stats['connections']);
        $this->assertSame(1, $stats['maxConnections']);
    }

    public function testNewPayloadCreatesCorrectStructure(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);
        $newPayload = $reflection->getMethod('newPayload');

        $payload = $newPayload->invoke($this->tcpSocket, 'testTask', 'testLoad', false);

        $this->assertIsArray($payload);
        $this->assertSame('testTask', $payload['task']);
        $this->assertSame('testLoad', $payload['load']);
        $this->assertArrayNotHasKey('stats', $payload);
    }

    public function testNewPayloadWithStatsIncludesStats(): void
    {
        $reflection = new \ReflectionClass($this->tcpSocket);
        $newPayload = $reflection->getMethod('newPayload');

        $payload = $newPayload->invoke($this->tcpSocket, 'testTask', 'testLoad', true);

        $this->assertArrayHasKey('stats', $payload);
        $this->assertIsArray($payload['stats']);
    }

    public function testIsValidConnectionReturnsTrueForReadableConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isReadable')->willReturn(true);
        $connection->method('isWritable')->willReturn(false);

        $reflection = new \ReflectionClass($this->tcpSocket);
        $isValidConnection = $reflection->getMethod('isValidConnection');

        $this->assertTrue($isValidConnection->invoke($this->tcpSocket, $connection));
    }

    public function testIsValidConnectionReturnsTrueForWritableConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isReadable')->willReturn(false);
        $connection->method('isWritable')->willReturn(true);

        $reflection = new \ReflectionClass($this->tcpSocket);
        $isValidConnection = $reflection->getMethod('isValidConnection');

        $this->assertTrue($isValidConnection->invoke($this->tcpSocket, $connection));
    }

    public function testIsValidConnectionReturnsFalseForInvalidConnection(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('isReadable')->willReturn(false);
        $connection->method('isWritable')->willReturn(false);

        $reflection = new \ReflectionClass($this->tcpSocket);
        $isValidConnection = $reflection->getMethod('isValidConnection');

        $this->assertFalse($isValidConnection->invoke($this->tcpSocket, $connection));
    }

    public function testAddConnectionDoesNotAddDuplicates(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');

        $connections = $reflection->getProperty('connections');

        $addConnection->invoke($this->tcpSocket, $connection);
        $addConnection->invoke($this->tcpSocket, $connection);

        // Should still only be 1
        $this->assertSame(1, $connections->getValue($this->tcpSocket)->count());
    }

    public function testSetTimerSetsTimerOnLoop(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        // Add connection first
        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');
        $addConnection->invoke($this->tcpSocket, $connection);

        $timer = $this->createMock(EventLoop\TimerInterface::class);
        $this->loop->expects($this->once())
            ->method('addTimer')
            ->with(5.0)
            ->willReturn($timer);

        $setTimer = $reflection->getMethod('setTimer');

        $callback = function () {};
        $setTimer->invoke($this->tcpSocket, $connection, 'testTimer', 5.0, $callback);
    }

    public function testCancelTimerRemovesTimer(): void
    {
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1:12345');

        // Add connection
        $reflection = new \ReflectionClass($this->tcpSocket);
        $addConnection = $reflection->getMethod('addConnection');
        $addConnection->invoke($this->tcpSocket, $connection);

        // Add timer
        $timer = $this->createMock(EventLoop\TimerInterface::class);
        $this->loop->method('addTimer')->willReturn($timer);

        $setTimer = $reflection->getMethod('setTimer');
        $callback = function () {};
        $setTimer->invoke($this->tcpSocket, $connection, 'testTimer', 5.0, $callback);

        // Cancel timer
        $this->loop->expects($this->once())
            ->method('cancelTimer')
            ->with($timer);

        $cancelTimer = $reflection->getMethod('cancelTimer');
        $cancelTimer->invoke($this->tcpSocket, $connection, 'testTimer');
    }
}
