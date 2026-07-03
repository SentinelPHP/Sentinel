<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421191357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_token_access table for RBAC';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_token_access (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, token_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_USER_TOKEN_ACCESS_USER ON user_token_access (user_id)');
        $this->addSql('CREATE INDEX IDX_USER_TOKEN_ACCESS_TOKEN ON user_token_access (token_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_TOKEN_ACCESS ON user_token_access (user_id, token_id)');
        $this->addSql('ALTER TABLE user_token_access ADD CONSTRAINT FK_87DDF854A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_token_access ADD CONSTRAINT FK_87DDF85441DEE7B9 FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_token_access DROP CONSTRAINT FK_87DDF854A76ED395');
        $this->addSql('ALTER TABLE user_token_access DROP CONSTRAINT FK_87DDF85441DEE7B9');
        $this->addSql('DROP TABLE user_token_access');
    }
}
