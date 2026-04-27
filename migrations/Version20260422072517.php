<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422072517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create alert_logs table and add muting fields to alert_configurations';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alert_logs (id UUID NOT NULL, channel_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, error_message TEXT DEFAULT NULL, payload JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alert_configuration_id UUID DEFAULT NULL, drift_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ALERT_LOGS_CONFIG_ID ON alert_logs (alert_configuration_id)');
        $this->addSql('CREATE INDEX IDX_ALERT_LOGS_DRIFT_ID ON alert_logs (drift_id)');
        $this->addSql('CREATE INDEX IDX_ALERT_LOGS_CHANNEL_TYPE ON alert_logs (channel_type)');
        $this->addSql('CREATE INDEX IDX_ALERT_LOGS_STATUS ON alert_logs (status)');
        $this->addSql('CREATE INDEX IDX_ALERT_LOGS_CREATED_AT ON alert_logs (created_at)');
        $this->addSql('ALTER TABLE alert_logs ADD CONSTRAINT FK_7F3F14D8701B6C9A FOREIGN KEY (alert_configuration_id) REFERENCES alert_configurations (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE alert_logs ADD CONSTRAINT FK_7F3F14D8EA047BC FOREIGN KEY (drift_id) REFERENCES schema_drifts (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE alert_configurations ADD muted_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE alert_configurations ADD mute_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_logs DROP CONSTRAINT FK_7F3F14D8701B6C9A');
        $this->addSql('ALTER TABLE alert_logs DROP CONSTRAINT FK_7F3F14D8EA047BC');
        $this->addSql('DROP TABLE alert_logs');
        $this->addSql('ALTER TABLE alert_configurations DROP muted_until');
        $this->addSql('ALTER TABLE alert_configurations DROP mute_reason');
    }
}
