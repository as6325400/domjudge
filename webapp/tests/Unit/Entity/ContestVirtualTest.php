<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Contest;
use PHPUnit\Framework\TestCase;

class ContestVirtualTest extends TestCase
{
    public function testAllowVirtualDefaultsFalse(): void
    {
        $contest = new Contest();
        static::assertFalse($contest->getAllowVirtual());
    }

    public function testSetAllowVirtual(): void
    {
        $contest = new Contest();
        $result = $contest->setAllowVirtual(true);

        static::assertSame($contest, $result);
        static::assertTrue($contest->getAllowVirtual());
    }

    public function testGetDuration(): void
    {
        $contest = new Contest();
        $contest->setStarttimeString('2023-01-01 10:00:00 Europe/Amsterdam');
        $contest->setEndtimeString('+02:00:00');

        static::assertEquals(7200.0, $contest->getDurationInSeconds());
    }

    public function testVirtualParticipationsCollectionEmpty(): void
    {
        $contest = new Contest();
        static::assertCount(0, $contest->getVirtualParticipations());
    }
}
