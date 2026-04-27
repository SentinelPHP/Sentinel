<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260419142421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mode field to api_tokens table for schema learning/validation modes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_schemas ALTER version DROP DEFAULT');
        $this->addSql('ALTER TABLE api_schemas ALTER is_master DROP DEFAULT');
        $this->addSql('ALTER TABLE api_tokens ADD mode VARCHAR(20) NOT NULL DEFAULT \'passive\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_schemas ALTER version SET DEFAULT 1');
        $this->addSql('ALTER TABLE api_schemas ALTER is_master SET DEFAULT false');
        $this->addSql('ALTER TABLE api_tokens DROP mode');
    }
}
