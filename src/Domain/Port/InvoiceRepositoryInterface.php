<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Model\Invoice;

interface InvoiceRepositoryInterface
{
    public function save(Invoice $invoice): void;
}
