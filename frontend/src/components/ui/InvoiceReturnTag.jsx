import { Undo2 } from 'lucide-react'

/**
 * "Partly / Fully Returned" chip shown beside an invoice's payment status. Kept apart from
 * the status badge: a Paid invoice can still have goods returned against it.
 */
export default function InvoiceReturnTag({ status, label }) {
  if (!status || status === 'none') return null

  return (
    <span
      className="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-slate-300 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
      title="Goods were returned against this invoice on a confirmed Customer Return"
    >
      <Undo2 size={10} /> {label}
    </span>
  )
}
