<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419152630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add validate_request_body column to api_tokens table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD validate_request_body BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP COLUMN validate_request_body');
    }
}
