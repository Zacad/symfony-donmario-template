<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Capability;

enum AuthorizingPermissionEnum: string
{
    case Manage = 'authorizing.manage';
    case CatalogueManage = 'authorizing.catalogue.manage';
}
