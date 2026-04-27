<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422160700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create generated_dtos table for DTO storage and versioning';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE generated_dtos (
            id UUID NOT NULL,
            schema_id UUID NOT NULL,
            class_name VARCHAR(255) NOT NULL,
            namespace VARCHAR(512) NOT NULL,
            php_code TEXT NOT NULL,
            checksum VARCHAR(64) NOT NULL,
            version INT NOT NULL,
            is_current BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IDX_GENERATED_DTOS_SCHEMA ON generated_dtos (schema_id)');
        $this->addSql('CREATE INDEX IDX_GENERATED_DTOS_CLASS_NAME ON generated_dtos (class_name)');
        $this->addSql('CREATE INDEX IDX_GENERATED_DTOS_CURRENT ON generated_dtos (schema_id, is_current)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_GENERATED_DTOS_SCHEMA_VERSION ON generated_dtos (schema_id, version)');
        $this->addSql('ALTER TABLE generated_dtos ADD CONSTRAINT FK_GENERATED_DTOS_SCHEMA FOREIGN KEY (schema_id) REFERENCES api_schemas (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE generated_dtos DROP CONSTRAINT FK_GENERATED_DTOS_SCHEMA');
        $this->addSql('DROP TABLE generated_dtos');
    }
}
