<?php

namespace App\Transaction\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One change to several money transactions at once (selected in a list):
 * set or clear the category, add a tag, or delete them.
 */
final readonly class BulkTransactionRequest
{
    public function __construct(
        /** @var list<string> */
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\All([new Assert\Uuid()])]
        public array $ids,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['category', 'tag', 'delete'])]
        public string $action,
        /** action "category": the category to set; null takes it off. */
        public ?string $categoryId = null,
        /** action "tag": the tag to add (without one, the tag isn't found: 404). */
        public ?string $tagId = null,
    ) {
    }
}
