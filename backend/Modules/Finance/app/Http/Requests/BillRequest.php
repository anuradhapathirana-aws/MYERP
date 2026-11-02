<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Services\CompanyAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\FinanceControlService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A supplier bill, create or update.
 *
 * This is the security boundary for the document. The form validates on blur
 * for convenience; everything is repeated here, and BillService re-checks the
 * rules that can change between validation and commit (duplicates, lock date)
 * under a row lock.
 *
 * Only the fields listed in rules() ever reach the DTO. bill_no, status,
 * paid_amount and the audit columns are not in it, so sending them does
 * nothing.
 */
class BillRequest extends FormRequest
{
    /** 9,999,999,999.99 -- the largest value decimal(15,2) holds comfortably. */
    public const MAX_AMOUNT = 9999999999.99;

    /**
     * Another company's bill answers 404 before any validation runs, so field
     * errors can never confirm that it exists.
     */
    public function authorize(): bool
    {
        $bill = $this->route('bill');

        if ($bill instanceof Bill && ! app(CompanyAccessService::class)->canAccess($this->user(), $bill->company_id)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    /**
     * Trim and strip markup from free text before validation, so length limits
     * apply to what is actually stored. React escapes on output anyway; this
     * keeps HTML out of PDFs, exports and anything else that reads the column.
     */
    protected function prepareForValidation(): void
    {
        $clean = static fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v;

        $lines = $this->input('lines');

        if (is_array($lines)) {
            $lines = array_map(static function ($line) use ($clean) {
                if (is_array($line) && array_key_exists('description', $line)) {
                    $line['description'] = $clean($line['description']);
                }

                return $line;
            }, $lines);
        }

        $this->merge(array_filter([
            'document_no' => $clean($this->input('document_no')),
            'description' => $clean($this->input('description')),
            'lines'       => $lines,
        ], static fn ($v) => $v !== null));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $maxDate = CarbonImmutable::today()->addYear()->toDateString();

        return [
            'request_id'       => ['nullable', 'uuid'],

            'supplier_id'      => ['required', 'integer', Rule::exists('inv_supplier_masters', 'id')],

            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $maxDate],
            'document_no'      => ['required', 'string', 'max:100'],
            'document_date'    => ['nullable', 'date_format:Y-m-d', 'before_or_equal:transaction_date'],
            'due_date'         => ['required', 'date_format:Y-m-d', 'after_or_equal:transaction_date'],

            'company_id'       => ['required', 'integer', Rule::exists('inv_companies', 'id')],
            'location_id'      => [
                'nullable',
                'integer',
                // The location must belong to the chosen company and not be deleted.
                Rule::exists('inv_locations', 'id')
                    ->where('company_id', (int) $this->input('company_id'))
                    ->whereNull('deleted_at'),
            ],

            'description'      => ['nullable', 'string', 'max:1000'],

            // An array so a later release can split a bill across accounts
            // without an API change. The screen sends exactly one line today.
            'lines'                     => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.ledger_account_id' => ['required', 'integer', 'distinct'],
            'lines.*.amount'            => [
                'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:' . self::MAX_AMOUNT,
            ],
            'lines.*.description'       => ['nullable', 'string', 'max:500'],

            // Optimistic lock: the updated_at the editor loaded. Checked in the
            // service so a stale save returns 409 instead of overwriting.
            'updated_at'       => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->checkCompanyAccess($v);
            $this->checkAccounts($v);
            $this->checkLockDate($v);
            $this->checkDuplicate($v);
        });
    }

    private function checkCompanyAccess(Validator $v): void
    {
        if (! app(CompanyAccessService::class)->canAccess($this->user(), (int) $this->input('company_id'))) {
            $v->errors()->add('company_id', 'You do not have access to this company.');
        }
    }

    /**
     * Each line must be charged to a billable expense account owned by this
     * company or shared by all companies.
     */
    private function checkAccounts(Validator $v): void
    {
        $companyId = (int) $this->input('company_id');

        foreach ((array) $this->input('lines', []) as $i => $line) {
            $account = LedgerAccount::query()
                ->billable()
                ->whereKey((int) ($line['ledger_account_id'] ?? 0))
                ->first(['id', 'company_id']);

            if ($account === null) {
                $v->errors()->add(
                    "lines.{$i}.ledger_account_id",
                    'Choose an active expense account. Bank, cash and party accounts cannot be used on a bill.',
                );

                continue;
            }

            if ($account->company_id !== null && $account->company_id !== $companyId) {
                $v->errors()->add("lines.{$i}.ledger_account_id", 'This account belongs to another company.');
            }
        }
    }

    private function checkLockDate(Validator $v): void
    {
        $controls = app(FinanceControlService::class);
        $date     = CarbonImmutable::parse((string) $this->input('transaction_date'));

        if (! $controls->isOpen($date)) {
            $v->errors()->add(
                'transaction_date',
                'Period closed up to ' . $controls->lockDate()?->format('d-m-Y') . '. Choose a later date.',
            );
        }
    }

    /** The same supplier invoice may not be entered twice (voided bills excepted). */
    private function checkDuplicate(Validator $v): void
    {
        /** @var Bill|null $current */
        $current = $this->route('bill');

        $exists = Bill::query()
            ->where('supplier_id', (int) $this->input('supplier_id'))
            ->where('document_no_normalized', Bill::normalizeDocumentNo((string) $this->input('document_no')))
            ->where('status', '!=', BillStatus::Voided->value)
            ->when($current instanceof Bill, fn ($q) => $q->whereKeyNot($current->id))
            // An idempotent retry of this same create is not a duplicate.
            ->when($this->filled('request_id'), fn ($q) => $q->where(
                fn ($w) => $w->whereNull('request_id')->orWhere('request_id', '!=', $this->input('request_id')),
            ))
            ->first(['bill_no']);

        if ($exists !== null) {
            $v->errors()->add(
                'document_no',
                'This supplier invoice is already entered as ' . $exists->bill_no . '.',
            );
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id'               => 'supplier',
            'transaction_date'          => 'transaction date',
            'document_no'               => 'document no',
            'document_date'             => 'document date',
            'due_date'                  => 'due date',
            'company_id'                => 'company',
            'location_id'               => 'location',
            'lines.*.ledger_account_id' => 'account',
            'lines.*.amount'            => 'amount',
            'lines.*.description'       => 'line description',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'location_id.exists'          => 'This location does not exist or does not belong to the selected company.',
            'due_date.after_or_equal'     => 'The due date cannot be before the transaction date.',
            'document_date.before_or_equal' => 'The document date cannot be after the transaction date.',
            'transaction_date.before_or_equal' => 'The transaction date cannot be more than one year ahead.',
            'lines.*.amount.gt'           => 'The amount must be greater than zero.',
            'lines.*.amount.decimal'      => 'The amount can have at most 2 decimal places.',
            'lines.*.amount.max'          => 'The amount is too large.',
            'lines.*.ledger_account_id.distinct' => 'Each account may appear only once on a bill.',
        ];
    }
}
