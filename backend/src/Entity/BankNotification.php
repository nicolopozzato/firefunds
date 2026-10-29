<?php

namespace App\Entity;

use App\Repository\BankNotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A notification the phone sent, as it was: what it became (a draft),
 * ignored (a login, a promotion), or not understood yet (`unread`), until
 * the user writes a rule for it on the Sources page and reads it again.
 */
#[ORM\Entity(repositoryClass: BankNotificationRepository::class)]
#[ORM\Table(name: 'bank_notification')]
#[ORM\Index(columns: ['source_id', 'posted_at'])]
#[ORM\UniqueConstraint(columns: ['user_id', 'device_key'])]
class BankNotification
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_UNREAD = 'unread';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: NotificationSource::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NotificationSource $source;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $text;

    /** The phone's clock time, where it was (`timezone`). */
    #[ORM\Column]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(length: 64)]
    private string $timezone;

    /** The phone's own id of the notification: sent twice, kept once. */
    #[ORM\Column(length: 255)]
    private string $deviceKey;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_UNREAD;

    #[ORM\ManyToOne(targetEntity: Draft::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Draft $draft = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct(User $user, NotificationSource $source, string $title, string $text, \DateTimeImmutable $postedAt, string $timezone, string $deviceKey)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->source = $source;
        $this->title = $title;
        $this->text = $text;
        $this->postedAt = $postedAt;
        $this->timezone = $timezone;
        $this->deviceKey = $deviceKey;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSource(): NotificationSource
    {
        return $this->source;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** Title and text together, as the rules read them. */
    public function getFullText(): string
    {
        return trim($this->title."\n".$this->text);
    }

    public function getPostedAt(): \DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDraft(): ?Draft
    {
        return $this->draft;
    }

    public function becameDraft(Draft $draft): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->draft = $draft;
    }

    public function ignore(): void
    {
        $this->status = self::STATUS_IGNORED;
    }
}
