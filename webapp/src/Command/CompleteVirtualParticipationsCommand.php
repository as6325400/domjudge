<?php declare(strict_types=1);

namespace App\Command;

use App\Service\VirtualContestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'domjudge:complete-virtual-participations',
    description: 'Marks expired virtual participations as completed'
)]
class CompleteVirtualParticipationsCommand extends Command
{
    public function __construct(
        protected readonly VirtualContestService $virtualContestService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $this->virtualContestService->checkAndCompleteExpiredParticipations();

        $style->success('Completed all expired virtual participations.');

        return Command::SUCCESS;
    }
}
