import { useMemo, useState } from 'react'
import {
  ActivityIndicator, FlatList, KeyboardAvoidingView, Modal, Platform, Pressable,
  ScrollView, StyleSheet, Text, TextInput, View,
} from 'react-native'
import { C, STATUS_COLORS } from '../lib/theme'

/** Full-height scrollable page with the app's slate background. */
export function Screen({ children, scroll = true, style }) {
  const body = scroll
    ? <ScrollView contentContainerStyle={[s.screenPad, style]} keyboardShouldPersistTaps="handled">{children}</ScrollView>
    : <View style={[s.screenPad, { flex: 1 }, style]}>{children}</View>
  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: C.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      {body}
    </KeyboardAvoidingView>
  )
}

export function Card({ title, children, style }) {
  return (
    <View style={[s.card, style]}>
      {title ? <Text style={s.cardTitle}>{title}</Text> : null}
      {children}
    </View>
  )
}

export function Label({ children, required }) {
  return (
    <Text style={s.label}>
      {children}{required ? <Text style={{ color: C.danger }}> *</Text> : null}
    </Text>
  )
}

export function Input({ style, ...props }) {
  return (
    <TextInput
      placeholderTextColor={C.faint}
      style={[s.input, style]}
      {...props}
    />
  )
}

export function Field({ label, required, error, children }) {
  return (
    <View style={{ marginBottom: 8 }}>
      {label ? <Label required={required}>{label}</Label> : null}
      {children}
      {error ? <Text style={s.error}>{error}</Text> : null}
    </View>
  )
}

export function Btn({ title, onPress, kind = 'primary', disabled, loading, style, small }) {
  const bg = disabled ? C.faint
    : kind === 'primary' ? C.primary
    : kind === 'danger'  ? C.danger
    : kind === 'success' ? C.green
    : C.card
  const fg = kind === 'ghost' ? C.text : '#fff'
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        s.btn, small && s.btnSmall,
        { backgroundColor: bg, opacity: pressed ? 0.85 : 1 },
        kind === 'ghost' && { borderWidth: 1, borderColor: C.border },
        style,
      ]}
    >
      {loading
        ? <ActivityIndicator color={fg} size="small" />
        : <Text style={[s.btnText, small && { fontSize: 13 }, { color: fg }]}>{title}</Text>}
    </Pressable>
  )
}

export function StatusBadge({ status, label }) {
  const c = STATUS_COLORS[status] ?? { bg: C.border, fg: C.sub }
  return (
    <View style={[s.badge, { backgroundColor: c.bg }]}>
      <Text style={[s.badgeText, { color: c.fg }]}>{label ?? status}</Text>
    </View>
  )
}

/** Compact key/value row for detail screens. */
export function KV({ k, v }) {
  return (
    <View style={s.kvRow}>
      <Text style={s.kvKey}>{k}</Text>
      <Text style={s.kvVal} numberOfLines={2}>{v == null || v === '' ? '—' : String(v)}</Text>
    </View>
  )
}

export function Loading({ text = 'Loading…' }) {
  return (
    <View style={s.center}>
      <ActivityIndicator color={C.primary} />
      <Text style={{ color: C.sub, marginTop: 8, fontSize: 13 }}>{text}</Text>
    </View>
  )
}

export function Empty({ text = 'Nothing here yet.' }) {
  return <View style={s.center}><Text style={{ color: C.faint, fontSize: 13 }}>{text}</Text></View>
}

/**
 * Tap-to-open picker: shows the selected label like an input, opens a
 * searchable full-screen modal list. items: [{ id, label, sub? }].
 */
export function PickerField({ label, required, error, value, placeholder = 'Select…', items, onSelect, disabled, searchable = true }) {
  const [open, setOpen] = useState(false)
  return (
    <Field label={label} required={required} error={error}>
      <Pressable onPress={() => !disabled && setOpen(true)} style={[s.input, s.pickerBox, disabled && { opacity: 0.6 }]}>
        <Text style={{ color: value ? C.text : C.faint, fontSize: 14 }} numberOfLines={1}>
          {value || placeholder}
        </Text>
        <Text style={{ color: C.faint }}>▾</Text>
      </Pressable>
      <SearchPickerModal
        visible={open}
        title={label || 'Select'}
        items={items}
        searchable={searchable}
        onClose={() => setOpen(false)}
        onSelect={(item) => { setOpen(false); onSelect(item) }}
      />
    </Field>
  )
}

export function SearchPickerModal({ visible, title, items = [], onSelect, onClose, searchable = true, onSearch, loading }) {
  const [q, setQ] = useState('')
  const filtered = useMemo(() => {
    if (onSearch || !q.trim()) return items
    const needle = q.trim().toLowerCase()
    return items.filter((i) => `${i.label} ${i.sub ?? ''}`.toLowerCase().includes(needle))
  }, [items, q, onSearch])

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: C.bg }}>
        <View style={s.modalHeader}>
          <Text style={s.modalTitle}>{title}</Text>
          <Pressable onPress={onClose} hitSlop={10}><Text style={{ color: C.primary, fontWeight: '600' }}>Close</Text></Pressable>
        </View>
        {searchable ? (
          <View style={{ paddingHorizontal: 10, paddingBottom: 6 }}>
            <Input
              placeholder="Search…"
              value={q}
              autoFocus
              onChangeText={(t) => { setQ(t); onSearch?.(t) }}
            />
          </View>
        ) : null}
        {loading ? <Loading /> : (
          <FlatList
            data={filtered}
            keyExtractor={(i) => String(i.id)}
            keyboardShouldPersistTaps="handled"
            ListEmptyComponent={<Empty text="No matches." />}
            renderItem={({ item }) => (
              <Pressable onPress={() => onSelect(item)} style={({ pressed }) => [s.pickerRow, pressed && { backgroundColor: C.primarySoft }]}>
                <Text style={{ color: C.text, fontSize: 14, fontWeight: '500' }}>{item.label}</Text>
                {item.sub ? <Text style={{ color: C.sub, fontSize: 12 }}>{item.sub}</Text> : null}
              </Pressable>
            )}
          />
        )}
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  screenPad: { padding: 8, gap: 8 },
  card: {
    backgroundColor: C.card, borderRadius: 8, borderWidth: 1, borderColor: C.border,
    padding: 10,
  },
  cardTitle: { fontSize: 14, fontWeight: '700', color: C.text, marginBottom: 8 },
  label: { fontSize: 12, fontWeight: '600', color: C.sub, marginBottom: 3 },
  input: {
    backgroundColor: C.slateSoft, borderWidth: 1, borderColor: '#cbd5e1', borderRadius: 6,
    paddingHorizontal: 10, paddingVertical: Platform.OS === 'ios' ? 10 : 7,
    fontSize: 14, color: C.text,
  },
  pickerBox: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  error: { color: C.danger, fontSize: 12, marginTop: 2 },
  btn: {
    borderRadius: 6, paddingVertical: 11, paddingHorizontal: 14,
    alignItems: 'center', justifyContent: 'center',
  },
  btnSmall: { paddingVertical: 7, paddingHorizontal: 10 },
  btnText: { fontSize: 14, fontWeight: '700' },
  badge: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'flex-start' },
  badgeText: { fontSize: 11, fontWeight: '700', textTransform: 'capitalize' },
  kvRow: { flexDirection: 'row', paddingVertical: 3, gap: 8 },
  kvKey: { width: 118, fontSize: 12, color: C.sub },
  kvVal: { flex: 1, fontSize: 13, color: C.text, fontWeight: '500' },
  center: { alignItems: 'center', justifyContent: 'center', padding: 24 },
  modalHeader: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    padding: 12, paddingTop: Platform.OS === 'android' ? 40 : 56,
    backgroundColor: C.card, borderBottomWidth: 1, borderBottomColor: C.border,
  },
  modalTitle: { fontSize: 16, fontWeight: '700', color: C.text },
  pickerRow: {
    paddingHorizontal: 14, paddingVertical: 10,
    borderBottomWidth: 1, borderBottomColor: C.border, backgroundColor: C.card,
  },
})
