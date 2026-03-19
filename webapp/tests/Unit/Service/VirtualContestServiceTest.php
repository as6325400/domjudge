<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Contest;
use App\Entity\Team;
use App\Entity\TeamCategory;
use App\Entity\VirtualParticipation;
use App\Service\VirtualContestService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class VirtualContestServiceTest extends KernelTestCase
{
    final public const CONTEST_NAME = 'virtualtest';

    private VirtualContestService $service;

    private ?EntityManagerInterface $em;

    private Contest $contest;

    private Team $team;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->service = new VirtualContestService(
            $this->em,
            self::getContainer()->get(LoggerInterface::class)
        );

        $category = $this->em->getRepository(TeamCategory::class)
            ->findOneBy(['sortorder' => 0]);

        $this->contest = new Contest();
        $this->contest
            ->setExternalid(self::CONTEST_NAME)
            ->setName(self::CONTEST_NAME)
            ->setShortname(self::CONTEST_NAME)
            ->setStarttimeString(date('Y').'-01-01 10:00:00 Europe/Amsterdam')
            ->setActivatetimeString('-01:00')
            ->setEndtimeString('+02:00')
            ->setAllowVirtual(true);
        $this->em->persist($this->contest);

        $this->team = new Team();
        $this->team
            ->setName(self::CONTEST_NAME.' team')
            ->setCategory($category);
        $this->em->persist($this->team);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (!$this->hasFailed()) {
            // Clean up VPs first due to FK constraints.
            $vps = $this->em->getRepository(VirtualParticipation::class)->findBy(['contest' => $this->contest]);
            foreach ($vps as $vp) {
                $this->em->remove($vp);
            }
            $this->em->flush();

            $this->em->remove($this->contest);
            $this->em->remove($this->team);
            $this->em->flush();
        }

        parent::tearDown();

        $this->em->close();
        $this->em = null;
    }

    public function testCanStartVirtual(): void
    {
        static::assertTrue($this->service->canStartVirtual($this->contest, $this->team));
    }

    public function testCanStartVirtualDisabled(): void
    {
        $this->contest->setAllowVirtual(false);
        $this->em->flush();

        static::assertFalse($this->service->canStartVirtual($this->contest, $this->team));
    }

    public function testStartVirtualParticipation(): void
    {
        $vp = $this->service->startVirtualParticipation($this->contest, $this->team);

        static::assertNotNull($vp->getVpid());
        static::assertEquals(1, $vp->getVirtualNumber());
        static::assertSame($this->contest, $vp->getContest());
        static::assertSame($this->team, $vp->getTeam());
        static::assertFalse($vp->getIsCompleted());
        static::assertEquals($this->contest->getDurationInSeconds(), (float)$vp->getDuration());
    }

    public function testStartMultipleVirtualParticipations(): void
    {
        $vp1 = $this->service->startVirtualParticipation($this->contest, $this->team);
        static::assertEquals(1, $vp1->getVirtualNumber());

        // Complete the first one so we can start another.
        $vp1->setIsCompleted(true);
        $this->em->flush();

        $vp2 = $this->service->startVirtualParticipation($this->contest, $this->team);
        static::assertEquals(2, $vp2->getVirtualNumber());
    }

    public function testCannotStartWhileActive(): void
    {
        $this->service->startVirtualParticipation($this->contest, $this->team);

        static::assertFalse($this->service->canStartVirtual($this->contest, $this->team));
    }

    public function testGetActiveVirtualParticipation(): void
    {
        $vp = $this->service->startVirtualParticipation($this->contest, $this->team);

        $active = $this->service->getActiveVirtualParticipation($this->contest, $this->team);
        static::assertNotNull($active);
        static::assertEquals($vp->getVpid(), $active->getVpid());
    }

    public function testGetActiveVirtualParticipationNone(): void
    {
        $active = $this->service->getActiveVirtualParticipation($this->contest, $this->team);
        static::assertNull($active);
    }

    public function testGetVisibleVirtualParticipations(): void
    {
        // Create a second team.
        $category = $this->em->getRepository(TeamCategory::class)
            ->findOneBy(['sortorder' => 0]);
        $team2 = new Team();
        $team2->setName(self::CONTEST_NAME.' team2')
              ->setCategory($category);
        $this->em->persist($team2);
        $this->em->flush();

        // Start VP for team1 first.
        $vp1 = $this->service->startVirtualParticipation($this->contest, $this->team);
        $vp1->setIsCompleted(true);
        $this->em->flush();

        // Then for team2.
        $vp2 = $this->service->startVirtualParticipation($this->contest, $team2);

        // VP2 should see VP1 (started earlier).
        $visible = $this->service->getVisibleVirtualParticipations($this->contest, $vp2);
        static::assertCount(1, $visible);
        static::assertEquals($vp1->getVpid(), $visible[0]->getVpid());

        // Clean up.
        $this->em->remove($team2);
    }

    public function testStartVirtualNotAllowed(): void
    {
        $this->contest->setAllowVirtual(false);
        $this->em->flush();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->service->startVirtualParticipation($this->contest, $this->team);
    }

    public function testCheckAndCompleteExpiredParticipations(): void
    {
        // Create a VP that is already expired (duration = 0).
        $vp = new VirtualParticipation();
        $vp->setContest($this->contest)
           ->setTeam($this->team)
           ->setVirtualStarttime(1000.0)
           ->setVirtualNumber(1)
           ->setDuration(0.0);
        $this->em->persist($vp);
        $this->em->flush();

        $this->service->checkAndCompleteExpiredParticipations($this->contest);

        // Reload.
        $this->em->refresh($vp);
        static::assertTrue($vp->getIsCompleted());
    }
}
