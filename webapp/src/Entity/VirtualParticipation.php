<?php declare(strict_types=1);

namespace App\Entity;

use App\Utils\Utils;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Virtual contest participations.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'virtual_participation',
    options: [
        'collation' => 'utf8mb4_unicode_ci',
        'charset' => 'utf8mb4',
        'comment' => 'Virtual contest participations',
    ]
)]
#[ORM\Index(columns: ['cid', 'teamid', 'virtual_starttime'], name: 'virtual_starttime')]
#[ORM\Index(columns: ['cid', 'is_completed'], name: 'is_completed')]
#[ORM\UniqueConstraint(name: 'cid_teamid_virtual_number', columns: ['cid', 'teamid', 'virtual_number'])]
class VirtualParticipation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['comment' => 'Virtual participation ID', 'unsigned' => true])]
    private ?int $vpid = null;

    #[ORM\Column(
        type: 'decimal',
        precision: 32,
        scale: 9,
        options: ['comment' => 'Wall clock time when virtual participation started', 'unsigned' => true]
    )]
    private string|float $virtual_starttime;

    #[ORM\Column(options: [
        'comment' => '1-indexed counter per team per contest',
        'unsigned' => true,
    ])]
    private int $virtual_number = 1;

    #[ORM\Column(options: [
        'comment' => 'Whether the virtual duration has elapsed',
        'default' => 0,
    ])]
    private bool $is_completed = false;

    #[ORM\Column(
        type: 'decimal',
        precision: 32,
        scale: 9,
        options: ['comment' => 'Duration of the virtual contest in seconds', 'unsigned' => true]
    )]
    private string|float $duration;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cid', referencedColumnName: 'cid', onDelete: 'CASCADE')]
    private Contest $contest;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'teamid', referencedColumnName: 'teamid', onDelete: 'CASCADE')]
    private Team $team;

    /**
     * @var Collection<int, Submission>
     */
    #[ORM\OneToMany(mappedBy: 'virtualParticipation', targetEntity: Submission::class)]
    private Collection $submissions;

    public function __construct()
    {
        $this->submissions = new ArrayCollection();
    }

    public function getVpid(): ?int
    {
        return $this->vpid;
    }

    public function setVirtualStarttime(string|float $virtualStarttime): VirtualParticipation
    {
        $this->virtual_starttime = $virtualStarttime;
        return $this;
    }

    public function getVirtualStarttime(): string|float
    {
        return $this->virtual_starttime;
    }

    public function setVirtualNumber(int $virtualNumber): VirtualParticipation
    {
        $this->virtual_number = $virtualNumber;
        return $this;
    }

    public function getVirtualNumber(): int
    {
        return $this->virtual_number;
    }

    public function setIsCompleted(bool $isCompleted): VirtualParticipation
    {
        $this->is_completed = $isCompleted;
        return $this;
    }

    public function getIsCompleted(): bool
    {
        return $this->is_completed;
    }

    public function setDuration(string|float $duration): VirtualParticipation
    {
        $this->duration = $duration;
        return $this;
    }

    public function getDuration(): string|float
    {
        return $this->duration;
    }

    public function setContest(Contest $contest): VirtualParticipation
    {
        $this->contest = $contest;
        return $this;
    }

    public function getContest(): Contest
    {
        return $this->contest;
    }

    public function setTeam(Team $team): VirtualParticipation
    {
        $this->team = $team;
        return $this;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    /**
     * @return Collection<int, Submission>
     */
    public function getSubmissions(): Collection
    {
        return $this->submissions;
    }

    public function addSubmission(Submission $submission): VirtualParticipation
    {
        $this->submissions[] = $submission;
        return $this;
    }

    /**
     * Get the wall clock time when this virtual participation ends.
     */
    public function getVirtualEndtime(): float
    {
        return (float)$this->virtual_starttime + (float)$this->duration;
    }

    /**
     * Check if this virtual participation is currently active.
     */
    public function isActive(): bool
    {
        $now = Utils::now();
        return $now >= (float)$this->virtual_starttime && $now < $this->getVirtualEndtime();
    }

    /**
     * Get the relative time (seconds since virtual start) for a given wall clock time.
     */
    public function getRelativeTime(float $wallTime): float
    {
        return $wallTime - (float)$this->virtual_starttime;
    }

    /**
     * Get display label for this virtual participation.
     */
    public function getDisplayLabel(): string
    {
        return sprintf('%s (virtual #%d)', $this->team->getEffectiveName(), $this->virtual_number);
    }
}
