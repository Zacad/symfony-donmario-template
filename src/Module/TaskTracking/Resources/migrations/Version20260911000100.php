<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the TaskTracking-owned task table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public.task_tracking_task (id UUID NOT NULL, title VARCHAR(200) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public.task_tracking_task');
    }
}
