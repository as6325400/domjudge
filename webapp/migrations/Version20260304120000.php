<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add virtual contest participation support.
 */
final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add virtual contest participation support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE virtual_participation (
            vpid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Virtual participation ID\',
            cid INT UNSIGNED NOT NULL COMMENT \'Contest ID\',
            teamid INT UNSIGNED NOT NULL COMMENT \'Team ID\',
            virtual_starttime NUMERIC(32, 9) UNSIGNED NOT NULL COMMENT \'Wall clock time when virtual participation started\',
            virtual_number INT UNSIGNED NOT NULL COMMENT \'1-indexed counter per team per contest\',
            is_completed TINYINT(1) DEFAULT 0 NOT NULL COMMENT \'Whether the virtual duration has elapsed\',
            duration NUMERIC(32, 9) UNSIGNED NOT NULL COMMENT \'Duration of the virtual contest in seconds\',
            INDEX virtual_starttime (cid, teamid, virtual_starttime),
            INDEX is_completed (cid, is_completed),
            UNIQUE INDEX cid_teamid_virtual_number (cid, teamid, virtual_number),
            PRIMARY KEY(vpid),
            CONSTRAINT FK_VP_contest FOREIGN KEY (cid) REFERENCES contest (cid) ON DELETE CASCADE,
            CONSTRAINT FK_VP_team FOREIGN KEY (teamid) REFERENCES team (teamid) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Virtual contest participations\'');

        $this->addSql('ALTER TABLE contest ADD allow_virtual TINYINT(1) DEFAULT 0 NOT NULL COMMENT \'Allow virtual participation for this contest?\'');

        $this->addSql('ALTER TABLE submission ADD vpid INT UNSIGNED DEFAULT NULL COMMENT \'Virtual participation ID\'');
        $this->addSql('ALTER TABLE submission ADD CONSTRAINT FK_submission_vp FOREIGN KEY (vpid) REFERENCES virtual_participation (vpid) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE submission ADD INDEX IDX_submission_vpid (vpid)');

        $this->addSql('ALTER TABLE scorecache ADD vpid INT UNSIGNED DEFAULT 0 NOT NULL COMMENT \'Virtual participation ID (0 = live participation)\'');
        $this->addSql('ALTER TABLE rankcache ADD vpid INT UNSIGNED DEFAULT 0 NOT NULL COMMENT \'Virtual participation ID (0 = live participation)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE submission DROP FOREIGN KEY FK_submission_vp');
        $this->addSql('ALTER TABLE submission DROP INDEX IDX_submission_vpid');
        $this->addSql('ALTER TABLE submission DROP vpid');

        $this->addSql('ALTER TABLE scorecache DROP vpid');
        $this->addSql('ALTER TABLE rankcache DROP vpid');

        $this->addSql('ALTER TABLE contest DROP allow_virtual');

        $this->addSql('DROP TABLE virtual_participation');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
