import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../api/axios'
import styles from './LoginPage.module.css'

export default function LoginPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)

    try {
      const { data } = await api.post('/api/login', { email, password })
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('user_id', String(data.user_id ?? ''))
      localStorage.setItem('user_name', data.user_name ?? '')
      localStorage.setItem('user_email', data.user_email ?? '')
      localStorage.setItem('active_modules', JSON.stringify(data.active_modules ?? []))
      localStorage.setItem('user_roles', JSON.stringify(data.roles ?? []))
      localStorage.setItem('user_permissions', JSON.stringify(data.permissions ?? []))
      navigate('/dashboard')
    } catch (err) {
      setError(err.response?.data?.message ?? 'An unexpected error occurred.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className={styles.wrapper}>
      <div className={styles.card}>
        <h1 className={styles.title}>ERP Login</h1>

        <form onSubmit={handleSubmit} noValidate>
          <div className={styles.field}>
            <label htmlFor="email" className={styles.label}>Email</label>
            <input
              id="email"
              type="email"
              className={styles.input}
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              required
              autoComplete="email"
            />
          </div>

          <div className={styles.field}>
            <label htmlFor="password" className={styles.label}>Password</label>
            <input
              id="password"
              type="password"
              className={styles.input}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              required
              autoComplete="current-password"
            />
          </div>

          {error && <p className={styles.error}>{error}</p>}

          <button type="submit" className={styles.button} disabled={loading}>
            {loading ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  )
}
