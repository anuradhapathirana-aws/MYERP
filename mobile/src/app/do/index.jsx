import { useRouter } from 'expo-router'
import { Text, View } from 'react-native'
import { getDeliveryOrders } from '../../api/deliveryOrders'
import DocList from '../../components/DocList'
import { StatusBadge } from '../../components/ui'
import { fmtQty } from '../../lib/format'
import { C } from '../../lib/theme'

const STATUSES = [
  { id: 'draft', label: 'Draft' },
  { id: 'confirmed', label: 'Confirmed' },
  { id: 'cancelled', label: 'Cancelled' },
]

export default function DeliveryOrderList() {
  const router = useRouter()
  return (
    <DocList
      fetchPage={getDeliveryOrders}
      statuses={STATUSES}
      onPressItem={(doDoc) => router.push(`/do/${doDoc.id}`)}
      emptyText="No delivery orders yet."
      renderItem={(doDoc) => (
        <View>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
            <Text style={{ fontSize: 14, fontWeight: '700', color: C.text }}>{doDoc.do_no}</Text>
            <StatusBadge status={doDoc.status} label={doDoc.status_label} />
          </View>
          <Text style={{ fontSize: 12, color: C.sub, marginTop: 2 }} numberOfLines={1}>
            {doDoc.customer?.name ?? `Customer #${doDoc.customer_id}`}
            {doDoc.sales_order?.so_no ? ` · ${doDoc.sales_order.so_no}` : ''}
          </Text>
          <Text style={{ fontSize: 12, color: C.sub, marginTop: 2 }}>
            Deliver {doDoc.delivery_date}{doDoc.total_quantity != null ? ` · qty ${fmtQty(doDoc.total_quantity)}` : ''}
          </Text>
        </View>
      )}
    />
  )
}
