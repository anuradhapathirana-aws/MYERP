import { useRouter } from 'expo-router'
import { Text, View } from 'react-native'
import { getSalesOrders } from '../../api/salesOrders'
import DocList from '../../components/DocList'
import { StatusBadge } from '../../components/ui'
import { fmtRs } from '../../lib/format'
import { C } from '../../lib/theme'

const STATUSES = [
  { id: 'draft', label: 'Draft' },
  { id: 'confirmed', label: 'Confirmed' },
  { id: 'completed', label: 'Completed' },
  { id: 'cancelled', label: 'Cancelled' },
]

export default function SalesOrderList() {
  const router = useRouter()
  return (
    <DocList
      fetchPage={getSalesOrders}
      statuses={STATUSES}
      onPressItem={(so) => router.push(`/so/${so.id}`)}
      emptyText="No sales orders yet."
      renderItem={(so) => (
        <View>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
            <Text style={{ fontSize: 14, fontWeight: '700', color: C.text }}>{so.so_no}</Text>
            <StatusBadge status={so.status} label={so.status_label} />
          </View>
          <Text style={{ fontSize: 12, color: C.sub, marginTop: 2 }} numberOfLines={1}>
            {so.customer?.name ?? `Customer #${so.customer_id}`} · {so.order_date}
          </Text>
          <Text style={{ fontSize: 13, fontWeight: '600', color: C.text, marginTop: 2 }}>
            {fmtRs(so.grand_total)}
          </Text>
        </View>
      )}
    />
  )
}
