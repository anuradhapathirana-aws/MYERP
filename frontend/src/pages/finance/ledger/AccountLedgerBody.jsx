import { Columns2 } from 'lucide-react'
import Pagination from '../../../components/ui/Pagination'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS, LABEL_CLS } from '../../../utils/fieldStyles'
import LedgerLinesTable from './LedgerLinesTable'
import TAccountView from './TAccountView'

/**
 * Everything below an account's header: the list filters, the lines (as a
 * running-balance table or a T-account) and the pager. The Trial Balance
 * popup and each Ledger Details card wrap this in their own header.
 *
 * @param {object}  ledger     the useAccountLedger() result
 * @param {string}  view       'list' | 't_account'
 * @param {object}  filters    the report's period / location filters
 * @param {object}  account    fallback account info until the first page loads
 * @param {string}  scrollCls  classes for the scrolling table area (height cap)
 * @param {boolean} autoFocus  focus the search box (popup only)
 */
export default function AccountLedgerBody({ ledger, view, filters, account, scrollCls = '', autoFocus = false }) {
  const { isLoading, isFetching, isError, info, rows, meta, summary, page, isFirst, isLast, filtered } = ledger
  const isList = view === 'list'

  return (
    <>
      {!isList && (
        <p className="mt-2 flex items-center gap-2 rounded-md border border-indigo-100 bg-indigo-50/60 px-2 py-1 text-[11px] text-indigo-800">
          <Columns2 size={13} className="shrink-0" />
          Debits on the left (<b>To</b>), credits on the right (<b>By</b>). Balance c/d sits on the smaller side so both
          sides agree. Search and document filters are available in List view.
          {isFetching && !isLoading && <span className="ml-auto text-slate-400">Updating…</span>}
        </p>
      )}

      {isList && (
        <div className="grid grid-cols-1 gap-2 pt-2 sm:grid-cols-3 lg:grid-cols-4">
          <div>
            <label className={LABEL_CLS}>Search</label>
            <input
              type="text"
              value={ledger.searchInput}
              onChange={(e) => ledger.setSearchInput(e.target.value)}
              placeholder="Journal no, narration, description"
              className={FILTER_INPUT_CLS}
              autoFocus={autoFocus}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Document Type</label>
            <select
              value={ledger.sourceType}
              onChange={(e) => ledger.setSourceType(e.target.value)}
              className={FILTER_SELECT_CLS}
            >
              <option value="">All</option>
              {ledger.sourceTypes.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>
          <div className="flex items-end gap-2">
            {ledger.hasListFilters && (
              <button
                type="button"
                onClick={ledger.clearListFilters}
                className="rounded-md border-2 border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
              >
                Clear
              </button>
            )}
            {isFetching && !isLoading && <span className="pb-1 text-[11px] text-slate-400">Updating…</span>}
          </div>
        </div>
      )}

      {filtered && isList && (
        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-700">
          Filtered view: the running balance is hidden because it only makes sense over every transaction.
          The balances above are still for the whole account.
        </p>
      )}

      {isList ? (
        <div className={`mt-2 overflow-auto rounded-lg border border-slate-200 ${scrollCls}`}>
          <LedgerLinesTable ledger={ledger} dateFrom={filters.date_from} />
        </div>
      ) : (
        <div className={`mt-2 overflow-auto ${scrollCls}`}>
          {isLoading && <p className="py-6 text-center text-xs text-slate-400">Loading…</p>}
          {isError && <p className="py-6 text-center text-xs text-red-500">Could not load the transactions.</p>}
          {!isLoading && !isError && (info ?? account) && (
            <TAccountView
              account={info ?? account}
              rows={rows}
              meta={meta}
              summary={summary}
              filters={filters}
              page={page}
              isFirst={isFirst}
              isLast={isLast}
            />
          )}
        </div>
      )}

      <Pagination meta={meta} page={page} onPageChange={ledger.setPage} />
    </>
  )
}
