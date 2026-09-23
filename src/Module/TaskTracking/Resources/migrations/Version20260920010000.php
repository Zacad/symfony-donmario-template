<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the owner and UUID keyset index used by bounded Task pages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX task_tracking_task_owner_id_idx ON public.task_tracking_task (owner_account_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX public.task_tracking_task_owner_id_idx');
    }
}
