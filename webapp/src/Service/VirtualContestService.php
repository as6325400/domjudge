<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Contest;
use App\Entity\Team;
use App\Entity\VirtualParticipation;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class VirtualContestService
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Start a new virtual participation for the given team in the given contest.
     */
    public function startVirtualParticipation(Contest $contest, Team $team): VirtualParticipation
    {
        if (!$contest->getAllowVirtual()) {
            throw new BadRequestHttpException(
                sprintf("Contest c%d does not allow virtual participation.", $contest->getCid()));
        }

        if ($this->getActiveVirtualParticipation($contest, $team) !== null) {
            throw new BadRequestHttpException(
                sprintf("Team t%d already has an active virtual participation in contest c%d.",
                    $team->getTeamid(), $contest->getCid()));
        }

        // Calculate virtual number (max existing + 1).
        $maxNumber = $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('MAX(vp.virtual_number)')
            ->andWhere('vp.contest = :contest')
            ->andWhere('vp.team = :team')
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getSingleScalarResult();

        $virtualNumber = ($maxNumber ?? 0) + 1;

        $vp = new VirtualParticipation();
        $vp->setContest($contest)
           ->setTeam($team)
           ->setVirtualStarttime(Utils::now())
           ->setVirtualNumber($virtualNumber)
           ->setDuration($contest->getDurationInSeconds());

        $this->em->persist($vp);
        $this->em->flush();

        $this->logger->info(
            "VirtualContestService::startVirtualParticipation team '%d' contest '%d' virtual #%d",
            [$team->getTeamid(), $contest->getCid(), $virtualNumber]
        );

        return $vp;
    }

    /**
     * Get the active (not completed) virtual participation for a team in a contest, if any.
     */
    public function getActiveVirtualParticipation(Contest $contest, Team $team): ?VirtualParticipation
    {
        $vp = $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.contest = :contest')
            ->andWhere('vp.team = :team')
            ->andWhere('vp.is_completed = false')
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        // Also check if the VP has expired by wall clock time.
        if ($vp !== null && !$vp->isActive()) {
            $vp->setIsCompleted(true);
            $this->em->flush();
            return null;
        }

        return $vp;
    }

    /**
     * Check if a team can start a virtual participation in a contest.
     */
    public function canStartVirtual(Contest $contest, Team $team): bool
    {
        if (!$contest->getAllowVirtual()) {
            return false;
        }

        // Must not have an active VP in this contest.
        if ($this->getActiveVirtualParticipation($contest, $team) !== null) {
            return false;
        }

        // Team must be allowed to see this contest.
        if (!$team->inContest($contest)) {
            return false;
        }

        return true;
    }

    /**
     * Get all virtual participations that are visible to a given viewer VP.
     * This includes all VPs with a virtual_starttime earlier than the viewer's.
     *
     * @return VirtualParticipation[]
     */
    public function getVisibleVirtualParticipations(
        Contest $contest,
        VirtualParticipation $viewerVp
    ): array {
        return $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.contest = :contest')
            ->andWhere('vp.virtual_starttime < :viewerStarttime')
            ->setParameter('contest', $contest)
            ->setParameter('viewerStarttime', $viewerVp->getVirtualStarttime())
            ->orderBy('vp.virtual_starttime')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all completed virtual participations for a contest.
     *
     * @return VirtualParticipation[]
     */
    public function getCompletedVirtualParticipations(Contest $contest): array
    {
        // First, mark any expired VPs as completed.
        $this->checkAndCompleteExpiredParticipations($contest);

        return $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.contest = :contest')
            ->andWhere('vp.is_completed = true')
            ->setParameter('contest', $contest)
            ->orderBy('vp.virtual_starttime')
            ->getQuery()
            ->getResult();
    }

    /**
     * Mark expired virtual participations as completed.
     */
    public function checkAndCompleteExpiredParticipations(?Contest $contest = null): void
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.is_completed = false');

        if ($contest !== null) {
            $queryBuilder
                ->andWhere('vp.contest = :contest')
                ->setParameter('contest', $contest);
        }

        /** @var VirtualParticipation[] $activeVps */
        $activeVps = $queryBuilder->getQuery()->getResult();

        foreach ($activeVps as $vp) {
            if (!$vp->isActive()) {
                $vp->setIsCompleted(true);
            }
        }

        $this->em->flush();
    }
}
