<?php

namespace Tests\Unit\Component\Handler;

use Exodus4D\Socket\Component\Handler\LogFileHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LogFileHandler::class)]
class LogFileHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create a unique temp directory for each test
        $this->tempDir = sys_get_temp_dir() . '/logfilehandler_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory and files
        if (is_dir($this->tempDir)) {
            $this->recursiveRemoveDirectory($this->tempDir);
        }
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testConstructorCreatesDirectory(): void
    {
        $logFile = $this->tempDir . '/logs/test.log';

        $this->assertDirectoryDoesNotExist($this->tempDir . '/logs');

        $handler = new LogFileHandler($logFile);

        $this->assertDirectoryExists($this->tempDir . '/logs');
    }

    public function testConstructorDoesNotThrowWhenDirectoryExists(): void
    {
        mkdir($this->tempDir, 0777, true);
        $logFile = $this->tempDir . '/test.log';

        $handler = new LogFileHandler($logFile);

        $this->assertDirectoryExists($this->tempDir);
    }

    public function testWriteCreatesFileWithJsonData(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $logData = [
            'level' => 'error',
            'message' => 'Test error message',
            'timestamp' => '2025-10-18 12:00:00'
        ];

        $handler->write($logData);

        $this->assertFileExists($logFile);

        $contents = file_get_contents($logFile);
        $this->assertNotEmpty($contents);

        // Should be valid JSON
        $decoded = json_decode(trim($contents), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['level']);
        $this->assertSame('Test error message', $decoded['message']);
        $this->assertSame('2025-10-18 12:00:00', $decoded['timestamp']);
    }

    public function testWriteAppendsToExistingFile(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $log1 = ['message' => 'First log'];
        $log2 = ['message' => 'Second log'];

        $handler->write($log1);
        $handler->write($log2);

        $contents = file_get_contents($logFile);
        $lines = explode(PHP_EOL, trim($contents));

        $this->assertCount(2, $lines);

        $decoded1 = json_decode($lines[0], true);
        $decoded2 = json_decode($lines[1], true);

        $this->assertSame('First log', $decoded1['message']);
        $this->assertSame('Second log', $decoded2['message']);
    }

    public function testWriteWithEmptyArrayWritesEmptyJsonArray(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $handler->write([]);

        $this->assertFileExists($logFile);
        $contents = trim(file_get_contents($logFile));
        // Empty array gets JSON encoded as '[]'
        $this->assertSame('[]', $contents);
    }

    public function testWriteEncodesJsonWithoutEscapingSlashes(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $logData = [
            'path' => '/var/www/html/index.php',
            'url' => 'http://example.com/path/to/resource'
        ];

        $handler->write($logData);

        $contents = file_get_contents($logFile);

        // Should not escape slashes
        $this->assertStringContainsString('/var/www/html/index.php', $contents);
        $this->assertStringContainsString('http://example.com/path/to/resource', $contents);
        $this->assertStringNotContainsString('\\/', $contents);
    }

    public function testWriteEncodesUnicodeWithoutEscaping(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $logData = [
            'message' => 'Test with Unicode: 日本語 中文 한국어'
        ];

        $handler->write($logData);

        $contents = file_get_contents($logFile);

        // Should preserve Unicode characters
        $this->assertStringContainsString('日本語', $contents);
        $this->assertStringContainsString('中文', $contents);
        $this->assertStringContainsString('한국어', $contents);
    }

    public function testWriteAddsNewlineAfterEachEntry(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $handler->write(['message' => 'Test']);

        $contents = file_get_contents($logFile);

        $this->assertStringEndsWith(PHP_EOL, $contents);
    }

    public function testWriteSetsFilePermissions(): void
    {
        // This test might fail on Windows or systems with different permission handling
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('File permission test skipped on Windows');
        }

        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        $handler->write(['message' => 'Test']);

        $perms = fileperms($logFile);
        // Check if file is readable and writable by all (0666)
        $this->assertTrue(($perms & 0444) === 0444); // Readable by all
        $this->assertTrue(($perms & 0222) === 0222); // Writable by all
    }

    public function testMultipleWritesAreSafelyAppended(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        // Write multiple logs to test file locking
        for ($i = 0; $i < 10; $i++) {
            $handler->write(['count' => $i, 'message' => "Log entry $i"]);
        }

        $contents = file_get_contents($logFile);
        $lines = explode(PHP_EOL, trim($contents));

        $this->assertCount(10, $lines);

        // Verify all entries are valid JSON
        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertSame($index, $decoded['count']);
        }
    }

    public function testConstructorThrowsExceptionWhenDirectoryCannotBeCreated(): void
    {
        // Try to create a directory in a path that shouldn't be writable
        // This is tricky to test reliably across platforms
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Directory creation test skipped on Windows');
        }

        // Create a file where we'll try to create a directory
        $blockingFile = $this->tempDir . '/blocking';
        mkdir($this->tempDir, 0777, true);
        touch($blockingFile);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('There is no existing directory');

        // Suppress the mkdir warning that will be triggered
        @new LogFileHandler($blockingFile . '/subdir/test.log');
    }

    public function testConstructorWithNestedDirectories(): void
    {
        $logFile = $this->tempDir . '/level1/level2/level3/test.log';

        $handler = new LogFileHandler($logFile);

        $this->assertDirectoryExists($this->tempDir . '/level1/level2/level3');
    }

    public function testConstructorOnlyCreatesDirectoryOnce(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $handler = new LogFileHandler($logFile);

        // Access dirCreated via reflection
        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('dirCreated');

        $this->assertTrue($property->getValue($handler));

        // Call createDir again (through reflection) - should not attempt to create again
        $method = $reflection->getMethod('createDir');
        $method->invoke($handler);

        // Should still be true
        $this->assertTrue($property->getValue($handler));
    }
}
