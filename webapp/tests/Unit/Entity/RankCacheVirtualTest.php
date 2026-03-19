<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\RankCache;
use PHPUnit\Framework\TestCase;

class RankCacheVirtualTest extends TestCase
{
    public function testVpidDefaultsToZero(): void
    {
        $rankCache = new RankCache();
        static::assertEquals(0, $rankCache->getVpid());
    }

    public function testSetVpid(): void
    {
        $rankCache = new RankCache();
        $result = $rankCache->setVpid(42);

        static::assertSame($rankCache, $result);
        static::assertEquals(42, $rankCache->getVpid());
    }
}
