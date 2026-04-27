<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421085600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add data protection columns to api_tokens and request_logs tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD COLUMN data_protection_strategy VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE api_tokens ADD COLUMN custom_redaction_patterns JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE request_logs ADD COLUMN is_encrypted BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP COLUMN data_protection_strategy');
        $this->addSql('ALTER TABLE api_tokens DROP COLUMN custom_redaction_patterns');

        $this->addSql('ALTER TABLE request_logs DROP COLUMN is_encrypted');
    }
}
