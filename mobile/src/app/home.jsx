import { useRouter } from 'expo-router'
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native'
import { Screen } from '../components/ui'
import { C } from '../lib/theme'
import { useSession } from '../store/session'

function Tile({ title, subtitle, icon, color, onPress, disabled }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [s.tile, { opacity: disabled ? 0.4 : pressed ? 0.85 : 1 }]}
    >
      <Text style={[s.tileIcon, { color }]}>{icon}</Text>
      <Text style={s.tileTitle}>{title}</Text>
      <Text style={s.tileSub}>{subtitle}</Text>
    </Pressable>
  )
}

export default function HomeScreen() {
  const { user, logout, can } = useSession()
  const router = useRouter()

  const confirmLogout = () => {
    Alert.alert('Sign out', 'Sign out of PG Inventory?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign Out', style: 'destructive', onPress: logout },
    ])
  }

  return (
    <Screen>
      <View style={s.userRow}>
        <View style={{ flex: 1 }}>
          <Text style={{ fontSize: 15, fontWeight: '700', color: C.text }}>{user?.user_name ?? ''}</Text>
          <Text style={{ fontSize: 12, color: C.sub }}>{user?.user_email ?? ''}</Text>
        </View>
        <Pressable onPress={confirmLogout} hitSlop={8}>
          <Text style={{ color: C.danger, fontWeight: '700', fontSize: 13 }}>Sign Out</Text>
        </Pressable>
      </View>

      <View style={s.grid}>
        <Tile
          title="New Sales Order"
          subtitle="Scan rolls, save as draft"
          icon="⊕"
          color={C.primary}
          disabled={!can('create_sales_orders')}
          onPress={() => router.push('/so/create')}
        />
        <Tile
          title="Sales Orders"
          subtitle="View created orders"
          icon="≣"
          color={C.green}
          disabled={!can('view_sales_orders')}
          onPress={() => router.push('/so')}
        />
        {/* Delivery Order screens exist but are not released yet — keep the
            tiles visible as a roadmap hint, permanently disabled for now. */}
        <Tile
          title="New Delivery Order"
          subtitle="Coming soon"
          icon="⊞"
          color={C.amber}
          disabled
          onPress={() => {}}
        />
        <Tile
          title="Delivery Orders"
          subtitle="Coming soon"
          icon="▦"
          color={C.sub}
          disabled
          onPress={() => {}}
        />
      </View>

      <Text style={s.note}>
        Orders created here are saved as DRAFT. The office confirms and dispatches them in the ERP system.
      </Text>
    </Screen>
  )
}

const s = StyleSheet.create({
  userRow: {
    flexDirection: 'row', alignItems: 'center',
    backgroundColor: C.card, borderRadius: 8, borderWidth: 1, borderColor: C.border,
    padding: 12,
  },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  tile: {
    width: '48.7%', backgroundColor: C.card, borderRadius: 8,
    borderWidth: 1, borderColor: C.border, padding: 14, minHeight: 110,
  },
  tileIcon: { fontSize: 26, fontWeight: '800', marginBottom: 8 },
  tileTitle: { fontSize: 14, fontWeight: '700', color: C.text },
  tileSub: { fontSize: 11, color: C.sub, marginTop: 2 },
  note: { fontSize: 11, color: C.faint, textAlign: 'center', marginTop: 10, paddingHorizontal: 20 },
})
