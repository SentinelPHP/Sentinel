<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419192600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add schema_validated, drift_detected, and drift_id columns to request_logs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_logs ADD COLUMN schema_validated BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE request_logs ADD COLUMN drift_detected BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE request_logs ADD COLUMN drift_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE request_logs ADD CONSTRAINT FK_REQUEST_LOGS_DRIFT_ID FOREIGN KEY (drift_id) REFERENCES schema_drifts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_REQUEST_LOGS_DRIFT_ID ON request_logs (drift_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_REQUEST_LOGS_DRIFT_ID');
        $this->addSql('ALTER TABLE request_logs DROP CONSTRAINT FK_REQUEST_LOGS_DRIFT_ID');
        $this->addSql('ALTER TABLE request_logs DROP COLUMN drift_id');
        $this->addSql('ALTER TABLE request_logs DROP COLUMN drift_detected');
        $this->addSql('ALTER TABLE request_logs DROP COLUMN schema_validated');
    }
}
