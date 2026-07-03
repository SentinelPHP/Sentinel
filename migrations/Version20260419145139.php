<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260419145139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sample_count column to api_schemas for learning threshold tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_schemas ADD sample_count INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_schemas DROP sample_count');
    }
}
