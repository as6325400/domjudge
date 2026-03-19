<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\ExternalJudgement;
use App\Entity\Judging;
use App\Entity\Problem;
use App\Entity\RankCache;
use App\Entity\ScoreCache;
use App\Entity\Submission;
use App\Entity\Team;
use App\Entity\TeamAffiliation;
use App\Entity\TeamCategory;
use App\Entity\VirtualParticipation;
use App\Utils\FreezeData;
use App\Utils\Scoreboard\Filter;
use App\Utils\Scoreboard\Scoreboard;
use App\Utils\Scoreboard\SingleTeamScoreboard;
use App\Utils\Scoreboard\TeamScore;
use App\Utils\Scoreboard\VirtualTeamWrapper;
use App\Utils\Utils;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr\Join;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ScoreboardService
{
    final public const SHOW_TEAM_ALWAYS = 0;
    final public const SHOW_TEAM_AFTER_LOGIN = 1;
    final public const SHOW_TEAM_AFTER_SUBMIT = 2;

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Get scoreboard data based on the cached data in the scorecache table.
     *
     * @param Contest     $contest     The contest to get the scoreboard for.
     * @param bool        $jury        If true, the scoreboard will always be current.
     *                                 If false, frozen results will not be returned.
     * @param Filter|null $filter      Filter to use for the scoreboard.
     * @param bool        $visibleOnly Iff $jury is true, determines whether
     *                                 to show non-publicly visible teams.
     */
    public function getScoreboard(
        Contest $contest,
        bool $jury = false,
        ?Filter $filter = null,
        bool $visibleOnly = false,
        bool $forceUnfrozen = false,
        ?VirtualParticipation $viewerVp = null,
    ): ?Scoreboard {
        $freezeData = new FreezeData($contest);

        // Don't leak information before start of contest.
        if (!$freezeData->started() && !$jury && !$forceUnfrozen) {
            return null;
        }
        $restricted = ($jury || $freezeData->showFinal(false));

        $participationType = $filter?->participationType ?? Filter::PARTICIPATION_LIVE;
        $includeLive = $participationType !== Filter::PARTICIPATION_VIRTUAL;
        $includeVirtual = $participationType !== Filter::PARTICIPATION_LIVE && $contest->getAllowVirtual();

        // Collect teams and cache data.
        $teams      = [];
        $scoreCache = [];
        $rankCache  = [];

        if ($includeLive) {
            $teams      = $this->getTeamsInOrder($contest, $jury && !$visibleOnly, $filter, $restricted, 0);
            $scoreCache = $this->getScorecache($contest, null, 0);
            $rankCache  = $this->getRankcache($contest, null, 0);
        }

        if ($includeVirtual) {
            $this->addVirtualParticipationEntries(
                $contest, $teams, $scoreCache, $rankCache, $restricted, $jury && !$visibleOnly
            );
        }

        // Ghost dynamic replay: when a virtual participant views the scoreboard,
        // filter all entries so they only see what happened by their current relative time.
        if ($viewerVp !== null) {
            $viewerRelativeTime = $viewerVp->getRelativeTime(Utils::now());
            // Cap at VP duration so completed VPs see the full scoreboard.
            $viewerRelativeTime = min($viewerRelativeTime, (float)$viewerVp->getDuration());
            $this->applyGhostTimeFilter($contest, $scoreCache, $rankCache, $teams, $viewerRelativeTime);
        }

        // Sort after ghost filter so rankings reflect the filtered scores.
        if ($includeVirtual) {
            $this->sortTeamsWithVirtual($teams, $rankCache, $restricted);
        }

        $problems   = $this->getProblems($contest);
        $categories = $this->getCategories($jury && !$visibleOnly);

        return new Scoreboard(
            $contest, $teams, $categories, $problems,
            $scoreCache, $rankCache, $freezeData, $jury || $forceUnfrozen,
            (int)$this->config->get('penalty_time'),
            (bool)$this->config->get('score_in_seconds'),
        );
    }

    /**
     * Add virtual participation entries to the scoreboard data arrays.
     *
     * For each completed virtual participation, creates a VirtualTeamWrapper
     * entry with its own score/rank cache data, using negative IDs to avoid
     * collisions with real team IDs.
     *
     * @param Team[]       $teams      Teams array (indexed by teamid), modified in-place
     * @param ScoreCache[] $scoreCache Score cache array, modified in-place
     * @param RankCache[]  $rankCache  Rank cache array, modified in-place
     */
    protected function addVirtualParticipationEntries(
        Contest $contest,
        array &$teams,
        array &$scoreCache,
        array &$rankCache,
        bool $restricted,
        bool $jury,
    ): void {
        // Fetch all virtual participations (both active and completed) for this contest.
        /** @var VirtualParticipation[] $virtualParticipations */
        $virtualParticipations = $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getResult();

        foreach ($virtualParticipations as $vp) {
            $team = $vp->getTeam();
            if (!$team->getEnabled()) {
                continue;
            }
            if (!$jury && $team->getCategory() && !$team->getCategory()->getVisible()) {
                continue;
            }

            $vpid = $vp->getVpid();
            $wrapper = new VirtualTeamWrapper($team, $vp);
            $virtualTeamId = $wrapper->getTeamid();

            // Add the virtual team wrapper to the teams array.
            $teams[$virtualTeamId] = $wrapper;

            // Fetch score cache for this VP.
            $vpScoreCache = $this->getScorecache($contest, $team, $vpid);
            foreach ($vpScoreCache as $sc) {
                // Remap team reference: we need the score cache entries to be
                // associated with the virtual team ID for the Scoreboard class.
                // We create a clone and override the team to our wrapper.
                $clonedSc = clone $sc;
                $clonedSc->setTeam($wrapper);
                $scoreCache[] = $clonedSc;
            }

            // Fetch rank cache for this VP.
            $vpRankCache = $this->getRankcache($contest, $team, $vpid);
            foreach ($vpRankCache as $rc) {
                $clonedRc = clone $rc;
                $clonedRc->setTeam($wrapper);
                $rankCache[] = $clonedRc;
            }
        }
    }

    /**
     * Sort teams array so virtual entries are interleaved with real teams in
     * correct ranking order (sortorder ASC, sortKey DESC, name ASC).
     *
     * @param Team[]      $teams     Teams array (indexed by teamid), modified in-place
     * @param RankCache[] $rankCache Rank cache array to look up sort keys
     */
    protected function sortTeamsWithVirtual(array &$teams, array $rankCache, bool $restricted): void
    {
        // Build a map from teamid -> sortKey for quick lookup.
        $sortKeyMap = [];
        foreach ($rankCache as $rc) {
            $sortKeyMap[$rc->getTeam()->getTeamid()] = $restricted
                ? $rc->getSortKeyRestricted()
                : $rc->getSortKeyPublic();
        }

        // Default sort key for teams with no RankCache entry (0 solved, 0 time, 0 last correct).
        // This ensures they rank equally with teams whose scores were filtered to zero.
        $defaultSortKey = self::getICPCScoreKey(0, 0, 0);

        uasort($teams, function (Team $a, Team $b) use ($sortKeyMap, $defaultSortKey) {
            // 1. Sort by category sortorder ASC.
            $soA = $a->getCategory() ? $a->getCategory()->getSortorder() : 0;
            $soB = $b->getCategory() ? $b->getCategory()->getSortorder() : 0;
            if ($soA !== $soB) {
                return $soA <=> $soB;
            }
            // 2. Sort by sortKey DESC (higher = better).
            $skA = $sortKeyMap[$a->getTeamid()] ?? $defaultSortKey;
            $skB = $sortKeyMap[$b->getTeamid()] ?? $defaultSortKey;
            if ($skA !== $skB) {
                return $skB <=> $skA; // DESC
            }
            // 3. Sort by name ASC.
            return $a->getEffectiveName() <=> $b->getEffectiveName();
        });
    }

    /**
     * Apply ghost time filtering to ScoreCache and RankCache arrays.
     *
     * For each ScoreCache entry where the problem was solved after the viewer's
     * current relative time, mask it as unsolved. Then rebuild RankCache entries
     * in-memory to match the filtered ScoreCache data.
     *
     * @param ScoreCache[] $scoreCache Modified in-place
     * @param RankCache[]  $rankCache  Replaced in-place with recomputed entries
     * @param Team[]       $teams      All teams on the scoreboard (to ensure every team gets a RankCache)
     * @param float        $viewerRelativeTime Viewer's seconds into contest
     */
    protected function applyGhostTimeFilter(
        Contest $contest,
        array &$scoreCache,
        array &$rankCache,
        array $teams,
        float $viewerRelativeTime,
    ): void {
        $penaltyTime      = (int)$this->config->get('penalty_time');
        $scoreIsInSeconds = (bool)$this->config->get('score_in_seconds');

        /** @var ContestProblem[] $contestProblems */
        $contestProblems = [];
        foreach ($contest->getProblems() as $cp) {
            $contestProblems[$cp->getProbid()] = $cp;
        }

        // Phase 1: Filter ScoreCache entries — mask solves that happen after viewer's time.
        foreach ($scoreCache as $key => $sc) {
            $needsFilter = false;

            // Check restricted audience solve time.
            if ($sc->getIsCorrectRestricted() && (float)$sc->getSolvetimeRestricted() > $viewerRelativeTime) {
                $needsFilter = true;
            }
            // Check public audience solve time.
            if ($sc->getIsCorrectPublic() && (float)$sc->getSolvetimePublic() > $viewerRelativeTime) {
                $needsFilter = true;
            }

            if ($needsFilter) {
                $filtered = clone $sc;
                // Mask restricted if solved after viewer's time.
                // Also clear submission count so cell shows "not attempted" (blank)
                // instead of "attempted but wrong" (red).
                if ($sc->getIsCorrectRestricted() && (float)$sc->getSolvetimeRestricted() > $viewerRelativeTime) {
                    $filtered->setIsCorrectRestricted(false);
                    $filtered->setSolvetimeRestricted(0);
                    $filtered->setRuntimeRestricted(0);
                    $filtered->setSubmissionsRestricted(0);
                    $filtered->setPendingRestricted(0);
                    $filtered->setIsFirstToSolve(false);
                }
                // Mask public if solved after viewer's time.
                if ($sc->getIsCorrectPublic() && (float)$sc->getSolvetimePublic() > $viewerRelativeTime) {
                    $filtered->setIsCorrectPublic(false);
                    $filtered->setSolvetimePublic(0);
                    $filtered->setRuntimePublic(0);
                    $filtered->setSubmissionsPublic(0);
                    $filtered->setPendingPublic(0);
                    $filtered->setIsFirstToSolve(false);
                }
                $scoreCache[$key] = $filtered;
            }
        }

        // Phase 2: Rebuild RankCache from the filtered ScoreCache.
        // Group ScoreCache by teamid.
        $teamScores = [];
        foreach ($scoreCache as $sc) {
            $teamId = $sc->getTeam()->getTeamid();
            $teamScores[$teamId][] = $sc;
        }

        $newRankCache = [];
        // Build a lookup of existing RankCache entries to preserve team references.
        $existingRcByTeam = [];
        foreach ($rankCache as $rc) {
            $existingRcByTeam[$rc->getTeam()->getTeamid()] = $rc;
        }

        // Iterate ALL teams so every team gets a RankCache entry (even those
        // without any scorecache). This ensures consistent sort keys and ranks.
        foreach ($teams as $teamId => $team) {
            $variants = ['public' => false, 'restricted' => true];
            $numPoints = [];
            $totalTime = [];
            $totalRuntime = [];
            $timeOfLastCorrect = [];

            foreach ($variants as $variant => $isRestricted) {
                $numPoints[$variant] = 0;
                $totalTime[$variant] = $team->getPenalty();
                $totalRuntime[$variant] = 0;
                $timeOfLastCorrect[$variant] = 0;
            }

            foreach ($teamScores[$teamId] ?? [] as $sc) {
                $probId = $sc->getProblem()->getProbid();
                foreach ($variants as $variant => $isRestricted) {
                    $isCorrect = $sc->getIsCorrect($isRestricted);
                    if (isset($contestProblems[$probId]) && $isCorrect) {
                        $submissions = $sc->getSubmissions($isRestricted);
                        $penalty = Utils::calcPenaltyTime(true, $submissions, $penaltyTime, $scoreIsInSeconds);
                        $numPoints[$variant] += $contestProblems[$probId]->getPoints();
                        $solveTime = (float)$sc->getSolveTime($isRestricted);
                        $solveTimeForProblem = Utils::scoretime($solveTime, $scoreIsInSeconds);
                        $timeOfLastCorrect[$variant] = max($timeOfLastCorrect[$variant], $solveTimeForProblem);
                        $totalTime[$variant] += $solveTimeForProblem + $penalty;
                        $totalRuntime[$variant] += $sc->getRuntime($isRestricted);
                    }
                }
            }

            if (isset($existingRcByTeam[$teamId])) {
                $rc = clone $existingRcByTeam[$teamId];
            } else {
                $rc = new RankCache();
                $rc->setContest($contest);
                $rc->setTeam($team);
            }
            $rc->setPointsRestricted($numPoints['restricted']);
            $rc->setTotaltimeRestricted($totalTime['restricted']);
            $rc->setTotalruntimeRestricted($totalRuntime['restricted']);
            $rc->setPointsPublic($numPoints['public']);
            $rc->setTotaltimePublic($totalTime['public']);
            $rc->setTotalruntimePublic($totalRuntime['public']);
            $rc->setSortKeyRestricted(self::getICPCScoreKey(
                $numPoints['restricted'], $totalTime['restricted'], $timeOfLastCorrect['restricted']
            ));
            $rc->setSortKeyPublic(self::getICPCScoreKey(
                $numPoints['public'], $totalTime['public'], $timeOfLastCorrect['public']
            ));
            $newRankCache[] = $rc;
        }

        $rankCache = $newRankCache;
    }

    /**
     * Get scoreboard data for a single team based on the cached data in the
     * scorecache table.
     *
     * @param Contest $contest         The contest to get the scoreboard for.
     * @param int     $teamId          The ID of the team to get the scoreboard for.
     * @param bool    $showFtsInFreeze If false, the scoreboard will hide first
     *                                 to solve for submissions after contest freeze.
     */
    public function getTeamScoreboard(
        Contest $contest,
        int $teamId,
        bool $showFtsInFreeze = true,
        ?VirtualParticipation $vp = null,
    ): ?Scoreboard {
        $freezeData = new FreezeData($contest);
        $vpid = $vp !== null ? $vp->getVpid() : 0;

        $teams = $this->getTeamsInOrder($contest, true, new Filter([], [], [], [$teamId]), true, $vpid);
        if (empty($teams)) {
            return null;
        }
        $team       = reset($teams);
        $problems   = $this->getProblems($contest);
        $rankCache  = $this->getRankcache($contest, $team, $vpid);
        $scoreCache = $this->getScorecache($contest, $team, $vpid);
        $teamRank   = $this->calculateTeamRank($contest, $team, $freezeData, true, $vpid);

        return new SingleTeamScoreboard(
            $contest, $team, $teamRank, $problems,
            $rankCache, $scoreCache, $freezeData, $showFtsInFreeze,
            (int)$this->config->get('penalty_time'),
            (bool)$this->config->get('score_in_seconds')
        );
    }

    /**
     * Calculate the rank for a single team based on the cache tables.
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function calculateTeamRank(
        Contest $contest,
        Team $team,
        ?FreezeData $freezeData = null,
        bool $jury = false,
        int $vpid = 0
    ): int {
        if ($freezeData === null) {
            $freezeData = new FreezeData($contest);
        }
        $restricted = ($jury || $freezeData->showFinal(false));
        $variant    = $restricted ? 'Restricted' : 'Public';
        $sortOrder  = $team->getCategory()->getSortorder();

        $sortKey = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->select('r.sortKey'.$variant)
            ->andWhere('r.contest = :contest')
            ->andWhere('r.team = :team')
            ->andWhere('r.vpid = :vpid')
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->setParameter('vpid', $vpid)
            ->getQuery()
            ->getOneOrNullResult();

        if ($sortKey === null) {
            // '.' sorts before any digit, so this team will be ranked last (which may be actually rank 1 if no team
            // solved anything yet).
            $sortKey = ".";
        }

        $better = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->join('r.team', 't')
            ->join('t.category', 'tc')
            ->select('COUNT(t.teamid)')
            ->andWhere('r.sortKey'.$variant.' > :sortKey')
            ->andWhere('r.contest = :contest')
            ->andWhere('r.vpid = :vpid')
            ->andWhere('tc.sortorder = :sortorder')
            ->setParameter('sortKey', $sortKey)
            ->setParameter('contest', $contest)
            ->setParameter('vpid', $vpid)
            ->setParameter('sortorder', $sortOrder)
            ->getQuery()
            ->getSingleScalarResult();

        $rank = $better + 1;
        return $rank;
    }

    /**
     * Scoreboard calculation
     *
     * Given a contest, team and a problem (re)calculate the values for one
     * row in the scoreboard.
     *
     * Due to current transactions usage, this function MUST NOT do anything
     * inside a transaction.
     *
     * @param bool $updateRankCache If set to false, do not update the rankcache.
     * @throws DBALException
     */
    public function calculateScoreRow(
        Contest $contest,
        Team    $team,
        Problem $problem,
        bool    $updateRankCache = true,
        ?VirtualParticipation $vp = null
    ): void {
        $vpid = $vp !== null ? $vp->getVpid() : 0;
        $this->logger->debug(
            "ScoreboardService::calculateScoreRow '%d' '%d' '%d' vpid='%d'",
            [ $contest->getCid(), $team->getTeamid(), $problem->getProbid(), $vpid ]
        );

        if (!$team->getCategory()) {
            $this->logger->warning(
                "Team '%d' has no category, skipping",
                [ $team->getTeamid() ]
            );
            return;
        }

        // First acquire an advisory lock to prevent other calls to this
        // method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d.%d.%d',
                              $contest->getCid(), $team->getTeamid(), $problem->getProbid(), $vpid);
        if ($this->em->getConnection()->fetchOne('SELECT GET_LOCK(:lock, 3)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception(sprintf("ScoreboardService::calculateScoreRow failed to obtain lock '%s'",
                                         $lockString));
        }

        // Determine whether we will use external judgements instead of judgings.
        $useExternalJudgements = $this->dj->shadowMode();

        // Note the clause 's.submittime < c.endtime': this is used to
        // filter out TOO-LATE submissions from pending, but it also means
        // that these will not count as solved. Correct submissions with
        // submittime after contest end should never happen, unless one
        // resets the contest time after successful judging.
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->select('s, c')
            ->leftJoin('s.contest', 'c')
            ->andWhere('s.team = :teamid')
            ->andWhere('s.problem = :probid')
            ->andWhere('s.contest = :cid')
            ->andWhere('s.valid = 1')
            ->setParameter('teamid', $team)
            ->setParameter('probid', $problem)
            ->setParameter('cid', $contest)
            ->orderBy('s.submittime');

        if ($vp !== null) {
            // Virtual participation: filter by vpid and use VP end time.
            $queryBuilder
                ->andWhere('s.virtualParticipation = :vp')
                ->setParameter('vp', $vp);
        } else {
            // Live participation: filter to live submissions only and use contest end time.
            $queryBuilder
                ->andWhere('s.virtualParticipation IS NULL')
                ->andWhere('s.submittime < c.endtime');
        }

        if ($useExternalJudgements) {
            $queryBuilder
                ->addSelect('ej')
                ->leftJoin('s.external_judgements', 'ej', Join::WITH, 'ej.valid = 1');
        } else {
            $queryBuilder
                ->addSelect('j')
                ->leftJoin('s.judgings', 'j', Join::WITH, 'j.valid = 1')
                ->leftJoin('j.runs', 'jr');
        }

        // Check if we need to count compile error as a penalty.
        $compilePenalty = $this->config->get('compile_penalty');

        /** @var Submission[] $submissions */
        $submissions = $queryBuilder->getQuery()->getResult();

        $verificationRequired = $this->config->get('verification_required');

        // Initialize variables.
        $submissionsJury = $pendingJury = $timeJury = 0;
        $submissionsPubl = $pendingPubl = $timePubl = 0;
        $correctJury     = false;
        $correctPubl     = false;
        $runtimeJury     = PHP_INT_MAX;
        $runtimePubl     = PHP_INT_MAX;

        // For virtual participations, use the VP start time as the reference.
        // Virtual participations have no freeze.
        $isVirtual = $vp !== null;
        $contestStartTime = $isVirtual ? (float)$vp->getVirtualStarttime() : $contest->getStarttime();

        foreach ($submissions as $submission) {
            /** @var Judging|ExternalJudgement|null $judging */
            if ($useExternalJudgements) {
                $judging = $submission->getValidExternalJudgement();
            } else {
                $judging = $submission->getValidJudging();
            }

            // three things will happen in the loop in this order:
            // 1. update fastest runtime
            // 2. count submissions until correct submission
            // 3. determine time of first correct submission

            // For virtual participations, there is no freeze, so isAfterFreeze
            // is always false.
            $afterFreeze = !$isVirtual && $submission->isAfterFreeze();

            // STEP 1:
            // runtime improvements should be possible for all correct submissions
            if (!is_null($judging) && $judging->getResult() == Judging::RESULT_CORRECT) {
                $runtime = (int) floor(1000*$judging->getMaxRuntime()); // round to milliseconds
                $runtimeJury = min($runtimeJury, $runtime);
                if (!$afterFreeze) {
                    $runtimePubl = min($runtimePubl, $runtime);
                }
            }

            // If there is a public and correct submission, we can stop counting
            // submissions or looking for a correct one (skip steps 2,3)
            if ($correctPubl) {
                continue;
            }

            // STEP 2:
            // Check if this submission has a publicly visible judging result:
            if ($judging === null || empty($judging->getResult()) ||
                (!$useExternalJudgements && $verificationRequired && !$judging->getVerified())) {
                // For the jury: only consider it pending if we don't have a
                // correct one yet. This is needed because during the freeze
                // we consider submissions after the correct one for the
                // public to not leak any info.
                if (!$correctJury) {
                    $pendingJury++;
                }
                $pendingPubl++;
                // Don't do any more counting for this submission.
                continue;
            }

            // We need to count the submission always, except when we don't want
            // to count compiler penalties and the judging is a compiler error.
            $countSubmission = $compilePenalty || $judging->getResult() != Judging::RESULT_COMPILER_ERROR;

            if (!$correctJury && $countSubmission) {
                // For the jury: only consider it as a submission if we don't
                // have a correct one yet. This is needed because during the
                // freeze we consider submissions after the correct one for
                // the public to not leak any info.
                $submissionsJury++;
            }
            if ($afterFreeze) {
                // Show submissions after freeze as pending to the public (if
                // SHOW_PENDING is enabled). Note that we even show these
                // submissions if they are a compiler-error and
                // compile_penalty is set to false, to not leak any info.
                $pendingPubl++;
            } elseif ($countSubmission) {
                $submissionsPubl++;
            }

            // If we encountered a correct submission during the whole contest,
            // do not consider the submissions after that one for correctness.
            if ($correctJury) {
                continue;
            }

            // STEP 3:
            $absSubmitTime = (float)$submission->getSubmittime();
            // Negative numbers don't make sense on the scoreboard, cap them to the contest start.
            $absSubmitTime = max($absSubmitTime, $contestStartTime);
            if ($isVirtual) {
                // For virtual participation, calculate relative time from VP start.
                $submitTime = $absSubmitTime - $contestStartTime;
            } else {
                $submitTime = $contest->getContestTime($absSubmitTime);
            }

            if ($judging->getResult() == Judging::RESULT_CORRECT) {
                $correctJury = true;
                $timeJury    = $submitTime;
                if (!$afterFreeze) {
                    $correctPubl = true;
                    $timePubl    = $submitTime;
                }
            }
        }

        // See if this submission was the first to solve this problem.
        // Only relevant if it was correct in the first place.
        $firstToSolve = false;
        if ($correctJury) {
            $params = [
                'cid' => $contest->getCid(),
                'probid' => $problem->getProbid(),
                'teamSortOrder' => $team->getCategory()->getSortorder(),
                /** @phpstan-ignore-next-line $absSubmitTime is always set when $correctJury is true */
                'submitTime' => $absSubmitTime,
                'correctResult' => Judging::RESULT_CORRECT,
            ];

            // For virtual participations, first-to-solve is scoped to the same vpid.
            $vpFilter = $isVirtual
                ? 's.vpid = :vpid'
                : 's.vpid IS NULL';
            if ($isVirtual) {
                $params['vpid'] = $vpid;
            }

            // Find out how many valid submissions were submitted earlier
            // that have a valid judging that is correct, or are awaiting judgement.
            // Only if there are 0 found, we are definitely the first to solve this problem.
            $verificationRequiredExtra = $verificationRequired ? 'OR j.verified = 0' : '';
            if ($useExternalJudgements) {
                $firstToSolve = 0 == $this->em->getConnection()->fetchOne('
                SELECT count(*) FROM submission s
                    LEFT JOIN external_judgement ej USING (submitid)
                    LEFT JOIN external_judgement ej2 ON ej2.submitid = s.submitid AND ej2.starttime > ej.starttime
                    LEFT JOIN team t USING(teamid)
                    LEFT JOIN team_category tc USING (categoryid)
                WHERE s.valid = 1 AND '.$vpFilter.' AND
                    (ej.result IS NULL OR ej.result = :correctResult '.
                    $verificationRequiredExtra.') AND
                    s.cid = :cid AND s.probid = :probid AND
                    tc.sortorder = :teamSortOrder AND
                    round(s.submittime,4) < :submitTime', $params);
            } else {
                $firstToSolve = 0 == $this->em->getConnection()->fetchOne('
                SELECT count(*) FROM submission s
                    LEFT JOIN judging j ON (s.submitid=j.submitid AND j.valid=1)
                    LEFT JOIN team t USING (teamid)
                    LEFT JOIN team_category tc USING (categoryid)
                WHERE s.valid = 1 AND '.$vpFilter.' AND
                    (j.judgingid IS NULL OR j.result IS NULL OR j.result = :correctResult '.
                    $verificationRequiredExtra.') AND
                    s.cid = :cid AND s.probid = :probid AND
                    tc.sortorder = :teamSortOrder AND
                    round(s.submittime,4) < :submitTime', $params);
            }
        }

        // Use a direct REPLACE INTO query to drastically speed this up
        $params = [
            'cid' => $contest->getCid(),
            'teamid' => $team->getTeamid(),
            'probid' => $problem->getProbid(),
            'vpid' => $vpid,
            'submissionsRestricted' => $submissionsJury,
            'pendingRestricted' => $pendingJury,
            'solvetimeRestricted' => (int)$timeJury,
            'runtimeRestricted' => $runtimeJury === PHP_INT_MAX ? 0 : $runtimeJury,
            'isCorrectRestricted' => (int)$correctJury,
            'submissionsPublic' => $submissionsPubl,
            'pendingPublic' => $pendingPubl,
            'solvetimePublic' => (int)$timePubl,
            'runtimePublic' => $runtimePubl === PHP_INT_MAX ? 0 : $runtimePubl,
            'isCorrectPublic' => (int)$correctPubl,
            'isFirstToSolve' => (int)$firstToSolve,
        ];
        $this->em->getConnection()->executeQuery('REPLACE INTO scorecache
            (cid, teamid, probid, vpid,
             submissions_restricted, pending_restricted, solvetime_restricted, runtime_restricted, is_correct_restricted,
             submissions_public, pending_public, solvetime_public, runtime_public, is_correct_public, is_first_to_solve)
            VALUES (:cid, :teamid, :probid, :vpid, :submissionsRestricted, :pendingRestricted, :solvetimeRestricted, :runtimeRestricted, :isCorrectRestricted,
            :submissionsPublic, :pendingPublic, :solvetimePublic, :runtimePublic, :isCorrectPublic, :isFirstToSolve)', $params);

        if ($this->em->getConnection()->fetchOne('SELECT RELEASE_LOCK(:lock)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception('ScoreboardService::calculateScoreRow failed to release lock');
        }

        // If we found a new correct result, update the rank cache too.
        if ($updateRankCache && ($correctJury || $correctPubl)) {
            $this->updateRankCache($contest, $team, $vp);
        }
    }

    /**
     * Update tables used for efficiently computing team ranks.
     *
     * Given a contest and team (re)calculate the time and solved problems for a team.
     *
     * Due to current transactions usage, this function MUST NOT do anything
     * inside a transaction.
     */
    public function updateRankCache(Contest $contest, Team $team, ?VirtualParticipation $vp = null): void
    {
        $vpid = $vp !== null ? $vp->getVpid() : 0;
        $this->logger->debug("ScoreboardService::updateRankCache '%d' '%d' vpid='%d'",
                             [ $contest->getCid(), $team->getTeamid(), $vpid ]);

        // First acquire an advisory lock to prevent other calls to this
        // method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d.%d', $contest->getCid(), $team->getTeamid(), $vpid);
        if ($this->em->getConnection()->fetchOne('SELECT GET_LOCK(:lock, 3)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception(sprintf("ScoreboardService::updateRankCache failed to obtain lock '%s'", $lockString));
        }

        // Fetch contest problems. We can not add it as a relation on
        // ScoreCache as Doctrine doesn't seem to like that its keys are part
        // of the primary key.
        /** @var ContestProblem[] $contestProblems */
        $contestProblems = $this->em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->select('cp')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getResult();
        $contestProblemsIndexed = [];
        foreach ($contestProblems as $cp) {
            $contestProblemsIndexed[$cp->getProblem()->getProbid()] = $cp;
        }
        $contestProblems = $contestProblemsIndexed;

        // Initialize our data
        $variants  = ['public' => false, 'restricted' => true];
        $numPoints = [];
        $totalTime = [];
        $totalRuntime = [];
        $timeOfLastCorrect = [];
        foreach ($variants as $variant => $isRestricted) {
            $numPoints[$variant] = 0;
            $totalTime[$variant] = $team->getPenalty();
            $totalRuntime[$variant] = 0;
            $timeOfLastCorrect[$variant] = 0;
        }

        $penaltyTime      = (int) $this->config->get('penalty_time');
        $scoreIsInSeconds = (bool)$this->config->get('score_in_seconds');

        // Now fetch the ScoreCache entries for this specific vpid.
        /** @var ScoreCache[] $scoreCacheCells */
        $scoreCacheCells = $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM scorecache WHERE cid = :cid AND teamid = :teamid AND vpid = :vpid',
            ['cid' => $contest->getCid(), 'teamid' => $team->getTeamid(), 'vpid' => $vpid]
        );

        // Process all score cache cells.
        foreach ($scoreCacheCells as $row) {
            foreach ($variants as $variant => $isRestricted) {
                $probId = $row['probid'];
                $isCorrect = $isRestricted ? $row['is_correct_restricted'] : $row['is_correct_public'];
                if (isset($contestProblems[$probId]) && $isCorrect) {
                    $submissions = $isRestricted ? $row['submissions_restricted'] : $row['submissions_public'];
                    $penalty = Utils::calcPenaltyTime(
                        (bool)$isCorrect, (int)$submissions, $penaltyTime, $scoreIsInSeconds
                    );

                    $numPoints[$variant] += $contestProblems[$probId]->getPoints();
                    $solveTime = $isRestricted ? $row['solvetime_restricted'] : $row['solvetime_public'];
                    $solveTimeForProblem = Utils::scoretime((float)$solveTime, $scoreIsInSeconds);
                    $timeOfLastCorrect[$variant] = max($timeOfLastCorrect[$variant], $solveTimeForProblem);
                    $totalTime[$variant] += $solveTimeForProblem + $penalty;
                    $runtime = $isRestricted ? $row['runtime_restricted'] : $row['runtime_public'];
                    $totalRuntime[$variant] += (int)$runtime;
                }
            }
        }

        foreach ($variants as $variant => $isRestricted) {
            $scoreKey[$variant] = self::getICPCScoreKey(
                $numPoints[$variant],
                $totalTime[$variant],
                $timeOfLastCorrect[$variant]
            );
        }

        // Use a direct REPLACE INTO query to drastically speed this up.
        $params = [
            'cid' => $contest->getCid(),
            'teamid' => $team->getTeamid(),
            'vpid' => $vpid,
            'pointsRestricted' => $numPoints['restricted'],
            'totalTimeRestricted' => $totalTime['restricted'],
            'totalRuntimeRestricted' => $totalRuntime['restricted'],
            'pointsPublic' => $numPoints['public'],
            'totalTimePublic' => $totalTime['public'],
            'totalRuntimePublic' => $totalRuntime['public'],
            'sortKeyRestricted' => $scoreKey['restricted'],
            'sortKeyPublic' => $scoreKey['public'],
        ];
        $this->em->getConnection()->executeQuery('REPLACE INTO rankcache (cid, teamid, vpid,
            points_restricted, totaltime_restricted, totalruntime_restricted,
            points_public, totaltime_public, totalruntime_public, sort_key_restricted, sort_key_public)
            VALUES (:cid, :teamid, :vpid, :pointsRestricted, :totalTimeRestricted, :totalRuntimeRestricted,
            :pointsPublic, :totalTimePublic, :totalRuntimePublic, :sortKeyRestricted, :sortKeyPublic)', $params);

        if ($this->em->getConnection()->fetchOne('SELECT RELEASE_LOCK(:lock)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception('ScoreboardService::updateRankCache failed to release lock');
        }
    }

    public const SCALE = 9;

    // Converts integer or bcmath floats to a string that can be used as a key in a score cache.
    // The resulting key will be a string with 33 characters, 23 before the decimal dot and 9 after.
    // The keys are left-padded with zeros to ensure they have the same length and are lexicographically sortable.
    // If the sort order is descending, the keys are inverted by subtracting them from a large number.
    // Assumes that the input is a non-negative number, smaller than 10^23.
    //
    // Example:
    // input: 42
    // output: "00000000000000000000042.000000000"
    public static function convertToScoreKeyElement(int|string $value, Order $order = Order::Descending): string
    {
        // Ensure we have a fixed precision number with 9 decimals.
        $value = bcadd("$value", "0", scale: self::SCALE);

        $ALMOST_INFINITE = "99999999999999999999999";
        if (bccomp($value, $ALMOST_INFINITE, scale: self::SCALE) > 0) {
            throw new Exception("Value $value is too large to convert to a score key element.");
        }
        if (str_starts_with($value, '-')) {
            throw new Exception("No negative values allowed in score key element, got $value.");
        }

        // If ascending, we need to subtract it from a large high value.
        if ($order === Order::Ascending) {
            $value = bcsub($ALMOST_INFINITE, $value, scale: self::SCALE);
        }

        // Left pad it so it has always the same number of characters.
        return str_pad($value, 33, "0", STR_PAD_LEFT);
    }

    public static function getICPCScoreKey(int $numSolved, int $totalTime, int $timeOfLastSolved): string
    {
        $scoreKeyArray = [
            self::convertToScoreKeyElement($numSolved),
            self::convertToScoreKeyElement($totalTime, Order::Ascending),
            self::convertToScoreKeyElement($timeOfLastSolved, Order::Ascending),
        ];
        return implode(',', $scoreKeyArray);
    }

    /**
     * Recalculate the scoreCache and rankCache of a contest.
     *
     * $progressReporter (optional) should be a callable that takes a string.
     */
    public function refreshCache(Contest $contest, ?callable $progressReporter = null): void
    {
        Utils::extendMaxExecutionTime(300);

        $this->dj->auditlog('contest', $contest->getCid(), 'refresh scoreboard cache');

        if ($progressReporter === null) {
            $progressReporter = static function (int $progress, string $log, ?string $message = null) {
                // no-op
            };
        }

        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Team::class, 't')
            ->select('t')
            ->orderBy('t.teamid');
        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->join('t.category', 'cat')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c.cid = :cid OR cc.cid = :cid')
                ->setParameter('cid', $contest->getCid());
        }
        /** @var Team[] $teams */
        $teams = $queryBuilder->getQuery()->getResult();
        /** @var Problem[] $problems */
        $problems = $this->em->createQueryBuilder()
            ->from(Problem::class, 'p')
            ->join('p.contest_problems', 'cp')
            ->select('p')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('cp.shortname')
            ->getQuery()
            ->getResult();

        if (count($teams) == 0) {
            $progressReporter(100, '', 'No teams defined, doing nothing.');
            return;
        }
        if (count($problems) == 0) {
            $progressReporter(100, '', 'No problems defined, doing nothing.');
            return;
        }

        $first = true;
        $log = '';

        // Fetch all virtual participations for this contest.
        /** @var VirtualParticipation[] $virtualParticipations */
        $virtualParticipations = $this->em->createQueryBuilder()
            ->from(VirtualParticipation::class, 'vp')
            ->select('vp')
            ->andWhere('vp.contest = :contest')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getResult();

        // Index VPs by teamid for quick lookup.
        $vpsByTeam = [];
        foreach ($virtualParticipations as $vp) {
            $vpsByTeam[$vp->getTeam()->getTeamid()][] = $vp;
        }

        // for each team, fetch the status of each problem.
        foreach ($teams as $index => $team) {
            if (!$first) {
                $log .= ', ';
            }
            $first = false;
            $log .= sprintf('t%d', $team->getTeamid());
            $progress = (int)round($index / count($teams) * 100);
            $progressReporter($progress, $log);

            // for each problem fetch the result (live participation)
            foreach ($problems as $problem) {
                $this->calculateScoreRow($contest, $team, $problem, false);
            }

            $this->updateRankCache($contest, $team);

            // Also refresh virtual participations for this team.
            if (isset($vpsByTeam[$team->getTeamid()])) {
                foreach ($vpsByTeam[$team->getTeamid()] as $vp) {
                    foreach ($problems as $problem) {
                        $this->calculateScoreRow($contest, $team, $problem, false, $vp);
                    }
                    $this->updateRankCache($contest, $team, $vp);
                }
            }
        }

        // Drop all teams and problems that do not exist in the contest.
        $problemIds = array_map(fn(Problem $problem) => $problem->getProbid(), $problems);
        $teamIds = array_map(fn(Team $team) => $team->getTeamid(), $teams);

        $params = [
            'cid' => $contest->getCid(),
            'problemIds' => $problemIds,
        ];
        $types  = [
            'problemIds' => ArrayParameterType::INTEGER,
            'teamIds' => ArrayParameterType::INTEGER,
        ];
        $this->em->getConnection()->executeQuery(
            'DELETE FROM scorecache WHERE cid = :cid AND probid NOT IN (:problemIds)',
            $params, $types);

        $params = [
            'cid' => $contest->getCid(),
            'teamIds' => $teamIds,
        ];
        $this->em->getConnection()->executeQuery(
            'DELETE FROM scorecache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
            $params, $types);
        $this->em->getConnection()->executeQuery(
            'DELETE FROM rankcache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
            $params, $types);

        $progressReporter(100, '');
    }

    /**
     * Initialize the scoreboard filter for the given request.
     */
    public function initializeScoreboardFilter(Request $request, ?Response $response): Filter
    {
        $scoreFilter = [];
        if ($this->dj->getCookie('domjudge_scorefilter')) {
            $scoreFilter = Utils::jsonDecode((string)$this->dj->getCookie('domjudge_scorefilter'));
        }

        if ($request->query->has('clear')) {
            $scoreFilter = [];
        }

        if ($request->query->has('filter')) {
            $scoreFilter = [];
            foreach (['affiliations', 'countries', 'categories'] as $type) {
                if ($request->query->has($type)) {
                    $scoreFilter[$type] = $request->query->all($type);
                }
            }
        }

        // Handle participation type filter separately (not stored in cookie).
        $participationType = $request->query->get('participation', Filter::PARTICIPATION_LIVE);
        if (!in_array($participationType, [Filter::PARTICIPATION_LIVE, Filter::PARTICIPATION_VIRTUAL, Filter::PARTICIPATION_ALL], true)) {
            $participationType = Filter::PARTICIPATION_LIVE;
        }

        $this->dj->setCookie(
            'domjudge_scorefilter',
            Utils::jsonEncode($scoreFilter),
            0, null, '', false, false, $response
        );

        return new Filter(
            $scoreFilter['affiliations'] ?? [],
            $scoreFilter['countries'] ?? [],
            $scoreFilter['categories'] ?? [],
            $scoreFilter['teams'] ?? [],
            $participationType,
        );
    }

    /**
     * Get a list of affiliation names grouped on category name.
     *
     * @return array<array<string, array<array{id: string, name: string}>>>
     */
    public function getGroupedAffiliations(Contest $contest): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'cat')
            ->select('cat', 't', 'affil')
            ->leftJoin('cat.teams', 't')
            ->leftJoin('t.affiliation', 'affil')
            ->andWhere('cat.visible = 1')
            ->orderBy('cat.name')
            ->addOrderBy('affil.name')
            ->addOrderBy('t.name');

        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c = :contest OR cc = :contest')
                ->setParameter('contest', $contest);
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();

        $groupedAffiliations = [];
        foreach ($categories as $category) {
            $affiliations = [];
            /** @var Team $team */
            foreach ($category->getTeams() as $team) {
                if ($teamaffil = $team->getAffiliation()) {
                    $affiliations[$teamaffil->getName()] = [
                        'id'   => $teamaffil->getExternalid(),
                        'name' => $teamaffil->getName(),
                        'country' => $teamaffil->getCountry(),
                        'color' => $category->getColor(),
                    ];
                }
            }

            if (empty($affiliations)) {
                /** @var Team $team */
                foreach ($category->getTeams() as $team) {
                    $affiliations[$team->getEffectiveName()] = [
                        'id' => -1,
                        'name' => $team->getEffectiveName()];
                }
            }
            if (!empty($affiliations)) {
                $groupedAffiliations[$category->getName()] = array_values($affiliations);
            }
        }

        return array_chunk($groupedAffiliations, 3, true);
    }

    /**
     * Get values to display in the scoreboard filter.
     *
     * @return array{affiliations: string[], countries: string[], categories: string[]}
     */
    public function getFilterValues(Contest $contest, bool $jury): array
    {
        $filters = [
            'affiliations' => [],
            'countries'    => [],
            'categories'   => [],
        ];
        $showFlags        = $this->config->get('show_flags');
        $showAffiliations = $this->config->get('show_affiliations');

        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'c')
            ->select('c');
        if (!$jury) {
            $queryBuilder->andWhere('c.visible = 1');
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();
        foreach ($categories as $category) {
            $filters['categories'][$category->getCategoryid()] = $category->getName();
        }

        // Show only affiliations / countries with visible teams.
        if (empty($categories) || !$showAffiliations) {
            $filters['affiliations'] = [];
        } else {
            $queryBuilder = $this->em->createQueryBuilder()
                ->from(TeamAffiliation::class, 'a')
                ->select('a')
                ->join('a.teams', 't')
                ->andWhere('t.category IN (:categories)')
                ->setParameter('categories', $categories);
            if (!$contest->isOpenToAllTeams()) {
                $queryBuilder
                    ->leftJoin('t.contests', 'c')
                    ->join('t.category', 'cat')
                    ->leftJoin('cat.contests', 'cc')
                    ->andWhere('c = :contest OR cc = :contest')
                    ->setParameter('contest', $contest);
            }

            /** @var TeamAffiliation[] $affiliations */
            $affiliations = $queryBuilder->getQuery()->getResult();
            foreach ($affiliations as $affiliation) {
                $filters['affiliations'][$affiliation->getAffilid()] = $affiliation->getName();
                if ($showFlags && $affiliation->getCountry() !== null) {
                    $filters['countries'][] = $affiliation->getCountry();
                }
            }
        }

        $filters['countries'] = array_unique($filters['countries']);
        sort($filters['countries']);
        asort($filters['affiliations'], SORT_FLAG_CASE);

        return $filters;
    }

    /**
     * Get the scoreboard Twig data for a given contest.
     *
     * @return array{refresh?: array{after: int, url: string, ajax: bool}, static: bool, contest?: Contest,
     *               scoreFilter?: Filter, scoreboard: Scoreboard, filterValues: array<string, string[]>,
     *               groupedAffiliations: null|array<array<string, array<array{id: string, name: string}>>>,
     *               showFlags: int, showAffiliationLogos: bool, showAffiliations: int, showPending: int,
     *               showTeamSubmissions: int, scoreInSeconds: bool, maxWidth: int, jury?: bool,
     *               public?: bool, ajax?: bool}
     */
    public function getScoreboardTwigData(
        ?Request $request,
        ?Response $response,
        string $refreshUrl,
        bool $jury,
        bool $public,
        bool $static,
        ?Contest $contest = null,
        ?Scoreboard $scoreboard = null,
        bool $forceUnfrozen = false,
        ?VirtualParticipation $viewerVp = null,
    ): array {
        $data = [
            'refresh' => [
                'after' => 30,
                'url' => $refreshUrl,
                'ajax' => true,
             ],
             'static' => $static,
        ];
        if ($static && $contest && ($forceUnfrozen || $contest->getFreezeData()->showFinal())) {
            unset($data['refresh']);
            $data['refreshstop'] = true;
        }

        if ($contest) {
            if ($request && $response) {
                $scoreFilter = $this->initializeScoreboardFilter($request, $response);
            } else {
                $scoreFilter = null;
            }
            if ($scoreboard === null) {
                $scoreboard = $this->getScoreboard(
                    contest: $contest,
                    jury: $jury,
                    filter: $scoreFilter,
                    forceUnfrozen: $forceUnfrozen,
                    viewerVp: $viewerVp,
                );
            }

            if ($forceUnfrozen) {
                $scoreboard->getFreezeData()
                    ->setForceValue(FreezeData::KEY_SHOW_FROZEN, false)
                    ->setForceValue(FreezeData::KEY_SHOW_FINAL, true)
                    ->setForceValue(FreezeData::KEY_SHOW_FINAL_JURY, true)
                    ->setForceValue(FreezeData::KEY_FINALIZED, true);

                if (!$contest->getFinalizetime()) {
                    $contest->setFinalizetime(Utils::now());
                }
            }

            $data['contest']              = $contest;
            $data['scoreFilter']          = $scoreFilter;
            $data['scoreboard']           = $scoreboard;
            $data['filterValues']         = $this->getFilterValues($contest, $jury);
            $data['groupedAffiliations']  = empty($scoreboard) ? $this->getGroupedAffiliations($contest) : null;
            $data['showFlags']            = $this->config->get('show_flags');
            $data['showAffiliationLogos'] = $this->config->get('show_affiliation_logos');
            $data['showAffiliations']     = $this->config->get('show_affiliations');
            $data['showPending']          = $this->config->get('show_pending');
            $data['showTeamSubmissions']  = $this->config->get('show_teams_submissions');
            $data['scoreInSeconds']       = $this->config->get('score_in_seconds');
            $data['maxWidth']             = $this->config->get('team_column_width');
            $data['allowVirtual']         = $contest->getAllowVirtual();
            $data['participationType']    = $scoreFilter?->participationType ?? Filter::PARTICIPATION_LIVE;
        }

        if ($request && $request->isXmlHttpRequest()) {
            $data['jury']   = $jury;
            $data['public'] = $public;
            $data['ajax']   = true;
        }

        return $data;
    }

    /**
     * Get the teams to display on the scoreboard, returns them in order.
     * @return Team[]
     */
    protected function getTeamsInOrder(Contest $contest, bool $jury = false, ?Filter $filter = null, bool $restricted = false, int $vpid = 0): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Team::class, 't', 't.teamid')
            ->innerJoin('t.category', 'tc')
            ->leftJoin(RankCache::class, 'r', Join::WITH, 'r.team = t AND r.contest = :rcid AND r.vpid = :rvpid')
            ->leftJoin('t.affiliation', 'ta')
            ->select('t, tc, ta', 'COALESCE(t.display_name, t.name) AS HIDDEN effectivename')
            ->andWhere('t.enabled = 1')
            ->setParameter('rcid', $contest->getCid())
            ->setParameter('rvpid', $vpid);

        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->join('t.category', 'cat')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c.cid = :cid OR cc.cid = :cid')
                ->setParameter('cid', $contest->getCid());
        }

        $show_filter = $this->config->get('show_teams_on_scoreboard');
        if (!$jury) {
            $queryBuilder->andWhere('tc.visible = 1');
            if ($show_filter === self::SHOW_TEAM_AFTER_LOGIN) {
                $queryBuilder
                    ->join('t.users', 'u', Join::WITH, 'u.last_login IS NOT NULL OR u.last_api_login IS NOT NULL');
            } elseif ($show_filter === self::SHOW_TEAM_AFTER_SUBMIT) {
                $vpCondition = $vpid === 0 ? ' AND s.virtualParticipation IS NULL' : '';
                $queryBuilder
                    ->join('t.submissions', 's', Join::WITH, 's.contest = :cid' . $vpCondition)
                    ->setParameter('cid', $contest->getCid());
            }
        }

        if ($filter) {
            if ($filter->affiliations) {
                $queryBuilder
                    ->andWhere('t.affiliation IN (:affiliations)')
                    ->setParameter('affiliations', $filter->affiliations);
            }

            if ($filter->categories) {
                $queryBuilder
                    ->andWhere('t.category IN (:categories)')
                    ->setParameter('categories', $filter->categories);
            }

            if ($filter->countries) {
                $queryBuilder
                    ->andWhere('ta.country IN (:countries)')
                    ->setParameter('countries', $filter->countries);
            }

            if ($filter->teams) {
                $queryBuilder
                    ->andWhere('t.teamid IN (:teams)')
                    ->setParameter('teams', $filter->teams);
            }
        }

        $ret = $queryBuilder
            ->addOrderBy('tc.sortorder')
            ->addOrderBy('r.sortKey' . ($restricted ? 'Restricted' : 'Public'), 'DESC')
            ->addOrderBy('effectivename')
            ->getQuery()->getResult();
        return $ret;
    }

    /**
     * Get the problems to display on the scoreboard.
     *
     * Note that this will return only a partial object for optimization purposes.
     *
     * @return ContestProblem[]
     */
    protected function getProblems(Contest $contest): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->select('cp, p')
            ->innerJoin('cp.problem', 'p')
            ->andWhere('cp.allowSubmit = 1')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('cp.shortname');

        /** @var ContestProblem[] $contestProblems */
        $contestProblems = $queryBuilder->getQuery()->getResult();
        $contestProblemsIndexed = [];
        foreach ($contestProblems as $cp) {
            /** @var Problem|int $p */
            $p = $cp->getProblem();
            // Doctrine has a bug with eagerly loaded second level hydration
            // when the object is already loaded. In that case it might happen
            // that the problem of a contest problem is its ID instead of the
            // whole object. If this happes, load the whole problem. This
            // should not do any additional database queries, since the problem
            // has already been loaded.
            // See https://github.com/doctrine/orm/pull/7145 for the Doctrine issue.
            if (is_numeric($p)) {
                $p = $this->em->getRepository(Problem::class)->find($p);
                $cp->setProblem($p);
            }
            $contestProblemsIndexed[$p->getProbid()] = $cp;
        }
        return $contestProblemsIndexed;
    }

    /**
     * Get the categories to display on the scoreboard.
     * @return TeamCategory[]
     */
    protected function getCategories(bool $jury): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'cat', 'cat.categoryid')
            ->select('cat')
            ->orderBy('cat.sortorder')
            ->addOrderBy('cat.name')
            ->addOrderBy('cat.categoryid');

        if (!$jury) {
            $queryBuilder->andWhere('cat.visible = 1');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the scorecache used to calculate the scoreboard.
     * @return ScoreCache[]
     */
    protected function getScorecache(Contest $contest, ?Team $team = null, int $vpid = 0): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ScoreCache::class, 's')
            ->select('s')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.vpid = :vpid')
            ->setParameter('contest', $contest)
            ->setParameter('vpid', $vpid);

        if ($team) {
            $queryBuilder
                ->andWhere('s.team = :team')
                ->setParameter('team', $team);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the rank cache for the given team.
     * @throws NonUniqueResultException
     * @return RankCache[]
     */
    protected function getRankcache(Contest $contest, ?Team $team = null, int $vpid = 0): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->select('r')
            ->andWhere('r.contest = :contest')
            ->andWhere('r.vpid = :vpid')
            ->setParameter('contest', $contest)
            ->setParameter('vpid', $vpid);

        if ($team !== null) {
            $queryBuilder
                ->andWhere('r.team = :team')
                ->setParameter('team', $team);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
