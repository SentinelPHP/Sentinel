<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419200200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create request_bodies table for full audit logging mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_bodies (
            id UUID NOT NULL,
            request_log_id UUID NOT NULL,
            request_body TEXT DEFAULT NULL,
            response_body TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX IDX_REQUEST_BODIES_REQUEST_LOG_ID ON request_bodies (request_log_id)');
        $this->addSql('ALTER TABLE request_bodies ADD CONSTRAINT FK_REQUEST_BODIES_REQUEST_LOG FOREIGN KEY (request_log_id) REFERENCES request_logs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_bodies DROP CONSTRAINT FK_REQUEST_BODIES_REQUEST_LOG');
        $this->addSql('DROP TABLE request_bodies');
    }
}
