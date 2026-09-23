<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Resources\migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Authorizing role catalogue and global subject assignments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public.authorizing_role (role_key VARCHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, revision INT NOT NULL, retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (role_key), CONSTRAINT authorizing_role_revision CHECK (revision >= 1))');
        $this->addSql('CREATE TABLE public.authorizing_role_permission (role_key VARCHAR(64) NOT NULL, permission_key VARCHAR(64) NOT NULL, PRIMARY KEY (role_key, permission_key))');
        $this->addSql('CREATE INDEX authorizing_role_permission_role ON public.authorizing_role_permission (role_key)');
        $this->addSql('CREATE INDEX authorizing_role_permission_permission_role ON public.authorizing_role_permission (permission_key, role_key)');
        $this->addSql('ALTER TABLE public.authorizing_role_permission ADD CONSTRAINT authorizing_role_permission_role_fk FOREIGN KEY (role_key) REFERENCES public.authorizing_role (role_key) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE public.authorizing_role_assignment (subject_id UUID NOT NULL, role_key VARCHAR(64) NOT NULL, PRIMARY KEY (subject_id, role_key))');
        $this->addSql('CREATE INDEX authorizing_role_assignment_role ON public.authorizing_role_assignment (role_key)');
        $this->addSql('ALTER TABLE public.authorizing_role_assignment ADD CONSTRAINT authorizing_role_assignment_role_fk FOREIGN KEY (role_key) REFERENCES public.authorizing_role (role_key) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE public.authorizing_permission_grant (subject_id UUID NOT NULL, permission_key VARCHAR(64) NOT NULL, PRIMARY KEY (subject_id, permission_key))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public.authorizing_permission_grant');
        $this->addSql('DROP TABLE public.authorizing_role_assignment');
        $this->addSql('DROP TABLE public.authorizing_role_permission');
        $this->addSql('DROP TABLE public.authorizing_role');
    }
}
