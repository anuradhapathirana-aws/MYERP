import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Receipt } from 'lucide-react'
import { getGrnLandedCharges } from '../../api/goodsReceivedNotes'
import Money from '../ui/Money'

const TH_CLS = 'px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500'

/**
 * Supplier bills (freight, duty, clearing...) linked to a confirmed GRN in
 * Finance > Bills — the shipment's landed costs. Hidden when Finance is off or
 * nothing is linked yet.
 */
export default function GrnLandedCharges({ grnId }) {
  const { data } = useQuery({
    queryKey: ['grn-landed-charges', grnId],
    queryFn:  () => getGrnLandedCharges(grnId),
    enabled:  Boolean(grnId),
    staleTime: 30 * 1000,
  })

  const charges = data?.charges ?? []
  if (!data?.available || !charges.length) return null

  const total = charges.reduce((s, c) => s + Number(c.amount), 0)

  return (
    <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-sky-100 bg-sky-50 px-3 py-1.5">
        <div className="flex items-center gap-1.5 text-xs font-bold text-sky-700">
          <Receipt size={12} /> Linked Charges <span className="font-normal text-slate-500">landed costs from supplier bills</span>
        </div>
        <span className="text-[11px] text-slate-500">Total cost: <strong className="text-sky-700"><Money value={total} /></strong></span>
      </div>
      <table className="w-full text-xs">
        <thead className="bg-slate-50 text-left">
          <tr>
            <th className={TH_CLS}>Bill</th>
            <th className={TH_CLS}>Date</th>
            <th className={TH_CLS}>From</th>
            <th className={TH_CLS}>Charge</th>
            <th className={`${TH_CLS} text-right`}>Cost</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {charges.map((c) => (
            <tr key={c.source_id}>
              <td className="px-2 py-1 font-mono">
                <Link to={`/finance/bills/${c.bill_id}`} className="text-indigo-600 hover:underline">{c.bill_no}</Link>
              </td>
              <td className="px-2 py-1 text-slate-500">{c.bill_date}</td>
              <td className="max-w-[14rem] truncate px-2 py-1 text-slate-600" title={c.party_name ?? ''}>{c.party_name ?? '—'}</td>
              <td className="px-2 py-1 text-slate-700">{c.charge_name}</td>
              <td className="px-2 py-1 text-right font-semibold tabular-nums"><Money value={c.amount} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
