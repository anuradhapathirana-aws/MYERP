import { CameraView, useCameraPermissions } from 'expo-camera'
import { useEffect, useRef, useState } from 'react'
import { Modal, Pressable, StyleSheet, Text, Vibration, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { normalizeScannedCode } from '../lib/format'
import { C } from '../lib/theme'
import { Btn } from './ui'

/**
 * Full-screen QR scanner with explicit confirmation — nothing is added
 * automatically:
 *
 *   1. The camera reads ONE label, then freezes.
 *   2. `onLookup(code)` resolves what the label is (without adding it) and
 *      the result is shown: a valid roll offers "+ Add", anything else shows
 *      the error. "Scan Next" discards and resumes the camera either way.
 *   3. "+ Add" hands the looked-up payload to `onAdd(payload)`, shows a brief
 *      confirmation, and resumes scanning for the next roll.
 *   4. "Done" closes the scanner.
 *
 * onLookup(code) must resolve to { ok, message, payload? }.
 */
export default function Scanner({ visible, onClose, onLookup, onAdd, summary }) {
  // The APK runs edge-to-edge on Android: content draws behind the status and
  // navigation bars, so every overlay must be inset by the device's real safe
  // areas or the bottom controls end up underneath the system buttons.
  const insets = useSafeAreaInsets()
  const [permission, requestPermission] = useCameraPermissions()
  const [pending, setPending] = useState(null) // lookup result awaiting the user's decision
  const [justAdded, setJustAdded] = useState('')
  const [torch, setTorch] = useState(false)
  // State updates are async, but camera frames keep arriving — this ref blocks
  // them synchronously so one label can never be processed twice per pause.
  const lockRef = useRef(false)
  const addedTimer = useRef(null)

  useEffect(() => {
    if (visible) {
      setPending(null)
      setJustAdded('')
      lockRef.current = false
      if (!permission?.granted) requestPermission()
    }
    return () => clearTimeout(addedTimer.current)
  }, [visible])

  const handleBarcode = async ({ data }) => {
    if (lockRef.current) return
    const code = normalizeScannedCode(data)
    if (!code) return

    lockRef.current = true // stays locked until Add / Scan Next
    setJustAdded('')
    const result = await onLookup(code).catch(() => ({ ok: false, message: 'Scan failed.' }))
    Vibration.vibrate(result?.ok ? 60 : [0, 120, 80, 120])
    setPending(result ?? { ok: false, message: 'Scan failed.' })
  }

  const resume = () => {
    setPending(null)
    lockRef.current = false
  }

  const confirmAdd = () => {
    if (pending?.ok && pending.payload !== undefined) onAdd(pending.payload)
    Vibration.vibrate(40)
    setJustAdded('✓ Added to the order')
    clearTimeout(addedTimer.current)
    addedTimer.current = setTimeout(() => setJustAdded(''), 2200)
    resume()
  }

  const paused = pending !== null

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <View style={s.wrap}>
        {permission?.granted ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            facing="back"
            enableTorch={torch}
            barcodeScannerSettings={{ barcodeTypes: ['qr', 'code128', 'code39'] }}
            onBarcodeScanned={paused ? undefined : handleBarcode}
          />
        ) : (
          <View style={s.permBox}>
            <Text style={s.permText}>Camera access is needed to scan roll QR labels.</Text>
            <Btn title="Allow Camera" onPress={requestPermission} />
          </View>
        )}

        {/* Aiming frame — green while live, dimmed while a result is showing.
            Anchored at 50% of the screen height (frame is 230px tall, so its
            top sits at half-screen minus half the frame) — explicit numbers,
            because flex centering proved unreliable on some devices. */}
        <View pointerEvents="none" style={s.frameWrap}>
          <View style={[s.frame, paused && { borderColor: 'rgba(255,255,255,0.25)' }]} />
          {!paused ? <Text style={s.frameHint}>Point at a roll QR label</Text> : null}
        </View>

        {/* Top bar */}
        <View style={[s.topBar, { paddingTop: insets.top + 8 }]}>
          <Text style={s.summary} numberOfLines={1}>{summary ?? 'Scan a roll QR label'}</Text>
          <Pressable onPress={() => setTorch((t) => !t)} hitSlop={8} style={s.torchBtn}>
            <Text style={{ color: '#fff', fontSize: 12, fontWeight: '700' }}>{torch ? '🔦 On' : '🔦 Off'}</Text>
          </Pressable>
        </View>

        {/* Brief confirmation after Add */}
        {justAdded ? (
          <View style={[s.banner, { top: insets.top + 60, backgroundColor: C.green }]}>
            <Text style={s.bannerText}>{justAdded}</Text>
          </View>
        ) : null}

        {/* Scan result awaiting the user's decision */}
        {pending ? (
          <View style={[s.resultCard, { bottom: insets.bottom + 88, borderColor: pending.ok ? C.green : C.danger }]}>
            <Text style={[s.resultBadge, { color: pending.ok ? C.green : C.danger }]}>
              {pending.ok ? 'ROLL FOUND' : 'CANNOT ADD'}
            </Text>
            <Text style={s.resultText}>{pending.message}</Text>
            <View style={{ flexDirection: 'row', gap: 8, marginTop: 10 }}>
              {pending.ok ? <Btn title="＋ Add" kind="success" onPress={confirmAdd} style={{ flex: 2 }} /> : null}
              <Btn title="Scan Next" kind={pending.ok ? 'ghost' : 'primary'} onPress={resume} style={{ flex: pending.ok ? 1 : 2 }} />
            </View>
          </View>
        ) : null}

        <View style={[s.bottomBar, { paddingBottom: insets.bottom + 10 }]}>
          <Btn title="Done" kind="success" onPress={onClose} style={{ flex: 1 }} />
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#000' },
  permBox: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 14 },
  permText: { color: '#fff', fontSize: 15, textAlign: 'center' },
  frameWrap: {
    position: 'absolute', left: 0, right: 0,
    top: '50%', marginTop: -130, // frame (230) + hint, visually centered
    alignItems: 'center',
  },
  frame: {
    width: 230, height: 230, borderWidth: 2, borderColor: 'rgba(52,211,153,0.95)',
    borderRadius: 14, backgroundColor: 'transparent',
  },
  frameHint: { color: 'rgba(255,255,255,0.85)', fontSize: 12, marginTop: 10, fontWeight: '600' },
  topBar: {
    position: 'absolute', top: 0, left: 0, right: 0,
    paddingBottom: 10, paddingHorizontal: 14,
    backgroundColor: 'rgba(0,0,0,0.55)',
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10,
  },
  summary: { color: '#fff', fontSize: 13, fontWeight: '600', flex: 1 },
  torchBtn: { backgroundColor: 'rgba(255,255,255,0.2)', borderRadius: 6, paddingHorizontal: 10, paddingVertical: 5 },
  banner: {
    position: 'absolute', left: 12, right: 12,
    borderRadius: 8, padding: 10,
  },
  bannerText: { color: '#fff', fontSize: 13, fontWeight: '700', textAlign: 'center' },
  resultCard: {
    position: 'absolute', left: 12, right: 12,
    backgroundColor: 'rgba(15,23,42,0.94)', borderRadius: 10, borderWidth: 2,
    padding: 12,
  },
  resultBadge: { fontSize: 11, fontWeight: '800', letterSpacing: 1, marginBottom: 4 },
  resultText: { color: '#fff', fontSize: 14, fontWeight: '600' },
  bottomBar: {
    position: 'absolute', bottom: 0, left: 0, right: 0,
    padding: 12, backgroundColor: 'rgba(0,0,0,0.55)',
    flexDirection: 'row', gap: 8,
  },
})
