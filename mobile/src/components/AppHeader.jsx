import { Pressable, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { C } from '../lib/theme'

/**
 * Compact custom header (44px + status-bar inset) with the ERP brand badge —
 * the same text badge the web sidebar uses — and the page title. Replaces the
 * much taller platform-default navigation header.
 */
export default function AppHeader({ navigation, route, options, back }) {
  const insets = useSafeAreaInsets()
  const title = options.title ?? route.name

  return (
    <View style={[s.wrap, { paddingTop: insets.top }]}>
      <View style={s.bar}>
        {back ? (
          <Pressable onPress={navigation.goBack} hitSlop={10} style={s.backBtn}>
            <Text style={s.backIcon}>‹</Text>
          </Pressable>
        ) : null}
        <View style={s.logo}><Text style={s.logoText}>PG</Text></View>
        <Text style={s.title} numberOfLines={1}>{title}</Text>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  wrap: { backgroundColor: C.card, borderBottomWidth: 1, borderBottomColor: C.border },
  bar: { height: 44, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 10, gap: 8 },
  backBtn: { marginRight: 2, paddingRight: 4 },
  backIcon: { fontSize: 26, lineHeight: 28, color: C.primary, fontWeight: '700' },
  logo: { backgroundColor: C.primary, borderRadius: 5, paddingHorizontal: 6, paddingVertical: 2 },
  logoText: { color: '#fff', fontSize: 11, fontWeight: '800', letterSpacing: 0.5 },
  title: { flex: 1, fontSize: 15, fontWeight: '700', color: C.text },
})
