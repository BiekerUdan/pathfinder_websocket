<?php

namespace Tests\Unit\Log;

use Exodus4D\Socket\Log\ShellColors;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShellColors::class)]
class ShellColorsTest extends TestCase
{
    private ShellColors $shellColors;

    protected function setUp(): void
    {
        $this->shellColors = new ShellColors();
    }

    public function testGetForegroundColorsReturnsArray(): void
    {
        $colors = $this->shellColors->getForegroundColors();

        $this->assertIsArray($colors);
        $this->assertNotEmpty($colors);
        $this->assertContains('red', $colors);
        $this->assertContains('green', $colors);
        $this->assertContains('blue', $colors);
        $this->assertContains('white', $colors);
    }

    public function testGetBackgroundColorsReturnsArray(): void
    {
        $colors = $this->shellColors->getBackgroundColors();

        $this->assertIsArray($colors);
        $this->assertNotEmpty($colors);
        $this->assertContains('red', $colors);
        $this->assertContains('green', $colors);
        $this->assertContains('blue', $colors);
    }

    public function testGetColoredStringWithNoColors(): void
    {
        $result = $this->shellColors->getColoredString('test');

        // Should have reset code but no color codes
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testGetColoredStringWithForegroundColor(): void
    {
        $result = $this->shellColors->getColoredString('test', 'red');

        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[0;31m", $result); // Red foreground code
        $this->assertStringContainsString("\033[0m", $result); // Reset code
    }

    public function testGetColoredStringWithBackgroundColor(): void
    {
        $result = $this->shellColors->getColoredString('test', null, 'blue');

        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[44m", $result); // Blue background code
        $this->assertStringContainsString("\033[0m", $result); // Reset code
    }

    public function testGetColoredStringWithBothColors(): void
    {
        $result = $this->shellColors->getColoredString('test', 'white', 'red');

        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[1;37m", $result); // White foreground code
        $this->assertStringContainsString("\033[41m", $result); // Red background code
        $this->assertStringContainsString("\033[0m", $result); // Reset code
    }

    public function testGetColoredStringWithInvalidForegroundColor(): void
    {
        $result = $this->shellColors->getColoredString('test', 'invalid_color');

        // Should not contain any foreground color code
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[0m", $result);
        // Should not start with an ANSI code (only ends with reset)
        $this->assertStringStartsWith('test', $result);
    }

    public function testGetColoredStringWithInvalidBackgroundColor(): void
    {
        $result = $this->shellColors->getColoredString('test', null, 'invalid_color');

        // Should not contain any background color code
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testGetColoredStringWithEmptyString(): void
    {
        $result = $this->shellColors->getColoredString('', 'red');

        $this->assertStringContainsString("\033[0;31m", $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testAllForegroundColorsHaveCorrectCodes(): void
    {
        $expectedColors = [
            'black', 'dark_gray', 'blue', 'light_blue', 'green', 'light_green',
            'cyan', 'light_cyan', 'red', 'light_red', 'purple', 'light_purple',
            'brown', 'yellow', 'light_gray', 'white'
        ];

        $actualColors = $this->shellColors->getForegroundColors();

        $this->assertCount(count($expectedColors), $actualColors);
        foreach ($expectedColors as $color) {
            $this->assertContains($color, $actualColors);
        }
    }

    public function testAllBackgroundColorsHaveCorrectCodes(): void
    {
        $expectedColors = [
            'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'light_gray'
        ];

        $actualColors = $this->shellColors->getBackgroundColors();

        $this->assertCount(count($expectedColors), $actualColors);
        foreach ($expectedColors as $color) {
            $this->assertContains($color, $actualColors);
        }
    }

    public function testColoredStringEndsWithReset(): void
    {
        $result = $this->shellColors->getColoredString('test', 'red', 'blue');

        $this->assertStringEndsWith("\033[0m", $result);
    }
}
