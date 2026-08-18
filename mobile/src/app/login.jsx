import { useRouter } from 'expo-router'
import { useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { apiErrorMessage } from '../api/client'
import { Btn, Card, Field, Input, Screen } from '../components/ui'
import { C } from '../lib/theme'
import { useSession } from '../store/session'

export default function LoginScreen() {
  const { login, serverUrl } = useSession()
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!email.trim() || !password) {
      setError('Enter your email and password.')
      return
    }
    setBusy(true)
    setError('')
    try {
      await login(email.trim(), password)
      router.replace('/home')
    } catch (e) {
      setError(e.response?.status === 401 || e.response?.status === 422
        ? 'Invalid email or password.'
        : apiErrorMessage(e, 'Login failed.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen style={{ flexGrow: 1, justifyContent: 'center' }}>
      <View style={{ alignItems: 'center', marginBottom: 18 }}>
        <Text style={s.logo}>PG Inventory</Text>
        <Text style={{ color: C.sub, fontSize: 13 }}>Sales Orders by QR scan</Text>
      </View>

      <Card>
        <Field label="Email" required>
          <Input
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            placeholder="you@company.com"
          />
        </Field>
        <Field label="Password" required error={error || null}>
          <Input
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            placeholder="••••••••"
            onSubmitEditing={submit}
          />
        </Field>
        <Btn title="Sign In" onPress={submit} loading={busy} style={{ marginTop: 4 }} />
      </Card>

      <Pressable onPress={() => router.push('/settings')} style={{ alignItems: 'center', marginTop: 14 }}>
        <Text style={{ color: C.primary, fontSize: 13, fontWeight: '600' }}>
          Server: {serverUrl || 'not set'} — change
        </Text>
      </Pressable>
    </Screen>
  )
}

const s = StyleSheet.create({
  logo: { fontSize: 26, fontWeight: '800', color: C.primary },
})
