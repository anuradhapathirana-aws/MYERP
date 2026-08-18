import { useCallback, useEffect, useRef, useState } from 'react'
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native'
import { C } from '../lib/theme'
import { Empty, Input, Loading } from './ui'

/**
 * Server-paginated document list with search + status filter chips, shared by
 * the SO and DO screens. `fetchPage(page, { search, status })` must resolve to
 * a Laravel paginated resource response ({ data, meta }).
 */
export default function DocList({ fetchPage, statuses, renderItem, onPressItem, emptyText }) {
  const [rows, setRows] = useState([])
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const pageRef = useRef({ current: 1, last: 1, fetching: false })
  const searchTimer = useRef(null)

  const load = useCallback(async (page, { reset = false, silent = false } = {}, overrides = {}) => {
    if (pageRef.current.fetching) return
    pageRef.current.fetching = true
    if (!silent && page === 1) setLoading(true)
    try {
      const filters = { }
      const st = overrides.status ?? status
      const q  = overrides.search ?? search
      if (st) filters.status = st
      if (q.trim()) filters.search = q.trim()
      const res = await fetchPage(page, filters)
      const data = res.data ?? []
      const meta = res.meta ?? res
      pageRef.current.current = meta.current_page ?? page
      pageRef.current.last    = meta.last_page ?? page
      setRows((prev) => (reset || page === 1 ? data : [...prev, ...data]))
      setError('')
    } catch {
      setError('Could not load. Pull down to retry.')
    } finally {
      pageRef.current.fetching = false
      setLoading(false)
      setRefreshing(false)
    }
  }, [fetchPage, status, search])

  useEffect(() => { load(1, { reset: true }) }, [])

  const changeStatus = (st) => {
    setStatus(st)
    load(1, { reset: true }, { status: st })
  }

  const changeSearch = (q) => {
    setSearch(q)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(1, { reset: true }, { search: q }), 400)
  }

  const loadMore = () => {
    const { current, last, fetching } = pageRef.current
    if (!fetching && current < last) load(current + 1, { silent: true })
  }

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <View style={s.controls}>
        <Input placeholder="Search…" value={search} onChangeText={changeSearch} />
        <View style={s.chips}>
          {[{ id: '', label: 'All' }, ...statuses].map((st) => (
            <Pressable
              key={st.id}
              onPress={() => changeStatus(st.id)}
              style={[s.chip, status === st.id && s.chipActive]}
            >
              <Text style={[s.chipText, status === st.id && { color: '#fff' }]}>{st.label}</Text>
            </Pressable>
          ))}
        </View>
      </View>

      {loading ? <Loading /> : (
        <FlatList
          data={rows}
          keyExtractor={(r) => String(r.id)}
          renderItem={({ item }) => (
            <Pressable onPress={() => onPressItem(item)} style={({ pressed }) => [s.row, pressed && { backgroundColor: C.primarySoft }]}>
              {renderItem(item)}
            </Pressable>
          )}
          onEndReached={loadMore}
          onEndReachedThreshold={0.4}
          refreshControl={
            <RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); load(1, { reset: true, silent: true }) }} />
          }
          ListEmptyComponent={<Empty text={error || emptyText || 'No documents found.'} />}
          contentContainerStyle={{ paddingBottom: 24 }}
        />
      )}
    </View>
  )
}

const s = StyleSheet.create({
  controls: { padding: 8, gap: 6 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: {
    borderRadius: 999, paddingHorizontal: 12, paddingVertical: 5,
    backgroundColor: C.card, borderWidth: 1, borderColor: C.border,
  },
  chipActive: { backgroundColor: C.primary, borderColor: C.primary },
  chipText: { fontSize: 12, fontWeight: '600', color: C.sub },
  row: {
    backgroundColor: C.card, marginHorizontal: 8, marginBottom: 6,
    borderRadius: 8, borderWidth: 1, borderColor: C.border, padding: 10,
  },
})
