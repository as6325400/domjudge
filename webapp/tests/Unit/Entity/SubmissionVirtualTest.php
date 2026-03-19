<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Submission;
use App\Entity\VirtualParticipation;
use PHPUnit\Framework\TestCase;

class SubmissionVirtualTest extends TestCase
{
    public function testIsVirtualDefaultsFalse(): void
    {
        $submission = new Submission();
        static::assertFalse($submission->isVirtual());
    }

    public function testIsVirtualWhenSet(): void
    {
        $submission = new Submission();
        $vp = new VirtualParticipation();

        $submission->setVirtualParticipation($vp);

        static::assertTrue($submission->isVirtual());
        static::assertSame($vp, $submission->getVirtualParticipation());
    }

    public function testIsVirtualWhenNull(): void
    {
        $submission = new Submission();
        $vp = new VirtualParticipation();

        $submission->setVirtualParticipation($vp);
        $submission->setVirtualParticipation(null);

        static::assertFalse($submission->isVirtual());
        static::assertNull($submission->getVirtualParticipation());
    }

    public function testSetVirtualParticipationReturnsSelf(): void
    {
        $submission = new Submission();
        $vp = new VirtualParticipation();

        static::assertSame($submission, $submission->setVirtualParticipation($vp));
    }
}
