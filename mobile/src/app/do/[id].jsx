import { useLocalSearchParams } from 'expo-router'
import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { apiErrorMessage } from '../../api/client'
import { getDeliveryOrder } from '../../api/deliveryOrders'
import { Card, Empty, KV, Loading, Screen, StatusBadge } from '../../components/ui'
import { fmtQty } from '../../lib/format'
import { C } from '../../lib/theme'

export default function DeliveryOrderDetail() {
  const { id } = useLocalSearchParams()
  const [doDoc, setDoDoc] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    getDeliveryOrder(id)
      .then((res) => setDoDoc(res.data))
      .catch((e) => setError(apiErrorMessage(e, 'Could not load the delivery order.')))
  }, [id])

  if (error) return <Empty text={error} />
  if (!doDoc) return <Loading />

  return (
    <Screen>
      <Card>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <Text style={{ fontSize: 17, fontWeight: '800', color: C.text }}>{doDoc.do_no}</Text>
          <StatusBadge status={doDoc.status} label={doDoc.status_label} />
        </View>
        <KV k="Sales Order" v={doDoc.sales_order?.so_no} />
        <KV k="Customer" v={doDoc.customer?.name} />
        <KV k="Delivery Date" v={doDoc.delivery_date} />
        <KV k="Mode" v={doDoc.delivery_mode} />
        <KV k="Vehicle" v={doDoc.delivery_vehicle ?? doDoc.vehicle?.registration_number} />
        <KV k="Responsible" v={doDoc.responsible_person} />
        <KV k="Address" v={doDoc.delivery_address} />
        <KV k="Remarks" v={doDoc.remarks} />
      </Card>

      <Card title={`Items (${doDoc.items?.length ?? 0})`}>
        {(doDoc.items ?? []).map((item) => {
          const baseUnit = item.base_unit?.symbol ?? item.unit?.symbol ?? ''
          return (
            <View key={item.id} style={s.line}>
              <Text style={s.lineTitle}>
                {item.product?.name ?? `Product #${item.product_id}`}
                {item.attribute?.name ? ` — ${item.attribute.name}` : ''}
              </Text>
              <View style={{ flexDirection: 'row', gap: 10 }}>
                <View style={{ gap: 2 }}>
                  <Text style={s.metaLabel}>Delivery Qty ({item.unit?.symbol ?? 'unit'})</Text>
                  <Text style={s.metaValue}>{fmtQty(item.quantity)}</Text>
                </View>
              </View>
              {(item.pieces ?? []).length > 0 ? (
                <>
                  <Text style={s.metaLabel}>Rolls ({item.pieces.length}) — ✂ = roll is cut</Text>
                  <View style={s.chipsWrap}>
                    {item.pieces.map((p) => (
                      <View key={p.piece_code ?? p.id} style={s.chip}>
                        <Text style={s.chipText}>
                          Roll {p.roll_no || p.piece_code} · {fmtQty(p.taken_quantity ?? p.weight)} {baseUnit}{p.is_cut ? ' ✂' : ''}
                        </Text>
                      </View>
                    ))}
                  </View>
                </>
              ) : null}
            </View>
          )
        })}
      </Card>
    </Screen>
  )
}

const s = StyleSheet.create({
  line: { borderBottomWidth: 1, borderBottomColor: C.border, paddingVertical: 7, gap: 5 },
  lineTitle: { fontSize: 13, fontWeight: '700', color: C.text },
  metaLabel: { fontSize: 10, fontWeight: '600', color: C.faint, textTransform: 'uppercase' },
  metaValue: { fontSize: 13, fontWeight: '700', color: C.text },
  chipsWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 5, marginTop: 3 },
  chip: { backgroundColor: C.slateSoft, borderWidth: 1, borderColor: C.border, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 },
  chipText: { fontSize: 11, color: C.sub, fontWeight: '600' },
})
