<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421133400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create alert_configurations table for configurable alert channels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert_configurations (
            id UUID NOT NULL,
            token_id UUID DEFAULT NULL,
            channel_type VARCHAR(20) NOT NULL,
            channel_config JSON NOT NULL,
            min_severity VARCHAR(20) NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX IDX_ALERT_CONFIGS_TOKEN_ID ON alert_configurations (token_id)');
        $this->addSql('CREATE INDEX IDX_ALERT_CONFIGS_CHANNEL_TYPE ON alert_configurations (channel_type)');
        $this->addSql('CREATE INDEX IDX_ALERT_CONFIGS_IS_ACTIVE ON alert_configurations (is_active)');

        $this->addSql('ALTER TABLE alert_configurations ADD CONSTRAINT FK_ALERT_CONFIGS_TOKEN FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_configurations DROP CONSTRAINT FK_ALERT_CONFIGS_TOKEN');
        $this->addSql('DROP TABLE alert_configurations');
    }
}
