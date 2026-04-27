<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421174253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table for web authentication';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON users (email)');
        $this->addSql('ALTER TABLE alert_configurations ALTER is_active DROP DEFAULT');
        $this->addSql('ALTER TABLE api_schemas ALTER sample_count DROP DEFAULT');
        $this->addSql('ALTER TABLE api_tokens ALTER mode DROP DEFAULT');
        $this->addSql('ALTER TABLE api_tokens ALTER validate_request_body DROP DEFAULT');
        $this->addSql('ALTER TABLE api_tokens ALTER severity_overrides TYPE JSON');
        $this->addSql('ALTER TABLE api_tokens ALTER auto_switch_to_validating DROP DEFAULT');
        $this->addSql('ALTER TABLE drift_payloads ALTER is_compressed DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D202C97EA13E9E7D ON drift_payloads (request_log_id)');
        $this->addSql('ALTER TABLE request_logs ALTER is_encrypted DROP DEFAULT');
        $this->addSql('ALTER TABLE request_logs ALTER is_compressed DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE users');
        $this->addSql('ALTER TABLE alert_configurations ALTER is_active SET DEFAULT true');
        $this->addSql('ALTER TABLE api_schemas ALTER sample_count SET DEFAULT 1');
        $this->addSql('ALTER TABLE api_tokens ALTER mode SET DEFAULT \'passive\'');
        $this->addSql('ALTER TABLE api_tokens ALTER validate_request_body SET DEFAULT false');
        $this->addSql('ALTER TABLE api_tokens ALTER severity_overrides TYPE JSONB');
        $this->addSql('ALTER TABLE api_tokens ALTER auto_switch_to_validating SET DEFAULT false');
        $this->addSql('DROP INDEX UNIQ_D202C97EA13E9E7D');
        $this->addSql('ALTER TABLE drift_payloads ALTER is_compressed SET DEFAULT false');
        $this->addSql('ALTER TABLE request_logs ALTER is_encrypted SET DEFAULT false');
        $this->addSql('ALTER TABLE request_logs ALTER is_compressed SET DEFAULT false');
    }
}
