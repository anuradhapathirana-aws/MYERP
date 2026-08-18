import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Plus, X } from 'lucide-react'
import { createUser, deleteUser, getUsers, updateUser } from '../../api/users'
import { getRoles } from '../../api/roles'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [{ label: 'Team Management' }]

const EMPTY_FORM = { name: '', email: '', password: '', role: '', modules: ['inventory'] }

const AVAILABLE_MODULES = [
  { key: 'inventory', label: 'Inventory' },
  { key: 'finance',   label: 'Finance' },
  { key: 'hr',        label: 'HR' },
]

// ── Role badge ────────────────────────────────────────────────────────────────
const ROLE_COLOURS = {
  super_admin:  'bg-purple-100 text-purple-700',
  admin: 'bg-indigo-100 text-indigo-700',
  staff:        'bg-slate-100  text-slate-600',
}

function RoleBadge({ role }) {
  const cls = ROLE_COLOURS[role] ?? 'bg-slate-100 text-slate-600'
  return (
    <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${cls}`}>
      {role.replace('_', ' ')}
    </span>
  )
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function UserModal({ open, onClose, editing, roles, onSuccess, isSuperAdmin }) {
  const queryClient = useQueryClient()
  const [form, setForm]     = useState(EMPTY_FORM)
  const [errors, setErrors] = useState({})

  // Populate form when opening for edit, reset when opening for add
  useEffect(() => {
    if (!open) return
    if (editing) {
      setForm({
        name:     editing.name,
        email:    editing.email,
        password: '',
        role:     editing.roles?.[0] ?? '',
        modules:  editing.active_modules ?? [],
      })
    } else {
      setForm(EMPTY_FORM)
    }
    setErrors({})
  }, [open, editing])

  const isEdit = Boolean(editing)

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEdit ? updateUser(editing.id, payload) : createUser(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      showSuccess(isEdit ? 'User updated successfully.' : 'User created successfully.')
      onSuccess()
    },
    onError: (err) => {
      const serverErrors = err.response?.data?.errors ?? {}
      setErrors(serverErrors)
      showError('Failed to save user. Please check the form.')
    },
  })

  const set = (field) => (e) => {
    setForm((prev) => ({ ...prev, [field]: e.target.value }))
    setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    setErrors({})

    const payload = {
      name:           form.name,
      email:          form.email,
      roles:          form.role ? [form.role] : [],
      active_modules: form.modules,
    }
    if (form.password) payload.password = form.password

    mutation.mutate(payload)
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div className="w-full max-w-md rounded-xl bg-white shadow-xl">

        {/* Header */}
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-base font-semibold text-slate-800">
            {isEdit ? 'Edit User' : 'Add User'}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X size={16} />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} noValidate className="space-y-4 px-6 py-5">

          {/* Name */}
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Full Name <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={form.name}
              onChange={set('name')}
              onBlur={() => !form.name && setErrors(p => ({ ...p, name: ['Name is required.'] }))}
              placeholder="Jane Smith"
              className={[
                'block w-full rounded-lg border-2 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all',
                'focus:bg-white focus:ring-2',
                errors.name
                  ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:ring-red-500/15'
                  : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:ring-indigo-500/15',
              ].join(' ')}
            />
            {errors.name && (
              <p className="mt-1 text-xs text-red-600">{errors.name[0]}</p>
            )}
          </div>

          {/* Email */}
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Email Address <span className="text-red-500">*</span>
            </label>
            <input
              type="email"
              value={form.email}
              onChange={set('email')}
              placeholder="jane@company.com"
              className={[
                'block w-full rounded-lg border-2 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all',
                'focus:bg-white focus:ring-2',
                errors.email
                  ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:ring-red-500/15'
                  : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:ring-indigo-500/15',
              ].join(' ')}
            />
            {errors.email && (
              <p className="mt-1 text-xs text-red-600">{errors.email[0]}</p>
            )}
          </div>

          {/* Password */}
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Password{' '}
              {isEdit ? (
                <span className="font-normal text-slate-400">(leave blank to keep current)</span>
              ) : (
                <span className="text-red-500">*</span>
              )}
            </label>
            <input
              type="password"
              value={form.password}
              onChange={set('password')}
              placeholder="••••••••"
              autoComplete="new-password"
              className={[
                'block w-full rounded-lg border-2 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all',
                'focus:bg-white focus:ring-2',
                errors.password
                  ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:ring-red-500/15'
                  : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:ring-indigo-500/15',
              ].join(' ')}
            />
            {errors.password && (
              <p className="mt-1 text-xs text-red-600">{errors.password[0]}</p>
            )}
          </div>

          {/* Role */}
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Role <span className="text-red-500">*</span>
            </label>
            <select
              value={form.role}
              onChange={set('role')}
              className={[
                'block w-full rounded-lg border-2 px-3 py-1.5 text-sm text-slate-800 outline-none transition-all cursor-pointer',
                'focus:bg-white focus:ring-2',
                errors.roles
                  ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:ring-red-500/15'
                  : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:ring-indigo-500/15',
              ].join(' ')}
            >
              <option value="">Select a role…</option>
              {roles
                .filter((r) => isSuperAdmin || r.name !== 'super_admin')
                .map((r) => (
                  <option key={r.name} value={r.name}>
                    {r.label}
                  </option>
                ))}
            </select>
            {errors.roles && (
              <p className="mt-1 text-xs text-red-600">{errors.roles[0]}</p>
            )}
          </div>

          {/* Module Access — super_admin only */}
          {isSuperAdmin ? (
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">
                Module Access
              </label>
              <div className="flex flex-wrap gap-3">
                {AVAILABLE_MODULES.map(({ key, label }) => (
                  <label key={key} className="flex cursor-pointer items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={form.modules.includes(key)}
                      onChange={(e) => {
                        const next = e.target.checked
                          ? [...form.modules, key]
                          : form.modules.filter((m) => m !== key)
                        setForm((prev) => ({ ...prev, modules: next }))
                      }}
                      className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30"
                    />
                    <span className="text-slate-700">{label}</span>
                  </label>
                ))}
              </div>
              <p className="mt-1 text-xs text-slate-400">
                Controls which modules appear in the sidebar for this user.
              </p>
            </div>
          ) : (
            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
              <p className="text-xs text-slate-400">
                <span className="font-medium text-slate-500">Module Access</span> can only be configured by a Super Admin.
              </p>
            </div>
          )}

          {/* Generic server error */}
          {mutation.isError && !Object.keys(errors).length && (
            <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600">
              {mutation.error?.response?.data?.message ?? 'Something went wrong. Please try again.'}
            </p>
          )}

          {/* Actions */}
          <div className="flex items-center justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={mutation.isPending}
              className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
              {mutation.isPending && <Loader2 size={14} className="animate-spin" />}
              {isEdit ? 'Save Changes' : 'Create User'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function readIsSuperAdmin() {
  try {
    const roles = JSON.parse(localStorage.getItem('user_roles') ?? '[]')
    return Array.isArray(roles) && roles.includes('super_admin')
  } catch {
    return false
  }
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function UserManagementPage() {
  const queryClient = useQueryClient()
  const isSuperAdmin = readIsSuperAdmin()
  const [page,         setPage]         = useState(1)
  const [modalOpen,    setModalOpen]    = useState(false)
  const [editingUser,  setEditingUser]  = useState(null)

  const { data: usersData, isLoading, isError } = useQuery({
    queryKey: ['users', page],
    queryFn:  () => getUsers(page),
    placeholderData: (prev) => prev,
  })

  const { data: rolesData } = useQuery({
    queryKey: ['roles'],
    queryFn:  getRoles,
    staleTime: Infinity,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteUser,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      showSuccess('User deleted.')
    },
    onError: () => showError('Failed to delete user.'),
  })

  const openAdd  = () => { setEditingUser(null); setModalOpen(true) }
  const openEdit = (user) => { setEditingUser(user); setModalOpen(true) }
  const closeModal = () => setModalOpen(false)

  const handleDelete = async (user) => {
    const ok = await confirmDelete(user.name)
    if (ok) deleteMutation.mutate(user.id)
  }

  const meta = usersData?.meta
  const rows = usersData?.data ?? []
  const roles = rolesData?.data ?? []

  return (
    <div>
      {/* Page header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Team Management</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <button
          type="button"
          onClick={openAdd}
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={15} strokeWidth={2.5} />
          Add User
        </button>
      </div>

      {/* Table card */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-400">
            <Loader2 size={16} className="animate-spin" />
            Loading users…
          </div>
        )}

        {isError && (
          <div className="flex items-center justify-center py-16 text-sm text-red-500">
            Failed to load users. Check that the backend is running.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-10 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Email</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                    <th className="w-32 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="sticky right-0 w-20 bg-slate-50 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Actions
                    </th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-14 text-center text-sm text-slate-400">
                        No users yet.{' '}
                        <button
                          type="button"
                          onClick={openAdd}
                          className="font-medium text-indigo-600 hover:underline"
                        >
                          Add the first one.
                        </button>
                      </td>
                    </tr>
                  ) : (
                    rows.map((user, i) => (
                      <tr key={user.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-4 py-2.5 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-4 py-2.5 font-medium text-slate-800">
                          {user.name}
                        </td>
                        <td className="px-4 py-2.5 text-slate-500">
                          {user.email}
                        </td>
                        <td className="px-4 py-2.5">
                          <div className="flex flex-wrap gap-1">
                            {user.roles?.length > 0
                              ? user.roles.map((r) => <RoleBadge key={r} role={r} />)
                              : <span className="italic text-slate-300">—</span>
                            }
                          </div>
                        </td>
                        <td className="whitespace-nowrap px-4 py-2.5 text-slate-400">
                          {new Date(user.created_at).toLocaleDateString()}
                        </td>
                        <td className="sticky right-0 bg-white px-4 py-2.5 group-hover:bg-slate-50">
                          <div className="flex items-center justify-end gap-1">
                            <EditBtn onClick={() => openEdit(user)} />
                            <DeleteBtn onClick={() => handleDelete(user)} disabled={deleteMutation.isPending} />
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>

      <UserModal
        open={modalOpen}
        onClose={closeModal}
        editing={editingUser}
        roles={roles}
        onSuccess={closeModal}
        isSuperAdmin={isSuperAdmin}
      />
    </div>
  )
}
