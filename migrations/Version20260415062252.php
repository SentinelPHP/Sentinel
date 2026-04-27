<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415062252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_tokens (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, allowed_targets JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CAD560EB3BC57DA ON api_tokens (token_hash)');
        $this->addSql('CREATE TABLE request_logs (id UUID NOT NULL, target_host VARCHAR(255) NOT NULL, request_method VARCHAR(10) NOT NULL, request_path VARCHAR(2048) NOT NULL, response_status_code INT NOT NULL, latency_ms INT NOT NULL, request_headers TEXT DEFAULT NULL, request_body TEXT DEFAULT NULL, response_headers TEXT DEFAULT NULL, response_body TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, token_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_REQUEST_LOGS_TOKEN_ID ON request_logs (token_id)');
        $this->addSql('CREATE INDEX IDX_REQUEST_LOGS_CREATED_AT ON request_logs (created_at)');
        $this->addSql('ALTER TABLE request_logs ADD CONSTRAINT FK_8F28E1A641DEE7B9 FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE request_logs DROP CONSTRAINT FK_8F28E1A641DEE7B9');
        $this->addSql('DROP TABLE api_tokens');
        $this->addSql('DROP TABLE request_logs');
    }
}
