import { Lock, X } from 'lucide-react'

/**
 * Shown when a switched-off module is clicked in the menu. A super admin can go
 * straight to System Settings > Modules; everyone else is told whom to ask.
 */
export default function UpgradeModal({ module, canSwitchOn = false, onOpenSettings, onClose }) {
  if (!module) return null

  return (
    <>
      {/* Backdrop */}
      <div
        className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm"
        onClick={onClose}
      />

      {/* Panel */}
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div className="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          {/* Close */}
          <button
            onClick={onClose}
            className="absolute right-3 top-3 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
          >
            <X size={18} />
          </button>

          {/* Icon */}
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50">
            <Lock size={22} className="text-indigo-600" />
          </div>

          {/* Text */}
          <h2 className="mt-4 text-center text-xl font-bold text-slate-800">
            {module.label} Module
          </h2>
          <p className="mt-2 text-center text-sm leading-relaxed text-slate-500">
            {canSwitchOn ? (
              <>The <strong>{module.label}</strong> module is switched off on this installation. Switch it on in System Settings › Modules.</>
            ) : (
              <>The <strong>{module.label}</strong> module is not switched on for this installation. Ask your system administrator to switch it on.</>
            )}
          </p>

          {/* Actions */}
          <div className="mt-5 flex flex-col gap-2">
            {canSwitchOn ? (
              <button
                className="w-full rounded-lg bg-indigo-600 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                onClick={onOpenSettings}
              >
                Open System Settings
              </button>
            ) : (
              <button
                className="w-full rounded-lg bg-indigo-600 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                onClick={onClose}
              >
                OK
              </button>
            )}
            {canSwitchOn && (
              <button
                onClick={onClose}
                className="w-full rounded-lg py-1.5 text-sm text-slate-400 transition-colors hover:text-slate-600"
              >
                Not now
              </button>
            )}
          </div>
        </div>
      </div>
    </>
  )
}
