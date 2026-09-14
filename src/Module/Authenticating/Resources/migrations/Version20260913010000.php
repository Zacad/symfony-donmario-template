<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Authenticating-owned account table with unique normalized email.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public.authenticating_account (id UUID NOT NULL, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX authenticating_account_email_unique ON public.authenticating_account (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public.authenticating_account');
    }
}
