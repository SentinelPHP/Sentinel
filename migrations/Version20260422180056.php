<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422180056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dto_configuration JSON column to api_tokens for per-token DTO settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_tokens ADD dto_configuration JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE api_tokens ALTER auto_generate_dtos DROP DEFAULT');
        $this->addSql('ALTER TABLE generated_dtos ALTER is_current DROP DEFAULT');
        $this->addSql('ALTER TABLE generated_dtos ALTER status DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_tokens DROP dto_configuration');
        $this->addSql('ALTER TABLE api_tokens ALTER auto_generate_dtos SET DEFAULT false');
        $this->addSql('ALTER TABLE generated_dtos ALTER is_current SET DEFAULT false');
        $this->addSql('ALTER TABLE generated_dtos ALTER status SET DEFAULT \'completed\'');
    }
}
