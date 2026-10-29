import { useSearchParams } from 'react-router-dom'
import { BarChart3, ShieldCheck } from 'lucide-react'
import { api } from '../api/client'

export default function LoginPage() {
  const [params] = useSearchParams()
  const error = params.get('error')
  return (
    <main style={{ minHeight: '100vh', background: '#08101f', color: 'white', display: 'grid', placeItems: 'center', padding: 24 }}>
      <div style={{ width: '100%', maxWidth: 460 }} className="fade-up">
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 40 }}>
          <div style={{ width: 42, height: 42, borderRadius: 12, background: '#2563eb', display: 'grid', placeItems: 'center' }}><BarChart3 size={21} /></div>
          <div><div style={{ font: '700 21px Syne, sans-serif' }}>SharkAds</div><div style={{ color: '#7890b4', fontSize: 12 }}>Performance operations</div></div>
        </div>
        <h1 style={{ font: '700 38px/1.1 Syne, sans-serif', letterSpacing: '-.04em', margin: '0 0 14px' }}>One place to catch performance drift.</h1>
        <p style={{ color: '#9aacc8', lineHeight: 1.65, margin: '0 0 32px' }}>Hourly Google and Meta reporting, accountable alerts, and client-safe reporting in SharkAds.</p>
        {error && <div style={{ color: '#fda4af', borderLeft: '3px solid #f43f5e', padding: '9px 12px', marginBottom: 18 }}>{error}</div>}
        <a href={api.workspaceLoginUrl} style={{ height: 48, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10, background: 'white', color: '#0f172a', borderRadius: 10, textDecoration: 'none', fontWeight: 700 }}>
          <ShieldCheck size={18} color="#2563eb" /> Continue with Google Workspace
        </a>
        <p style={{ color: '#526681', fontSize: 12, marginTop: 16 }}>Access is restricted to the configured Workspace domain.</p>
      </div>
    </main>
  )
}
