<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422160730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto_generate_dtos to api_tokens and status tracking to generated_dtos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD auto_generate_dtos BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("ALTER TABLE generated_dtos ADD status VARCHAR(20) NOT NULL DEFAULT 'completed'");
        $this->addSql('ALTER TABLE generated_dtos ADD error_message TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP auto_generate_dtos');
        $this->addSql('ALTER TABLE generated_dtos DROP status');
        $this->addSql('ALTER TABLE generated_dtos DROP error_message');
    }
}
