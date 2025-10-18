<?php

namespace Tests\Unit\Data;

use Exodus4D\Socket\Data\Payload;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Payload::class)]
class PayloadTest extends TestCase
{
    public function testConstructorSetsTaskAndLoad(): void
    {
        $payload = new Payload('testTask', ['data' => 'value']);

        $this->assertSame('testTask', $payload->task);
        $this->assertSame(['data' => 'value'], $payload->load);
        $this->assertNull($payload->characterIds);
    }

    public function testConstructorWithCharacterIds(): void
    {
        $characterIds = [1, 2, 3];
        $payload = new Payload('testTask', null, $characterIds);

        $this->assertSame('testTask', $payload->task);
        $this->assertNull($payload->load);
        $this->assertSame($characterIds, $payload->characterIds);
    }

    public function testConstructorThrowsExceptionForEmptyTask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'task' must be a not empty string");

        new Payload('');
    }

    public function testSetTask(): void
    {
        $payload = new Payload('initialTask');
        $payload->setTask('newTask');

        $this->assertSame('newTask', $payload->task);
    }

    public function testSetTaskThrowsExceptionForEmptyString(): void
    {
        $payload = new Payload('validTask');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'task' must be a not empty string");

        $payload->setTask('');
    }

    public function testSetLoad(): void
    {
        $payload = new Payload('task');
        $payload->setLoad(['new' => 'data']);

        $this->assertSame(['new' => 'data'], $payload->load);
    }

    public function testSetLoadWithNull(): void
    {
        $payload = new Payload('task', ['initial' => 'data']);
        $payload->setLoad(null);

        $this->assertNull($payload->load);
    }

    public function testSetCharacterIds(): void
    {
        $payload = new Payload('task');
        $characterIds = [4, 5, 6];
        $payload->setCharacterIds($characterIds);

        $this->assertSame($characterIds, $payload->characterIds);
    }

    public function testSetCharacterIdsWithNull(): void
    {
        $payload = new Payload('task', null, [1, 2]);
        $payload->setCharacterIds(null);

        $this->assertNull($payload->characterIds);
    }

    public function testJsonSerialize(): void
    {
        $payload = new Payload('testTask', ['data' => 'value'], [1, 2, 3]);
        $json = json_encode($payload);

        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertSame('testTask', $decoded['task']);
        $this->assertSame(['data' => 'value'], $decoded['load']);
        $this->assertSame([1, 2, 3], $decoded['characterIds']);
    }

    public function testJsonSerializeWithNullValues(): void
    {
        $payload = new Payload('task');
        $json = json_encode($payload);

        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertSame('task', $decoded['task']);
        $this->assertNull($decoded['load']);
        $this->assertNull($decoded['characterIds']);
    }

    public function testMagicGetter(): void
    {
        $payload = new Payload('testTask', 'testLoad', [1, 2]);

        $this->assertSame('testTask', $payload->task);
        $this->assertSame('testLoad', $payload->load);
        $this->assertSame([1, 2], $payload->characterIds);
    }
}
