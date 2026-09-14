<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'authenticating_account', schema: 'public')]
#[ORM\UniqueConstraint(name: 'authenticating_account_email_unique', columns: ['email'])]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 254)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    public function __construct(string $email, #[\SensitiveParameter] string $passwordHash)
    {
        $this->email = EmailAddress::normalize($email);
        PasswordHash::validate($passwordHash);
        $this->passwordHash = $passwordHash;
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
