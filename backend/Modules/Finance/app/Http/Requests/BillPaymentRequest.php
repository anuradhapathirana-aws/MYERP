<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Models\PaymentMode;
use App\Services\CompanyAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BillPaymentService;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Support\PaymentModeRules;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bill payment, create or update.
 *
 * The security boundary for the document, as BillRequest is for bills. Every
 * rule that can change between validation and commit (bill balances, cheque
 * numbers, lock date) is checked again by BillPaymentService under row locks
 * when the payment is confirmed.
 *
 * Only the fields in rules() reach the DTO. payment_no, status, total_amount
 * and the audit columns are not in it, so sending them does nothing. The total
 * is always the sum of the allocations, never a number the client supplies.
 */
class BillPaymentRequest extends FormRequest
{
    public const MAX_AMOUNT = BillRequest::MAX_AMOUNT;

    /** A cheque older than this is stale and a bank will not honour it. */
    public const CHEQUE_STALE_DAYS = 180;

    /** Resolved once per request; null when the id is missing or unknown. */
    private ?PaymentMode $mode = null;

    private bool $modeResolved = false;

    public function authorize(): bool
    {
        $payment = $this->route('bill_payment');

        if ($payment instanceof BillPayment && ! app(CompanyAccessService::class)->canAccess($this->user(), $payment->company_id)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = static fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v;

        $this->merge(array_filter([
            'reference_no' => $clean($this->input('reference_no')),
            'cheque_no'    => $clean($this->input('cheque_no')),
            'description'  => $clean($this->input('description')),
        ], static fn ($v) => $v !== null));

        // Cheque fields mean nothing on a cash or transfer payment; drop them
        // rather than store a stray cheque number against a bank transfer.
        $mode = $this->mode();
        if ($mode === null || ! PaymentModeRules::isCheque($mode)) {
            $this->merge(['cheque_no' => null, 'cheque_date' => null]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $mode     = $this->mode();
        $isCheque = $mode !== null && PaymentModeRules::isCheque($mode);

        return [
            'request_id'          => ['nullable', 'uuid'],

            'supplier_id'         => ['required', 'integer', Rule::exists('inv_supplier_masters', 'id')],
            'company_id'          => ['required', 'integer', Rule::exists('inv_companies', 'id')],

            // Money cannot leave the bank tomorrow; a payment is recorded when made.
            'payment_date'        => ['required', 'date_format:Y-m-d', 'before_or_equal:' . CarbonImmutable::today()->toDateString()],
            'reference_no'        => $mode !== null && PaymentModeRules::needsReference($mode)
                ? ['required', 'string', 'max:100']
                : ['nullable', 'string', 'max:100'],

            'pay_from_account_id' => ['required', 'integer'],
            'payment_mode_id'     => ['required', 'integer'],
            // Same rule as Supplier Payment: a cheque leaf number is 6 digits.
            'cheque_no'           => $isCheque
                ? ['required', 'string', 'regex:/^\d{6}$/']
                : ['nullable'],
            'cheque_date'         => $isCheque
                ? ['required', 'date_format:Y-m-d']
                : ['nullable'],

            'description'         => ['nullable', 'string', 'max:1000'],

            'allocations'           => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.bill_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount'  => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:' . self::MAX_AMOUNT],

            'updated_at'          => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->checkCompanyAccess($v);
            $this->checkLockDate($v);
            $this->checkPaymentMode($v);
            $this->checkPayFromAccount($v);
            $this->checkChequeDate($v);
            $this->checkChequeNo($v);
            $this->checkAllocations($v);
        });
    }

    private function checkCompanyAccess(Validator $v): void
    {
        if (! app(CompanyAccessService::class)->canAccess($this->user(), (int) $this->input('company_id'))) {
            $v->errors()->add('company_id', 'You do not have access to this company.');
        }
    }

    private function checkLockDate(Validator $v): void
    {
        $controls = app(FinanceControlService::class);

        if (! $controls->isOpen(CarbonImmutable::parse((string) $this->input('payment_date')))) {
            $v->errors()->add(
                'payment_date',
                'Period closed up to ' . $controls->lockDate()?->format('d-m-Y') . '. Choose a later date.',
            );
        }
    }

    private function mode(): ?PaymentMode
    {
        if (! $this->modeResolved) {
            $this->modeResolved = true;
            $id = $this->input('payment_mode_id');
            $this->mode = is_numeric($id) ? PaymentMode::query()->find((int) $id) : null;
        }

        return $this->mode;
    }

    /** An active mode from the shared master that moves money (not a set-off). */
    private function checkPaymentMode(Validator $v): void
    {
        $mode = $this->mode();

        if ($mode === null || ! PaymentModeRules::isUsable($mode)) {
            $v->errors()->add('payment_mode_id', 'Choose an active payment mode.');
        }
    }

    /**
     * The account the money leaves: an active Cash Book or Bank account of
     * this company (or shared), and it must suit the payment mode.
     */
    private function checkPayFromAccount(Validator $v): void
    {
        $account = LedgerAccount::query()
            ->payFrom()
            ->whereKey((int) $this->input('pay_from_account_id'))
            ->first();

        if ($account === null) {
            $v->errors()->add('pay_from_account_id', 'Choose an active Cash Book or Bank account.');

            return;
        }

        if ($account->company_id !== null && $account->company_id !== (int) $this->input('company_id')) {
            $v->errors()->add('pay_from_account_id', 'This account belongs to another company.');

            return;
        }

        $mode = $this->mode();

        if ($mode !== null && PaymentModeRules::isUsable($mode) && ! PaymentModeRules::accountSuits($mode, $account)) {
            $v->errors()->add('pay_from_account_id', PaymentModeRules::wrongAccountMessage($mode));
        }
    }

    private function checkChequeDate(Validator $v): void
    {
        if (! $this->filled('cheque_date')) {
            return;
        }

        $cheque  = CarbonImmutable::parse((string) $this->input('cheque_date'));
        $payment = CarbonImmutable::parse((string) $this->input('payment_date'));

        if ($cheque->lessThan($payment->subDays(self::CHEQUE_STALE_DAYS))) {
            $v->errors()->add('cheque_date', 'This cheque would be stale (older than 6 months).');
        } elseif ($cheque->greaterThan($payment->addYear())) {
            $v->errors()->add('cheque_date', 'The cheque date cannot be more than one year after the payment date.');
        }
    }

    /** A cheque number is used once per bank account, voided payments excepted. */
    private function checkChequeNo(Validator $v): void
    {
        if (! $this->filled('cheque_no')) {
            return;
        }

        $current = $this->route('bill_payment');

        $existing = app(BillPaymentService::class)->chequeAlreadyUsed(
            (int) $this->input('pay_from_account_id'),
            (string) $this->input('cheque_no'),
            $current instanceof BillPayment ? $current->id : null,
            $this->input('request_id'),
        );

        if ($existing !== null) {
            $v->errors()->add('cheque_no', 'Cheque ' . $this->input('cheque_no') . ' is already used on ' . $existing . '.');
        }
    }

    /**
     * Each bill: confirmed, this supplier's, this company's, and the amount no
     * more than what is still owed on it. No advances in this release.
     */
    private function checkAllocations(Validator $v): void
    {
        $rows  = (array) $this->input('allocations', []);
        $ids   = array_map(static fn ($r) => (int) ($r['bill_id'] ?? 0), $rows);
        $bills = Bill::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($rows as $i => $row) {
            /** @var Bill|null $bill */
            $bill = $bills->get((int) ($row['bill_id'] ?? 0));

            if ($bill === null
                || $bill->supplier_id !== (int) $this->input('supplier_id')
                || $bill->company_id !== (int) $this->input('company_id')) {
                $v->errors()->add("allocations.{$i}.bill_id", 'This bill is not an open bill of the selected supplier and company.');

                continue;
            }

            if ($bill->status !== BillStatus::Confirmed) {
                $v->errors()->add("allocations.{$i}.bill_id", $bill->bill_no . ' is ' . $bill->status->label() . ' and cannot be paid.');

                continue;
            }

            // A bill cannot be paid before it exists. Both are Y-m-d, so they compare as strings.
            if ((string) $this->input('payment_date') < $bill->transaction_date->toDateString()) {
                $v->errors()->add(
                    "allocations.{$i}.bill_id",
                    $bill->bill_no . ' is dated ' . $bill->transaction_date->format('d-m-Y') . '. The payment date cannot be before the bill date.',
                );

                continue;
            }

            if (Bill::toCents((string) $row['amount']) > Bill::toCents($bill->balance())) {
                $v->errors()->add(
                    "allocations.{$i}.amount",
                    'More than the ' . number_format((float) $bill->balance(), 2) . ' still owed on ' . $bill->bill_no . '.',
                );
            }
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id'           => 'vendor',
            'company_id'            => 'company',
            'payment_date'          => 'payment date',
            'reference_no'          => 'reference no',
            'pay_from_account_id'   => 'pay-from account',
            'payment_mode_id'       => 'payment mode',
            'cheque_no'             => 'cheque no',
            'cheque_date'           => 'cheque date',
            'allocations.*.bill_id' => 'bill',
            'allocations.*.amount'  => 'payment amount',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_date.before_or_equal'   => 'The payment date cannot be in the future.',
            'cheque_no.regex'                => 'The cheque number must be exactly 6 digits.',
            'reference_no.required'          => 'Enter the bank reference number for this payment mode.',
            'allocations.required'           => 'Select at least one bill to pay.',
            'allocations.min'                => 'Select at least one bill to pay.',
            'allocations.*.bill_id.distinct' => 'A bill may appear only once on a payment.',
            'allocations.*.amount.gt'        => 'The payment amount must be greater than zero.',
            'allocations.*.amount.decimal'   => 'The payment amount can have at most 2 decimal places.',
            'allocations.*.amount.max'       => 'The payment amount is too large.',
        ];
    }
}
