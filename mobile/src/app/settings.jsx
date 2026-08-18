import { useRouter } from 'expo-router'
import { useState } from 'react'
import { Alert, Text } from 'react-native'
import axios from 'axios'
import { Btn, Card, Field, Input, Screen } from '../components/ui'
import { C } from '../lib/theme'
import { useSession } from '../store/session'

export default function SettingsScreen() {
  const { serverUrl, setServerUrl, token } = useSession()
  const router = useRouter()
  // Default to the live server so installed APKs work anywhere with one tap;
  // developers overwrite this with the LAN IP when testing locally.
  const [url, setUrl] = useState(serverUrl || 'https://erp.150theaddress.com')
  const [testing, setTesting] = useState(false)

  const save = async () => {
    const clean = url.trim().replace(/\/+$/, '')
    if (!/^https?:\/\/.+/i.test(clean)) {
      Alert.alert('Invalid URL', 'Enter the full server address, e.g. http://192.168.1.10')
      return
    }
    setTesting(true)
    try {
      // Any HTTP answer (even 401/404) proves the server is reachable — only a network error fails.
      await axios.get(`${clean}/api/login`, { timeout: 8000, validateStatus: () => true })
      await setServerUrl(clean)
      Alert.alert('Connected', 'Server is reachable.', [
        { text: 'OK', onPress: () => router.replace(token ? '/home' : '/login') },
      ])
    } catch {
      Alert.alert(
        'Cannot reach server',
        'No response from that address. Check that:\n\n• the phone and the server are on the same Wi-Fi\n• you used the PC’s IP address (not "localhost")\n• Apache/Laragon is running and allowed through the firewall',
      )
    } finally {
      setTesting(false)
    }
  }

  return (
    <Screen>
      <Card title="ERP Server">
        <Field label="Server URL" required>
          <Input
            value={url}
            onChangeText={setUrl}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="url"
            placeholder="http://192.168.1.10"
          />
        </Field>
        <Text style={{ fontSize: 12, color: C.sub, marginBottom: 10 }}>
          The address of the ERP backend. On the office network this is the server PC's IP
          (e.g. http://192.168.1.10). Do not add /api — the app adds it automatically.
        </Text>
        <Btn title={testing ? 'Testing…' : 'Test & Save'} onPress={save} loading={testing} />
      </Card>
    </Screen>
  )
}
