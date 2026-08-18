import { Redirect } from 'expo-router'
import { useSession } from '../store/session'
import { Loading } from '../components/ui'

export default function Index() {
  const { ready, token, serverUrl } = useSession()
  if (!ready) return <Loading text="Starting…" />
  if (token) return <Redirect href="/home" />
  return <Redirect href={serverUrl ? '/login' : '/settings'} />
}
