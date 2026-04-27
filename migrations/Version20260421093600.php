<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421093600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename request_bodies table to drift_payloads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_bodies RENAME TO drift_payloads');
        $this->addSql('ALTER INDEX idx_request_bodies_request_log_id RENAME TO idx_drift_payloads_request_log_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_drift_payloads_request_log_id RENAME TO idx_request_bodies_request_log_id');
        $this->addSql('ALTER TABLE drift_payloads RENAME TO request_bodies');
    }
}
