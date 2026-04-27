<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add request_headers and response_headers columns to drift_payloads table.
 * This allows drift_only log level to store headers when drift is detected.
 */
final class Version20260421115843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add request_headers and response_headers to drift_payloads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drift_payloads ADD request_headers TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE drift_payloads ADD response_headers TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drift_payloads DROP request_headers');
        $this->addSql('ALTER TABLE drift_payloads DROP response_headers');
    }
}
