<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Authorizing-owned global/resource roles and direct permission grants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public.authorizing_global_role_assignment (id UUID NOT NULL, account_id UUID NOT NULL, role_key VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX authorizing_global_role_natural_unique ON public.authorizing_global_role_assignment (account_id, role_key)');
        $this->addSql('CREATE INDEX authorizing_global_role_account_cursor ON public.authorizing_global_role_assignment (account_id, id)');

        $this->addSql('CREATE TABLE public.authorizing_resource_role_assignment (id UUID NOT NULL, account_id UUID NOT NULL, role_key VARCHAR(64) NOT NULL, resource_type VARCHAR(64) NOT NULL, resource_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX authorizing_resource_role_natural_unique ON public.authorizing_resource_role_assignment (account_id, role_key, resource_type, resource_id)');
        $this->addSql('CREATE INDEX authorizing_resource_role_account_cursor ON public.authorizing_resource_role_assignment (account_id, id)');

        $this->addSql('CREATE TABLE public.authorizing_global_permission_grant (id UUID NOT NULL, account_id UUID NOT NULL, permission_key VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX authorizing_global_permission_natural_unique ON public.authorizing_global_permission_grant (account_id, permission_key)');
        $this->addSql('CREATE INDEX authorizing_global_permission_account_cursor ON public.authorizing_global_permission_grant (account_id, id)');

        $this->addSql('CREATE TABLE public.authorizing_resource_permission_grant (id UUID NOT NULL, account_id UUID NOT NULL, permission_key VARCHAR(64) NOT NULL, resource_type VARCHAR(64) NOT NULL, resource_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX authorizing_resource_permission_natural_unique ON public.authorizing_resource_permission_grant (account_id, permission_key, resource_type, resource_id)');
        $this->addSql('CREATE INDEX authorizing_resource_permission_account_cursor ON public.authorizing_resource_permission_grant (account_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public.authorizing_resource_permission_grant');
        $this->addSql('DROP TABLE public.authorizing_global_permission_grant');
        $this->addSql('DROP TABLE public.authorizing_resource_role_assignment');
        $this->addSql('DROP TABLE public.authorizing_global_role_assignment');
    }
}
