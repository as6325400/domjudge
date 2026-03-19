<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Contest;
use App\Entity\Team;
use App\Entity\VirtualParticipation;
use PHPUnit\Framework\TestCase;

class VirtualParticipationTest extends TestCase
{
    public function testGetVirtualEndtime(): void
    {
        $vp = new VirtualParticipation();
        $vp->setVirtualStarttime(1000.0);
        $vp->setDuration(7200.0);

        static::assertEquals(8200.0, $vp->getVirtualEndtime());
    }

    public function testGetRelativeTime(): void
    {
        $vp = new VirtualParticipation();
        $vp->setVirtualStarttime(1000.0);
        $vp->setDuration(7200.0);

        static::assertEquals(500.0, $vp->getRelativeTime(1500.0));
        static::assertEquals(0.0, $vp->getRelativeTime(1000.0));
        static::assertEquals(7200.0, $vp->getRelativeTime(8200.0));
    }

    public function testGetDisplayLabel(): void
    {
        $vp = new VirtualParticipation();
        $vp->setVirtualNumber(3);

        static::assertEquals('(virtual #3)', $vp->getDisplayLabel());
    }

    public function testVpidDefaultsToNull(): void
    {
        $vp = new VirtualParticipation();
        static::assertNull($vp->getVpid());
    }

    public function testIsCompletedDefaultsFalse(): void
    {
        $vp = new VirtualParticipation();
        static::assertFalse($vp->getIsCompleted());
    }

    public function testSettersReturnSelf(): void
    {
        $contest = new Contest();
        $team = new Team();
        $vp = new VirtualParticipation();

        static::assertSame($vp, $vp->setContest($contest));
        static::assertSame($vp, $vp->setTeam($team));
        static::assertSame($vp, $vp->setVirtualStarttime(1000.0));
        static::assertSame($vp, $vp->setVirtualNumber(1));
        static::assertSame($vp, $vp->setIsCompleted(true));
        static::assertSame($vp, $vp->setDuration(7200.0));
    }

    public function testGettersReturnSetValues(): void
    {
        $contest = new Contest();
        $team = new Team();
        $vp = new VirtualParticipation();

        $vp->setContest($contest);
        $vp->setTeam($team);
        $vp->setVirtualStarttime(1000.0);
        $vp->setVirtualNumber(2);
        $vp->setIsCompleted(true);
        $vp->setDuration(7200.0);

        static::assertSame($contest, $vp->getContest());
        static::assertSame($team, $vp->getTeam());
        static::assertEquals(1000.0, $vp->getVirtualStarttime());
        static::assertEquals(2, $vp->getVirtualNumber());
        static::assertTrue($vp->getIsCompleted());
        static::assertEquals(7200.0, $vp->getDuration());
    }

    public function testSubmissionsCollectionEmpty(): void
    {
        $vp = new VirtualParticipation();
        static::assertCount(0, $vp->getSubmissions());
    }
}
