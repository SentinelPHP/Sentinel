<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422053125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add acceptance fields to schema_drifts table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE schema_drifts ADD accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE schema_drifts ADD accepted_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE schema_drifts ADD CONSTRAINT FK_76F3627D20F699D9 FOREIGN KEY (accepted_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_76F3627D20F699D9 ON schema_drifts (accepted_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE schema_drifts DROP CONSTRAINT FK_76F3627D20F699D9');
        $this->addSql('DROP INDEX IDX_76F3627D20F699D9');
        $this->addSql('ALTER TABLE schema_drifts DROP accepted_at');
        $this->addSql('ALTER TABLE schema_drifts DROP accepted_by_id');
    }
}
