import { Lock, X } from 'lucide-react'

export default function UpgradeModal({ module, onClose }) {
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
        <div className="relative w-full max-w-sm rounded-2xl bg-white p-8 shadow-2xl">
          {/* Close */}
          <button
            onClick={onClose}
            className="absolute right-4 top-4 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
          >
            <X size={18} />
          </button>

          {/* Icon */}
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50">
            <Lock size={24} className="text-indigo-600" />
          </div>

          {/* Text */}
          <h2 className="mt-5 text-center text-xl font-bold text-slate-800">
            {module.label} Module
          </h2>
          <p className="mt-2 text-center text-sm leading-relaxed text-slate-500">
            This module is not included in your current plan. Contact your administrator
            or upgrade your subscription to unlock <strong>{module.label}</strong>.
          </p>

          {/* Actions */}
          <div className="mt-7 flex flex-col gap-2.5">
            <button
              className="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
              onClick={onClose}
            >
              Contact Administrator
            </button>
            <button
              onClick={onClose}
              className="w-full rounded-lg py-2 text-sm text-slate-400 transition-colors hover:text-slate-600"
            >
              Maybe later
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
