import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Box, Calendar, MapPin, Package, QrCode, Tag, Truck, Weight } from 'lucide-react'
import { getGrnItemPiece } from '../../api/grnItemPieces'

function Row({ icon: Icon, label, value }) {
  return (
    <div className="flex items-start gap-2 py-1.5">
      {Icon && <Icon size={13} className="mt-0.5 shrink-0 text-slate-400" />}
      <span className="w-28 shrink-0 text-xs text-slate-500">{label}</span>
      <span className="text-xs font-medium text-slate-800">{value ?? <span className="italic text-slate-400">—</span>}</span>
    </div>
  )
}

export default function PieceScanResultPage() {
  const { pieceCode } = useParams()

  const { data: piece, isLoading, isError, error } = useQuery({
    queryKey: ['grn-item-piece', pieceCode],
    queryFn:  () => getGrnItemPiece(pieceCode),
    retry:    false,
  })

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <span className="text-sm text-slate-500">Loading…</span>
      </div>
    )
  }

  if (isError) {
    const status = error?.response?.status
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 text-center shadow-sm">
          <QrCode size={28} className="mx-auto mb-3 text-slate-300" />
          {status === 401 ? (
            <>
              <h1 className="text-sm font-bold text-slate-800">Please log in</h1>
              <p className="mt-1 text-xs text-slate-500">You need to be signed in to view this piece's details.</p>
              <Link to="/login" className="mt-4 inline-block rounded bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                Go to Login
              </Link>
            </>
          ) : (
            <>
              <h1 className="text-sm font-bold text-slate-800">Piece not found</h1>
              <p className="mt-1 text-xs text-slate-500">No record matches code <span className="font-mono">{pieceCode}</span>.</p>
            </>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="mx-auto w-full max-w-md rounded-lg border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-200 bg-indigo-50 px-4 py-3">
          <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-indigo-600">
            <QrCode size={12} /> Piece Detail
          </div>
          <div className="mt-0.5 font-mono text-sm font-bold text-slate-800">{piece.piece_code}</div>
        </div>

        <div className="px-4 py-3">
          <Row icon={Package}  label="Product"       value={piece.product ? `${piece.product.name} (${piece.product.product_code})` : null} />
          <Row icon={Truck}    label="Shipping Code" value={piece.grn?.shipping_code} />
          <Row icon={Tag}      label="Roll No"       value={piece.roll_no} />
          <Row icon={Weight}   label="Weight"   value={piece.weight != null ? piece.weight : null} />
          <Row icon={Tag}      label="Batch No" value={piece.batch?.batch_no} />
          <Row icon={Calendar} label="Expiry"   value={piece.batch?.expiry_date} />
          <Row icon={Box}      label="Store"    value={piece.store?.store_name} />
          <Row icon={MapPin}   label="Location" value={piece.location?.name} />
          <Row label="Status"      value={piece.status} />
          <Row label="Piece No."   value={piece.piece_no} />
          <Row label="Printed At"  value={piece.printed_at} />
        </div>

        {piece.grn && (
          <div className="border-t border-slate-200 bg-slate-50 px-4 py-2.5">
            <div className="flex items-center justify-between text-xs">
              <span className="text-slate-500">GRN {piece.grn.grn_no} · {piece.grn.grn_date}</span>
              <Link to={`/inventory/goods-received-notes/${piece.grn.id}/edit`} className="font-semibold text-indigo-600 hover:underline">
                View GRN
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
