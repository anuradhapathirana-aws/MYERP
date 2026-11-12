import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Blocks, Database, Loader2, Power, PowerOff } from 'lucide-react'
import { getModules, setModuleEnabled } from '../../api/system'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmAction, escapeHtml, showError, showSuccess } from '../../utils/alerts'

const CRUMBS = [{ label: 'System Settings' }, { label: 'Modules' }]

const usageText = (usage) =>
  usage.map((u) => `${u.count.toLocaleString()} ${u.label}`).join(', ')

/**
 * System Settings > Modules (super admin only). The ONE place a module is
 * switched on or off for this installation; roles & permissions then decide
 * which of its screens each user can open. Switching off never deletes data.
 */
export default function ModulesPage() {
  const queryClient = useQueryClient()

  const { data: modules = [], isLoading, isError, error } = useQuery({
    queryKey: ['system-modules'],
    queryFn:  getModules,
  })

  const mutation = useMutation({
    mutationFn: ({ key, enabled }) => setModuleEnabled(key, enabled),
    onSuccess: (mod) => {
      queryClient.invalidateQueries({ queryKey: ['system-modules'] })
      // The menu follows the switch right away.
      queryClient.invalidateQueries({ queryKey: ['me'] })
      showSuccess(`${mod.label} is now switched ${mod.enabled ? 'on' : 'off'}.`)
    },
    onError: (err) => showError(err.response?.data?.message ?? 'Could not change the module.'),
  })

  const toggle = async (mod) => {
    const turningOn = !mod.enabled
    const label = escapeHtml(mod.label)
    const data = mod.usage.length > 0 ? escapeHtml(usageText(mod.usage)) : ''

    const message = turningOn
      ? `Switch on <b>${label}</b> for everyone on this installation?<br><span style="font-size:12px;color:#64748b">Users see its screens according to their roles &amp; permissions.</span>`
      : `Switch off <b>${label}</b>? Its screens will be hidden from every user.`
        + (data ? `<br><br><span style="color:#b45309">This module already holds ${data}. Nothing is deleted: switching it on again brings everything back.</span>` : '')

    const ok = await confirmAction({
      title:        turningOn ? 'Switch on module' : 'Switch off module',
      message,
      confirmText:  turningOn ? 'Yes, switch on' : 'Yes, switch off',
      confirmColor: turningOn ? '#16a34a' : '#dc2626',
      icon:         turningOn ? 'question' : 'warning',
    })
    if (ok) mutation.mutate({ key: mod.key, enabled: turningOn })
  }

  return (
    <div>
      <div>
        <h1 className="text-xl font-bold leading-none text-slate-800">Modules</h1>
        <Breadcrumb crumbs={CRUMBS} />
      </div>

      <p className="mt-2 text-xs text-slate-500">
        Switch modules on or off for the whole installation. Who sees which screens inside a module is set in Roles &amp; Permissions.
      </p>

      {isLoading && (
        <div className="mt-3 flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-8 text-sm text-slate-400">
          <Loader2 size={16} className="animate-spin" /> Loading modules…
        </div>
      )}

      {isError && (
        <div className="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
          {error?.response?.data?.message ?? 'Failed to load modules.'}
        </div>
      )}

      {!isLoading && !isError && (
        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          {modules.map((mod) => {
            const busy = mutation.isPending && mutation.variables?.key === mod.key
            return (
              <div
                key={mod.key}
                className={`rounded-xl border bg-white p-3 shadow-sm ${mod.enabled ? 'border-green-200' : 'border-slate-200'}`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${mod.enabled ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400'}`}>
                      <Blocks size={16} />
                    </span>
                    <span className="text-sm font-semibold text-slate-800">{mod.label}</span>
                  </div>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${mod.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                    {mod.enabled ? 'On' : 'Off'}
                  </span>
                </div>

                <p className="mt-2 text-xs leading-relaxed text-slate-500">{mod.description}</p>

                <div className="mt-2 flex items-center gap-1.5 text-xs text-slate-500">
                  <Database size={12} className="shrink-0 text-slate-400" />
                  {mod.usage.length > 0 ? usageText(mod.usage) : 'No data yet'}
                </div>

                <div className="mt-3 flex justify-end">
                  <button
                    type="button"
                    onClick={() => toggle(mod)}
                    disabled={mutation.isPending}
                    className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition-colors disabled:opacity-60 ${
                      mod.enabled ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700'
                    }`}
                  >
                    {busy
                      ? <Loader2 size={13} className="animate-spin" />
                      : mod.enabled ? <PowerOff size={13} /> : <Power size={13} />}
                    {mod.enabled ? 'Switch off' : 'Switch on'}
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
