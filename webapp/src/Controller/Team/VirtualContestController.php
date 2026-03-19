<?php declare(strict_types=1);

namespace App\Controller\Team;

use App\Controller\BaseController;
use App\Entity\Contest;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\VirtualContestService;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEAM')]
#[IsGranted(
    new Expression('user.getTeam() !== null'),
    message: 'You do not have a team associated with your account.'
)]
#[Route(path: '/team')]
class VirtualContestController extends BaseController
{
    public function __construct(
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly VirtualContestService $virtualContestService,
        EntityManagerInterface $em,
        protected readonly EventLogService $eventLogService,
        KernelInterface $kernel,
    ) {
        parent::__construct($em, $eventLogService, $dj, $kernel);
    }

    #[Route(path: '/virtual/{contestId<\d+>}/start', name: 'team_virtual_start', methods: ['GET', 'POST'])]
    public function startAction(Request $request, int $contestId): Response
    {
        $user    = $this->dj->getUser();
        $team    = $user->getTeam();
        $contest = $this->em->getRepository(Contest::class)->find($contestId);

        if ($contest === null) {
            throw new NotFoundHttpException(sprintf('Contest %d not found', $contestId));
        }

        if (!$this->virtualContestService->canStartVirtual($contest, $team)) {
            throw new BadRequestHttpException('Cannot start virtual participation for this contest.');
        }

        if ($request->isMethod('POST')) {
            $vp = $this->virtualContestService->startVirtualParticipation($contest, $team);
            $this->addFlash('info', sprintf(
                'Virtual participation #%d started for contest "%s".',
                $vp->getVirtualNumber(), $contest->getName()
            ));
            return $this->redirectToRoute('team_index');
        }

        return $this->render('team/virtual_start.html.twig', [
            'contest' => $contest,
            'duration' => Utils::relTime($contest->getDurationInSeconds()),
            'numProblems' => count($contest->getProblems()),
        ]);
    }

    #[Route(path: '/virtual/{contestId<\d+>}/status', name: 'team_virtual_status', methods: ['GET'])]
    public function statusAction(int $contestId): JsonResponse
    {
        $user    = $this->dj->getUser();
        $team    = $user->getTeam();
        $contest = $this->em->getRepository(Contest::class)->find($contestId);

        if ($contest === null) {
            throw new NotFoundHttpException(sprintf('Contest %d not found', $contestId));
        }

        $activeVp = $this->virtualContestService->getActiveVirtualParticipation($contest, $team);

        if ($activeVp === null) {
            return new JsonResponse([
                'active' => false,
            ]);
        }

        $now       = Utils::now();
        $remaining = $activeVp->getVirtualEndtime() - $now;
        $elapsed   = $now - (float)$activeVp->getVirtualStarttime();
        $duration  = (float)$activeVp->getDuration();

        return new JsonResponse([
            'active'    => true,
            'remaining' => max(0, $remaining),
            'elapsed'   => min($elapsed, $duration),
            'duration'  => $duration,
            'progress'  => min(100, (int)round($elapsed / $duration * 100)),
        ]);
    }
}
