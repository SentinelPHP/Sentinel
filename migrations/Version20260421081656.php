<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421081656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add learning_threshold and auto_switch_to_validating columns to api_tokens for automatic schema promotion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD learning_threshold INT DEFAULT NULL');
        $this->addSql('ALTER TABLE api_tokens ADD auto_switch_to_validating BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP learning_threshold');
        $this->addSql('ALTER TABLE api_tokens DROP auto_switch_to_validating');
    }
}
