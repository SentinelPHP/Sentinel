<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419161935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add severity_overrides and alert_min_severity columns to api_tokens table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE api_tokens
            ADD COLUMN severity_overrides JSONB DEFAULT NULL,
            ADD COLUMN alert_min_severity VARCHAR(20) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE api_tokens
            DROP COLUMN severity_overrides,
            DROP COLUMN alert_min_severity
        SQL);
    }
}
