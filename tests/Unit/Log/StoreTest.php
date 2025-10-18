<?php

namespace Tests\Unit\Log;

use Exodus4D\Socket\Log\Store;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Store::class)]
class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store('testStore');
    }

    public function testConstructorSetsName(): void
    {
        $reflection = new \ReflectionClass($this->store);
        $property = $reflection->getProperty('name');

        $this->assertSame('testStore', $property->getValue($this->store));
    }

    public function testGetStoreReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->store->getStore());
    }

    public function testSetLockedAndIsLocked(): void
    {
        $this->assertFalse($this->store->isLocked());

        $this->store->setLocked(true);
        $this->assertTrue($this->store->isLocked());

        $this->store->setLocked(false);
        $this->assertFalse($this->store->isLocked());
    }

    public function testSetLogLevelZeroLocksStore(): void
    {
        $this->store->setLogLevel(0);

        $this->assertTrue($this->store->isLocked());
    }

    public function testSetLogLevelOneEnablesOnlyErrors(): void
    {
        $this->store->setLogLevel(1);

        // Capture output
        ob_start();
        $this->store->log('error', '127.0.0.1', 1, 'testAction', 'error message');
        $this->store->log('info', '127.0.0.1', 2, 'testAction', 'info message');
        $this->store->log('debug', '127.0.0.1', 3, 'testAction', 'debug message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs); // Only error should be logged
        $this->assertContains('error', $logs[0]['logTypes']);
    }

    public function testSetLogLevelTwoEnablesErrorsAndInfo(): void
    {
        $this->store->setLogLevel(2);

        ob_start();
        $this->store->log('error', '127.0.0.1', 1, 'testAction', 'error message');
        $this->store->log('info', '127.0.0.1', 2, 'testAction', 'info message');
        $this->store->log('debug', '127.0.0.1', 3, 'testAction', 'debug message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(2, $logs); // Error and info should be logged
    }

    public function testSetLogLevelThreeEnablesAllLogs(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log('error', '127.0.0.1', 1, 'testAction', 'error message');
        $this->store->log('info', '127.0.0.1', 2, 'testAction', 'info message');
        $this->store->log('debug', '127.0.0.1', 3, 'testAction', 'debug message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(3, $logs); // All three should be logged
    }

    public function testLogWithStringType(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log('error', '192.168.1.1', 123, 'testAction', 'test message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        $this->assertSame('testStore', $log['store']);
        $this->assertSame(['error'], $log['logTypes']);
        $this->assertSame('192.168.1.1', $log['remoteAddress']);
        $this->assertSame(123, $log['resourceId']);
        $this->assertSame('testAction', $log['action']);
        $this->assertSame('test message', $log['message']);
        $this->assertArrayHasKey('mTime', $log);
        $this->assertArrayHasKey('mTimeFormat1', $log);
        $this->assertArrayHasKey('mTimeFormat2', $log);
        $this->assertArrayHasKey('fileName', $log);
        $this->assertArrayHasKey('lineNumber', $log);
        $this->assertArrayHasKey('function', $log);
    }

    public function testLogWithArrayOfTypes(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log(['error', 'debug'], '127.0.0.1', 1, 'testAction', 'test');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs);
        $this->assertSame(['error', 'debug'], $logs[0]['logTypes']);
    }

    public function testLogWithNullRemoteAddressAndResourceId(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log('info', null, null, 'testAction', 'message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]['remoteAddress']);
        $this->assertNull($logs[0]['resourceId']);
    }

    public function testLogWithEmptyMessage(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log('info', '127.0.0.1', 1, 'testAction');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs);
        $this->assertSame('', $logs[0]['message']);
    }

    public function testLogDoesNotStoreWhenLocked(): void
    {
        $this->store->setLogLevel(3);
        $this->store->setLocked(true);

        ob_start();
        $this->store->log('error', '127.0.0.1', 1, 'testAction', 'message');
        ob_end_clean();

        $this->assertCount(0, $this->store->getStore());
    }

    public function testLogFiltersInvalidLogTypes(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log(['error', 'invalid_type', 'debug'], '127.0.0.1', 1, 'testAction', 'message');
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(1, $logs);
        // array_filter preserves keys, so check values instead
        $this->assertContains('error', $logs[0]['logTypes']);
        $this->assertContains('debug', $logs[0]['logTypes']);
        $this->assertNotContains('invalid_type', $logs[0]['logTypes']);
        $this->assertCount(2, $logs[0]['logTypes']);
    }

    public function testLogLimitsStoreSize(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        // Add more than DEFAULT_LOG_STORE_SIZE (50) entries
        for ($i = 0; $i < 60; $i++) {
            $this->store->log('info', '127.0.0.1', $i, 'testAction', "message $i");
        }
        ob_end_clean();

        $logs = $this->store->getStore();
        $this->assertCount(50, $logs); // Should be limited to 50

        // Should keep the latest 50 (10-59)
        $this->assertSame('message 10', $logs[0]['message']);
        $this->assertSame('message 59', $logs[49]['message']);
    }

    public function testLogEchosToStdout(): void
    {
        $this->store->setLogLevel(3);

        ob_start();
        $this->store->log('error', '127.0.0.1', 123, 'testAction', 'test message');
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('testStore', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('#123', $output);
        $this->assertStringContainsString('testAction', $output);
        $this->assertStringContainsString('test message', $output);
    }

    public function testGetDateTimeFromMicrotime(): void
    {
        $microtime = 1234567890.123456;
        $dateTime = Store::getDateTimeFromMicrotime($microtime);

        $this->assertInstanceOf(\DateTime::class, $dateTime);
        $this->assertSame('2009-02-13', $dateTime->format('Y-m-d'));
    }

    public function testGetDateTimeFromMicrotimeWithCurrentTime(): void
    {
        $microtime = microtime(true);
        $dateTime = Store::getDateTimeFromMicrotime($microtime);

        $this->assertInstanceOf(\DateTime::class, $dateTime);

        // Should be very close to now
        $now = new \DateTime();
        $diff = abs($now->getTimestamp() - $dateTime->getTimestamp());
        $this->assertLessThan(2, $diff); // Within 2 seconds
    }

    public function testLogDoesNotLogWhenAllTypesFiltered(): void
    {
        $this->store->setLogLevel(1); // Only errors enabled

        ob_start();
        $this->store->log(['info', 'debug'], '127.0.0.1', 1, 'testAction', 'message');
        ob_end_clean();

        $this->assertCount(0, $this->store->getStore());
    }

    public function testEchoLogInitializesColorsOnlyOnce(): void
    {
        $this->store->setLogLevel(3);

        // First call should initialize colors
        ob_start();
        $this->store->log('info', '127.0.0.1', 1, 'action1', 'message1');
        ob_end_clean();

        // Get the static colors property
        $reflection = new \ReflectionClass(Store::class);
        $property = $reflection->getProperty('colors');
        $colorsAfterFirst = $property->getValue();

        // Second call should reuse the same colors instance
        ob_start();
        $this->store->log('info', '127.0.0.1', 2, 'action2', 'message2');
        ob_end_clean();

        $colorsAfterSecond = $property->getValue();
        $this->assertSame($colorsAfterFirst, $colorsAfterSecond);
    }

    public function testEchoLogWithDifferentStoreNames(): void
    {
        $webSockStore = new Store('webSock');
        $tcpSockStore = new Store('tcpSock');

        $webSockStore->setLogLevel(3);
        $tcpSockStore->setLogLevel(3);

        // WebSock store should use brown color
        ob_start();
        $webSockStore->log('info', '127.0.0.1', 1, 'test', 'message');
        $webSockOutput = ob_get_clean();

        // TcpSock store should use cyan color
        ob_start();
        $tcpSockStore->log('info', '127.0.0.1', 1, 'test', 'message');
        $tcpSockOutput = ob_get_clean();

        $this->assertNotEmpty($webSockOutput);
        $this->assertNotEmpty($tcpSockOutput);
        // Both should contain their store names
        $this->assertStringContainsString('webSock', $webSockOutput);
        $this->assertStringContainsString('tcpSock', $tcpSockOutput);
    }
}
