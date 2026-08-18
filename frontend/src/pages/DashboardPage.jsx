export default function DashboardPage() {
  return (
    <div>
      <h1 className="text-2xl font-bold text-slate-800">Dashboard</h1>

      {/* Placeholder stat cards */}
      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {['Total Orders', 'Active Items', 'Low Stock', 'Pending Invoices'].map((label) => (
          <div
            key={label}
            className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
          >
            <p className="text-xs font-medium uppercase tracking-wider text-slate-400">{label}</p>
            <p className="mt-2 text-3xl font-bold text-slate-800">—</p>
          </div>
        ))}
      </div>
    </div>
  )
}
