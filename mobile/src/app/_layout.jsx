import { Stack, useRouter, useSegments } from 'expo-router'
import { useEffect } from 'react'
import { StatusBar } from 'expo-status-bar'
import { SessionProvider, useSession } from '../store/session'
import AppHeader from '../components/AppHeader'
import { Loading } from '../components/ui'
import { C } from '../lib/theme'

function AuthGate({ children }) {
  const { ready, token, serverUrl } = useSession()
  const segments = useSegments()
  const router = useRouter()

  useEffect(() => {
    if (!ready) return
    const first = segments[0]
    const onPublicScreen = first === 'login' || first === 'settings'
    if (!token && !onPublicScreen) {
      // First run has no server URL yet — set that up before asking for credentials.
      router.replace(serverUrl ? '/login' : '/settings')
    } else if (token && first === 'login') {
      // Settings stays reachable while signed in (to change the server URL).
      router.replace('/home')
    }
  }, [ready, token, segments, serverUrl])

  if (!ready) return <Loading text="Starting…" />
  return children
}

export default function RootLayout() {
  return (
    <SessionProvider>
      <StatusBar style="dark" />
      <AuthGate>
        <Stack
          screenOptions={{
            header: (props) => <AppHeader {...props} />,
            contentStyle: { backgroundColor: C.bg },
          }}
        >
          <Stack.Screen name="index" options={{ headerShown: false }} />
          <Stack.Screen name="login" options={{ title: 'Sign In', headerShown: false }} />
          <Stack.Screen name="settings" options={{ title: 'Server Settings' }} />
          <Stack.Screen name="home" options={{ title: 'Home' }} />
          <Stack.Screen name="so/index" options={{ title: 'Sales Orders' }} />
          <Stack.Screen name="so/create" options={{ title: 'New Sales Order' }} />
          <Stack.Screen name="so/[id]" options={{ title: 'Sales Order' }} />
          <Stack.Screen name="do/index" options={{ title: 'Delivery Orders' }} />
          <Stack.Screen name="do/create" options={{ title: 'New Delivery Order' }} />
          <Stack.Screen name="do/[id]" options={{ title: 'Delivery Order' }} />
        </Stack>
      </AuthGate>
    </SessionProvider>
  )
}
