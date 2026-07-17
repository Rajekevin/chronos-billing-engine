<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Model\Invoice;
use App\Domain\Port\InvoiceRepositoryInterface;

/**
 * Minimal placeholder adapter. In a real deployment this would map
 * Invoice to an InvoiceEntity the same way EventMapper does for events.
 * Kept intentionally light here to keep the lab focused on the
 * ingestion/idempotency path requested for this milestone.
 */
final class DoctrineInvoiceRepository implements InvoiceRepositoryInterface
{
    public function save(Invoice $invoice): void
    {
        // TODO: map Invoice -> InvoiceEntity and persist, mirroring
        // DoctrineEventRepository::save().
    }
}
