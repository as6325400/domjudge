<?php declare(strict_types=1);

namespace App\Controller\Team;

use App\Controller\BaseController;
use App\Entity\Judging;
use App\Entity\Language;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\Testcase;
use App\Form\Type\SubmitProblemType;
use App\Form\Type\SubmitProblemPasteType;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\SubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[IsGranted('ROLE_TEAM')]
#[IsGranted(
    new Expression('user.getTeam() !== null'),
    message: 'You do not have a team associated with your account.'
)]
#[Route(path: '/team')]
class SubmissionController extends BaseController
{
    final public const NEVER_SHOW_COMPILE_OUTPUT = 0;
    final public const ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR = 1;
    final public const ALWAYS_SHOW_COMPILE_OUTPUT = 2;

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly SubmissionService $submissionService,
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly FormFactoryInterface $formFactory
    ) {
    }

    #[Route(path: '/submit/{problem}', name: 'team_submit')]
    public function createAction(Request $request, ?Problem $problem = null): Response
    {
        $user = $this->dj->getUser();
        $team = $user->getTeam();
        $contest = $this->dj->getCurrentContest($user->getTeam()->getTeamid());
        $data = [];
        if ($problem !== null) {
            $data['problem'] = $problem;
        }
        $formUpload = $this->formFactory
            ->createBuilder(SubmitProblemType::class, $data)
            ->setAction($this->generateUrl('team_submit'))
            ->getForm();

        $formPaste = $this->formFactory
            ->createBuilder(SubmitProblemPasteType::class, $data)
            ->setAction($this->generateUrl('team_submit'))
            ->getForm();

        $formUpload->handleRequest($request);
        $formPaste->handleRequest($request);
        if ($formUpload->isSubmitted() && $formUpload->isValid()) {
            if ($contest === null) {
                $this->addFlash('danger', 'No active contest');
            } elseif (!$this->dj->checkrole('jury') && !$contest->getFreezeData()->started()) {
                $this->addFlash('danger', 'Contest has not yet started');
            } else {
                /** @var Problem $problem */
                $problem = $formUpload->get('problem')->getData();
                /** @var Language $language */
                $language = $formUpload->get('language')->getData();
                /** @var UploadedFile[] $files */
                $files      = $formUpload->get('code')->getData();
                if (!is_array($files)) {
                    $files = [$files];
                }
                $entryPoint = $formUpload->get('entry_point')->getData() ?: null;
                $submission = $this->submissionService->submitSolution(
                    $team,
                    $this->dj->getUser(),
                    $problem->getProbid(),
                    $contest,
                    $language,
                    $files,
                    'team page',
                    null,
                    null,
                    $entryPoint,
                    null,
                    null,
                    $message
                );

                if ($submission) {
                    $this->addFlash(
                        'success',
                        'Submission done! Watch for the verdict in the list below.'
                    );
                } else {
                    $this->addFlash('danger', $message);
                }
                return $this->redirectToRoute('team_index');
            }
        } elseif ($formPaste->isSubmitted() && $formPaste->isValid()) {
            if ($contest === null) {
                $this->addFlash('danger', 'No active contest');
            } elseif (!$this->dj->checkrole('jury') && !$contest->getFreezeData()->started()) {
                $this->addFlash('danger', 'Contest has not yet started');
            } else {
                $problem = $formPaste->get('problem')->getData();
                $language = $formPaste->get('language')->getData();
                $codeContent = $formPaste->get('code_content')->getData();
                if($codeContent == null || empty(trim($codeContent))) {
                    $this->addFlash('danger','No code content provided.');
                    return $this->redirectToRoute('team_index');
                }
                $tempDir = sys_get_temp_dir();
                $tempFileName = sprintf(
                    'submission_%s_%s_%s.%s',
                    $user->getUsername(),
                    $problem->getName(),
                    date('Y-m-d_H-i-s'),
                    $language->getExtensions()[0]
                );
                $tempFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $tempFileName);
                $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . $tempFileName;
                file_put_contents($tempFilePath, $codeContent);

                $uploadedFile = new UploadedFile(
                    $tempFilePath,
                    $tempFileName,
                    'application/octet-stream',
                    null,
                    true
                );

                $files = [$uploadedFile];
                $entryPoint = $tempFileName;
                $submission = $this->submissionService->submitSolution(
                    $team,
                    $this->dj->getUser(),
                    $problem,
                    $contest,
                    $language,
                    $files,
                    'team page',
                    null,
                    null,
                    $entryPoint,
                    null,
                    null,
                    $message
                );
                if ($submission) {
                    $this->addFlash(
                        'success',
                        'Submission done! Watch for the verdict in the list below.'
                    );
                } else {
                    $this->addFlash('danger', $message);
                }

                return $this->redirectToRoute('team_index');
            }
        }

        $data = [
            'formupload' => $formUpload->createView(),
            'formpaste' => $formPaste->createView(),
            'problem' => $problem,
            'defaultSubmissionCodeMode' => (bool) $this->config->get('default_submission_code_mode'),
        ];
        $data['validFilenameRegex'] = SubmissionService::FILENAME_REGEX;

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submit_modal.html.twig', $data);
        } else {
            return $this->render('team/submit.html.twig', $data);
        }
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/submission/{submitId<\d+>}', name: 'team_submission')]
    public function viewAction(Request $request, int $submitId): Response
    {
        $verificationRequired = (bool) $this->config->get('verification_required');
        $showCompile = $this->config->get('show_compile');
        $showSampleOutput = $this->config->get('show_sample_output');
        $allowDownload = (bool) $this->config->get('allow_team_submission_download');
        $showTooLateResult = $this->config->get('show_too_late_result');
        $user = $this->dj->getUser();
        $team = $user->getTeam();
        $contest = $this->dj->getCurrentContest($team->getTeamid());
        $showTestResults = (bool) $this->config->get('show_test_results');
        $showFisrtWrongTest = (bool) $this->config->get('show_first_wrong_testcase');
        /** @var Judging|null $judging */
        $judging = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->join('j.submission', 's')
            ->join('s.contest_problem', 'cp')
            ->join('cp.problem', 'p')
            ->join('s.language', 'l')
            ->select('j', 's', 'cp', 'p', 'l')
            ->andWhere('j.submission = :submitId')
            ->andWhere('j.valid = 1')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        // Update seen status when viewing submission.
        if (
            $judging && $judging->getSubmission()->getSubmittime() < $contest->getEndtime() &&
            (!$verificationRequired || $judging->getVerified())
        ) {
            $judging->setSeen(true);
            $this->em->flush();
        }

        $runs = [];
        $testcasesruns = [];
        $firstWrongTestcase = [];
        if ($showSampleOutput && $judging && $judging->getResult() !== 'compiler-error') {
            $outputDisplayLimit = (int) $this->config->get('output_display_limit');
            $outputTruncateMessage = sprintf("\n[output display truncated after %d B]\n", $outputDisplayLimit);

            $queryBuilder = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                ->join('t.content', 'tc')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->leftJoin('jr.output', 'jro')
                ->select('t', 'jr', 'tc')
                ->andWhere('t.problem = :problem')
                ->andWhere('t.sample = 1')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber');

            if ($outputDisplayLimit < 0) {
                $queryBuilder
                    ->addSelect('tc.output AS output_reference')
                    ->addSelect('jro.output_run AS output_run')
                    ->addSelect('jro.output_diff AS output_diff')
                    ->addSelect('jro.output_error AS output_error')
                    ->addSelect('jro.output_system AS output_system')
                    ->addSelect('jro.team_message AS team_message');
            } else {
                $queryBuilder
                    ->addSelect('TRUNCATE(tc.output, :outputDisplayLimit, :outputTruncateMessage) AS output_reference')
                    ->addSelect('TRUNCATE(jro.output_run, :outputDisplayLimit, :outputTruncateMessage) AS output_run')
                    ->addSelect('TRUNCATE(jro.output_diff, :outputDisplayLimit, :outputTruncateMessage) AS output_diff')
                    ->addSelect('TRUNCATE(jro.output_error, :outputDisplayLimit, :outputTruncateMessage) AS output_error')
                    ->addSelect('TRUNCATE(jro.output_system, :outputDisplayLimit, :outputTruncateMessage) AS output_system')
                    ->addSelect('TRUNCATE(jro.team_message, :outputDisplayLimit, :outputTruncateMessage) AS team_message')
                    ->setParameter('outputDisplayLimit', $outputDisplayLimit)
                    ->setParameter('outputTruncateMessage', $outputTruncateMessage);
            }

            $runs = $queryBuilder
                ->getQuery()
                ->getResult();
        }

        if ($showTestResults) {

            $testcasesruns = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                ->join('t.content', 'tc')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->leftJoin('jr.output', 'jro')
                ->select('t', 'jr', 'tc')
                ->andWhere('t.problem = :problem')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber')
                ->getQuery()
                ->getResult();
        }

        if ($showFisrtWrongTest) {
            $testcases = $testcasesruns;
            $alloutput = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                ->join('t.content', 'tc')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->leftJoin('jr.output', 'jro')
                ->select('t', 'jr', 'tc')
                ->andWhere('t.problem = :problem')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber')
                ->addSelect('tc.output AS output_reference')
                ->addSelect('jro.output_run AS output_run')
                ->addSelect('jro.output_diff AS output_diff')
                ->addSelect('jro.output_error AS output_error')
                ->addSelect('jro.output_system AS output_system')
                ->addSelect('jro.team_message AS team_message')
                ->addSelect('tc.input AS input')
                ->getQuery()
                ->getResult();

            $transform = function ($inputString) {
                if (empty($inputString)) {
                    return '';
                }
                $parts = explode('-', $inputString);
                $capitalizedParts = array_map('ucfirst', $parts);
                return implode(' ', $capitalizedParts);
            };

            $limitStringSize = function ($input, $maxSizeInBytes = 12800) {
                if (strlen($input) > $maxSizeInBytes) {
                    $input = substr($input, 0, $maxSizeInBytes);
                    $input .= "\n\nmore than {$maxSizeInBytes} bytes";
                }
                return $input;
            };

            foreach ($testcases as $index => $testcase) {
                $run = $testcase->getFirstJudgingRun();
                if ($run) {
                    $runResult = $run->getRunresult();
                    if ($runResult != 'correct' && $runResult != null) {
                        $results = $runResult;
                        $firstWrongTestcase['result'] = $transform($runResult);
                        $firstWrongTestcase['rank'] = $testcase->getRank();
                        $firstWrongTestcase['totalTestCaseNums'] = count($testcases);
                        $firstWrongTestcase['input'] = $limitStringSize(rtrim($alloutput[$index]['input']));
                        $firstWrongTestcase['teamoutput'] = $limitStringSize(rtrim($alloutput[$index]['output_run']));
                        $firstWrongTestcase['judgeoutput'] = $limitStringSize(rtrim($alloutput[$index]['output_reference']));
                        $firstWrongTestcase['testcaseid'] = $testcase->getTestcaseid();
                        break;
                    }
                }
            }
            // $submission = $this->em->createQueryBuilder()
            //         ->from(Submission::class, 's')
            //         ->join('s.team', 't')
            //         ->join('s.problem', 'p')
            //         ->join('s.language', 'l')
            //         ->join('s.contest', 'c')
            //         ->leftJoin('s.files', 'f')
            //         ->leftJoin('s.external_judgements', 'ej', Join::WITH, 'ej.valid = 1')
            //         ->leftJoin('s.contest_problem', 'cp')
            //         ->select('s', 't', 'p', 'l', 'c', 'f', 'cp', 'ej')
            //         ->andWhere('s.submitid = :submitid')
            //         ->setParameter('submitid', $submitId)
            //         ->getQuery()
            //         ->getOneOrNullResult();
            // $queryBuilder = $this->em->createQueryBuilder()
            //         ->from(Testcase::class, 't')
            //         ->join('t.content', 'tc')
            //         ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
            //         ->leftJoin('jr.output', 'jro')
            //         ->select('t', 'jr', 'tc.image_thumb AS image_thumb', 'jro.metadata')
            //         ->andWhere('t.problem = :problem')
            //         ->setParameter('judging', 1)
            //         ->setParameter('problem', $submission->getProblem())
            //         ->orderBy('t.ranknumber')
            //         ->addSelect('tc.output AS output_reference')
            //         ->addSelect('jro.output_run AS output_run')
            //         ->addSelect('jro.output_diff AS output_diff')
            //         ->addSelect('jro.output_error AS output_error')
            //         ->addSelect('jro.team_message As team_message')
            //         ->addSelect('jro.output_system AS output_system');
            // $firstWrongTestcase = $queryBuilder
            //     ->getQuery()
            //     ->getResult();
        }

        $actuallyShowCompile = $showCompile == self::ALWAYS_SHOW_COMPILE_OUTPUT
            || ($showCompile == self::ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR && $judging->getResult() === 'compiler-error');
        $data = [
            'judging' => $judging,
            'verificationRequired' => $verificationRequired,
            'showCompile' => $actuallyShowCompile,
            'allowDownload' => $allowDownload,
            'showTestResults' => $showTestResults,
            'showSampleOutput' => $showSampleOutput,
            'runs' => $runs,
            'testcasesruns' => $testcasesruns,
            'showTooLateResult' => $showTooLateResult,
            'showFisrtWrongTest' => $showFisrtWrongTest,
            'firstWrongTestcase' => $firstWrongTestcase
        ];
        if ($actuallyShowCompile) {
            $data['size'] = 'xl';
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submission_modal.html.twig', $data);
        } else {
            return $this->render('team/submission.html.twig', $data);
        }
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/submission/{submitId<\d+>}/download', name: 'team_submission_download')]
    public function downloadAction(int $submitId): Response
    {
        $allowDownload = (bool) $this->config->get('allow_team_submission_download');
        if (!$allowDownload) {
            throw new NotFoundHttpException('Submission download not allowed');
        }

        $user = $this->dj->getUser();
        $team = $user->getTeam();
        /** @var Submission|null $submission */
        $submission = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->join('s.files', 'f')
            ->select('s, f')
            ->andWhere('s.submitid = :submitId')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        if ($submission === null) {
            throw new NotFoundHttpException(sprintf(
                'Submission with ID \'%s\' not found',
                $submitId
            ));
        }

        if($this->submissionService->getSubmissionFileNums($submission) == 1){
            return $this->submissionService->getSubmissionFileResponse($submission);
        }

        return $this->submissionService->getSubmissionZipResponse($submission);
    }
}
