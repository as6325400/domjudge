<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ScoreCache;
use PHPUnit\Framework\TestCase;

class ScoreCacheVirtualTest extends TestCase
{
    public function testVpidDefaultsToZero(): void
    {
        $scoreCache = new ScoreCache();
        static::assertEquals(0, $scoreCache->getVpid());
    }

    public function testSetVpid(): void
    {
        $scoreCache = new ScoreCache();
        $result = $scoreCache->setVpid(42);

        static::assertSame($scoreCache, $result);
        static::assertEquals(42, $scoreCache->getVpid());
    }
}
