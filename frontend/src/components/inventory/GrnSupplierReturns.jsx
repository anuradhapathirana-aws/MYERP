import { useState } from 'react'
import { Link } from 'react-router-dom'
import { PackageMinus } from 'lucide-react'
import { downloadSupplierReturnPdf } from '../../api/supplierReturns'
import Money from '../ui/Money'
import { ViewBtn, PrintBtn, PdfBtn } from '../ui/ActionButtons'
import { showError } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'

const TH_CLS = 'px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500'

/** Confirmed Supplier Returns raised against a GRN — shown under a confirmed GRN. */
export default function GrnSupplierReturns({ returns }) {
  const [pdfBusy, setPdfBusy] = useState(null)

  if (!returns?.length) return null

  const handlePdf = async (r, print) => {
    setPdfBusy(r.id)
    try {
      const blob = await downloadSupplierReturnPdf(r.id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href     = url
        a.download = `PRN_${r.return_no}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate the return note PDF.')
    } finally {
      setPdfBusy(null)
    }
  }

  return (
    <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center gap-1.5 border-b border-rose-100 bg-rose-50 px-3 py-1.5">
        <PackageMinus size={12} className="text-rose-600" />
        <span className="text-[10px] font-bold uppercase tracking-wider text-rose-700">Supplier Returns</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-slate-200 text-left">
              <th className={`${TH_CLS} w-28`}>Return No</th>
              <th className={`${TH_CLS} w-24`}>Date</th>
              <th className={`${TH_CLS} w-32 text-right`}>Return Value</th>
              <th className={`${TH_CLS} w-32 text-right`} title="Part of the return that reduced what this GRN still owed">Deducted from GRN</th>
              <th className={TH_CLS} title="Excess over what was owed, kept as supplier credit">Credit Note</th>
              <th className={`${TH_CLS} w-24 text-right`}>Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {returns.map((r) => (
              <tr key={r.id} className="transition-colors hover:bg-slate-50/60">
                <td className="px-2 py-1.5 font-mono font-medium text-indigo-600">
                  <Link to={`/inventory/supplier-returns/${r.id}/edit`} className="hover:underline">{r.return_no}</Link>
                </td>
                <td className="whitespace-nowrap px-2 py-1.5 text-slate-500">{r.return_date}</td>
                <td className="px-2 py-1.5 text-right font-medium text-slate-700"><Money value={r.total_amount} /></td>
                <td className="px-2 py-1.5 text-right text-slate-600"><Money value={r.applied_to_grn} /></td>
                <td className="px-2 py-1.5 font-mono text-slate-600">{r.credit_note_no || <span className="italic text-slate-300">—</span>}</td>
                <td className="px-2 py-1.5">
                  <div className="flex items-center justify-end gap-1">
                    <ViewBtn to={`/inventory/supplier-returns/${r.id}/edit`} />
                    <PrintBtn onClick={() => handlePdf(r, true)} disabled={pdfBusy === r.id} />
                    <PdfBtn onClick={() => handlePdf(r, false)} disabled={pdfBusy === r.id} />
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
