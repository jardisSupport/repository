<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\PrimaryKey;

use JardisSupport\Repository\PrimaryKey\StringPkGenerator;
use PHPUnit\Framework\TestCase;

final class StringPkGeneratorTest extends TestCase
{
    private StringPkGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new StringPkGenerator();
    }

    public function testGeneratesValidUuidV4Format(): void
    {
        $uuid = $this->generator->generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testGeneratesUniqueValues(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = $this->generator->generate();
        }

        $this->assertCount(100, array_unique($uuids));
    }

    public function testGeneratesCorrectLength(): void
    {
        $uuid = $this->generator->generate();

        $this->assertSame(36, strlen($uuid));
    }
}
