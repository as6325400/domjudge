<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add optimization score (optscore) fields.
 *
 * This migration is idempotent: it handles the case where columns already
 * exist from a previous 8.3.1-DS_OOP installation by using
 * "ADD COLUMN IF NOT EXISTS" (MariaDB) and adjusting column definitions
 * to match the new schema.
 *
 * It also cleans up the old DS_OOP migration records from
 * doctrine_migration_versions so that Doctrine does not warn about
 * unknown migrations.
 */
final class Version20260319000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optimization score (optscore) fields to contest, judging_run, scorecache, and rankcache (idempotent).';
    }

    public function up(Schema $schema): void
    {
        // contest: add columns if not present, then normalize schema
        $this->addSql('ALTER TABLE contest ADD COLUMN IF NOT EXISTS opt_score_as_score_tiebreaker TINYINT(1) DEFAULT 0 NOT NULL COMMENT \'Is optscore used as tiebreaker?\'');
        $this->addSql('ALTER TABLE contest ADD COLUMN IF NOT EXISTS opt_score_order VARCHAR(4) DEFAULT \'asc\' COMMENT \'Order for optscore sorting (asc/desc)\'');
        // Fix schema differences from 8.3.1-DS_OOP (VARCHAR(10) NOT NULL -> VARCHAR(4) nullable)
        $this->addSql('ALTER TABLE contest MODIFY opt_score_order VARCHAR(4) DEFAULT \'asc\' COMMENT \'Order for optscore sorting (asc/desc)\'');

        // judging_run
        $this->addSql('ALTER TABLE judging_run ADD COLUMN IF NOT EXISTS optscore DOUBLE PRECISION DEFAULT NULL COMMENT \'Optimization score for this testcase run\'');

        // scorecache: add if not present, then normalize to nullable defaults
        $this->addSql('ALTER TABLE scorecache ADD COLUMN IF NOT EXISTS optscore_max_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Max optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE scorecache ADD COLUMN IF NOT EXISTS optscore_min_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Min optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE scorecache ADD COLUMN IF NOT EXISTS optscore_max_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Max optscore (public)\'');
        $this->addSql('ALTER TABLE scorecache ADD COLUMN IF NOT EXISTS optscore_min_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Min optscore (public)\'');
        // Fix schema: 8.3.1 used DEFAULT '0' NOT NULL, we want DEFAULT NULL
        $this->addSql('ALTER TABLE scorecache MODIFY optscore_max_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Max optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE scorecache MODIFY optscore_min_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Min optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE scorecache MODIFY optscore_max_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Max optscore (public)\'');
        $this->addSql('ALTER TABLE scorecache MODIFY optscore_min_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Min optscore (public)\'');

        // rankcache: add if not present, then normalize
        $this->addSql('ALTER TABLE rankcache ADD COLUMN IF NOT EXISTS totaloptscore_max_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Total max optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE rankcache ADD COLUMN IF NOT EXISTS totaloptscore_min_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Total min optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE rankcache ADD COLUMN IF NOT EXISTS totaloptscore_max_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Total max optscore (public)\'');
        $this->addSql('ALTER TABLE rankcache ADD COLUMN IF NOT EXISTS totaloptscore_min_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Total min optscore (public)\'');
        // Fix schema: 8.3.1 used NOT NULL DEFAULT '0', we want DEFAULT NULL
        $this->addSql('ALTER TABLE rankcache MODIFY totaloptscore_max_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Total max optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE rankcache MODIFY totaloptscore_min_restricted DOUBLE PRECISION DEFAULT NULL COMMENT \'Total min optscore (restricted audience)\'');
        $this->addSql('ALTER TABLE rankcache MODIFY totaloptscore_max_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Total max optscore (public)\'');
        $this->addSql('ALTER TABLE rankcache MODIFY totaloptscore_min_public DOUBLE PRECISION DEFAULT NULL COMMENT \'Total min optscore (public)\'');

        // Clean up old 8.3.1-DS_OOP migration records so Doctrine won't warn about unknown migrations
        $oldMigrations = [
            'DoctrineMigrations\\Version20250517062145',
            'DoctrineMigrations\\Version20250517084703',
            'DoctrineMigrations\\Version20250517085220',
            'DoctrineMigrations\\Version20250517145253',
            'DoctrineMigrations\\Version20250519064616',
            'DoctrineMigrations\\Version20250519122129',
            'DoctrineMigrations\\Version20250519130014',
            'DoctrineMigrations\\Version20250519134327',
        ];
        foreach ($oldMigrations as $version) {
            $this->addSql('DELETE FROM doctrine_migration_versions WHERE version = ?', [$version]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest DROP COLUMN IF EXISTS opt_score_as_score_tiebreaker');
        $this->addSql('ALTER TABLE contest DROP COLUMN IF EXISTS opt_score_order');
        $this->addSql('ALTER TABLE judging_run DROP COLUMN IF EXISTS optscore');
        $this->addSql('ALTER TABLE scorecache DROP COLUMN IF EXISTS optscore_max_restricted');
        $this->addSql('ALTER TABLE scorecache DROP COLUMN IF EXISTS optscore_min_restricted');
        $this->addSql('ALTER TABLE scorecache DROP COLUMN IF EXISTS optscore_max_public');
        $this->addSql('ALTER TABLE scorecache DROP COLUMN IF EXISTS optscore_min_public');
        $this->addSql('ALTER TABLE rankcache DROP COLUMN IF EXISTS totaloptscore_max_restricted');
        $this->addSql('ALTER TABLE rankcache DROP COLUMN IF EXISTS totaloptscore_min_restricted');
        $this->addSql('ALTER TABLE rankcache DROP COLUMN IF EXISTS totaloptscore_max_public');
        $this->addSql('ALTER TABLE rankcache DROP COLUMN IF EXISTS totaloptscore_min_public');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
