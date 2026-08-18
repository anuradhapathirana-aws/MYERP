<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

// Same rules as store — a draft receipt is fully re-synced on every save.
class UpdateCustomerReceiptRequest extends StoreCustomerReceiptRequest
{
}
