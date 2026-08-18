import { useRouter } from 'expo-router'
import { useRef, useState } from 'react'
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native'
import { apiErrorMessage } from '../../api/client'
import {
  createDeliveryOrder, getAllLocations, getAllStores, getDoSourceSalesOrder,
} from '../../api/deliveryOrders'
import { getSalesOrders } from '../../api/salesOrders'
import Scanner from '../../components/Scanner'
import {
  Btn, Card, Empty, Field, Input, PickerField, Screen, SearchPickerModal,
} from '../../components/ui'
import { fmtQty, isValidDate, today } from '../../lib/format'
import { C } from '../../lib/theme'

export default function CreateDeliveryOrder() {
  const router = useRouter()

  /* ── Confirmed-SO picker (server-side search) ── */
  const [soPickerOpen, setSoPickerOpen] = useState(false)
  const [soOptions, setSoOptions] = useState([])
  const [soLoading, setSoLoading] = useState(false)
  const soSearchTimer = useRef(null)

  /* ── Loaded source SO + lines ── */
  const [source, setSource] = useState(null) // { sales_order, items }
  const [lines, setLines] = useState([])     // per SO item: { ...item, selected: {piece_id:true}, qty: '' }
  const linesRef = useRef(lines)
  linesRef.current = lines

  const [deliveryDate, setDeliveryDate] = useState(today())
  const [deliveryAddress, setDeliveryAddress] = useState('')
  const [deliveryMode, setDeliveryMode] = useState('')
  const [deliveryVehicle, setDeliveryVehicle] = useState('')
  const [responsiblePerson, setResponsiblePerson] = useState('')
  const [remarks, setRemarks] = useState('')

  const [stores, setStores] = useState([])
  const [locations, setLocations] = useState([])
  const [store, setStore] = useState(null)
  const [location, setLocation] = useState(null)

  const [scannerOpen, setScannerOpen] = useState(false)
  const [saving, setSaving] = useState(false)

  const searchSos = (q = '') => {
    setSoLoading(true)
    getSalesOrders(1, { status: 'confirmed', ...(q.trim() ? { search: q.trim() } : {}) })
      .then((res) => setSoOptions(res.data ?? []))
      .catch(() => setSoOptions([]))
      .finally(() => setSoLoading(false))
  }

  const openSoPicker = () => {
    setSoPickerOpen(true)
    searchSos()
  }

  const pickSo = async (soId) => {
    setSoPickerOpen(false)
    try {
      const data = await getDoSourceSalesOrder(soId)
      setSource(data)
      setDeliveryAddress(data.sales_order.delivery_address ?? '')
      setLines(data.items.map((item) => ({ ...item, selected: {}, qty: '' })))
      // Store + location only matter when a manual (non-roll) line ships — load them up front if any exist.
      if (data.items.some((i) => !i.is_scanned)) {
        getAllStores().then(setStores).catch(() => {})
        getAllLocations().then(setLocations).catch(() => {})
      }
    } catch (e) {
      Alert.alert('Cannot load SO', apiErrorMessage(e, 'Could not load the sales order.'))
    }
  }

  const togglePiece = (soItemId, pieceId) => {
    setLines((prev) => prev.map((l) => {
      if (l.so_item_id !== soItemId) return l
      const selected = { ...l.selected }
      if (selected[pieceId]) delete selected[pieceId]
      else selected[pieceId] = true
      return { ...l, selected }
    }))
  }

  /** Resolve a scanned roll WITHOUT ticking it — it must be one the SO already allocated. */
  const lookupPiece = async (code) => {
    const current = linesRef.current
    for (const line of current) {
      const piece = (line.available_pieces ?? []).find((p) => p.piece_code === code)
      if (piece) {
        if (line.selected[piece.piece_id]) {
          return { ok: false, message: `Roll ${piece.roll_no || code} is already selected.` }
        }
        return {
          ok: true,
          message: `${line.product?.name ?? ''}${line.attribute?.name ? ` (${line.attribute.name})` : ''}\nRoll ${piece.roll_no || code} — takes ${fmtQty(piece.taken_quantity)} of ${fmtQty(piece.weight)}`,
          payload: { so_item_id: line.so_item_id, piece_id: piece.piece_id },
        }
      }
    }
    return { ok: false, message: `${code} is not an available roll on this sales order.` }
  }

  /** "+ Add" confirmed in the scanner — tick the roll for delivery. */
  const addScan = ({ so_item_id, piece_id }) => {
    setLines((prev) => prev.map((l) =>
      l.so_item_id === so_item_id ? { ...l, selected: { ...l.selected, [piece_id]: true } } : l))
  }

  const selectedCount = lines.reduce((n, l) => n + Object.keys(l.selected).length, 0)
  const manualLines   = lines.filter((l) => !l.is_scanned)
  const manualActive  = manualLines.some((l) => parseFloat(l.qty) > 0)

  const save = async () => {
    if (!source) return
    if (!isValidDate(deliveryDate)) return Alert.alert('Missing', 'Delivery date must be YYYY-MM-DD.')

    const items = []
    for (const l of lines) {
      if (l.is_scanned) {
        const pieceIds = Object.keys(l.selected).map(Number)
        if (pieceIds.length > 0) items.push({ so_item_id: l.so_item_id, piece_ids: pieceIds })
      } else {
        const qty = parseFloat(l.qty)
        if (qty > 0) {
          if (qty > l.remaining + 0.001) {
            return Alert.alert('Too much', `${l.product?.name}: only ${fmtQty(l.remaining)} ${l.unit?.symbol ?? ''} remaining to deliver.`)
          }
          items.push({ so_item_id: l.so_item_id, quantity: qty })
        }
      }
    }
    if (items.length === 0) return Alert.alert('Missing', 'Select at least one roll or enter a quantity.')
    if (manualActive && (!store || !location)) {
      return Alert.alert('Missing', 'Quantity lines need a store and location to ship from.')
    }

    setSaving(true)
    try {
      const res = await createDeliveryOrder({
        so_id:              source.sales_order.id,
        document_date:      today(),
        delivery_date:      deliveryDate,
        store_id:           store?.id ?? null,
        location_id:        location?.id ?? null,
        delivery_mode:      deliveryMode.trim() || null,
        delivery_vehicle:   deliveryVehicle.trim() || null,
        responsible_person: responsiblePerson.trim() || null,
        delivery_address:   deliveryAddress.trim() || null,
        remarks:            remarks.trim() || null,
        items,
      })
      const doDoc = res.data
      Alert.alert('Saved as Draft', `Delivery order ${doDoc.do_no} was created.`, [
        { text: 'OK', onPress: () => router.replace(`/do/${doDoc.id}`) },
      ])
    } catch (e) {
      Alert.alert('Save failed', apiErrorMessage(e, 'Could not save the delivery order.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Screen>
      <Card title="Sales Order">
        <Pressable onPress={openSoPicker} style={s.soPicker}>
          <Text style={{ color: source ? C.text : C.faint, fontSize: 14, fontWeight: source ? '700' : '400' }}>
            {source
              ? `${source.sales_order.so_no} — ${source.sales_order.customer?.name ?? ''}`
              : 'Select a confirmed sales order…'}
          </Text>
          <Text style={{ color: C.faint }}>▾</Text>
        </Pressable>
        {source ? (
          <Text style={{ fontSize: 12, color: C.sub, marginTop: 6 }}>
            Ordered {source.sales_order.order_date} · {source.sales_order.sales_person ?? ''}
          </Text>
        ) : null}
      </Card>

      {source ? (
        <>
          <Card title="Delivery Details">
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Field label="Delivery Date" required>
                  <Input value={deliveryDate} onChangeText={setDeliveryDate} placeholder="YYYY-MM-DD" autoCapitalize="none" />
                </Field>
              </View>
              <View style={{ flex: 1 }}>
                <Field label="Delivery Mode">
                  <Input value={deliveryMode} onChangeText={setDeliveryMode} placeholder="e.g. Company Vehicle" />
                </Field>
              </View>
            </View>
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Field label="Vehicle">
                  <Input value={deliveryVehicle} onChangeText={setDeliveryVehicle} placeholder="e.g. ABC-1234" />
                </Field>
              </View>
              <View style={{ flex: 1 }}>
                <Field label="Responsible Person">
                  <Input value={responsiblePerson} onChangeText={setResponsiblePerson} placeholder="Name" />
                </Field>
              </View>
            </View>
            <Field label="Delivery Address">
              <Input value={deliveryAddress} onChangeText={setDeliveryAddress} placeholder="Address" />
            </Field>
            <Field label="Remarks">
              <Input value={remarks} onChangeText={setRemarks} placeholder="Optional note" />
            </Field>
            {manualLines.length > 0 ? (
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <PickerField
                    label="Store"
                    required={manualActive}
                    value={store?.store_name ?? store?.name ?? ''}
                    items={stores.map((st) => ({ id: st.id, label: st.store_name ?? st.name, raw: st }))}
                    onSelect={(i) => setStore(i.raw)}
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <PickerField
                    label="Location"
                    required={manualActive}
                    value={location?.location_name ?? location?.name ?? ''}
                    items={locations.map((lc) => ({ id: lc.id, label: lc.location_name ?? lc.name, raw: lc }))}
                    onSelect={(i) => setLocation(i.raw)}
                  />
                </View>
              </View>
            ) : null}
          </Card>

          {lines.some((l) => l.is_scanned) ? (
            <Btn
              title={`📷  Scan Rolls to Deliver (${selectedCount} selected)`}
              onPress={() => setScannerOpen(true)}
            />
          ) : null}

          <Card title="Items">
            {lines.length === 0 ? <Empty text="This order has nothing left to deliver." /> : lines.map((l) => (
              <View key={l.so_item_id} style={s.line}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={s.lineTitle} numberOfLines={1}>
                    {l.product?.name ?? ''}{l.attribute?.name ? ` — ${l.attribute.name}` : ''}
                  </Text>
                  <Text style={s.lineQty}>
                    {fmtQty(l.remaining)} {l.unit?.symbol ?? ''} left
                  </Text>
                </View>

                {l.is_scanned ? (
                  l.fully_delivered ? (
                    <Text style={s.deliveredText}>Fully delivered.</Text>
                  ) : (
                    <View style={{ gap: 4, marginTop: 4 }}>
                      {(l.available_pieces ?? []).map((p) => {
                        const on = !!l.selected[p.piece_id]
                        return (
                          <Pressable
                            key={p.piece_id}
                            onPress={() => togglePiece(l.so_item_id, p.piece_id)}
                            style={[s.pieceRow, on && s.pieceRowOn]}
                          >
                            <Text style={[s.pieceCheck, on && { color: C.green }]}>{on ? '☑' : '☐'}</Text>
                            <View style={{ flex: 1 }}>
                              <Text style={{ fontSize: 12, fontWeight: '600', color: C.text }}>
                                Roll {p.roll_no || p.piece_code}{p.is_cut ? '  ✂ cut roll' : ''}
                              </Text>
                              <Text style={{ fontSize: 11, color: C.sub }}>
                                Deliver {fmtQty(p.taken_quantity)} {l.base_unit?.symbol ?? ''} (roll holds {fmtQty(p.weight)} {l.base_unit?.symbol ?? ''})
                                {p.location ? ` · ${p.location}` : ''}
                              </Text>
                            </View>
                          </Pressable>
                        )
                      })}
                      {(l.available_pieces ?? []).length === 0 ? (
                        <Text style={s.deliveredText}>No rolls available (already on another delivery).</Text>
                      ) : null}
                    </View>
                  )
                ) : (
                  <View style={s.qtyRow}>
                    <Text style={{ fontSize: 12, color: C.sub }}>Quantity to deliver</Text>
                    <Input
                      value={l.qty}
                      onChangeText={(v) => setLines((prev) => prev.map((x) => (x.so_item_id === l.so_item_id ? { ...x, qty: v } : x)))}
                      keyboardType="decimal-pad"
                      placeholder="0"
                      style={s.qtyInput}
                      editable={l.remaining > 0}
                    />
                    <Text style={{ fontSize: 12, color: C.sub }}>{l.unit?.symbol ?? ''}</Text>
                  </View>
                )}
              </View>
            ))}
          </Card>

          <Btn
            title="Save as Draft"
            kind="success"
            onPress={save}
            loading={saving}
            disabled={selectedCount === 0 && !manualActive}
          />
          <Text style={s.footNote}>The office will confirm and dispatch this delivery in the ERP system.</Text>
        </>
      ) : null}

      <SearchPickerModal
        visible={soPickerOpen}
        title="Confirmed Sales Orders"
        items={soOptions.map((so) => ({
          id: so.id,
          label: `${so.so_no} — ${so.customer?.name ?? ''}`,
          sub: `${so.order_date} · ${so.status_label ?? so.status}`,
        }))}
        loading={soLoading}
        onSearch={(q) => {
          clearTimeout(soSearchTimer.current)
          soSearchTimer.current = setTimeout(() => searchSos(q), 400)
        }}
        onSelect={(item) => pickSo(item.id)}
        onClose={() => setSoPickerOpen(false)}
      />

      <Scanner
        visible={scannerOpen}
        onClose={() => setScannerOpen(false)}
        onLookup={lookupPiece}
        onAdd={addScan}
        summary={`${selectedCount} roll${selectedCount === 1 ? '' : 's'} selected for delivery`}
      />
    </Screen>
  )
}

const s = StyleSheet.create({
  soPicker: {
    backgroundColor: C.slateSoft, borderWidth: 1, borderColor: '#cbd5e1', borderRadius: 6,
    paddingHorizontal: 10, paddingVertical: 10,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
  },
  line: { borderBottomWidth: 1, borderBottomColor: C.border, paddingVertical: 8 },
  lineTitle: { flex: 1, fontSize: 13, fontWeight: '700', color: C.text, marginRight: 8 },
  lineQty: { fontSize: 12, fontWeight: '600', color: C.sub },
  deliveredText: { fontSize: 12, color: C.faint, marginTop: 3 },
  pieceRow: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: C.slateSoft, borderWidth: 1, borderColor: C.border,
    borderRadius: 6, paddingHorizontal: 8, paddingVertical: 6,
  },
  pieceRowOn: { backgroundColor: C.greenSoft, borderColor: C.green },
  pieceCheck: { fontSize: 16, color: C.faint },
  qtyRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 },
  qtyInput: { width: 100, paddingVertical: 5, textAlign: 'right' },
  footNote: { fontSize: 11, color: C.faint, textAlign: 'center', marginBottom: 16 },
})
