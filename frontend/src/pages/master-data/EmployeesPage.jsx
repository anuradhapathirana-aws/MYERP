import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Save, UserCircle, X } from 'lucide-react'
import {
  createEmployee, deleteEmployee, getEmployee, getEmployees, updateEmployee,
} from '../../api/employees'
import { getAllLocations } from '../../api/locations'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Master Data', to: '/master-data/employees' },
  { label: 'Employees' },
]

const EMPTY_FORM = {
  employee_code: '', employee_name: '', designation: '', department: '',
  location_id: '', mobile: '', email: '', is_active: true,
}

const CODE_RE  = /^[A-Za-z0-9_-]+$/
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate(field, value) {
  if (field === 'employee_code') {
    const v = String(value).trim()
    if (!v) return 'Employee code is required.'
    if (v.length > 30) return 'Max 30 characters.'
    if (!CODE_RE.test(v)) return 'Letters, numbers, hyphens and underscores only.'
  }
  if (field === 'employee_name') {
    const v = String(value).trim()
    if (!v) return 'Employee name is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  if (field === 'email') {
    const v = String(value).trim()
    if (v && !EMAIL_RE.test(v)) return 'Enter a valid email address.'
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function EmployeeForm({ editId, locations, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const codeRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['employee', editId],
    queryFn:  () => getEmployee(editId),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    initialized.current = false
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const e = fetchedData.data
      setForm({
        employee_code: e.employee_code ?? '',
        employee_name: e.employee_name ?? '',
        designation:   e.designation   ?? '',
        department:    e.department    ?? '',
        location_id:   e.location_id ? String(e.location_id) : '',
        mobile:        e.mobile        ?? '',
        email:         e.email         ?? '',
        is_active:     e.is_active     ?? true,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) codeRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => ({ ...prev, [name]: newVal }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, newVal) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateEmployee(editId, payload) : createEmployee(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] })
      queryClient.invalidateQueries({ queryKey: ['employees-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['employee', editId] })
      showSuccess(isEditing ? 'Employee updated.' : 'Employee created.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError('Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const newErrors = {
      employee_code: validate('employee_code', form.employee_code),
      employee_name: validate('employee_name', form.employee_name),
      email:         validate('email', form.email),
    }
    setErrors(newErrors)
    setTouched({ employee_code: true, employee_name: true, email: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      employee_code: form.employee_code.trim().toUpperCase(),
      employee_name: form.employee_name.trim(),
      designation:   form.designation.trim() || null,
      department:    form.department.trim() || null,
      location_id:   form.location_id ? parseInt(form.location_id, 10) : null,
      mobile:        form.mobile.trim() || null,
      email:         form.email.trim() || null,
      is_active:     form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Code <span className="text-red-500">*</span></label>
          <input
            ref={codeRef}
            name="employee_code"
            type="text"
            value={form.employee_code}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. EMP-0001"
            maxLength={30}
            autoComplete="off"
            className={errors.employee_code && touched.employee_code ? inputErr : inputBase}
          />
          {errors.employee_code && touched.employee_code && <p className={ERR_CLS}>{errors.employee_code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>Name <span className="text-red-500">*</span></label>
          <input
            name="employee_name"
            type="text"
            value={form.employee_name}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="Full name"
            maxLength={100}
            autoComplete="off"
            className={errors.employee_name && touched.employee_name ? inputErr : inputBase}
          />
          {errors.employee_name && touched.employee_name && <p className={ERR_CLS}>{errors.employee_name}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Designation</label>
          <input name="designation" type="text" value={form.designation} onChange={handleChange} placeholder="e.g. Accountant" maxLength={100} className={inputBase} />
        </div>
        <div>
          <label className={LABEL_CLS}>Department</label>
          <input name="department" type="text" value={form.department} onChange={handleChange} placeholder="e.g. Finance" maxLength={100} className={inputBase} />
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Location</label>
        <select name="location_id" value={form.location_id} onChange={handleChange} className={inputBase}>
          <option value="">— None —</option>
          {locations.map((l) => (
            <option key={l.id} value={l.id}>{l.location_name}</option>
          ))}
        </select>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Mobile</label>
          <input name="mobile" type="text" value={form.mobile} onChange={handleChange} placeholder="+94 77 1234567" maxLength={20} className={inputBase} />
        </div>
        <div>
          <label className={LABEL_CLS}>Email</label>
          <input
            name="email"
            type="email"
            value={form.email}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="name@company.lk"
            maxLength={100}
            className={errors.email && touched.email ? inputErr : inputBase}
          />
          {errors.email && touched.email && <p className={ERR_CLS}>{errors.email}</p>}
        </div>
      </div>

      <div className="rounded border border-slate-100 bg-slate-50 p-2">
        <label className="flex cursor-pointer items-center gap-3">
          <div className="relative">
            <input type="checkbox" name="is_active" checked={form.is_active} onChange={handleChange} className="sr-only peer" />
            <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
            <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
          </div>
          <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active' : 'Inactive'}</span>
        </label>
        <p className="mt-0.5 text-[10px] text-slate-400">Inactive employees are hidden from requestor and payee dropdowns.</p>
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className={ERR_CLS}>{mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}</p>
      )}

      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button type="button" onClick={onCancel} className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200">
            <X size={11} /> Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Employee'}
        </button>
      </div>
    </form>
  )
}

export default function EmployeesPage() {
  const [page,   setPage]   = useState(1)
  const [search, setSearch] = useState('')
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data: locations = [] } = useQuery({
    queryKey: ['locations-all'],
    queryFn:  getAllLocations,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['employees', page, search],
    queryFn:  () => getEmployees(page, search ? { search } : {}),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteEmployee,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] })
      showSuccess('Employee deleted.')
    },
    onError: (err) =>
      showError(err.response?.data?.message ?? 'Failed to delete. The employee may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  const meta = data?.meta
  const rows = data?.data ?? []
  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Employees</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-1.5">
            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Search</label>
            <input
              type="text"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
              placeholder="Name, code or designation"
              className="w-56 rounded border-2 border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-700 outline-none focus:border-indigo-500"
            />
          </div>

          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load employees.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Designation</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Department</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-400">
                          No employees yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.employee_code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.employee_name}</td>
                          <td className="px-3 py-2 text-slate-500">{row.designation || <span className="italic text-slate-300">—</span>}</td>
                          <td className="px-3 py-2 text-slate-500">{row.department || <span className="italic text-slate-300">—</span>}</td>
                          <td className="px-3 py-2 text-slate-500">{row.location_name || <span className="italic text-slate-300">—</span>}</td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_employees') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_employees') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, row.employee_name)} disabled={deleteMutation.isPending} />
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <Pagination meta={meta} page={page} onPageChange={setPage} />
            </>
          )}
        </div>

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <UserCircle size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Employee' : 'New Employee'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_employees') || can('edit_employees') ? (
            <EmployeeForm key={editId ?? 'create'} editId={editId} locations={locations} onDone={() => setEditId(null)} onCancel={() => setEditId(null)} />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage employees.</div>
          )}
        </div>
      </div>
    </div>
  )
}
