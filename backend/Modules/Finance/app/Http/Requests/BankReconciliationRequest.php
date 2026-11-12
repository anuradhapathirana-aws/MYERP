<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Services\CompanyAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\ReconLineStatus;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\LedgerAccount;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bank reconciliation, create or update (always a draft).
 *
 * The shape and the cheap checks live here; whether each line is still an
 * uncleared bank line of this account, and the statement-date ordering, are
 * checked by BankReconciliationService under a lock on the bank account, so a
 * post running at the same moment cannot slip between the two.
 */
class BankReconciliationRequest extends FormRequest
{
    public const MAX_AMOUNT = BillRequest::MAX_AMOUNT;

    public const MAX_LINES = 3000;

    public function authorize(): bool
    {
        $recon = $this->route('bank_reconciliation');

        if ($recon instanceof BankReconciliation && ! app(CompanyAccessService::class)->canAccess($this->user(), $recon->company_id)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim(strip_tags($this->input('notes')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $today = CarbonImmutable::today()->toDateString();

        return [
            'request_id'        => ['nullable', 'uuid'],
            'company_id'        => ['required', 'integer', Rule::exists('inv_companies', 'id')],
            'bank_account_id'   => ['required', 'integer'],
            'date_from'         => ['nullable', 'date_format:Y-m-d', 'before_or_equal:statement_date'],
            // A statement cannot be dated ahead of today.
            'statement_date'    => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            // Signed: an overdraft opens or closes below zero.
            'opening_balance'   => ['nullable', 'numeric', 'decimal:0,2', 'min:-' . self::MAX_AMOUNT, 'max:' . self::MAX_AMOUNT],
            'statement_balance' => ['required', 'numeric', 'decimal:0,2', 'min:-' . self::MAX_AMOUNT, 'max:' . self::MAX_AMOUNT],
            'notes'             => ['nullable', 'string', 'max:1000'],

            'lines'                   => ['nullable', 'array', 'max:' . self::MAX_LINES],
            'lines.*.journal_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.status'          => ['required', Rule::enum(ReconLineStatus::class)],
            // The day the bank returned a cheque; the bounce entry is dated on it.
            // Defaults to the statement date. Checked against the period by the service.
            'lines.*.return_date'     => ['nullable', 'date_format:Y-m-d', 'before_or_equal:statement_date'],

            'updated_at'        => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (! app(CompanyAccessService::class)->canAccess($this->user(), (int) $this->input('company_id'))) {
                $v->errors()->add('company_id', 'You do not have access to this company.');

                return;
            }

            $account = LedgerAccount::query()->bankAccounts()->whereKey((int) $this->input('bank_account_id'))->first();

            if ($account === null) {
                $v->errors()->add('bank_account_id', 'Choose an active Bank account.');
            } elseif ($account->company_id !== (int) $this->input('company_id')) {
                $v->errors()->add('bank_account_id', 'This bank account belongs to another company.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_id'              => 'company',
            'bank_account_id'         => 'bank account',
            'date_from'               => 'from date',
            'statement_date'          => 'statement date',
            'opening_balance'         => 'opening balance',
            'statement_balance'       => 'statement balance',
            'lines.*.journal_line_id' => 'entry',
            'lines.*.status'          => 'status',
            'lines.*.return_date'     => 'return date',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'statement_date.before_or_equal' => 'The statement date cannot be in the future.',
            'date_from.before_or_equal'      => 'The from date must be on or before the statement date.',
            'statement_balance.decimal'      => 'The statement balance can have at most 2 decimal places.',
            'lines.*.journal_line_id.distinct' => 'An entry may appear only once.',
            'lines.*.return_date.before_or_equal' => 'A cheque cannot be returned after the statement date.',
        ];
    }
}
