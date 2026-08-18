import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Save } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { getPermissionsGrouped } from '../../api/permissions'
import { createRole, getRole, syncRolePermissions, updateRole } from '../../api/roles'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

// Column order used across every group; a group only renders the columns
// its resources actually use.
const ACTION_ORDER = ['view', 'create', 'edit', 'delete', 'approve', 'confirm', 'manage']
const ACTION_LABELS = {
  view: 'View', create: 'Create', edit: 'Edit', delete: 'Delete',
  approve: 'Approve', confirm: 'Confirm', manage: 'Manage',
}

// ── Indeterminate checkbox ────────────────────────────────────────────────────
function IndeterminateCheckbox({ checked, indeterminate, onChange, disabled }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate && !checked
  }, [indeterminate, checked])
  return (
    <input
      ref={ref}
      type="checkbox"
      checked={checked}
      onChange={onChange}
      disabled={disabled}
      className="h-4 w-4 cursor-pointer rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30 disabled:cursor-default disabled:opacity-50"
    />
  )
}

// ── Permission matrix ─────────────────────────────────────────────────────────
function PermissionMatrix({ groups, selected, onChange, disabled }) {
  const allPerms = Object.values(groups ?? {}).flatMap((m) =>
    Object.values(m.resources).flatMap((r) => r.permissions)
  )
  const allChecked = allPerms.length > 0 && allPerms.every((p) => selected.has(p))
  const someChecked = allPerms.some((p) => selected.has(p))

  const toggle = (perm) => {
    const next = new Set(selected)
    if (next.has(perm)) next.delete(perm); else next.add(perm)
    onChange(next)
  }

  const toggleRow = (perms) => {
    const allOn = perms.every((p) => selected.has(p))
    const next = new Set(selected)
    if (allOn) perms.forEach((p) => next.delete(p))
    else perms.forEach((p) => next.add(p))
    onChange(next)
  }

  const toggleAll = () => {
    onChange(allChecked ? new Set() : new Set(allPerms))
  }

  if (!groups || Object.keys(groups).length === 0) {
    return (
      <div className="flex items-center justify-center py-12 text-sm text-slate-400">
        No permissions available.
      </div>
    )
  }

  return (
    <div className="space-y-5">
      {/* Select / Clear All */}
      <div className="flex items-center gap-3">
        <IndeterminateCheckbox
          checked={allChecked}
          indeterminate={someChecked}
          onChange={toggleAll}
          disabled={disabled}
        />
        <span className="text-sm font-medium text-slate-600">
          {allChecked ? 'Clear all permissions' : 'Select all permissions'}
        </span>
        <span className="ml-auto rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
          {selected.size} / {allPerms.length} selected
        </span>
      </div>

      {Object.entries(groups).map(([moduleKey, module]) => {
        // Columns for this group = every action used by at least one of its
        // resources, kept in the canonical ACTION_ORDER.
        const columns = ACTION_ORDER.filter((a) =>
          Object.values(module.resources).some((r) => (r.actions ?? []).includes(a))
        )

        return (
          <div key={moduleKey}>
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
              {module.label}
            </p>

            <div className="overflow-x-auto rounded-lg border border-slate-200">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50">
                    <th className="w-8 px-3 py-2" />
                    <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500">Resource</th>
                    {columns.map((a) => (
                      <th key={a} className="px-3 py-2 text-center text-xs font-semibold text-slate-500 w-20">
                        {ACTION_LABELS[a]}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {Object.entries(module.resources).map(([resourceKey, resource]) => {
                    const perms = resource.permissions
                    const actions = resource.actions ?? []
                    const rowChecked  = perms.every((p) => selected.has(p))
                    const rowPartial  = perms.some((p) => selected.has(p))

                    return (
                      <tr key={resourceKey} className="hover:bg-slate-50/50">
                        {/* Row select-all checkbox */}
                        <td className="px-3 py-2 text-center">
                          <IndeterminateCheckbox
                            checked={rowChecked}
                            indeterminate={rowPartial}
                            onChange={() => toggleRow(perms)}
                            disabled={disabled}
                          />
                        </td>

                        {/* Resource label */}
                        <td className="px-3 py-2 font-medium text-slate-700 whitespace-nowrap">
                          {resource.label}
                        </td>

                        {/* Action cells */}
                        {columns.map((action) => {
                          const perm   = `${action}_${resourceKey}`
                          const exists = actions.includes(action)
                          return (
                            <td key={action} className="px-3 py-2 text-center">
                              {exists ? (
                                <input
                                  type="checkbox"
                                  checked={selected.has(perm)}
                                  onChange={() => toggle(perm)}
                                  disabled={disabled}
                                  className="h-4 w-4 cursor-pointer rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30 disabled:cursor-default disabled:opacity-50"
                                />
                              ) : (
                                <span className="text-slate-200">—</span>
                              )}
                            </td>
                          )
                        })}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function RoleFormPage() {
  const { id }      = useParams()
  const isEdit      = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const [roleName,   setRoleName]   = useState('')
  const [nameError,  setNameError]  = useState('')
  const [selected,   setSelected]   = useState(new Set())

  // Fetch permission groups
  const { data: groupsData, isLoading: groupsLoading } = useQuery({
    queryKey: ['permissions-grouped'],
    queryFn:  getPermissionsGrouped,
    staleTime: Infinity,
  })

  // Fetch existing role (edit mode)
  const { data: roleData, isLoading: roleLoading } = useQuery({
    queryKey: ['role', id],
    queryFn:  () => getRole(id),
    enabled:  isEdit,
    staleTime: 0,
  })

  // Populate form from fetched role
  useEffect(() => {
    if (!roleData?.data) return
    setRoleName(roleData.data.label ?? roleData.data.name)
    setSelected(new Set(roleData.data.permissions ?? []))
  }, [roleData])

  const role      = roleData?.data
  const isSystem  = role?.is_system ?? false
  const groups    = groupsData?.data ?? {}

  const crumbs = [
    { label: 'Roles & Permissions', to: '/admin/roles' },
    { label: isEdit ? (role?.label ?? 'Edit Role') : 'New Role' },
  ]

  // ── Mutations ──────────────────────────────────────────────────────────────
  const createMutation = useMutation({
    mutationFn: async (payload) => {
      const { data: created } = await createRole({ name: payload.name })
      await syncRolePermissions(created.id, payload.permissions)
      return created
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      showSuccess('Role created successfully.')
      navigate('/admin/roles')
    },
    onError: (err) => {
      const serverErrors = err.response?.data?.errors ?? {}
      if (serverErrors.name) setNameError(serverErrors.name[0])
      else showError(err.response?.data?.message ?? 'Failed to create role.')
    },
  })

  const updateMutation = useMutation({
    mutationFn: async (payload) => {
      if (!isSystem) await updateRole(id, { name: payload.name })
      await syncRolePermissions(id, payload.permissions)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      queryClient.invalidateQueries({ queryKey: ['role', id] })
      showSuccess('Role updated successfully.')
      navigate('/admin/roles')
    },
    onError: (err) => {
      const serverErrors = err.response?.data?.errors ?? {}
      if (serverErrors.name) setNameError(serverErrors.name[0])
      else showError(err.response?.data?.message ?? 'Failed to update role.')
    },
  })

  const isPending = createMutation.isPending || updateMutation.isPending
  const isLoading = groupsLoading || (isEdit && roleLoading)

  const handleSubmit = (e) => {
    e.preventDefault()
    setNameError('')

    if (!isSystem && !roleName.trim()) {
      setNameError('Role name is required.')
      return
    }

    const payload = {
      name:        roleName.trim().toLowerCase().replace(/\s+/g, '_'),
      permissions: Array.from(selected),
    }

    if (isEdit) updateMutation.mutate(payload)
    else        createMutation.mutate(payload)
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center gap-2 text-sm text-slate-400">
        <Loader2 size={16} className="animate-spin" />
        Loading…
      </div>
    )
  }

  return (
    <div>
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {isEdit ? `Edit Role: ${role?.label ?? ''}` : 'New Role'}
          </h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
      </div>
      <form onSubmit={handleSubmit} noValidate className="mt-5">
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">

          {/* ── Left: Role info ── */}
          <div className="lg:col-span-1">
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="mb-4 text-sm font-semibold text-slate-700">Role Details</h2>

              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">
                  Role Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={roleName}
                  onChange={(e) => { setRoleName(e.target.value); setNameError('') }}
                  disabled={isSystem}
                  placeholder="e.g. Warehouse Manager"
                  className={[
                    'w-full rounded-lg border-2 px-3 py-1.5 text-sm outline-none transition-all',
                    'focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15',
                    isSystem
                      ? 'cursor-not-allowed border-slate-100 bg-slate-100 text-slate-400'
                      : nameError
                        ? 'border-red-300 bg-red-50/40'
                        : 'border-slate-200 bg-slate-50',
                  ].join(' ')}
                />
                {nameError && <p className="mt-1 text-xs text-red-600">{nameError}</p>}
                {isSystem && (
                  <p className="mt-1.5 text-xs text-amber-600">
                    System roles cannot be renamed.
                  </p>
                )}
              </div>

              {isEdit && role && (
                <div className="mt-4 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
                  <span className="font-medium">Internal name:</span>{' '}
                  <code className="rounded bg-slate-200 px-1 py-0.5 text-slate-600">{role.name}</code>
                </div>
              )}

              {/* Save button */}
              <div className="mt-5 flex gap-2">
                <button
                  type="button"
                  onClick={() => navigate('/admin/roles')}
                  className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isPending}
                  className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                  {isPending
                    ? <Loader2 size={14} className="animate-spin" />
                    : <Save size={14} />
                  }
                  {isEdit ? 'Save Changes' : 'Create Role'}
                </button>
              </div>
            </div>
          </div>

          {/* ── Right: Permission matrix ── */}
          <div className="lg:col-span-2">
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-slate-700">Permissions</h2>
                {role?.name === 'super_admin' && (
                  <span className="rounded-full bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700">
                    Super Admin bypasses all permission checks
                  </span>
                )}
              </div>

              <PermissionMatrix
                groups={groups}
                selected={selected}
                onChange={setSelected}
                disabled={role?.name === 'super_admin'}
              />
            </div>
          </div>

        </div>
      </form>
    </div>
  )
}
