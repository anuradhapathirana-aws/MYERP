<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Services\CompanyAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BankDepositService;
use Modules\Finance\Services\FinanceControlService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bank deposit, create or update.
 *
 * The security boundary for the document, as BillPaymentRequest is for
 * payments. The cheque and cash rules live in BankDepositService so confirm
 * can run exactly the same checks again under row locks.
 *
 * Only the fields in rules() reach the DTO. deposit_no, status and the totals
 * are not in it, and a cheque is named by its id alone: its amount, number and
 * date are read from the receipt, so the client cannot restate them.
 */
class BankDepositRequest extends FormRequest
{
    public const MAX_AMOUNT = BillRequest::MAX_AMOUNT;

    public const MAX_CHEQUES = 500;

    public function authorize(): bool
    {
        $deposit = $this->route('bank_deposit');

        if ($deposit instanceof BankDeposit && ! app(CompanyAccessService::class)->canAccess($this->user(), $deposit->company_id)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = static fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v;

        $this->merge(array_filter([
            'slip_no'     => $clean($this->input('slip_no')),
            'description' => $clean($this->input('description')),
        ], static fn ($v) => $v !== null));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'request_id'      => ['nullable', 'uuid'],

            'company_id'      => ['required', 'integer', Rule::exists('inv_companies', 'id')],
            // Money is banked when it is banked; a deposit is not recorded ahead.
            'deposit_date'    => ['required', 'date_format:Y-m-d', 'before_or_equal:' . CarbonImmutable::today()->toDateString()],
            'bank_account_id' => ['required', 'integer'],
            'slip_no'         => ['nullable', 'string', 'max:50'],
            'description'     => ['nullable', 'string', 'max:1000'],

            'cash_account_id' => ['nullable', 'integer'],
            'cash_amount'     => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:' . self::MAX_AMOUNT],

            'cheque_ids'      => ['nullable', 'array', 'max:' . self::MAX_CHEQUES],
            'cheque_ids.*'    => ['required', 'integer', 'distinct'],

            'updated_at'      => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->checkSomethingDeposited($v);
            $this->checkCompanyAccess($v);
            $this->checkLockDate($v);
            $this->checkBankAccount($v);

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $service = app(BankDepositService::class);
            $current = $this->depositBeingSaved();

            $errors = [
                ...$service->cashErrors(
                    $this->filled('cash_account_id') ? (int) $this->input('cash_account_id') : null,
                    number_format((float) $this->input('cash_amount', 0), 2, '.', ''),
                    (int) $this->input('company_id'),
                    (string) $this->input('deposit_date'),
                ),
                ...$service->chequeErrors(
                    array_values(array_map('intval', (array) $this->input('cheque_ids', []))),
                    (int) $this->input('company_id'),
                    (string) $this->input('deposit_date'),
                    $current instanceof BankDeposit ? $current->id : null,
                ),
            ];

            foreach ($errors as $field => $message) {
                $v->errors()->add($field, $message);
            }
        });
    }

    /**
     * The deposit being edited, or the one an idempotent retry of this create
     * already made. Either way its own cheques still count as in hand, or a
     * double-clicked Save would be refused by the copy that got there first.
     */
    private function depositBeingSaved(): ?BankDeposit
    {
        $current = $this->route('bank_deposit');

        if ($current instanceof BankDeposit) {
            return $current;
        }

        $requestId = $this->input('request_id');

        return is_string($requestId) && $requestId !== ''
            ? BankDeposit::withTrashed()->where('request_id', $requestId)->first()
            : null;
    }

    private function checkSomethingDeposited(Validator $v): void
    {
        if ((float) $this->input('cash_amount', 0) <= 0 && (array) $this->input('cheque_ids', []) === []) {
            $v->errors()->add('cheque_ids', 'Enter a cash amount or select at least one cheque to deposit.');
        }
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

        if (! $controls->isOpen(CarbonImmutable::parse((string) $this->input('deposit_date')))) {
            $v->errors()->add(
                'deposit_date',
                'Period closed up to ' . $controls->lockDate()?->format('d-m-Y') . '. Choose a later date.',
            );
        }
    }

    /** An active Bank account of this company. */
    private function checkBankAccount(Validator $v): void
    {
        $account = LedgerAccount::query()->bankAccounts()->whereKey((int) $this->input('bank_account_id'))->first();

        if ($account === null) {
            $v->errors()->add('bank_account_id', 'Choose an active Bank account.');
        } elseif ($account->company_id !== (int) $this->input('company_id')) {
            $v->errors()->add('bank_account_id', 'This bank account belongs to another company.');
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_id'      => 'company',
            'deposit_date'    => 'deposit date',
            'bank_account_id' => 'bank account',
            'slip_no'         => 'slip no',
            'cash_account_id' => 'cash book',
            'cash_amount'     => 'cash amount',
            'cheque_ids'      => 'cheques',
            'cheque_ids.*'    => 'cheque',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'deposit_date.before_or_equal' => 'The deposit date cannot be in the future.',
            'cash_amount.decimal'          => 'The cash amount can have at most 2 decimal places.',
            'cash_amount.max'              => 'The cash amount is too large.',
            'cheque_ids.max'               => 'A deposit can carry at most ' . self::MAX_CHEQUES . ' cheques.',
            'cheque_ids.*.distinct'        => 'A cheque may appear only once on a deposit.',
        ];
    }
}
