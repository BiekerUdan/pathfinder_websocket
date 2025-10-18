<?php

namespace Tests\Unit\Component\Formatter;

use Exodus4D\Socket\Component\Formatter\SubscriptionFormatter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubscriptionFormatter::class)]
class SubscriptionFormatterTest extends TestCase
{
    public function testGroupCharactersDataBySystemWithEmptyArray(): void
    {
        $result = SubscriptionFormatter::groupCharactersDataBySystem([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGroupCharactersDataBySystemWithSingleCharacterNoLog(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Character 1'
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsObject($result[0]);
        $this->assertSame(0, $result[0]->id); // No system = systemId 0
        $this->assertIsArray($result[0]->user);
        $this->assertCount(1, $result[0]->user);
        $this->assertSame(1, $result[0]->user[0]['id']);
    }

    public function testGroupCharactersDataBySystemWithSingleCharacterWithLog(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Character 1',
                'log' => [
                    'system' => [
                        'id' => 5
                    ]
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsObject($result[0]);
        $this->assertSame(5, $result[0]->id);
        $this->assertIsArray($result[0]->user);
        $this->assertCount(1, $result[0]->user);
        $this->assertSame(1, $result[0]->user[0]['id']);
    }

    public function testGroupCharactersDataBySystemWithMultipleCharactersInSameSystem(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Character 1',
                'log' => [
                    'system' => [
                        'id' => 10
                    ]
                ]
            ],
            2 => [
                'id' => 2,
                'name' => 'Character 2',
                'log' => [
                    'system' => [
                        'id' => 10
                    ]
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertIsArray($result);
        $this->assertCount(1, $result); // All in same system
        $this->assertSame(10, $result[0]->id);
        $this->assertIsArray($result[0]->user);
        $this->assertCount(2, $result[0]->user); // Two users in this system
    }

    public function testGroupCharactersDataBySystemWithMultipleCharactersInDifferentSystems(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Character 1',
                'log' => [
                    'system' => [
                        'id' => 10
                    ]
                ]
            ],
            2 => [
                'id' => 2,
                'name' => 'Character 2',
                'log' => [
                    'system' => [
                        'id' => 20
                    ]
                ]
            ],
            3 => [
                'id' => 3,
                'name' => 'Character 3',
                'log' => [
                    'system' => [
                        'id' => 10
                    ]
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertIsArray($result);
        $this->assertCount(2, $result); // Two different systems

        // Find systems in result
        $system10 = null;
        $system20 = null;
        foreach ($result as $system) {
            if ($system->id === 10) {
                $system10 = $system;
            } elseif ($system->id === 20) {
                $system20 = $system;
            }
        }

        $this->assertNotNull($system10);
        $this->assertNotNull($system20);
        $this->assertCount(2, $system10->user); // Characters 1 and 3
        $this->assertCount(1, $system20->user); // Character 2
    }

    public function testGroupCharactersDataBySystemMixedWithAndWithoutLogs(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Character 1',
                'log' => [
                    'system' => [
                        'id' => 15
                    ]
                ]
            ],
            2 => [
                'id' => 2,
                'name' => 'Character 2'
                // No log
            ],
            3 => [
                'id' => 3,
                'name' => 'Character 3',
                'log' => [
                    // Log exists but no system
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertIsArray($result);
        $this->assertCount(2, $result); // System 15 and system 0

        // Find systems
        $system15 = null;
        $system0 = null;
        foreach ($result as $system) {
            if ($system->id === 15) {
                $system15 = $system;
            } elseif ($system->id === 0) {
                $system0 = $system;
            }
        }

        $this->assertNotNull($system15);
        $this->assertNotNull($system0);
        $this->assertCount(1, $system15->user); // Character 1
        $this->assertCount(2, $system0->user); // Characters 2 and 3
    }

    public function testGroupCharactersDataBySystemReturnsReindexedArray(): void
    {
        $charactersData = [
            100 => [
                'id' => 100,
                'name' => 'Character 100',
                'log' => [
                    'system' => [
                        'id' => 1
                    ]
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        // Result should be numerically indexed starting from 0
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayNotHasKey(100, $result);
    }

    public function testGroupCharactersDataBySystemPreservesCharacterData(): void
    {
        $charactersData = [
            1 => [
                'id' => 1,
                'name' => 'Test Character',
                'shipType' => 'Frigate',
                'log' => [
                    'system' => [
                        'id' => 5,
                        'name' => 'Jita'
                    ]
                ]
            ]
        ];

        $result = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

        $this->assertSame('Test Character', $result[0]->user[0]['name']);
        $this->assertSame('Frigate', $result[0]->user[0]['shipType']);
        $this->assertSame(5, $result[0]->user[0]['log']['system']['id']);
        $this->assertSame('Jita', $result[0]->user[0]['log']['system']['name']);
    }
}
