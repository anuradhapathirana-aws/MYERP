import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router'
import { useCallback, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { apiErrorMessage } from '../../api/client'
import { getSalesOrder } from '../../api/salesOrders'
import { Btn, Card, Empty, KV, Loading, Screen, StatusBadge } from '../../components/ui'
import { CURRENCY, fmtMoney, fmtQty, fmtRs } from '../../lib/format'
import { C } from '../../lib/theme'

export default function SalesOrderDetail() {
  const { id } = useLocalSearchParams()
  const router = useRouter()
  const [so, setSo] = useState(null)
  const [error, setError] = useState('')

  // Reload every time the screen gains focus, so returning from Edit shows fresh data.
  useFocusEffect(useCallback(() => {
    getSalesOrder(id)
      .then((res) => setSo(res.data))
      .catch((e) => setError(apiErrorMessage(e, 'Could not load the sales order.')))
  }, [id]))

  if (error) return <Empty text={error} />
  if (!so) return <Loading />

  return (
    <Screen>
      <Card>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <Text style={{ fontSize: 17, fontWeight: '800', color: C.text }}>{so.so_no}</Text>
          <StatusBadge status={so.status} label={so.status_label} />
        </View>
        <KV k="Customer" v={so.customer?.name} />
        <KV k="Order Date" v={so.order_date} />
        <KV k="Sales Person" v={so.sales_person?.name} />
        <KV k="Delivery Address" v={so.delivery_address} />
        <KV k="Remarks" v={so.remarks} />
        <KV k="Grand Total" v={fmtRs(so.grand_total)} />
        {so.status === 'draft' ? (
          <Btn
            title="✎  Edit Draft"
            onPress={() => router.push(`/so/create?id=${so.id}`)}
            style={{ marginTop: 8 }}
          />
        ) : null}
      </Card>

      <Card title={`Items (${so.items?.length ?? 0})`}>
        {(so.items ?? []).map((item) => {
          const unit = item.unit?.name ?? ''
          return (
            <View key={item.id} style={s.line}>
              <Text style={s.lineTitle}>
                {item.product?.name ?? `Product #${item.product_id}`}
                {item.attribute?.name ? ` — ${item.attribute.name}` : ''}
              </Text>
              <View style={s.metaRow}>
                <View style={s.metaBox}>
                  <Text style={s.metaLabel}>Qty ({unit || 'unit'})</Text>
                  <Text style={s.metaValue}>{fmtQty(item.quantity)}</Text>
                </View>
                <View style={s.metaBox}>
                  <Text style={s.metaLabel}>Unit Price ({CURRENCY}/{unit || 'unit'})</Text>
                  <Text style={s.metaValue}>{fmtMoney(item.unit_price)}</Text>
                </View>
                <View style={[s.metaBox, { alignItems: 'flex-end' }]}>
                  <Text style={s.metaLabel}>Amount</Text>
                  <Text style={s.metaValue}>{fmtRs(item.line_total)}</Text>
                </View>
              </View>
              {(item.pieces ?? []).length > 0 ? (
                <>
                  <Text style={s.metaLabel}>Rolls ({item.pieces.length})</Text>
                  <View style={s.chipsWrap}>
                    {item.pieces.map((p) => (
                      <View key={p.piece_code ?? p.id} style={s.chip}>
                        <Text style={s.chipText}>
                          Roll {p.roll_no || p.piece_code}{p.weight != null ? ` · ${fmtQty(p.weight)} ${unit}` : ''}
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

      {(so.delivery_orders ?? []).length > 0 ? (
        <Card title="Delivery Orders">
          {so.delivery_orders.map((d) => (
            <View key={d.id} style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4 }}>
              <Text style={{ fontSize: 13, color: C.text, fontWeight: '600' }}>{d.do_no}</Text>
              <StatusBadge status={d.status} label={d.status_label} />
            </View>
          ))}
        </Card>
      ) : null}
    </Screen>
  )
}

const s = StyleSheet.create({
  line: { borderBottomWidth: 1, borderBottomColor: C.border, paddingVertical: 7, gap: 5 },
  lineTitle: { fontSize: 13, fontWeight: '700', color: C.text },
  metaRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
  metaBox: { flex: 1, gap: 2 },
  metaLabel: { fontSize: 10, fontWeight: '600', color: C.faint, textTransform: 'uppercase' },
  metaValue: { fontSize: 13, fontWeight: '700', color: C.text },
  chipsWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 5, marginTop: 3 },
  chip: { backgroundColor: C.slateSoft, borderWidth: 1, borderColor: C.border, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 },
  chipText: { fontSize: 11, color: C.sub, fontWeight: '600' },
})
