<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421134500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_compressed column to request_logs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_logs ADD COLUMN is_compressed BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_logs DROP COLUMN is_compressed');
    }
}
