<?php

namespace App\Classification\Service;

use App\Classification\DTO\ClassificationDeletionPreviewResponse;
use App\Entity\Category;
use App\Entity\Tag;
use App\Repository\TransactionRepository;
use App\Transaction\Service\TransactionAuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deleting a category or tag never removes it: it is marked as deleted (kept
 * for history) and taken off every transaction it was set on, each change
 * recorded in that transaction's audit log.
 */
class ClassificationDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRepository $transactionRepository,
        private readonly TransactionAuditLogService $auditLog,
    ) {
    }

    public function previewCategory(Category $category): ClassificationDeletionPreviewResponse
    {
        return new ClassificationDeletionPreviewResponse($this->transactionRepository->countByCategory($category));
    }

    public function deleteCategory(Category $category): void
    {
        $this->entityManager->wrapInTransaction(function () use ($category): void {
            foreach ($this->transactionRepository->findByCategory($category) as $transaction) {
                $before = $this->auditLog->snapshot($transaction);
                $transaction->setCategory(null);
                $this->auditLog->logUpdated($transaction, $transaction->getUser(), $before);
            }

            $category->markDeleted();
        });
    }

    public function previewTag(Tag $tag): ClassificationDeletionPreviewResponse
    {
        return new ClassificationDeletionPreviewResponse($this->transactionRepository->countByTag($tag));
    }

    public function deleteTag(Tag $tag): void
    {
        $this->entityManager->wrapInTransaction(function () use ($tag): void {
            foreach ($this->transactionRepository->findByTag($tag) as $transaction) {
                $before = $this->auditLog->snapshot($transaction);
                $transaction->removeTag($tag);
                $this->auditLog->logUpdated($transaction, $transaction->getUser(), $before);
            }

            $tag->markDeleted();
        });
    }
}
