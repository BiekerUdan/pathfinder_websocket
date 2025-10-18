<?php

namespace Tests\Unit\Component;

use Exodus4D\Socket\Component\MapUpdate;
use Exodus4D\Socket\Log\Store;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MapUpdate::class)]
class MapUpdateTest extends TestCase
{
    private MapUpdate $mapUpdate;
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store('test');
        $this->store->setLocked(true); // Disable logging during tests
        $this->mapUpdate = new MapUpdate($this->store);
    }

    public function testReceiveDataWithHealthCheck(): void
    {
        $token = microtime(true);
        $result = $this->mapUpdate->receiveData('healthCheck', $token);

        $this->assertIsFloat($result);
        $this->assertEquals($token, $result);
    }

    public function testReceiveDataWithCharacterLogout(): void
    {
        $characterIds = [1, 2, 3];
        $result = $this->mapUpdate->receiveData('characterLogout', $characterIds);

        $this->assertTrue($result);
    }

    public function testSetConnectionAccessCreatesTokens(): void
    {
        $connectionAccessData = [
            'id' => 123,
            'token' => 'char_token_abc',
            'characterData' => [
                'id' => 123,
                'name' => 'Test Character'
            ],
            'mapData' => [
                [
                    'id' => 1,
                    'token' => 'map_token_xyz'
                ],
                [
                    'id' => 2,
                    'token' => 'map_token_def'
                ]
            ]
        ];

        $result = $this->mapUpdate->receiveData('mapConnectionAccess', $connectionAccessData);

        $this->assertSame('OK', $result);

        // Test that character access was set by trying to check it
        // We'll use reflection to access private method
        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkCharacterAccess = $reflection->getMethod('checkCharacterAccess');

        $characterData = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'char_token_abc');

        $this->assertIsArray($characterData);
        $this->assertSame(123, $characterData['id']);
        $this->assertSame('Test Character', $characterData['name']);

        // Test map access
        $checkMapAccess = $reflection->getMethod('checkMapAccess');

        $hasAccess1 = $checkMapAccess->invoke($this->mapUpdate, 123, 1, 'map_token_xyz');
        $hasAccess2 = $checkMapAccess->invoke($this->mapUpdate, 123, 2, 'map_token_def');

        $this->assertTrue($hasAccess1);
        $this->assertTrue($hasAccess2);
    }

    public function testCheckCharacterAccessWithInvalidToken(): void
    {
        // Set up valid access first
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'valid_token',
            'characterData' => ['id' => 123],
            'mapData' => []
        ]);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkCharacterAccess = $reflection->getMethod('checkCharacterAccess');

        // Try with wrong token
        $characterData = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'wrong_token');

        $this->assertEmpty($characterData);
    }

    public function testCheckCharacterAccessRemovesExpiredTokens(): void
    {
        // Manually set up expired token via reflection
        $reflection = new \ReflectionClass($this->mapUpdate);

        $characterAccessData = $reflection->getProperty('characterAccessData');

        // Set token that expired 10 seconds ago
        $characterAccessData->setValue($this->mapUpdate, [
            123 => [
                [
                    'token' => 'expired_token',
                    'expire' => time() - 10,
                    'characterData' => ['id' => 123]
                ]
            ]
        ]);

        $checkCharacterAccess = $reflection->getMethod('checkCharacterAccess');

        $characterData = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'expired_token');

        // Should return empty because token is expired
        $this->assertEmpty($characterData);

        // Token should be removed from storage
        $accessData = $characterAccessData->getValue($this->mapUpdate);
        $this->assertArrayNotHasKey(123, $accessData);
    }

    public function testCheckMapAccessWithInvalidToken(): void
    {
        // Set up valid access first
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'char_token',
            'characterData' => ['id' => 123],
            'mapData' => [
                ['id' => 1, 'token' => 'valid_map_token']
            ]
        ]);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkMapAccess = $reflection->getMethod('checkMapAccess');

        // Try with wrong token
        $hasAccess = $checkMapAccess->invoke($this->mapUpdate, 123, 1, 'wrong_token');

        $this->assertFalse($hasAccess);
    }

    public function testCheckMapAccessRemovesExpiredTokens(): void
    {
        $reflection = new \ReflectionClass($this->mapUpdate);

        $mapAccessData = $reflection->getProperty('mapAccessData');

        // Set token that expired 10 seconds ago
        $mapAccessData->setValue($this->mapUpdate, [
            1 => [
                123 => [
                    [
                        'token' => 'expired_map_token',
                        'expire' => time() - 10
                    ]
                ]
            ]
        ]);

        $checkMapAccess = $reflection->getMethod('checkMapAccess');

        $hasAccess = $checkMapAccess->invoke($this->mapUpdate, 123, 1, 'expired_map_token');

        $this->assertFalse($hasAccess);

        // Token should be removed from storage
        $accessData = $mapAccessData->getValue($this->mapUpdate);
        $this->assertArrayNotHasKey(1, $accessData);
    }

    public function testTokensAreConsumedOnValidation(): void
    {
        // Set up access
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'one_time_token',
            'characterData' => ['id' => 123, 'name' => 'Test'],
            'mapData' => []
        ]);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkCharacterAccess = $reflection->getMethod('checkCharacterAccess');

        // First use should work
        $characterData1 = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'one_time_token');
        $this->assertNotEmpty($characterData1);
        $this->assertSame('Test', $characterData1['name']);

        // Second use should fail (token consumed) - suppress warning about missing key
        $characterData2 = @$checkCharacterAccess->invoke($this->mapUpdate, 123, 'one_time_token');
        $this->assertEmpty($characterData2);
    }

    public function testMapTokensAreConsumedOnValidation(): void
    {
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'char_token',
            'characterData' => ['id' => 123],
            'mapData' => [
                ['id' => 1, 'token' => 'one_time_map_token']
            ]
        ]);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkMapAccess = $reflection->getMethod('checkMapAccess');

        // First use should work
        $hasAccess1 = $checkMapAccess->invoke($this->mapUpdate, 123, 1, 'one_time_map_token');
        $this->assertTrue($hasAccess1);

        // Second use should fail (token consumed) - suppress warnings
        $hasAccess2 = @$checkMapAccess->invoke($this->mapUpdate, 123, 1, 'one_time_map_token');
        $this->assertFalse($hasAccess2);
    }

    public function testGetSocketStatsReturnsStructure(): void
    {
        $stats = $this->mapUpdate->getSocketStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('connections', $stats);
        $this->assertArrayHasKey('maxConnections', $stats);
        $this->assertArrayHasKey('logs', $stats);
        $this->assertSame(0, $stats['connections']);
        $this->assertSame(0, $stats['maxConnections']);
    }

    public function testReceiveDataWithMapUpdateReturnsConnectionCount(): void
    {
        $mapData = [
            'config' => ['id' => 1],
            'data' => ['systems' => []]
        ];

        $result = $this->mapUpdate->receiveData('mapUpdate', $mapData);

        $this->assertIsInt($result);
        $this->assertSame(0, $result); // No connections subscribed
    }

    public function testReceiveDataWithMapDeletedReturnsConnectionCount(): void
    {
        $mapId = 1;

        $result = $this->mapUpdate->receiveData('mapDeleted', $mapId);

        $this->assertIsInt($result);
        $this->assertSame(0, $result); // No connections subscribed
    }

    public function testReceiveDataWithCharacterUpdateDoesNotThrow(): void
    {
        $characterData = [
            'id' => 123,
            'name' => 'Updated Character'
        ];

        // Should not throw, even if character not subscribed
        $result = $this->mapUpdate->receiveData('characterUpdate', $characterData);

        $this->assertNull($result);
    }

    public function testSetConnectionAccessWithMissingDataReturnsFalse(): void
    {
        // Missing characterData - suppress warning
        $result = @$this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'token',
            'mapData' => []
        ]);

        $this->assertFalse($result);
    }

    public function testSetConnectionAccessWithMissingTokenReturnsFalse(): void
    {
        // Missing token - suppress warning
        $result = @$this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'characterData' => ['id' => 123],
            'mapData' => []
        ]);

        $this->assertFalse($result);
    }

    public function testSetConnectionAccessWithMissingIdReturnsFalse(): void
    {
        // Missing id - suppress warning
        $result = @$this->mapUpdate->receiveData('mapConnectionAccess', [
            'token' => 'token',
            'characterData' => ['id' => 123],
            'mapData' => []
        ]);

        $this->assertFalse($result);
    }

    public function testMultipleTokensCanExistForSameCharacter(): void
    {
        // Add first token
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'token1',
            'characterData' => ['id' => 123, 'name' => 'User1'],
            'mapData' => []
        ]);

        // Add second token for same character (e.g., multiple browser tabs)
        $this->mapUpdate->receiveData('mapConnectionAccess', [
            'id' => 123,
            'token' => 'token2',
            'characterData' => ['id' => 123, 'name' => 'User2'],
            'mapData' => []
        ]);

        $reflection = new \ReflectionClass($this->mapUpdate);
        $checkCharacterAccess = $reflection->getMethod('checkCharacterAccess');

        // Both tokens should work
        $data1 = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'token1');
        $this->assertSame('User1', $data1['name']);

        $data2 = $checkCharacterAccess->invoke($this->mapUpdate, 123, 'token2');
        $this->assertSame('User2', $data2['name']);
    }
}
