<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserTokenAccessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserTokenAccessRepository::class)]
#[ORM\Table(name: 'user_token_access')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_TOKEN_ACCESS', columns: ['user_id', 'token_id'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_USER_TOKEN_ACCESS_USER')]
#[ORM\Index(columns: ['token_id'], name: 'IDX_USER_TOKEN_ACCESS_TOKEN')]
class UserTokenAccess
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ApiToken $token;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ApiToken $token)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->token = $token;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): ApiToken
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
