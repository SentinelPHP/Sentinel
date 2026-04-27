<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419201600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_compressed column to request_bodies table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_bodies ADD COLUMN is_compressed BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_bodies DROP COLUMN is_compressed');
    }
}
