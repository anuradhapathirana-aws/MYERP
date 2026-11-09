import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Info, Lock, Save, Ship, X } from 'lucide-react'
import { createChargeType, deleteChargeType, getChargeTypeOptions, getChargeTypes, updateChargeType } from '../../api/chargeTypes'
import HelpTip from '../../components/ui/HelpTip'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { DeleteBtn } from '../../components/ui/ActionButtons'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const CRUMBS = [
  { label: 'Finance' },
  { label: 'Payables' },
  { label: 'Charge Types' },
]

const TREATMENT_BADGE = {
  landed_cost:     'bg-indigo-50 text-indigo-700',
  recoverable_tax: 'bg-green-50 text-green-700',
  expense:         'bg-orange-50 text-orange-700',
}

const TREATMENT_HELP = {
  landed_cost:     'Part of what the goods cost: posts to its account and is pulled into the shipment\'s Costing.',
  recoverable_tax: 'A tax claimed back (Import VAT): posts to Input VAT. Becomes cost by itself while VAT is off.',
  expense:         'A period cost (demurrage, bank charges): posts to its account, never into a product\'s cost.',
}

const CODE_RE = /^[A-Za-z0-9_-]+$/
const LOCKED_TREATMENT = ['IMPORT_VAT']

const inputBase = 'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr  = 'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const inputRo   = 'block w-full rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-1 text-xs text-slate-500 outline-none cursor-not-allowed'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

const emptyForm = () => ({
  code: '', name: '', treatment: 'landed_cost', ledger_account_id: '',
  fob_expense_type_id: '', cif_expense_type_id: '', is_active: true, sort_order: 0,
})

const fromRow = (r) => ({
  code:                r.code ?? '',
  name:                r.name ?? '',
  treatment:           r.treatment ?? 'landed_cost',
  ledger_account_id:   r.ledger_account_id ? String(r.ledger_account_id) : '',
  fob_expense_type_id: r.fob_expense_type_id ? String(r.fob_expense_type_id) : '',
  cif_expense_type_id: r.cif_expense_type_id ? String(r.cif_expense_type_id) : '',
  is_active:           Boolean(r.is_active),
  sort_order:          r.sort_order ?? 0,
})

function validate(form) {
  const e = {}
  const code = form.code.trim()
  if (!code) e.code = 'Code is required.'
  else if (code.length > 30) e.code = 'Max 30 characters.'
  else if (!CODE_RE.test(code)) e.code = 'Letters, numbers, hyphens and underscores only.'
  if (!form.name.trim()) e.name = 'Name is required.'
  else if (form.name.trim().length > 100) e.name = 'Max 100 characters.'
  return e
}

function ChargeTypeForm({ row, options, onDone, onCancel }) {
  const isEditing   = Boolean(row)
  const queryClient = useQueryClient()
  const firstRef    = useRef(null)

  const [form,    setForm]    = useState(() => (row ? fromRow(row) : emptyForm()))
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  useEffect(() => { firstRef.current?.focus() }, [])

  const isSystem        = Boolean(row?.is_system)
  const treatmentLocked = isSystem && LOCKED_TREATMENT.includes(row?.code)
  const linksToGrn      = form.treatment !== 'expense'

  const fobRows = (options.costing_rows ?? []).filter((r) => r.costing_type === 'fob')
  const cifRows = (options.costing_rows ?? []).filter((r) => r.costing_type === 'cif')

  const setField = (name, value) => {
    const next = { ...form, [name]: value }
    setForm(next)
    if (touched[name]) setErrors((p) => ({ ...p, [name]: validate(next)[name] }))
  }
  const blur = (name) => () => {
    setTouched((t) => ({ ...t, [name]: true }))
    setErrors((p) => ({ ...p, [name]: validate(form)[name] }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateChargeType(row.id, payload) : createChargeType(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['charge-types'] })
      queryClient.invalidateQueries({ queryKey: ['bill-options'] })
      showSuccess(isEditing ? 'Charge type updated.' : 'Charge type added.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      const flat = Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]]))
      setErrors(flat)
      setTouched(Object.fromEntries(Object.keys(flat).map((k) => [k, true])))
      showError(err.response?.data?.message ?? 'Failed to save. Please check the form and try again.')
    },
  })

  const submit = (e) => {
    e.preventDefault()
    const errs = validate(form)
    setErrors(errs)
    setTouched({ code: true, name: true })
    if (Object.keys(errs).length) return
    const id = (v) => (v ? Number(v) : null)
    mutation.mutate({
      code:                form.code.trim().toUpperCase(),
      name:                form.name.trim(),
      treatment:           form.treatment,
      ledger_account_id:   id(form.ledger_account_id),
      fob_expense_type_id: linksToGrn ? id(form.fob_expense_type_id) : null,
      cif_expense_type_id: linksToGrn ? id(form.cif_expense_type_id) : null,
      is_active:           form.is_active,
      sort_order:          Number(form.sort_order) || 0,
    })
  }

  const cls = (f) => (errors[f] && touched[f] ? inputErr : inputBase)

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-2 p-2.5">
      {isSystem && (
        <p className="flex items-start gap-1 rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] leading-snug text-slate-600">
          <Lock size={10} className="mt-0.5 shrink-0" />
          Built-in type: the code is fixed{treatmentLocked ? ', and Import VAT is always a claimable tax' : ''}. Set its account and Costing rows to match your chart.
        </p>
      )}

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Code <span className="text-red-500">*</span></label>
          <input
            ref={isSystem ? undefined : firstRef}
            value={form.code} onChange={(e) => setField('code', e.target.value)} onBlur={blur('code')}
            placeholder="e.g. PORT_HANDLING" maxLength={30} autoComplete="off" readOnly={isSystem}
            className={isSystem ? inputRo : `${cls('code')} uppercase`}
          />
          {errors.code && touched.code && <p className={ERR_CLS}>{errors.code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>Treatment <span className="text-red-500">*</span> <HelpTip text="Decides where the money goes. Landed cost: part of what the goods cost, pulled into Costing. Claimable tax: goes to Input VAT. Expense: a cost of the period, never in a product's cost." /></label>
          <select
            value={form.treatment} disabled={treatmentLocked}
            onChange={(e) => setField('treatment', e.target.value)}
            className={treatmentLocked ? inputRo : `${inputBase} cursor-pointer`}
          >
            {(options.treatments ?? []).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
          {errors.treatment && <p className={ERR_CLS}>{errors.treatment}</p>}
        </div>
      </div>
      <p className="-mt-1 text-[10px] leading-snug text-slate-500">{TREATMENT_HELP[form.treatment]}</p>

      <div>
        <label className={LABEL_CLS}>Name <span className="text-red-500">*</span></label>
        <input
          ref={isSystem ? firstRef : undefined}
          value={form.name} onChange={(e) => setField('name', e.target.value)} onBlur={blur('name')}
          placeholder="e.g. Port Handling" maxLength={100} autoComplete="off" className={cls('name')}
        />
        {errors.name && touched.name && <p className={ERR_CLS}>{errors.name}</p>}
      </div>

      <div>
        <label className={LABEL_CLS}>
          {form.treatment === 'recoverable_tax' ? 'Account when not claimable' : 'Default account'}{' '}
          <HelpTip text={form.treatment === 'recoverable_tax'
            ? 'This tax goes to Input VAT while VAT is switched on. If VAT is off it cannot be claimed, so it posts to this account as a cost.'
            : 'Fills in by itself when this Charge Type is picked on a bill line. Leave empty to pick the account on each bill.'} />
        </label>
        <select
          value={form.ledger_account_id} onChange={(e) => setField('ledger_account_id', e.target.value)}
          className={`${errors.ledger_account_id ? inputErr : inputBase} cursor-pointer`}
        >
          <option value="">— pick on each bill —</option>
          {(options.expense_accounts ?? []).map((a) => <option key={a.id} value={a.id}>{a.code} - {a.name}</option>)}
        </select>
        {errors.ledger_account_id && <p className={ERR_CLS}>{errors.ledger_account_id}</p>}
      </div>

      {linksToGrn && (
        <div className="rounded border border-slate-200">
          <div className="border-b border-slate-100 bg-slate-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
            Costing row <span className="font-normal normal-case">(optional)</span>{' '}
            <HelpTip text="The Costing expense row this charge fills when a Costing pulls bills. If a costing type has no row here, the charge is not pulled into that costing." />
          </div>
          {options.costing_available === false ? (
            <p className="px-2 py-1.5 text-[10px] text-slate-500">Inventory is not enabled, so there is no Costing to fill.</p>
          ) : (
            <>
              <p className="px-2 pt-1.5 text-[10px] leading-snug text-slate-500">
                FOB and CIF costings have separate expense lists, so pick the row for each kind you use.
                Leave one empty when this charge does not apply to it. Example: on CIF the supplier&apos;s price already includes freight, so Freight stays empty for CIF.
              </p>
              <div className="grid grid-cols-2 gap-2 p-2">
                <div>
                  <label className={LABEL_CLS}>On a FOB costing</label>
                  <select value={form.fob_expense_type_id} onChange={(e) => setField('fob_expense_type_id', e.target.value)}
                    className={`${errors.fob_expense_type_id ? inputErr : inputBase} cursor-pointer`}>
                    <option value="">— does not apply —</option>
                    {fobRows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                  {errors.fob_expense_type_id && <p className={ERR_CLS}>{errors.fob_expense_type_id}</p>}
                </div>
                <div>
                  <label className={LABEL_CLS}>On a CIF costing</label>
                  <select value={form.cif_expense_type_id} onChange={(e) => setField('cif_expense_type_id', e.target.value)}
                    className={`${errors.cif_expense_type_id ? inputErr : inputBase} cursor-pointer`}>
                    <option value="">— does not apply —</option>
                    {cifRows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                  {errors.cif_expense_type_id && <p className={ERR_CLS}>{errors.cif_expense_type_id}</p>}
                </div>
              </div>
              {!form.fob_expense_type_id && !form.cif_expense_type_id && (
                <p className="mx-2 mb-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[10px] leading-snug text-amber-800">
                  With both empty, the bill still posts to the accounts, but this charge cannot be pulled into a Costing.
                </p>
              )}
            </>
          )}
        </div>
      )}

      <div className="grid grid-cols-[1fr_5rem] items-end gap-2">
        <div className="rounded border border-slate-100 bg-slate-50 p-2">
          <label className="flex cursor-pointer items-center gap-3">
            <div className="relative">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setField('is_active', e.target.checked)} className="sr-only peer" />
              <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
              <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
            </div>
            <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active — can be picked on bills' : 'Inactive (hidden from bills)'}</span>
          </label>
        </div>
        <div>
          <label className={LABEL_CLS}>Order</label>
          <input type="number" min="0" max="999" value={form.sort_order} onChange={(e) => setField('sort_order', e.target.value)} className={`${inputBase} text-right`} />
        </div>
      </div>

      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button type="button" onClick={onCancel} className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200">
            <X size={11} /> Cancel
          </button>
        )}
        <button type="submit" disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Add Charge Type'}
        </button>
      </div>
    </form>
  )
}

/** Finance > Payables > Charge Types: what a bill line can be for, and where it posts. */
export default function ChargeTypesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['charge-types', page],
    queryFn:  () => getChargeTypes(page),
    placeholderData: (prev) => prev,
  })
  const { data: options = {} } = useQuery({
    queryKey: ['charge-type-options'],
    queryFn:  getChargeTypeOptions,
    staleTime: 5 * 60 * 1000,
  })

  const rows    = data?.data ?? []
  const editRow = rows.find((r) => r.id === editId) ?? null
  const rowName = useMemo(() => new Map((options.costing_rows ?? []).map((r) => [r.id, r.name])), [options.costing_rows])
  const missingAccounts = rows.filter((r) => r.is_active && !r.ledger_account_id).length

  const deleteMutation = useMutation({
    mutationFn: deleteChargeType,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['charge-types'] })
      queryClient.invalidateQueries({ queryKey: ['bill-options'] })
      showSuccess('Charge type deleted.')
    },
    onError: (err) => showError(err.response?.data?.errors?.id?.[0] ?? err.response?.data?.message ?? 'Failed to delete.'),
  })

  const handleDelete = async (row) => {
    if (await confirmDelete(`charge type ${row.name}`)) {
      if (editId === row.id) setEditId(null)
      deleteMutation.mutate(row.id)
    }
  }

  return (
    <div className="w-full">
      <div>
        <h1 className="text-xl font-bold leading-none text-slate-800">Charge Types</h1>
        <Breadcrumb crumbs={CRUMBS} />
      </div>

      <div className="mt-2 flex items-start gap-2 rounded-lg border border-sky-100 bg-sky-50 px-3 py-1.5 text-[11px] leading-snug text-sky-800">
        <Info size={13} className="mt-0.5 shrink-0" />
        <span>
          A charge type is what a bill line is for. On a bill you pick <b>what was charged</b> and the books follow:{' '}
          <span className="font-semibold text-indigo-700">Landed cost</span> (freight, duty, clearing) goes into the goods&apos; cost and the shipment&apos;s Costing,{' '}
          <span className="font-semibold text-green-700">Claimable tax</span> (Import VAT) goes to Input VAT, and{' '}
          <span className="font-semibold text-orange-700">Expense</span> (demurrage, bank charges) is a period cost.
        </span>
      </div>

      {missingAccounts > 0 && (
        <div className="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] text-amber-800">
          {missingAccounts} active charge {missingAccounts === 1 ? 'type has' : 'types have'} no default account yet. Create the import cost accounts
          (e.g. &quot;Import Duty &amp; Levies&quot;, &quot;Import Freight &amp; Insurance&quot;, under Cost of Sales) in Ledger Accounts and assign them here.
          Until then the account is picked on each bill line.
        </div>
      )}

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-2">
          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load charge types.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 z-10">
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Treatment</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Account</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Costing row (FOB / CIF)</th>
                      <th className="w-14 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="sticky right-0 w-16 bg-slate-50 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">No charge types yet.</td></tr>
                    ) : rows.map((row) => (
                      <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                        <td className="px-3 py-1.5 font-mono font-semibold text-slate-700">
                          <span className="inline-flex items-center gap-1">
                            {row.code}
                            {row.is_system && <Lock size={10} className="text-slate-400" title="Built-in — code fixed, cannot be deleted" />}
                          </span>
                        </td>
                        <td className="max-w-[12rem] truncate px-3 py-1.5 text-slate-700" title={row.name}>{row.name}</td>
                        <td className="px-3 py-1.5">
                          <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold ${TREATMENT_BADGE[row.treatment] ?? ''}`}>{row.treatment_label}</span>
                        </td>
                        <td className="max-w-[12rem] truncate px-3 py-1.5" title={row.ledger_account_name ?? ''}>
                          {row.ledger_account_name ?? <span className="text-amber-600">Not set</span>}
                        </td>
                        <td className="px-3 py-1.5 text-slate-600">
                          {row.treatment === 'expense' ? <span className="text-slate-400">Not a product cost</span> : (
                            <span className="text-[11px]">
                              {rowName.get(row.fob_expense_type_id) ?? <span className="text-slate-400">—</span>}
                              <span className="mx-1 text-slate-300">/</span>
                              {rowName.get(row.cif_expense_type_id) ?? <span className="text-slate-400">—</span>}
                            </span>
                          )}
                        </td>
                        <td className="px-3 py-1.5">
                          {row.is_active
                            ? <span className="inline-flex rounded bg-green-50 px-2 py-0.5 text-[11px] font-semibold text-green-700">Active</span>
                            : <span className="inline-flex rounded bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">Off</span>}
                        </td>
                        <td className="sticky right-0 bg-white px-3 py-1.5">
                          <div className="flex items-center justify-end gap-1">
                            {can('edit_charge_types') && (
                              <button type="button" title="Edit" onClick={() => setEditId(row.id)}
                                className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}>
                                <Edit2 size={13} />
                              </button>
                            )}
                            {can('delete_charge_types') && !row.is_system && (
                              <DeleteBtn onClick={() => handleDelete(row)} disabled={deleteMutation.isPending} />
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination meta={data?.meta} page={page} onPageChange={setPage} />
            </>
          )}
        </div>

        <div className="self-start overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <Ship size={13} />
              <h2 className="text-xs font-bold">{editRow ? 'Edit Charge Type' : 'New Charge Type'}</h2>
            </div>
            {editRow && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {(editRow ? can('edit_charge_types') : can('create_charge_types')) ? (
            <ChargeTypeForm
              key={editRow?.id ?? 'create'}
              row={editRow}
              options={options}
              onDone={() => setEditId(null)}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don&apos;t have permission to manage charge types.</div>
          )}
        </div>
      </div>
    </div>
  )
}
