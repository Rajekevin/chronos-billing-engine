<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create events and processed_idempotency_keys tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE events (
                id VARCHAR(36) NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                "timestamp" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_idempotency_key ON events (idempotency_key)');
        $this->addSql('CREATE INDEX idx_client_period ON events (client_id, "timestamp")');

        $this->addSql(<<<SQL
            CREATE TABLE processed_idempotency_keys (
                idempotency_key VARCHAR(128) NOT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(idempotency_key)
            )
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_messenger_queue ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX idx_messenger_available ON messenger_messages (available_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE processed_idempotency_keys');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
