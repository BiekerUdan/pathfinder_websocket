<?php

namespace Tests\Unit\Component;

use Exodus4D\Socket\Component\AbstractMessageComponent;
use Exodus4D\Socket\Data\Payload;
use Exodus4D\Socket\Log\Store;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Ratchet\ConnectionInterface;

class AbstractMessageComponentTest extends TestCase
{
    /**
     * This test would have caught the bug!
     * It verifies that send() works immediately after onOpen()
     */
    #[CoversNothing]
    public function testSendImmediatelyAfterOnOpen(): void
    {
        $store = new Store('test');
        $store->setLocked(true);

        $component = new class($store) extends AbstractMessageComponent {
            protected function dispatchWebSocketPayload(ConnectionInterface $conn, Payload $payload): void
            {
                // Not needed for this test
            }

            public function exposedSend(ConnectionInterface $conn, string $data): void
            {
                $this->send($conn, $data);
            }
        };

        // Create a mock connection that expects send() to be called
        $connection = new class implements ConnectionInterface {
            public int $resourceId = 1;
            public string $remoteAddress = '127.0.0.1:1234';
            public int $sendCallCount = 0;
            public string $lastSentData = '';

            public function send($data): void {
                $this->sendCallCount++;
                $this->lastSentData = $data;
            }
            public function close(): void {}
        };

        // Open connection
        $component->onOpen($connection);

        // THIS IS THE CRITICAL TEST: Send data immediately after opening
        // Without the fix, this triggers: "Undefined array key 'data'"
        $component->exposedSend($connection, 'test data');

        // Verify send was called exactly once with the correct data
        $this->assertSame(1, $connection->sendCallCount);
        $this->assertSame('test data', $connection->lastSentData);
    }

    /**
     * Test that getConnectionData works for new connections
     */
    #[CoversNothing]
    public function testGetConnectionDataForNewConnection(): void
    {
        $store = new Store('test');
        $store->setLocked(true);

        $component = new class($store) extends AbstractMessageComponent {
            protected function dispatchWebSocketPayload(ConnectionInterface $conn, Payload $payload): void {}

            public function exposedGetConnectionData(ConnectionInterface $conn): array
            {
                return $this->getConnectionData($conn);
            }
        };

        $connection = new class implements ConnectionInterface {
            public int $resourceId = 1;
            public string $remoteAddress = '127.0.0.1:1234';
            public function send($data): void {}
            public function close(): void {}
        };

        $component->onOpen($connection);

        // This should return empty array, not throw warning
        $data = $component->exposedGetConnectionData($connection);

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }
}
