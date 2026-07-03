<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419143600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create schema_drifts table for tracking API contract drift';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE schema_drifts (
            id UUID NOT NULL,
            schema_id UUID NOT NULL,
            token_id UUID NOT NULL,
            request_log_id UUID DEFAULT NULL,
            drift_type VARCHAR(20) NOT NULL,
            path VARCHAR(2048) NOT NULL,
            expected_value JSON DEFAULT NULL,
            actual_value JSON DEFAULT NULL,
            severity VARCHAR(20) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX IDX_SCHEMA_DRIFTS_SCHEMA_ID ON schema_drifts (schema_id)');
        $this->addSql('CREATE INDEX IDX_SCHEMA_DRIFTS_TOKEN_ID ON schema_drifts (token_id)');
        $this->addSql('CREATE INDEX IDX_SCHEMA_DRIFTS_CREATED_AT ON schema_drifts (created_at)');
        $this->addSql('CREATE INDEX IDX_SCHEMA_DRIFTS_SEVERITY ON schema_drifts (severity)');

        $this->addSql('ALTER TABLE schema_drifts ADD CONSTRAINT FK_SCHEMA_DRIFTS_SCHEMA FOREIGN KEY (schema_id) REFERENCES api_schemas (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schema_drifts ADD CONSTRAINT FK_SCHEMA_DRIFTS_TOKEN FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schema_drifts ADD CONSTRAINT FK_SCHEMA_DRIFTS_REQUEST_LOG FOREIGN KEY (request_log_id) REFERENCES request_logs (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schema_drifts DROP CONSTRAINT FK_SCHEMA_DRIFTS_SCHEMA');
        $this->addSql('ALTER TABLE schema_drifts DROP CONSTRAINT FK_SCHEMA_DRIFTS_TOKEN');
        $this->addSql('ALTER TABLE schema_drifts DROP CONSTRAINT FK_SCHEMA_DRIFTS_REQUEST_LOG');
        $this->addSql('DROP TABLE schema_drifts');
    }
}
