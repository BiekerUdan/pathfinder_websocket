<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use React\EventLoop;
use React\Socket;
use React\Promise;

#[CoversNothing]
class TcpSocketIntegrationTest extends TestCase
{
    private const TEST_PORT = 15555;

    public function testTcpSocketAcceptsConnection(): void
    {
        $loop = EventLoop\Loop::get();
        $connected = false;

        // Start a simple TCP server
        $server = new Socket\SocketServer('127.0.0.1:' . self::TEST_PORT, [], $loop);

        $server->on('connection', function (Socket\ConnectionInterface $connection) use (&$connected, $loop) {
            $connected = true;
            $connection->close();
            $loop->stop();
        });

        // Create a client connection
        $connector = new Socket\Connector([], $loop);
        $connector->connect('127.0.0.1:' . self::TEST_PORT)->then(
            function (Socket\ConnectionInterface $connection) {
                $connection->close();
            }
        );

        // Run loop with timeout
        $timeout = $loop->addTimer(2, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        $this->assertTrue($connected, 'Server should accept TCP connection');
        $server->close();
    }

    public function testTcpSocketReceivesJsonMessage(): void
    {
        $loop = EventLoop\Loop::get();
        $receivedData = null;

        // Start TCP server
        $server = new Socket\SocketServer('127.0.0.1:' . self::TEST_PORT, [], $loop);

        $server->on('connection', function (Socket\ConnectionInterface $connection) use (&$receivedData, $loop) {
            $connection->on('data', function ($data) use (&$receivedData, $connection, $loop) {
                $receivedData = json_decode($data, true);
                $connection->close();
                $loop->stop();
            });
        });

        // Create client and send JSON message
        $connector = new Socket\Connector([], $loop);
        $connector->connect('127.0.0.1:' . self::TEST_PORT)->then(
            function (Socket\ConnectionInterface $connection) {
                $message = json_encode([
                    'task' => 'healthCheck',
                    'load' => microtime(true)
                ]);
                $connection->write($message . "\n");
            }
        );

        // Run with timeout
        $timeout = $loop->addTimer(2, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        $this->assertNotNull($receivedData, 'Should have received data from client');
        $this->assertIsArray($receivedData);
        $this->assertArrayHasKey('task', $receivedData);
        $this->assertSame('healthCheck', $receivedData['task']);
        $server->close();
    }

    public function testTcpSocketSendsResponse(): void
    {
        $loop = EventLoop\Loop::get();
        $receivedResponse = null;

        // Start TCP server that echoes back
        $server = new Socket\SocketServer('127.0.0.1:' . self::TEST_PORT, [], $loop);

        $server->on('connection', function (Socket\ConnectionInterface $connection) {
            $connection->on('data', function ($data) use ($connection) {
                $decoded = json_decode($data, true);
                $response = json_encode([
                    'task' => $decoded['task'] ?? 'unknown',
                    'status' => 'OK'
                ]);
                $connection->end($response . "\n");
            });
        });

        // Create client
        $connector = new Socket\Connector([], $loop);
        $connector->connect('127.0.0.1:' . self::TEST_PORT)->then(
            function (Socket\ConnectionInterface $connection) use (&$receivedResponse, $loop) {
                $connection->on('data', function ($data) use (&$receivedResponse, $loop) {
                    $receivedResponse = json_decode($data, true);
                    $loop->stop();
                });

                $message = json_encode(['task' => 'test']);
                $connection->write($message . "\n");
            }
        );

        // Run with timeout
        $timeout = $loop->addTimer(2, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        $this->assertIsArray($receivedResponse);
        $this->assertSame('test', $receivedResponse['task']);
        $this->assertSame('OK', $receivedResponse['status']);
        $server->close();
    }

    public function testMultipleConcurrentConnections(): void
    {
        $loop = EventLoop\Loop::get();
        $connectionCount = 0;
        $targetConnections = 5;

        // Start TCP server
        $server = new Socket\SocketServer('127.0.0.1:' . self::TEST_PORT, [], $loop);

        $server->on('connection', function (Socket\ConnectionInterface $connection) use (&$connectionCount, $targetConnections, $loop) {
            $connectionCount++;
            $connection->close();

            if ($connectionCount >= $targetConnections) {
                $loop->stop();
            }
        });

        // Create multiple clients
        $connector = new Socket\Connector([], $loop);
        for ($i = 0; $i < $targetConnections; $i++) {
            $connector->connect('127.0.0.1:' . self::TEST_PORT)->then(
                function (Socket\ConnectionInterface $connection) {
                    $connection->close();
                }
            );
        }

        // Run with timeout
        $timeout = $loop->addTimer(3, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        $this->assertSame($targetConnections, $connectionCount);
        $server->close();
    }

    protected function tearDown(): void
    {
        // Ensure event loop is stopped and clean
        try {
            $loop = EventLoop\Loop::get();
            $loop->stop();
        } catch (\Exception $e) {
            // Ignore
        }

        parent::tearDown();
    }
}
