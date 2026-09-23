<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional task ownership and completion, retaining legacy ownerless open tasks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.task_tracking_task ADD owner_account_id UUID DEFAULT NULL, ADD completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.task_tracking_task DROP owner_account_id, DROP completed_at');
    }
}
