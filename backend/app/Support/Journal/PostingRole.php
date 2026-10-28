<?php

declare(strict_types=1);

namespace App\Support\Journal;

/**
 * The role keys a document may ask for when it posts.
 *
 * These live in core, not in Finance, for the same reason JournalPoster does:
 * Inventory has to name a role when it builds a JournalRequest, and Inventory
 * must not reference the Finance module. Bare string literals would work but
 * would scatter 'accounts_payable' through the codebase with nothing to catch
 * a typo -- and a typo here means a document that cannot confirm.
 *
 * Modules\Finance\Enums\AccountRole is the richer version: it adds labels,
 * hints and the account types each role accepts. Its values are exactly these
 * strings, and PostingRoleContractTest asserts the two never drift apart.
 *
 * Not an enum deliberately. An enum would be the natural choice, but Finance
 * already owns one with these values; a second enum in core would invite
 * someone to convert between them, when the only thing that needs to travel
 * between the modules is the string.
 */
final class PostingRole
{
    public const ACCOUNTS_PAYABLE      = 'accounts_payable';
    public const ACCOUNTS_RECEIVABLE   = 'accounts_receivable';
    public const PURCHASES             = 'purchases';
    public const SALES_REVENUE         = 'sales_revenue';
    public const OUTPUT_TAX_PAYABLE    = 'output_tax_payable';
    public const INPUT_TAX_RECOVERABLE = 'input_tax_recoverable';
    public const DISCOUNT_ALLOWED      = 'discount_allowed';
    public const DISCOUNT_RECEIVED     = 'discount_received';
    public const SUPPLIER_ADVANCE      = 'supplier_advance';
    public const CUSTOMER_ADVANCE      = 'customer_advance';
    public const ROUNDING_DIFFERENCE   = 'rounding_difference';
    public const OPENING_BALANCE_EQUITY = 'opening_balance_equity';
    public const BANK_CHARGES          = 'bank_charges';
    public const GRNI_CLEARING         = 'grni_clearing';
    public const INVENTORY             = 'inventory';
    public const COST_OF_SALES         = 'cost_of_sales';

    /** Party kinds a journal line may be tagged with -- the subledger dimension. */
    public const PARTY_SUPPLIER = 'supplier';
    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_EMPLOYEE = 'employee';

    /** Source document kinds, used for source_type on an entry and its lines. */
    public const SOURCE_GRN              = 'grn';
    public const SOURCE_INVOICE          = 'invoice';
    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';
    public const SOURCE_CUSTOMER_RECEIPT = 'customer_receipt';
    public const SOURCE_BILL             = 'bill';
    public const SOURCE_BILL_PAYMENT     = 'bill_payment';

    /** @return list<string> Every role key, for the contract test. */
    public static function all(): array
    {
        return [
            self::ACCOUNTS_PAYABLE,
            self::ACCOUNTS_RECEIVABLE,
            self::PURCHASES,
            self::SALES_REVENUE,
            self::OUTPUT_TAX_PAYABLE,
            self::INPUT_TAX_RECOVERABLE,
            self::DISCOUNT_ALLOWED,
            self::DISCOUNT_RECEIVED,
            self::SUPPLIER_ADVANCE,
            self::CUSTOMER_ADVANCE,
            self::ROUNDING_DIFFERENCE,
            self::OPENING_BALANCE_EQUITY,
            self::BANK_CHARGES,
            self::GRNI_CLEARING,
            self::INVENTORY,
            self::COST_OF_SALES,
        ];
    }
}
