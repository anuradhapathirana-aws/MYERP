import { Stack, useLocalSearchParams, useRouter } from 'expo-router'
import { useEffect, useRef, useState } from 'react'
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native'
import { apiErrorMessage } from '../../api/client'
import { getAllCustomers } from '../../api/customers'
import { createSalesOrder, getSalesOrder, scanSalesPiece, updateSalesOrder } from '../../api/salesOrders'
import Scanner from '../../components/Scanner'
import { Btn, Card, Empty, Field, Input, Loading, PickerField, Screen } from '../../components/ui'
import { CURRENCY, fmtMoney, fmtQty, fmtRs, isValidDate, today } from '../../lib/format'
import { C } from '../../lib/theme'
import { useSession } from '../../store/session'

const lineWeight = (line) => line.pieces.reduce((sum, p) => sum + (Number(p.weight) || 0), 0)

export default function CreateSalesOrder() {
  const { user } = useSession()
  const router = useRouter()
  // With ?id= this same screen edits an existing DRAFT sales order.
  const { id: editId } = useLocalSearchParams()
  const isEdit = Boolean(editId)

  const [customers, setCustomers] = useState([])
  const [customer, setCustomer] = useState(null)
  const [orderDate, setOrderDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const [lines, setLines] = useState([])
  const [scannerOpen, setScannerOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [loadingSo, setLoadingSo] = useState(isEdit)
  const linesRef = useRef(lines)
  linesRef.current = lines

  useEffect(() => {
    getAllCustomers().then(setCustomers).catch(() => {})
  }, [])

  // Edit mode: prefill the form from the saved draft.
  useEffect(() => {
    if (!isEdit) return
    getSalesOrder(editId)
      .then((res) => {
        const so = res.data
        if (so.status !== 'draft') {
          Alert.alert('Not editable', 'Only DRAFT sales orders can be edited.', [
            { text: 'OK', onPress: () => router.back() },
          ])
          return
        }
        setCustomer({
          id: so.customer_id,
          name: so.customer?.name ?? `Customer #${so.customer_id}`,
          customer_type: so.customer_type ?? so.customer?.customer_type ?? null,
          shipping_address: so.delivery_address ?? '',
        })
        setOrderDate(so.order_date ?? today())
        setRemarks(so.remarks ?? '')
        setLines((so.items ?? []).map((item, i) => ({
          key: `so-${item.id ?? i}`,
          product_id:   item.product_id,
          product_name: item.product?.name ?? `Product #${item.product_id}`,
          product_code: item.product?.product_code ?? '',
          unit_id:      item.unit_id,
          unit_name:    item.unit?.name ?? '',
          attribute_id: item.attribute_id,
          color_name:   item.attribute?.name ?? '',
          price_key:    item.unit_price ?? 0,
          unit_price:   item.unit_price != null ? String(item.unit_price) : '',
          pieces: (item.pieces ?? []).map((p) => ({
            piece_code: p.piece_code,
            weight:     Number(p.weight) || 0,
            roll_no:    p.roll_no ?? '',
            color:      item.attribute?.name ?? '',
            attribute_id: item.attribute_id,
            selling_price: item.unit_price ?? null,
          })),
        })))
      })
      .catch((e) => {
        Alert.alert('Load failed', apiErrorMessage(e, 'Could not load the sales order.'), [
          { text: 'OK', onPress: () => router.back() },
        ])
      })
      .finally(() => setLoadingSo(false))
  }, [editId])

  /** Resolve a scanned label WITHOUT adding it — the scanner shows the result and waits for "+ Add". */
  const lookupPiece = async (code) => {
    const current = linesRef.current
    if (current.some((l) => l.pieces.some((p) => p.piece_code === code))) {
      return { ok: false, message: `${code} is already on this order.` }
    }

    let scan
    try {
      scan = await scanSalesPiece(code)
    } catch (e) {
      return {
        ok: false,
        message: e.response?.status === 404 ? `Piece ${code} not found.` : apiErrorMessage(e, 'Failed to resolve the scanned piece.'),
      }
    }

    if (!scan.available) {
      return { ok: false, message: scan.unavailable_reason || `Piece ${code} is not available.` }
    }

    const colorTag = scan.piece.color ? ` (${scan.piece.color})` : ''
    const priceTag = scan.selling_price != null ? ` @ ${fmtMoney(scan.selling_price)}` : ''
    return {
      ok: true,
      message: `${scan.product.name}${colorTag}\nRoll ${scan.piece.roll_no || scan.piece.piece_code} — ${fmtQty(scan.piece.weight)} ${scan.product.unit?.name ?? ''}${priceTag}`,
      payload: scan,
    }
  }

  /**
   * Same merge rule as the web SO form: one line per product + colour +
   * selling price, so old stock keeps its old price and new stock its new one.
   */
  const addScan = (scan) => {
    const piece = {
      piece_code: scan.piece.piece_code,
      weight:     Number(scan.piece.weight) || 0,
      roll_no:    scan.piece.roll_no ?? '',
      color:      scan.piece.color ?? '',
      attribute_id: scan.piece.attribute_id ?? null,
      selling_price: scan.selling_price ?? null,
    }

    setLines((prev) => {
      const existing = prev.find((l) =>
        String(l.product_id) === String(scan.product.id) &&
        String(l.attribute_id ?? '') === String(scan.piece.attribute_id ?? '') &&
        Number(l.price_key ?? 0) === Number(scan.selling_price ?? 0))
      if (existing) {
        // Safety net: never let the same roll onto a line twice, even if a
        // rapid double-scan slipped past the check above.
        if (existing.pieces.some((p) => p.piece_code === piece.piece_code)) return prev
        return prev.map((l) => (l.key === existing.key ? { ...l, pieces: [...l.pieces, piece] } : l))
      }
      return [...prev, {
        key: `${Date.now()}-${Math.random()}`,
        product_id:   scan.product.id,
        product_name: scan.product.name ?? '',
        product_code: scan.product.product_code ?? '',
        unit_id:      scan.product.unit?.id ?? null,
        unit_name:    scan.product.unit?.name ?? '',
        attribute_id: scan.piece.attribute_id ?? null,
        color_name:   scan.piece.color ?? '',
        price_key:    scan.selling_price ?? 0,
        unit_price:   scan.selling_price != null ? String(scan.selling_price) : '',
        pieces:       [piece],
      }]
    })
  }

  const removePiece = (lineKey, pieceCode) => {
    setLines((prev) => prev
      .map((l) => (l.key === lineKey ? { ...l, pieces: l.pieces.filter((p) => p.piece_code !== pieceCode) } : l))
      .filter((l) => l.pieces.length > 0))
  }

  const setLinePrice = (lineKey, value) => {
    setLines((prev) => prev.map((l) => (l.key === lineKey ? { ...l, unit_price: value } : l)))
  }

  const removeLine = (lineKey, name) => {
    Alert.alert('Remove item', `Remove ${name} and all its rolls from this order?`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: () => setLines((prev) => prev.filter((l) => l.key !== lineKey)) },
    ])
  }

  const confirmRemovePiece = (lineKey, p) => {
    Alert.alert('Remove roll', `Remove roll ${p.roll_no || p.piece_code} from this order?`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: () => removePiece(lineKey, p.piece_code) },
    ])
  }

  const totalPieces = lines.reduce((n, l) => n + l.pieces.length, 0)
  const grandTotal  = lines.reduce((sum, l) => sum + lineWeight(l) * (parseFloat(l.unit_price) || 0), 0)

  const save = async () => {
    if (!customer)             return Alert.alert('Missing', 'Select a customer first.')
    if (!isValidDate(orderDate)) return Alert.alert('Missing', 'Order date must be YYYY-MM-DD.')
    if (lines.length === 0)    return Alert.alert('Missing', 'Scan at least one roll.')
    const unpriced = lines.find((l) => l.unit_price === '' || isNaN(parseFloat(l.unit_price)))
    if (unpriced) return Alert.alert('Missing', `Enter a unit price for ${unpriced.product_name}.`)

    setSaving(true)
    try {
      const payload = {
        order_date:       orderDate,
        customer_id:      customer.id,
        customer_type:    customer.customer_type || null,
        sales_person_id:  user.user_id,
        delivery_address: customer.shipping_address || null,
        remarks:          remarks.trim() || null,
        status:           'draft',
        items: lines.map((l) => ({
          product_id:   l.product_id,
          unit_id:      l.unit_id,
          attribute_id: l.attribute_id,
          quantity:     lineWeight(l),
          unit_price:   parseFloat(l.unit_price) || 0,
          discount:     0,
          tax:          0,
          piece_codes:  l.pieces.map((p) => p.piece_code),
        })),
      }
      const res = isEdit
        ? await updateSalesOrder(editId, payload)
        : await createSalesOrder(payload)
      const so = res.data
      Alert.alert('Saved as Draft', `Sales order ${so.so_no} was ${isEdit ? 'updated' : 'created'}.`, [
        { text: 'OK', onPress: () => router.replace(`/so/${so.id}`) },
      ])
    } catch (e) {
      Alert.alert('Save failed', apiErrorMessage(e, 'Could not save the sales order.'))
    } finally {
      setSaving(false)
    }
  }

  if (loadingSo) {
    return (
      <>
        <Stack.Screen options={{ title: 'Edit Sales Order' }} />
        <Loading text="Loading draft…" />
      </>
    )
  }

  return (
    <Screen>
      <Stack.Screen options={{ title: isEdit ? 'Edit Sales Order' : 'New Sales Order' }} />
      <Card title="Order Details">
        <PickerField
          label="Customer"
          required
          value={customer ? customer.name : ''}
          items={customers.map((c) => ({ id: c.id, label: c.name, sub: `${c.customer_code ?? ''} ${c.customer_type ?? ''}`.trim(), raw: c }))}
          onSelect={(item) => setCustomer(item.raw)}
        />
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <View style={{ flex: 1 }}>
            <Field label="Order Date" required>
              <Input value={orderDate} onChangeText={setOrderDate} placeholder="YYYY-MM-DD" autoCapitalize="none" />
            </Field>
          </View>
          <View style={{ flex: 1 }}>
            <Field label="Sales Person">
              <Input value={user?.user_name ?? ''} editable={false} style={{ color: C.sub }} />
            </Field>
          </View>
        </View>
        <Field label="Remarks">
          <Input value={remarks} onChangeText={setRemarks} placeholder="Optional note" />
        </Field>
      </Card>

      <Btn
        title={totalPieces === 0 ? '📷  Scan Rolls' : `📷  Scan More Rolls (${totalPieces} scanned)`}
        onPress={() => setScannerOpen(true)}
      />

      <Card title={`Items (${lines.length})`}>
        {lines.length === 0 ? <Empty text="Scan a roll QR label to add items." /> : lines.map((l) => (
          <View key={l.key} style={s.line}>
            <View style={{ flexDirection: 'row', alignItems: 'center' }}>
              <Text style={[s.lineTitle, { flex: 1 }]}>
                {l.product_name}{l.color_name ? ` — ${l.color_name}` : ''}
              </Text>
              <Pressable onPress={() => removeLine(l.key, l.product_name)} hitSlop={8} style={s.removeBtn}>
                <Text style={s.removeBtnText}>✕ Remove</Text>
              </Pressable>
            </View>
            <View style={s.metaRow}>
              <View style={s.metaBox}>
                <Text style={s.metaLabel}>Qty ({l.unit_name || 'unit'})</Text>
                <Text style={s.metaValue}>{fmtQty(lineWeight(l))}</Text>
              </View>
              <View style={s.metaBox}>
                <Text style={s.metaLabel}>Unit Price ({CURRENCY}/{l.unit_name || 'unit'})</Text>
                <Input
                  value={String(l.unit_price)}
                  onChangeText={(v) => setLinePrice(l.key, v)}
                  keyboardType="decimal-pad"
                  style={s.priceInput}
                />
              </View>
              <View style={[s.metaBox, { alignItems: 'flex-end' }]}>
                <Text style={s.metaLabel}>Amount</Text>
                <Text style={s.metaValue}>{fmtRs(lineWeight(l) * (parseFloat(l.unit_price) || 0))}</Text>
              </View>
            </View>
            <Text style={s.metaLabel}>Rolls ({l.pieces.length})</Text>
            <View style={s.chipsWrap}>
              {l.pieces.map((p) => (
                <Pressable
                  key={p.piece_code}
                  onPress={() => confirmRemovePiece(l.key, p)}
                  style={s.chip}
                >
                  <Text style={s.chipText}>Roll {p.roll_no || p.piece_code} · {fmtQty(p.weight)} {l.unit_name}  ✕</Text>
                </Pressable>
              ))}
            </View>
          </View>
        ))}
        {lines.length > 0 ? (
          <View style={s.totalRow}>
            <Text style={{ fontSize: 13, fontWeight: '700', color: C.text }}>Grand Total</Text>
            <Text style={{ fontSize: 15, fontWeight: '800', color: C.primary }}>{fmtRs(grandTotal)}</Text>
          </View>
        ) : null}
        <Text style={s.hint}>Tap a roll to remove it; ✕ Remove deletes the whole item.</Text>
      </Card>

      <Btn title="Save as Draft" kind="success" onPress={save} loading={saving} disabled={lines.length === 0} />
      <Text style={s.footNote}>The office will confirm this order in the ERP system.</Text>

      <Scanner
        visible={scannerOpen}
        onClose={() => setScannerOpen(false)}
        onLookup={lookupPiece}
        onAdd={addScan}
        summary={`${totalPieces} roll${totalPieces === 1 ? '' : 's'} on the order`}
      />
    </Screen>
  )
}

const s = StyleSheet.create({
  line: { borderBottomWidth: 1, borderBottomColor: C.border, paddingVertical: 8, gap: 6 },
  lineTitle: { fontSize: 13, fontWeight: '700', color: C.text },
  metaRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
  metaBox: { flex: 1, gap: 2 },
  metaLabel: { fontSize: 10, fontWeight: '600', color: C.faint, textTransform: 'uppercase' },
  metaValue: { fontSize: 13, fontWeight: '700', color: C.text },
  priceInput: { paddingVertical: 4, textAlign: 'right' },
  chipsWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 5 },
  chip: {
    backgroundColor: C.primarySoft, borderRadius: 999,
    paddingHorizontal: 8, paddingVertical: 3,
  },
  chipText: { fontSize: 11, color: C.primary, fontWeight: '600' },
  removeBtn: { backgroundColor: C.dangerSoft, borderRadius: 5, paddingHorizontal: 8, paddingVertical: 3 },
  removeBtnText: { fontSize: 11, fontWeight: '700', color: C.danger },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 8 },
  hint: { fontSize: 10, color: C.faint, marginTop: 6 },
  footNote: { fontSize: 11, color: C.faint, textAlign: 'center', marginBottom: 16 },
})
