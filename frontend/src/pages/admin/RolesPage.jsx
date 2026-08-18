import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Plus, Shield } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { deleteRole, getRoles } from '../../api/roles'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [{ label: 'Roles & Permissions' }]

const SYSTEM_COLOURS = {
  super_admin:  'bg-purple-100 text-purple-700 border-purple-200',
  admin: 'bg-indigo-100 text-indigo-700 border-indigo-200',
  staff:        'bg-slate-100  text-slate-600  border-slate-200',
}

function RoleBadge({ name, isSystem }) {
  const cls = SYSTEM_COLOURS[name] ?? 'bg-teal-100 text-teal-700 border-teal-200'
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium ${cls}`}>
      {isSystem && <Shield size={10} />}
      {name.replace(/_/g, ' ')}
    </span>
  )
}

export default function RolesPage() {
  const navigate     = useNavigate()
  const queryClient  = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['roles'],
    queryFn:  getRoles,
    staleTime: 30_000,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteRole,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      showSuccess('Role deleted.')
    },
    onError: (err) => {
      const msg = err.response?.data?.message ?? 'Failed to delete role.'
      showError(msg)
    },
  })

  const handleDelete = async (role) => {
    const ok = await confirmDelete(role.label)
    if (ok) deleteMutation.mutate(role.id)
  }

  const roles = data?.data ?? []

  return (
    <div>
      {/* Page header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Roles & Permissions</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <button
          type="button"
          onClick={() => navigate('/admin/roles/create')}
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={15} strokeWidth={2.5} />
          New Role
        </button>
      </div>

      {/* Table card */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-400">
            <Loader2 size={16} className="animate-spin" />
            Loading roles…
          </div>
        )}

        {isError && (
          <div className="flex items-center justify-center py-16 text-sm text-red-500">
            Failed to load roles. Check that the backend is running.
          </div>
        )}

        {!isLoading && !isError && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="w-10 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">#</th>
                  <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                  <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Type</th>
                  <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Permissions</th>
                  <th className="sticky right-0 w-24 bg-slate-50 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Actions
                  </th>
                </tr>
              </thead>

              <tbody className="divide-y divide-slate-100">
                {roles.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-14 text-center text-sm text-slate-400">
                      No roles found.
                    </td>
                  </tr>
                ) : (
                  roles.map((role, i) => (
                    <tr key={role.id} className="transition-colors hover:bg-slate-50">
                      <td className="px-4 py-2.5 text-slate-400">{i + 1}</td>
                      <td className="px-4 py-2.5">
                        <RoleBadge name={role.name} isSystem={role.is_system} />
                      </td>
                      <td className="px-4 py-2.5 text-slate-500">
                        {role.is_system ? (
                          <span className="text-xs text-slate-400">System</span>
                        ) : (
                          <span className="text-xs text-teal-600">Custom</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-slate-600">
                        {role.permissions?.length > 0 ? (
                          <span className="text-sm">
                            <span className="font-medium text-slate-800">{role.permissions.length}</span>
                            <span className="ml-1 text-slate-400">permissions</span>
                          </span>
                        ) : (
                          <span className="text-xs italic text-slate-300">
                            {role.name === 'super_admin' ? 'Full access (bypasses all checks)' : 'None assigned'}
                          </span>
                        )}
                      </td>
                      <td className="sticky right-0 bg-white px-4 py-2.5">
                        <div className="flex items-center justify-end gap-1">
                          <EditBtn onClick={() => navigate(`/admin/roles/${role.id}/edit`)} title="Edit permissions" />
                          {!role.is_system && (
                            <DeleteBtn onClick={() => handleDelete(role)} disabled={deleteMutation.isPending} title="Delete role" />
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
