<?php

namespace Tests\Unit\Socket;

use Exodus4D\Socket\Socket\CommandAuth;
use Exodus4D\Socket\Socket\TcpSocket;
use Exodus4D\Socket\Log\Store;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use React\EventLoop;
use React\Socket;

#[CoversClass(CommandAuth::class)]
class CommandAuthTest extends TestCase
{
    private const SECRET = 'test-command-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();
        $prop = new \ReflectionProperty(CommandAuth::class, 'seenNonces');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function testVerifyRoundtrip(): void
    {
        $auth = $this->sign('characterLogout', [12, 34]);
        $this->assertTrue(CommandAuth::verify(self::SECRET, 'characterLogout', [12, 34], $auth));
    }

    public function testTamperedLoadRejected(): void
    {
        $auth = $this->sign('mapUpdate', [1]);
        $this->assertFalse(CommandAuth::verify(self::SECRET, 'mapUpdate', [2], $auth));
    }

    public function testReplayRejected(): void
    {
        $auth = $this->sign('mapUpdate', [1]);
        $this->assertTrue(CommandAuth::verify(self::SECRET, 'mapUpdate', [1], $auth));
        $this->assertFalse(CommandAuth::verify(self::SECRET, 'mapUpdate', [1], $auth));
    }

    public function testStaleTimestampRejected(): void
    {
        $auth = $this->sign('mapUpdate', [1], time() - 600);
        $this->assertFalse(CommandAuth::verify(self::SECRET, 'mapUpdate', [1], $auth));
    }

    public function testWrongSecretRejected(): void
    {
        $auth = $this->sign('mapUpdate', [1]);
        $this->assertFalse(CommandAuth::verify('other-secret', 'mapUpdate', [1], $auth));
    }

    public function testMissingAuthRejected(): void
    {
        $this->assertFalse(CommandAuth::verify(self::SECRET, 'mapUpdate', [1], null));
        $this->assertFalse(CommandAuth::verify(self::SECRET, 'mapUpdate', [1], []));
    }

    /**
     * cross-repo interop: identical inputs must yield the identical signature
     * as the pathfinder repo's CommandAuth (see its testInteropVector).
     */
    public function testInteropVectorMatchesPathfinder(): void
    {
        $expected = hash_hmac(
            'sha256',
            "characterLogout\n1720000000\nab12cd34ef56ab78\n[12,34]",
            self::SECRET
        );
        $this->assertSame(
            $expected,
            CommandAuth::signature(self::SECRET, 'characterLogout', [12, 34], 1720000000, 'ab12cd34ef56ab78')
        );
        $this->assertTrue(CommandAuth::verify(
            self::SECRET,
            'characterLogout',
            [12, 34],
            // fresh ts for the liveness check; sig pinned above proves interop
            ['ts' => time(), 'nonce' => 'ab12cd34ef56ab78', 'sig' => CommandAuth::signature(self::SECRET, 'characterLogout', [12, 34], time(), 'ab12cd34ef56ab78')]
        ));
    }

    public function testTcpSocketAcceptsSignedWhenSecretSet(): void
    {
        $socket = $this->socketWithSecret(self::SECRET);
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $auth = $this->sign('healthCheck', 1234.5);
        $this->assertTrue($socket->verifyCommandAuth($connection, 'healthCheck', [
            'task' => 'healthCheck',
            'load' => 1234.5,
            'auth' => $auth,
        ]));
    }

    public function testTcpSocketRejectsUnsignedWhenSecretSet(): void
    {
        $socket = $this->socketWithSecret(self::SECRET);
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $this->assertFalse($socket->verifyCommandAuth($connection, 'healthCheck', [
            'task' => 'healthCheck',
            'load' => 1234.5,
        ]));
    }

    public function testTcpSocketAcceptsUnsignedWhenNoSecret(): void
    {
        $socket = $this->socketWithSecret('');
        $connection = $this->createMock(Socket\ConnectionInterface::class);
        $this->assertTrue($socket->verifyCommandAuth($connection, 'healthCheck', [
            'task' => 'healthCheck',
            'load' => 1234.5,
        ]));
    }

    private function sign(string $task, $load, ?int $ts = null): array
    {
        $ts = $ts ?? time();
        $nonce = bin2hex(random_bytes(8));
        return [
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => CommandAuth::signature(self::SECRET, $task, $load, $ts, $nonce),
        ];
    }

    private function socketWithSecret(string $secret): TcpSocket
    {
        $loop = $this->createMock(EventLoop\LoopInterface::class);
        $handler = $this->createMock(TestMessageHandler::class);
        $store = new Store('test');
        $store->setLocked(true);
        return new TcpSocket($loop, $handler, $store, 'json', 3.0, true, $secret);
    }
}
