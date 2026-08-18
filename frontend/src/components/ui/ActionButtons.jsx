import { Download, Edit2, Eye, FileSpreadsheet, Printer, Trash2 } from 'lucide-react'
import { Link } from 'react-router-dom'

const VIEW_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 active:bg-blue-200 transition-colors'
const EDIT_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-amber-50 text-amber-600 hover:bg-amber-100 active:bg-amber-200 transition-colors'
const DEL_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-red-50 text-red-500 hover:bg-red-100 active:bg-red-200 transition-colors disabled:opacity-40 disabled:cursor-not-allowed'
const PRINT_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-slate-100 text-slate-600 hover:bg-slate-200 active:bg-slate-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed'
const PDF_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-orange-50 text-orange-600 hover:bg-orange-100 active:bg-orange-200 transition-colors disabled:opacity-40 disabled:cursor-not-allowed'
const EXCEL_CLS =
  'inline-flex items-center justify-center rounded-lg p-1.5 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 active:bg-emerald-200 transition-colors disabled:opacity-40 disabled:cursor-not-allowed'

export function ViewBtn({ to, onClick, title = 'View', size = 14 }) {
  const icon = <Eye size={size} strokeWidth={2} />
  if (to) return <Link to={to} title={title} className={VIEW_CLS}>{icon}</Link>
  return (
    <button type="button" title={title} onClick={onClick} className={VIEW_CLS}>
      {icon}
    </button>
  )
}

export function EditBtn({ to, onClick, title = 'Edit', size = 14 }) {
  const icon = <Edit2 size={size} strokeWidth={2} />
  if (to) return <Link to={to} title={title} className={EDIT_CLS}>{icon}</Link>
  return (
    <button type="button" title={title} onClick={onClick} className={EDIT_CLS}>
      {icon}
    </button>
  )
}

export function DeleteBtn({ onClick, disabled, title = 'Delete', size = 14 }) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      disabled={disabled}
      className={DEL_CLS}
    >
      <Trash2 size={size} strokeWidth={2} />
    </button>
  )
}

export function PrintBtn({ onClick, disabled, title = 'Print', size = 14 }) {
  return (
    <button type="button" title={title} onClick={onClick} disabled={disabled} className={PRINT_CLS}>
      <Printer size={size} strokeWidth={2} />
    </button>
  )
}

export function PdfBtn({ onClick, disabled, title = 'Download PDF', size = 14 }) {
  return (
    <button type="button" title={title} onClick={onClick} disabled={disabled} className={PDF_CLS}>
      <Download size={size} strokeWidth={2} />
    </button>
  )
}

export function ExcelBtn({ onClick, disabled, title = 'Download Excel (CSV)', size = 14 }) {
  return (
    <button type="button" title={title} onClick={onClick} disabled={disabled} className={EXCEL_CLS}>
      <FileSpreadsheet size={size} strokeWidth={2} />
    </button>
  )
}
