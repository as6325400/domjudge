<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Include vpid in scorecache and rankcache primary keys so each virtual
 * participation gets its own cache rows.
 */
final class Version20260304120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vpid to scorecache and rankcache primary keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scorecache DROP PRIMARY KEY, ADD PRIMARY KEY (cid, teamid, probid, vpid)');
        $this->addSql('ALTER TABLE rankcache DROP PRIMARY KEY, ADD PRIMARY KEY (cid, teamid, vpid)');
    }

    public function down(Schema $schema): void
    {
        // Revert: remove vpid rows first, then restore original primary keys.
        $this->addSql('DELETE FROM scorecache WHERE vpid != 0');
        $this->addSql('DELETE FROM rankcache WHERE vpid != 0');
        $this->addSql('ALTER TABLE scorecache DROP PRIMARY KEY, ADD PRIMARY KEY (cid, teamid, probid)');
        $this->addSql('ALTER TABLE rankcache DROP PRIMARY KEY, ADD PRIMARY KEY (cid, teamid)');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
