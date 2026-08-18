<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

/**
 * Update shares Store's rules — draft costings are fully re-editable
 * (same pattern as UpdateSalesOrderRequest).
 */
class UpdateCostingRequest extends StoreCostingRequest
{
}
