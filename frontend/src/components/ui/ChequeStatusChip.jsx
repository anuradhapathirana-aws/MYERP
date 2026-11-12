const STYLES = {
  in_hand:   'bg-slate-100 text-slate-600',
  issued:    'bg-slate-100 text-slate-600',
  deposited: 'bg-sky-100 text-sky-700',
  realized:  'bg-green-100 text-green-700',
  returned:  'bg-red-100 text-red-700',
}

const fmtDate = (d) => (d ? d.split('-').reverse().join('-') : '')

/**
 * Where one cheque stands: In Hand / Issued / Deposited / Realized / Returned.
 * Worked out by the backend (ChequeStatusRegistry); null for non-cheque lines.
 */
export default function ChequeStatusChip({ status }) {
  if (!status) return null

  const detail = [status.reference, fmtDate(status.date)].filter(Boolean).join(' · ')

  return (
    <span
      className={`ml-1 inline-flex whitespace-nowrap rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ${STYLES[status.status] ?? STYLES.in_hand}`}
      title={detail ? `${status.label}: ${detail}` : status.label}
    >
      {status.label}{detail && <span className="ml-1 font-medium normal-case">{detail}</span>}
    </span>
  )
}
