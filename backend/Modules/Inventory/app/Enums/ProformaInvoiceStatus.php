<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * Proforma lifecycle. Draft/Issued/Cancelled are user-driven; PartiallyInvoiced
 * and Converted are set by the system from the invoiced quantity of the
 * delivery-order invoices auto-linked to the proforma.
 */
enum ProformaInvoiceStatus: string
{
    case Draft             = 'draft';
    case Issued            = 'issued';
    case PartiallyInvoiced = 'partially_invoiced';
    case Converted         = 'converted';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft             => 'Draft',
            self::Issued            => 'Issued',
            self::PartiallyInvoiced => 'Partially Invoiced',
            self::Converted         => 'Converted',
            self::Cancelled         => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Statuses a DO invoice auto-links to (the SO's one live, issued proforma). */
    public static function linkable(): array
    {
        return [self::Issued->value, self::PartiallyInvoiced->value, self::Converted->value];
    }
}
