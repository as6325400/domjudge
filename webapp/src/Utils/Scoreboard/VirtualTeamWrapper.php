<?php declare(strict_types=1);

namespace App\Utils\Scoreboard;

use App\Entity\Team;
use App\Entity\VirtualParticipation;

/**
 * Wraps a Team entity with virtual participation metadata for scoreboard display.
 *
 * Uses negative IDs to avoid collisions with real team IDs in the scoreboard matrix.
 * The effective name includes a "(virtual #N)" suffix to distinguish virtual entries.
 */
class VirtualTeamWrapper extends Team
{
    private int $virtualTeamId;

    public function __construct(
        private readonly Team $wrappedTeam,
        private readonly VirtualParticipation $virtualParticipation,
    ) {
        parent::__construct();
        // Use negative IDs derived from vpid to avoid collision with real team IDs.
        $this->virtualTeamId = -$this->virtualParticipation->getVpid();
    }

    public function getTeamid(): ?int
    {
        return $this->virtualTeamId;
    }

    public function getExternalid(): ?string
    {
        return $this->wrappedTeam->getExternalid() . '_v' . $this->virtualParticipation->getVirtualNumber();
    }

    public function getEffectiveName(): string
    {
        return $this->virtualParticipation->getDisplayLabel();
    }

    public function getName(): string
    {
        return $this->getEffectiveName();
    }

    public function getDisplayName(): ?string
    {
        return $this->getEffectiveName();
    }

    public function getCategory(): ?\App\Entity\TeamCategory
    {
        return $this->wrappedTeam->getCategory();
    }

    public function getAffiliation(): ?\App\Entity\TeamAffiliation
    {
        return $this->wrappedTeam->getAffiliation();
    }

    public function getPenalty(): int
    {
        return $this->wrappedTeam->getPenalty();
    }

    public function getEnabled(): bool
    {
        return $this->wrappedTeam->getEnabled();
    }

    public function getWrappedTeam(): Team
    {
        return $this->wrappedTeam;
    }

    public function getVirtualParticipation(): VirtualParticipation
    {
        return $this->virtualParticipation;
    }

    public function isVirtualEntry(): bool
    {
        return true;
    }
}
