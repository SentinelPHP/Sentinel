<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_schemas table for JSON Schema storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_schemas (
            id UUID NOT NULL,
            token_id UUID NOT NULL,
            target_host VARCHAR(255) NOT NULL,
            endpoint_path VARCHAR(2048) NOT NULL,
            http_method VARCHAR(10) NOT NULL,
            schema_type VARCHAR(20) NOT NULL,
            json_schema JSON NOT NULL,
            version INT NOT NULL DEFAULT 1,
            is_master BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX IDX_API_SCHEMAS_TOKEN_ID ON api_schemas (token_id)');
        $this->addSql('CREATE INDEX IDX_API_SCHEMAS_LOOKUP ON api_schemas (token_id, target_host, endpoint_path, http_method, schema_type)');
        $this->addSql('CREATE INDEX IDX_API_SCHEMAS_MASTER ON api_schemas (token_id, is_master)');

        $this->addSql('ALTER TABLE api_schemas ADD CONSTRAINT FK_API_SCHEMAS_TOKEN FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_schemas DROP CONSTRAINT FK_API_SCHEMAS_TOKEN');
        $this->addSql('DROP TABLE api_schemas');
    }
}
