import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { fetchPublicReport } from '../api/client'
import { AlertCircle } from 'lucide-react'

export default function PublicReportPage() {
  const { token } = useParams()
  const [tab, setTab] = useState('overview')

  const { data: report, isLoading, error } = useQuery({
    queryKey: ['public-report', token],
    queryFn: () => fetchPublicReport(token),
  })

  if (isLoading) return (
    <div style={{ minHeight: '60vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f8fafc' }}>
      <p style={{ color: '#64748b', fontSize: '14px' }}>Loading shared report…</p>
    </div>
  )

  if (error || !report) return (
    <div style={{ padding: '48px', textAlign: 'center', maxWidth: '480px', margin: '0 auto' }}>
      <AlertCircle size={36} color="#f43f5e" style={{ marginBottom: '12px' }} />
      <p style={{ color: '#64748b' }}>This share link is invalid or the report is no longer available.</p>
      <Link to="/login" style={{ color: '#2563eb', fontSize: '14px' }}>Sign in</Link>
    </div>
  )

  const checks = report.checks || []
  const issues = checks.filter(c => c.status !== 'PASS' && c.status !== 'SKIP')

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', padding: '28px 20px' }}>
      <div style={{ maxWidth: '960px', margin: '0 auto' }}>
        <p style={{ fontSize: '12px', color: '#94a3b8', marginBottom: '8px' }}>Shared read-only audit</p>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '22px', fontWeight: 700, color: '#0f172a', margin: '0 0 6px' }}>
          {report.platform === 'google' ? 'Google Ads' : 'Meta'} audit
        </h1>
        <p style={{ fontSize: '13px', color: '#64748b', marginBottom: '20px' }}>
          Score {report.health_score ?? '—'} · Grade {report.grade ?? '—'} · {report.period?.replace('last_', 'Last ').replace('d', ' days')}
        </p>

        <div style={{ display: 'flex', gap: '6px', marginBottom: '20px', flexWrap: 'wrap' }}>
          {['overview', 'issues'].map(id => (
            <button
              key={id}
              type="button"
              onClick={() => setTab(id)}
              style={{
                padding: '8px 16px', borderRadius: '8px', border: 'none', cursor: 'pointer',
                fontWeight: 600, fontSize: '13px', fontFamily: 'inherit',
                background: tab === id ? '#fff' : 'transparent',
                color: tab === id ? '#0f172a' : '#64748b',
                boxShadow: tab === id ? '0 1px 4px rgba(0,0,0,0.08)' : 'none',
              }}
            >{id === 'overview' ? 'Overview' : `Issues (${issues.length})`}</button>
          ))}
        </div>

        {tab === 'overview' && (
          <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '20px' }}>
            <p style={{ margin: 0, fontSize: '14px', color: '#475569', lineHeight: 1.6 }}>
              This is a snapshot shared by the account owner. Open the full app while signed in for campaigns, keywords, export, and methodology tabs.
            </p>
            {(report.google_snapshot?.kpis || report.meta_snapshot?.kpis) && (
              <div style={{ marginTop: '16px', display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: '10px' }}>
                {Object.entries(report.google_snapshot?.kpis || report.meta_snapshot?.kpis || {}).filter(([k]) => k !== 'currency').map(([k, v]) => (
                  <div key={k} style={{ background: '#f8fafc', borderRadius: '8px', padding: '10px' }}>
                    <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase' }}>{k.replace(/_/g, ' ')}</div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '14px', fontWeight: 700 }}>{typeof v === 'number' ? v.toLocaleString() : v}</div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {tab === 'issues' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {issues.length === 0 ? (
              <p style={{ color: '#64748b' }}>No open issues — all checks passed or were skipped.</p>
            ) : issues.map((c, i) => (
              <div key={i} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px 16px' }}>
                <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8' }}>{c.check_id} · {c.status}{c.severity ? ` · ${c.severity}` : ''}</div>
                <div style={{ fontWeight: 700, fontSize: '14px', color: '#0f172a', marginTop: '4px' }}>{c.name}</div>
                <p style={{ fontSize: '13px', color: '#64748b', margin: '8px 0' }}>{c.finding}</p>
                {c.recommendation && (
                  <p style={{ fontSize: '12px', color: '#166534', margin: 0, background: '#f0fdf4', padding: '8px', borderRadius: '6px' }}>{c.recommendation}</p>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
