import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle, Banknote, Boxes, ClipboardList, Clock, CreditCard, FileWarning, HandCoins,
  Landmark, LayoutDashboard, PackageCheck, PackageX, RefreshCw, ShoppingCart, TrendingUp,
  Truck, Users, Wallet,
} from 'lucide-react'
import { getMe } from '../api/system'
import { readProfile } from '../utils/session'
import {
  getCashDashboard, getOrdersDashboard, getPayablesDashboard, getReceivablesDashboard, getSalesDashboard, getStockDashboard,
} from '../api/dashboard'
import Money from '../components/ui/Money'
import KeyboardShortcuts from '../components/ui/KeyboardShortcuts'
import {
  BarList, Delta, EmptyState, ErrorState, KpiCard, Panel, PanelSkeleton,
} from '../components/dashboard/DashboardUi'
import {
  DonutWithList, MiniDonut, PurchaseBars, SalesTrendChart,
} from '../components/dashboard/DashboardCharts'
import { SERIES, agingColor, seriesColor } from '../components/dashboard/dashboardTheme'
import { CalcNote } from '../components/dashboard/CalcNote'

const PERIODS = [
  { key: 'today', label: 'Today',      compare: 'yesterday' },
  { key: 'week',  label: 'This Week',  compare: 'last week' },
  { key: 'month', label: 'This Month', compare: 'same days last month' },
  { key: 'year',  label: 'This Year',  compare: 'same days last year' },
]

const STALE = 60_000

const SHORTCUTS = [
  { keys: ['1'], desc: 'Today' },
  { keys: ['2'], desc: 'This Week' },
  { keys: ['3'], desc: 'This Month' },
  { keys: ['4'], desc: 'This Year' },
  { keys: ['R'], desc: 'Refresh figures' },
]

function greeting() {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
}

// ── "How this is calculated" notes ──────────────────────────────────────────
// Each card and panel explains its figure with the user's own numbers, so a
// total can be checked by hand against the report named at the bottom.

const fmtDate = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const periodText = (p) => (p ? (p.from === p.to ? fmtDate(p.from) : `${fmtDate(p.from)} – ${fmtDate(p.to)}`) : '')

const agingAmount = (d, key) => d.aging.find((a) => a.key === key)?.amount ?? 0

function netSalesNote(d, compare) {
  const k = d.kpis
  return (
    <CalcNote
      title={`Net Sales · ${periodText(d.period)}`}
      formula="Invoiced − Customer returns = Net Sales"
      lines={[
        { label: 'Invoiced', count: `${k.invoice_count} invoice${k.invoice_count === 1 ? '' : 's'}`, value: k.invoiced },
        { op: '−', label: 'Returns on these invoices', value: k.returns },
        { op: '=', label: 'Net Sales', value: k.net_sales },
      ]}
      points={[
        'Invoiced = grand total (incl. tax & transport) of invoices dated in this period with status Issued, Paid or Returned.',
        'Draft and Cancelled invoices are not counted.',
        'Returns = confirmed Customer Returns against those same invoices, dated up to the end of the period. A return against an older invoice is deducted in that invoice\'s period, not this one.',
        `The % compares with the ${compare}.`,
      ]}
      source="Sales Summary report → Net Sale (same dates)"
    />
  )
}

function collectionsNote(d) {
  const modes = d.collection_modes ?? []
  return (
    <CalcNote
      title={`Collections · ${periodText(d.period)}`}
      formula="Sum of money received on confirmed Customer Receipts"
      lines={[
        ...modes.map((m, i) => ({ op: i === 0 ? '' : '+', label: m.mode, value: m.amount })),
        { op: '=', label: 'Collections', value: d.kpis.collections },
      ]}
      points={[
        'Counted by receipt date. Draft and Voided receipts are not counted.',
        'Includes advance receipts. A cheque counts on the day it was received, not when it clears.',
        'A cheque the bank later returns is not deducted here (it re-opens the invoice instead).',
        'Set-offs against credit notes are not money received, so they are not counted.',
      ]}
      source="Sales Summary report → Total Collected (same dates)"
    />
  )
}

function receivablesNote(d) {
  return (
    <CalcNote
      title="Customers Owe Us · as of today"
      formula="Invoice total − Received − Returned, for every unpaid invoice"
      lines={[
        { label: 'Not yet due', value: agingAmount(d, 'current') },
        { op: '+', label: 'Past due date', value: d.overdue },
        { op: '+', label: 'No due date on invoice', value: agingAmount(d, 'no_due') },
        { op: '=', label: 'Total owed', count: `${d.documents} invoices · ${d.parties} customers`, value: d.total },
      ]}
      points={[
        'Only Issued invoices with a balance left (Paid, Draft and Cancelled owe nothing).',
        'Received = confirmed receipts and discounts given; a returned (bounced) cheque is added back.',
        'An invoice without a due date is never counted as past due.',
        'Not limited to the selected period: this is everything still unpaid.',
      ]}
      source="Outstanding Summary report → Total Outstanding"
    />
  )
}

function payablesNote(d) {
  return (
    <CalcNote
      title="We Owe Suppliers · as of today"
      formula="GRN total − Paid − Returned, for every unpaid GRN"
      lines={[
        { label: 'Not yet due', value: agingAmount(d, 'current') },
        { op: '+', label: 'Past due date', value: d.overdue },
        { op: '+', label: 'No payment terms', value: agingAmount(d, 'no_due') },
        { op: '=', label: 'Total owed', count: `${d.documents} GRNs · ${d.parties} suppliers`, value: d.total },
      ]}
      points={[
        'Only confirmed GRNs. Paid = confirmed Supplier Payments and discounts taken; a returned cheque is added back.',
        'Returned = confirmed Supplier Returns against the GRN.',
        'Due date = GRN date + the GRN\'s payment terms (Net 30 = 30 days, Immediate / COD = same day); if the GRN has none, the supplier\'s credit period.',
        'Supplier advances and credit notes are not deducted. Supplier bills entered in Finance are shown separately under "Supplier Bills to Pay".',
      ]}
      source="Supplier Payments (outstanding GRNs per supplier)"
    />
  )
}

function stockNote(d) {
  return (
    <CalcNote
      title="Stock Value · as of today"
      formula="Σ (Quantity on hand × Average cost)"
      lines={[
        { label: 'Stock value', value: d.stock_value },
      ]}
      points={[
        'Quantity on hand = current stock in every store and location.',
        'Average cost = total cost of all stock received (GRNs and customer returns) ÷ total quantity received.',
        d.alerts.tracked_count
          ? `Below the card: ${d.alerts.out_count} product(s) out of stock and ${d.alerts.low_count} low, out of ${d.alerts.tracked_count} with a reorder level.`
          : 'No product has a reorder level yet, so no low-stock alert can show. Set it on the product.',
      ]}
      source="Stock Valuation report → Total Value"
    />
  )
}

function cashNote(d) {
  return (
    <CalcNote
      title="Cash & Bank · available now"
      formula="Cash books + Bank accounts"
      lines={[
        { label: 'Cash books', value: d.cash_total },
        { op: '+', label: 'Bank accounts', value: d.bank_total },
        { op: '=', label: 'Cash & Bank', value: d.cash_total + d.bank_total },
      ]}
      points={[
        'Balance = posted debits − credits in each account\'s ledger (Finance).',
        'Only money posted in Finance counts: receipts and payments recorded before Finance was switched on, or without a cash/bank account, are not included until opening balances are entered.',
        `Cash books exclude cheques not yet banked (${d.cheques_in_hand.toLocaleString(undefined, { minimumFractionDigits: 2 })} shown separately as cheques in hand) and never go below zero.`,
        'A bank can be negative when it is overdrawn. Only the companies you have access to.',
      ]}
      source="Ledger of each cash / bank account, Uncleared Cheques report"
    />
  )
}

const PANEL_NOTES = {
  trend: (
    <CalcNote title="Sales Trend" formula="Per month: Invoiced − Returns on that month's invoices"
      points={[
        'Each point is one calendar month, last 12 months, current month so far.',
        'Each month equals the Sales Summary report\'s Net Sale run for that month.',
        'Collections (when shown) = money received on confirmed receipts that month.',
        'Hover a month to see its exact amounts.',
      ]}
      source="Sales Summary report" />
  ),
  receivablesAging: (
    <CalcNote title="Receivables Aging" formula="Days late = today − due date"
      points={[
        'Current = not yet due. 1–30, 31–60, 61–90, 90+ = days past the due date.',
        'No due date = the invoice has no due date, so it cannot be late.',
        'The buckets add up to the "Customers Owe Us" card.',
      ]}
      source="Outstanding Summary report" />
  ),
  topProducts: (
    <CalcNote title="Top Products" formula="Σ invoice line totals per product"
      points={[
        'Invoices dated in the selected period (Issued, Paid, Returned).',
        'All colours / variants of a product are added together; returns are not deducted here.',
        'Top 5 by value.',
      ]}
      source="Sales by Item Summary report (add up the product's colour rows)" />
  ),
  topCustomers: (
    <CalcNote title="Top Customers" formula="Σ invoice grand totals per customer"
      points={[
        'Invoices dated in the selected period (Issued, Paid, Returned) — paid or not.',
        'Returns are not deducted here.',
        'Top 5 by value.',
      ]}
      source="Invoices list filtered by customer and date (the Sales by Customer report shows Paid invoices only)" />
  ),
  collectionModes: (
    <CalcNote title="Collections by Method" formula="Σ receipt payment lines per payment method"
      points={[
        'Confirmed receipts dated in the selected period, grouped by payment mode.',
        'All methods add up to the Collections card.',
      ]}
      source="Sales Summary report" />
  ),
  stockAlerts: (
    <CalcNote title="Stock Alerts" formula="Quantity on hand ≤ Reorder level"
      points={[
        'Out = nothing left. Low = some left but at or below the reorder level.',
        'Quantity is the total over every store and location (the Low Stock report lists each store separately).',
        'Only products with a reorder level set. Shows the 8 worst.',
      ]}
      source="Low Stock report" />
  ),
  purchasing: (
    <CalcNote title="Purchasing"
      points={[
        'Requests to approve = purchase requests in Submitted status.',
        'Open orders = POs Sent, Confirmed or Partially Received; value = full PO total (not only what is still to come).',
        'Past delivery date = open POs whose expected delivery date has passed.',
        'Goods received = confirmed GRNs dated in the selected period. The chart shows confirmed GRN totals per month.',
      ]}
      source="Purchase Orders list (Outstanding POs report leaves out Sent POs)" />
  ),
  payablesAging: (
    <CalcNote title="Payables Aging" formula="Days late = today − (GRN date + payment terms)"
      points={[
        'Current = not yet due. Other buckets = days past due.',
        'Payment terms from the GRN (Net 30, Net 90, COD…), else the supplier\'s credit period. No terms anywhere = "No due date".',
        'The buckets add up to the "We Owe Suppliers" card.',
      ]}
      source="Supplier Payments" />
  ),
  cash: (
    <CalcNote title="Cash & Bank accounts"
      points={[
        'Each active cash book and bank account with its balance now, as posted in Finance.',
        'Cash books: balance less cheques received but not yet banked.',
        'Cheques in hand = received cheques not yet on a confirmed bank deposit.',
      ]}
      source="Ledger accounts, Uncleared Cheques report" />
  ),
  bills: (
    <CalcNote title="Supplier Bills to Pay" formula="Balance = Bill total − Paid"
      points={[
        'Only confirmed bills not fully paid. Draft and Voided bills are not counted.',
        'Overdue = due date before today. Due in next 7 days = due today up to 7 days ahead.',
      ]}
      source="Bills list" />
  ),
}

/** What this user may see: module switched on AND the permissions each section needs. */
function useAccess() {
  const { data: me } = useQuery({ queryKey: ['me'], queryFn: getMe, staleTime: STALE })
  const profile = me ?? readProfile()

  return useMemo(() => {
    const roles   = profile.roles ?? []
    const perms   = profile.permissions ?? []
    const modules = profile.active_modules ?? []
    const isSuper = roles.includes('super_admin')
    const can     = (...need) => isSuper || need.every((p) => perms.includes(p))
    const inv     = modules.includes('inventory') && can('view_dashboard')
    const fin     = modules.includes('finance') && can('view_dashboard')

    return {
      name:        profile.user_name ?? '',
      sales:       inv && can('view_invoices'),
      orders:      inv && can('view_sales_orders'),
      receivables: inv && can('view_invoices'),
      payables:    inv && can('view_supplier_payments'),
      stock:       inv && can('view_reports'),
      cash:        fin && can('view_finance_reports'),
    }
  }, [profile])
}

export default function DashboardPage() {
  const queryClient = useQueryClient()
  const access      = useAccess()
  const [period, setPeriod] = useState('month')
  const periodInfo  = PERIODS.find((p) => p.key === period)

  const sales       = useQuery({ queryKey: ['dashboard', 'sales', period], queryFn: () => getSalesDashboard(period), enabled: access.sales, staleTime: STALE, placeholderData: (prev) => prev })
  const orders      = useQuery({ queryKey: ['dashboard', 'orders', period], queryFn: () => getOrdersDashboard(period), enabled: access.orders, staleTime: STALE, placeholderData: (prev) => prev })
  const receivables = useQuery({ queryKey: ['dashboard', 'receivables'], queryFn: getReceivablesDashboard, enabled: access.receivables, staleTime: STALE })
  const payables    = useQuery({ queryKey: ['dashboard', 'payables'], queryFn: getPayablesDashboard, enabled: access.payables, staleTime: STALE })
  const stock       = useQuery({ queryKey: ['dashboard', 'stock', period], queryFn: () => getStockDashboard(period), enabled: access.stock, staleTime: STALE, placeholderData: (prev) => prev })
  const cash        = useQuery({ queryKey: ['dashboard', 'cash'], queryFn: getCashDashboard, enabled: access.cash, staleTime: STALE })

  const all       = [sales, orders, receivables, payables, stock, cash]
  const fetching  = all.some((q) => q.isFetching)
  const updatedAt = Math.max(0, ...all.map((q) => q.dataUpdatedAt || 0))

  const refresh = useCallback(
    () => queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
    [queryClient],
  )

  useEffect(() => {
    const onKey = (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return
      if (e.target.closest?.('input, textarea, select, [contenteditable="true"]')) return
      const idx = ['1', '2', '3', '4'].indexOf(e.key)
      if (idx >= 0) { setPeriod(PERIODS[idx].key); e.preventDefault() }
      if (e.key === 'r' || e.key === 'R') { refresh(); e.preventDefault() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [refresh])

  const nothing = !Object.entries(access).some(([k, v]) => k !== 'name' && v)
  const k       = sales.data?.kpis
  const showCollections = k?.collections !== undefined

  return (
    <div className="w-full">
      {/* ── Header ─────────────────────────────────────────────────────── */}
      <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <LayoutDashboard size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Dashboard</h1>
          </div>
          <p className="mt-1 text-[11px] text-slate-500">
            {greeting()}{access.name ? `, ${access.name}` : ''} ·{' '}
            {new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
          </p>
        </div>

        {!nothing && (
          <div className="flex flex-wrap items-center gap-2">
            <div role="tablist" aria-label="Period" className="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm">
              {PERIODS.map((p) => (
                <button
                  key={p.key}
                  type="button"
                  role="tab"
                  aria-selected={period === p.key}
                  onClick={() => setPeriod(p.key)}
                  className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${
                    period === p.key ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={refresh}
              title="Refresh (R)"
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 shadow-sm hover:bg-slate-50"
            >
              <RefreshCw size={13} className={fetching ? 'animate-spin text-indigo-500' : ''} />
              {updatedAt ? `Updated ${new Date(updatedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : 'Refresh'}
            </button>
          </div>
        )}
      </div>

      {nothing ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
          <LayoutDashboard size={28} className="mx-auto text-slate-300" />
          <p className="mt-2 text-sm font-semibold text-slate-700">Welcome{access.name ? `, ${access.name}` : ''}</p>
          <p className="mt-1 text-xs text-slate-500">
            No dashboard sections are assigned to you yet. Use the menu on the left, or ask your administrator for dashboard access.
          </p>
        </div>
      ) : (
        <>
          {/* ── KPI row ─────────────────────────────────────────────────── */}
          <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
            {access.sales && (
              <KpiCard icon={TrendingUp} tone="indigo" label={`Net Sales · ${periodInfo.label}`} loading={sales.isLoading} error={sales.isError} onRetry={sales.refetch}
                       value={k?.net_sales} to="/inventory/invoices"
                       note={sales.data && netSalesNote(sales.data, periodInfo.compare)}
                       footer={<Delta current={k?.net_sales} previous={k?.net_sales_previous} label={periodInfo.compare} />} />
            )}
            {access.sales && showCollections && (
              <KpiCard icon={HandCoins} tone="emerald" label={`Collections · ${periodInfo.label}`} loading={sales.isLoading} error={sales.isError} onRetry={sales.refetch}
                       value={k?.collections} to="/inventory/customer-receipts"
                       note={sales.data && collectionsNote(sales.data)}
                       footer={<Delta current={k?.collections} previous={k?.collections_previous} label={periodInfo.compare} />} />
            )}
            {access.receivables && (
              <KpiCard icon={Users} tone="sky" label="Customers Owe Us" loading={receivables.isLoading} error={receivables.isError} onRetry={receivables.refetch}
                       value={receivables.data?.total} to="/inventory/reports/outstanding-summary"
                       note={receivables.data && receivablesNote(receivables.data)}
                       footer={<CountNote data={receivables.data} doc="unpaid invoice" party="customer" />} />
            )}
            {access.payables && (
              <KpiCard icon={Truck} tone="violet" label="We Owe Suppliers" loading={payables.isLoading} error={payables.isError} onRetry={payables.refetch}
                       value={payables.data?.total} to="/inventory/supplier-payments"
                       note={payables.data && payablesNote(payables.data)}
                       footer={<CountNote data={payables.data} doc="unpaid GRN" party="supplier" />} />
            )}
            {access.stock && (
              <KpiCard icon={Boxes} tone="amber" label="Stock Value" loading={stock.isLoading} error={stock.isError} onRetry={stock.refetch}
                       value={stock.data?.stock_value} to="/inventory/reports/stock-valuation"
                       note={stock.data && stockNote(stock.data)}
                       footer={<StockNote alerts={stock.data?.alerts} />} />
            )}
            {access.cash && (
              <KpiCard icon={Landmark} tone="emerald" label="Cash & Bank" loading={cash.isLoading} error={cash.isError} onRetry={cash.refetch}
                       value={(cash.data?.cash_total ?? 0) + (cash.data?.bank_total ?? 0)} to="/finance/bank-deposits"
                       note={cash.data && cashNote(cash.data)}
                       footer={cash.data && (
                         <span className="text-[10px] text-slate-500">
                           + <Money value={cash.data.cheques_in_hand} className="font-semibold text-slate-700" /> cheques in hand
                         </span>
                       )} />
            )}
          </div>

          {/* ── Panels ──────────────────────────────────────────────────── */}
          <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
            {access.sales && (
              <Panel icon={TrendingUp} title="Sales Trend" note={PANEL_NOTES.trend} subtitle="Last 12 months"
                     to="/inventory/reports/sales-summary" linkLabel="Report">
                <Section q={sales} height={185}>{(d) => <SalesTrendChart rows={d.trend} showCollections={showCollections} />}</Section>
              </Panel>
            )}

            {(access.orders || access.receivables) && (
              <Panel icon={ClipboardList} title="Sales Orders & Deliveries" note={ordersNote(periodInfo.label)}
                     subtitle={`Orders & deliveries · ${periodInfo.label} · pending invoices now`}>
                <OrdersPanel access={access} orders={orders} receivables={receivables} />
              </Panel>
            )}

            {access.receivables && (
              <Panel icon={Clock} title="Receivables Aging" note={PANEL_NOTES.receivablesAging} subtitle="Unpaid invoices by days past their due date"
                     to="/inventory/reports/outstanding-summary">
                <Section q={receivables}>
                  {(d) => (
                    <>
                      <DonutWithList rows={d.aging} colorOf={agingColor} centerLabel="Owed" emptyText="No unpaid invoices" />
                      {d.top.length > 0 && (
                        <div className="mt-2 border-t border-slate-100 pt-2">
                          <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Top customers owing</p>
                          <BarList rows={d.top.slice(0, 3)} color={SERIES[0]} />
                        </div>
                      )}
                    </>
                  )}
                </Section>
              </Panel>
            )}

            {access.sales && (
              <Panel icon={ShoppingCart} title="Top Products" note={PANEL_NOTES.topProducts} subtitle={`Highest sales value · ${periodInfo.label}`} to="/inventory/reports/sales-by-item-summary">
                <Section q={sales}>{(d) => <BarList rows={d.top_products} color={SERIES[0]} note={(r) => `${r.code ?? ''} · Qty ${Number(r.quantity).toLocaleString()}`} />}</Section>
              </Panel>
            )}

            {access.sales && (
              <Panel icon={Users} title="Top Customers" note={PANEL_NOTES.topCustomers} subtitle={`Highest invoiced value · ${periodInfo.label}`} to="/inventory/invoices" linkLabel="Invoices">
                <Section q={sales}>{(d) => <BarList rows={d.top_customers} color={SERIES[2]} note={(r) => `${r.invoices} invoice${r.invoices === 1 ? '' : 's'}`} />}</Section>
              </Panel>
            )}

            {access.sales && showCollections && (
              <Panel icon={CreditCard} title="Collections by Method" note={PANEL_NOTES.collectionModes} subtitle={`Money received · ${periodInfo.label}`} to="/inventory/reports/sales-summary">
                <Section q={sales}>
                  {(d) => (
                    <DonutWithList rows={(d.collection_modes ?? []).map((m) => ({ key: m.mode, label: m.mode, amount: m.amount }))}
                                   colorOf={seriesColor} centerLabel="Collected" emptyText="No money collected in this period" />
                  )}
                </Section>
              </Panel>
            )}

            {access.stock && (
              <Panel icon={AlertTriangle} title="Stock Alerts" note={PANEL_NOTES.stockAlerts} subtitle="Products at or below their reorder level" to="/inventory/reports/low-stock">
                <Section q={stock}>{(d) => <StockAlerts alerts={d.alerts} />}</Section>
              </Panel>
            )}

            {access.stock && (
              <Panel icon={ClipboardList} title="Purchasing" note={PANEL_NOTES.purchasing} subtitle="Requests, orders and goods received" to="/inventory/purchase-orders" linkLabel="Purchase orders">
                <Section q={stock}>{(d) => <Purchasing data={d} periodLabel={periodInfo.label} />}</Section>
              </Panel>
            )}

            {access.payables && (
              <Panel icon={Truck} title="Payables Aging" note={PANEL_NOTES.payablesAging} subtitle="Unpaid GRNs by days past their payment terms" to="/inventory/supplier-payments" linkLabel="Supplier payments">
                <Section q={payables}>
                  {(d) => (
                    <>
                      <DonutWithList rows={d.aging} colorOf={agingColor} centerLabel="We owe" emptyText="Nothing owed to suppliers" />
                      {d.top.length > 0 && (
                        <div className="mt-2 border-t border-slate-100 pt-2">
                          <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Top suppliers to pay</p>
                          <BarList rows={d.top.slice(0, 3)} color={SERIES[6]} />
                        </div>
                      )}
                    </>
                  )}
                </Section>
              </Panel>
            )}

            {access.cash && (
              <Panel icon={Wallet} title="Cash & Bank" note={PANEL_NOTES.cash} subtitle="Available now in each account" to="/finance/reports/uncleared-cheques" linkLabel="Uncleared cheques">
                <Section q={cash}>{(d) => <CashAccounts data={d} />}</Section>
              </Panel>
            )}

            {access.cash && (
              <Panel className="lg:col-span-2" icon={FileWarning} title="Supplier Bills to Pay" note={PANEL_NOTES.bills} subtitle="Confirmed bills not fully paid, earliest due first" to="/finance/bills" linkLabel="All bills">
                <Section q={cash}>{(d) => <BillsDue bills={d.bills} />}</Section>
              </Panel>
            )}
          </div>

          <KeyboardShortcuts shortcuts={SHORTCUTS} />
        </>
      )}
    </div>
  )
}

// ── Section helpers ──────────────────────────────────────────────────────────

/** Loading / error / content for one query, so every panel behaves the same. */
function Section({ q, height = 200, children }) {
  if (q.isLoading) return <PanelSkeleton height={height} />
  if (q.isError || !q.data) return <ErrorState onRetry={() => q.refetch()} />
  return children(q.data)
}

/** Plain "10 unpaid invoices · 6 customers" — what the amount is made of, no alarm colours. */
function CountNote({ data, doc, party }) {
  if (!data) return null
  if (!data.documents) return <span className="text-[10px] font-medium text-emerald-600">Nothing outstanding</span>
  const s = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`
  return <span className="text-[10px] text-slate-500">{s(data.documents, doc)} · {s(data.parties, party)}</span>
}

function StockNote({ alerts }) {
  if (!alerts) return null
  if (!alerts.tracked_count) return <span className="text-[10px] text-slate-400">No reorder levels set</span>
  if (!alerts.low_count && !alerts.out_count) return <span className="text-[10px] font-medium text-emerald-600">All above reorder level</span>
  return (
    <span className="text-[10px] text-slate-500">
      {alerts.out_count > 0 && <span className="font-semibold text-rose-600">{alerts.out_count} out</span>}
      {alerts.out_count > 0 && alerts.low_count > 0 && ' · '}
      {alerts.low_count > 0 && <span className="font-semibold text-amber-600">{alerts.low_count} low</span>}
      {' '}of stock
    </span>
  )
}

function StockAlerts({ alerts }) {
  return (
    <div>
      <div className="mb-2 grid grid-cols-2 gap-2">
        <MiniStat icon={PackageX} tone="rose" label="Out of stock" value={alerts.out_count} />
        <MiniStat icon={AlertTriangle} tone="amber" label="Low stock" value={alerts.low_count} />
      </div>
      {alerts.items.length === 0 ? (
        <EmptyState text={alerts.tracked_count ? "Every product is above its reorder level" : "No product has a reorder level set yet"} height={120} />
      ) : (
        <ul className="divide-y divide-slate-100">
          {alerts.items.map((p) => {
            const pct = Math.min(100, (p.quantity / (p.reorder_level || 1)) * 100)
            return (
              <li key={p.id} className="flex items-center gap-2 py-1">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-xs font-medium text-slate-700" title={p.name}>{p.name}</p>
                  <div className="mt-0.5 h-1 rounded-full bg-slate-100">
                    <div className={`h-1 rounded-full ${p.status === 'out' ? 'bg-rose-500' : 'bg-amber-500'}`} style={{ width: `${Math.max(pct, 2)}%` }} />
                  </div>
                </div>
                <span className="shrink-0 text-right text-[10px] tabular-nums text-slate-500">
                  {Number(p.quantity).toLocaleString()} / {Number(p.reorder_level).toLocaleString()}
                </span>
                <span className={`w-9 shrink-0 rounded-full px-1.5 py-px text-center text-[10px] font-semibold ${
                  p.status === 'out' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'
                }`}>
                  {p.status === 'out' ? 'Out' : 'Low'}
                </span>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

const MINI_TONES = {
  rose:    'border-rose-100 bg-rose-50/60 text-rose-700',
  amber:   'border-amber-100 bg-amber-50/60 text-amber-700',
  emerald: 'border-emerald-100 bg-emerald-50/60 text-emerald-700',
  indigo:  'border-indigo-100 bg-indigo-50/60 text-indigo-700',
  slate:   'border-slate-200 bg-slate-50 text-slate-600',
}

function MiniStat({ icon: Icon, tone, label, value, sub, to }) {
  const body = (
    <div className={`flex items-center gap-2 rounded-lg border px-2 py-1.5 ${MINI_TONES[tone]}`}>
      <Icon size={15} className="shrink-0" />
      <div className="min-w-0">
        <p className="text-base font-bold leading-none">{value}</p>
        <p className="truncate text-[10px] font-medium opacity-80">{label}</p>
        {sub && <p className="truncate text-[10px] opacity-80">{sub}</p>}
      </div>
    </div>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

function Purchasing({ data, periodLabel }) {
  const p = data.purchasing
  return (
    <div className="flex flex-col gap-2">
      <div className="grid grid-cols-2 gap-2">
        <MiniStat icon={ClipboardList} tone={p.pending_prs ? 'amber' : 'slate'} label="Requests to approve" value={p.pending_prs} to="/inventory/purchase-requests" />
        <MiniStat icon={ShoppingCart} tone="indigo" label="Open purchase orders" value={p.open_pos} sub={<Money value={p.open_po_value} />} to="/inventory/purchase-orders" />
        <MiniStat icon={Clock} tone={p.overdue_pos ? 'rose' : 'slate'} label="Orders past delivery date" value={p.overdue_pos} to="/inventory/reports/outstanding-pos" />
        <MiniStat icon={PackageCheck} tone="emerald" label={`Goods received · ${periodLabel}`} value={p.grn_count} sub={<Money value={p.grn_value} />} />
      </div>
      <div>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Purchases per month (goods received)</p>
        <PurchaseBars rows={data.purchase_trend} />
      </div>
      {data.recent_grns.length > 0 && (
        <div>
          <p className="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Latest goods received</p>
          <ul className="divide-y divide-slate-100">
            {data.recent_grns.map((g) => (
              <li key={g.id} className="flex items-center justify-between gap-2 py-1 text-xs">
                <span className="min-w-0 truncate">
                  <span className="font-medium text-indigo-600">{g.grn_no}</span>
                  <span className="text-slate-500"> · {g.supplier ?? '—'}</span>
                </span>
                <Money value={g.amount} className="shrink-0 font-semibold text-slate-700" />
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function CashAccounts({ data }) {
  return (
    <div>
      <div className="mb-2 grid grid-cols-3 gap-2">
        <MiniStat icon={Banknote} tone="emerald" label="Cash" value={<Money value={data.cash_total} />} />
        <MiniStat icon={Landmark} tone="indigo" label="Bank" value={<Money value={data.bank_total} />} />
        <MiniStat icon={CreditCard} tone="amber" label="Cheques in hand" value={<Money value={data.cheques_in_hand} />} />
      </div>
      {data.accounts.length === 0 ? (
        <EmptyState text="No cash or bank accounts" height={100} />
      ) : (
        <ul className="divide-y divide-slate-100">
          {data.accounts.map((a) => (
            <li key={a.id} className="flex items-center justify-between gap-2 py-1 text-xs">
              <span className="flex min-w-0 items-center gap-1.5">
                {a.type === 'bank' ? <Landmark size={13} className="shrink-0 text-indigo-500" /> : <Banknote size={13} className="shrink-0 text-emerald-600" />}
                <span className="truncate text-slate-700" title={a.name}>{a.name}</span>
              </span>
              <Money value={a.balance} className={`shrink-0 font-semibold ${a.balance < 0 ? 'text-rose-600' : 'text-slate-800'}`} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function BillsDue({ bills }) {
  return (
    <div>
      <div className="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
        <MiniStat icon={AlertTriangle} tone={bills.overdue_count ? 'rose' : 'slate'} label="Overdue" value={bills.overdue_count} sub={<Money value={bills.overdue_total} />} />
        <MiniStat icon={Clock} tone={bills.due_soon_count ? 'amber' : 'slate'} label="Due in next 7 days" value={bills.due_soon_count} sub={<Money value={bills.due_soon_total} />} />
        <MiniStat icon={FileWarning} tone="indigo" label="All unpaid bills" value={bills.open_count} sub={<Money value={bills.open_total} />} />
      </div>
      {bills.upcoming.length === 0 ? (
        <EmptyState text="No unpaid supplier bills" height={100} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-[10px] uppercase tracking-wider text-slate-400">
                <th className="py-1 font-semibold">Bill</th>
                <th className="py-1 font-semibold">Supplier</th>
                <th className="py-1 font-semibold">Due date</th>
                <th className="py-1 text-right font-semibold">Balance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {bills.upcoming.map((b) => (
                <tr key={b.id}>
                  <td className="py-1 font-medium text-indigo-600">{b.bill_no}</td>
                  <td className="max-w-55 truncate py-1 text-slate-600" title={b.supplier}>{b.supplier ?? '—'}</td>
                  <td className="py-1">
                    <span className={b.overdue ? 'font-semibold text-rose-600' : 'text-slate-600'}>{b.due_date}</span>
                    {b.overdue && <span className="ml-1.5 rounded-full bg-rose-50 px-1.5 py-px text-[10px] font-semibold text-rose-700">Overdue</span>}
                  </td>
                  <td className="py-1 text-right"><Money value={b.balance} className="font-semibold text-slate-800" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Sales Orders & Deliveries ────────────────────────────────────────────────

function ordersNote(periodLabel) {
  return (
    <CalcNote
      title="Sales Orders & Deliveries"
      points={[
        `Sales Orders: orders dated ${periodLabel.toLowerCase()} (order date), counted by status. Value = SO grand total.`,
        'Not confirmed = Draft. The donut centre counts every status; the value line leaves out Cancelled.',
        `Delivery Orders: DOs dated ${periodLabel.toLowerCase()} (delivery date), by status. A DO has no value of its own.`,
        'Pending invoices: every Issued invoice that still has money to collect, whatever its date — the same total as "Customers Owe Us".',
      ]}
      source="Sales Orders, Delivery Orders and Outstanding Summary report"
    />
  )
}

// Same colour means the same state in both donuts: amber waits, green is done, grey is cancelled.
const SO_STATUSES = [
  { key: 'draft',     label: 'Not confirmed', color: '#f59e0b' },
  { key: 'confirmed', label: 'Confirmed',     color: '#2a78d6' },
  { key: 'completed', label: 'Completed',     color: '#16a34a' },
  { key: 'cancelled', label: 'Cancelled',     color: '#cbd5e1' },
]

const DO_STATUSES = [
  { key: 'draft',     label: 'Not confirmed', color: '#f59e0b' },
  { key: 'confirmed', label: 'Confirmed',     color: '#16a34a' },
  { key: 'cancelled', label: 'Cancelled',     color: '#cbd5e1' },
]

function SubHead({ children, to }) {
  return (
    <div className="mb-1 flex items-center justify-between">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{children}</p>
      {to && <Link to={to} className="text-[10px] font-medium text-indigo-600 hover:underline">Open</Link>}
    </div>
  )
}

function OrdersPanel({ access, orders, receivables }) {
  return (
    <div className="flex flex-col gap-2.5">
      {access.orders && (
        <Section q={orders} height={150}>
          {(d) => {
            const so    = d.sales_orders
            const value = SO_STATUSES.filter((s) => s.key !== 'cancelled').reduce((t, s) => t + so[s.key].value, 0)
            const soRows = SO_STATUSES.map((s) => ({ ...s, count: so[s.key].count, value: so[s.key].value }))
            const doRows = d.delivery_orders
              ? DO_STATUSES.map((s) => ({ ...s, count: d.delivery_orders[s.key] }))
              : null

            return (
              <div>
                <div className={`grid gap-3 ${doRows ? 'grid-cols-2' : 'grid-cols-1'}`}>
                  <div className="min-w-0">
                    <SubHead to="/inventory/sales-orders">Sales Orders</SubHead>
                    <MiniDonut rows={soRows} centerLabel="Orders" emptyText="No orders in this period" />
                  </div>
                  {doRows && (
                    <div className="min-w-0">
                      <SubHead to="/inventory/delivery-orders">Delivery Orders</SubHead>
                      <MiniDonut rows={doRows} centerLabel="DOs" emptyText="No deliveries in this period" />
                    </div>
                  )}
                </div>
                <p className="mt-1.5 flex items-baseline justify-between rounded-md bg-slate-50 px-2 py-1 text-[11px] text-slate-500">
                  Sales order value (excl. cancelled)
                  <Money value={value} className="font-semibold text-slate-800" />
                </p>
              </div>
            )
          }}
        </Section>
      )}

      {access.receivables && (
        <div>
          <SubHead to="/inventory/invoices">Pending Invoices (Issued, not paid)</SubHead>
          <Section q={receivables} height={50}>
            {(r) => (
              <div className="grid grid-cols-2 gap-1.5">
                <StatusChip tone="sky" label="Invoices to collect" count={r.documents} value={r.total} />
                <StatusChip tone={r.overdue > 0 ? 'rose' : 'emerald'} label="Of which overdue" value={r.overdue} />
              </div>
            )}
          </Section>
        </div>
      )}
    </div>
  )
}

const CHIP_TONES = {
  amber:   'border-amber-200 bg-amber-50 text-amber-800',
  indigo:  'border-indigo-200 bg-indigo-50 text-indigo-800',
  emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  slate:   'border-slate-200 bg-slate-50 text-slate-600',
  sky:     'border-sky-200 bg-sky-50 text-sky-800',
  rose:    'border-rose-200 bg-rose-50 text-rose-800',
}

function StatusChip({ tone, label, count, value }) {
  return (
    <div className={`rounded-md border px-2 py-1 ${CHIP_TONES[tone]}`}>
      <p className="truncate text-[10px] font-medium opacity-80">{label}</p>
      <p className="flex items-baseline justify-between gap-1">
        {count !== undefined && <span className="text-sm font-bold leading-tight">{count}</span>}
        {value !== undefined && <Money value={value} className="truncate text-[11px] font-semibold" />}
      </p>
    </div>
  )
}
