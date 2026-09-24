import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import AuditErrorModal from '../components/AuditErrorModal'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts'
import { PlayCircle, ChevronRight, Clock, CheckCircle, AlertCircle, Loader2, BarChart2, Ban, Square } from 'lucide-react'
import { useAuth } from '../context/AuthContext'

function scoreColor(score) {
  if (score === null || score === undefined) return '#94a3b8'
  if (score >= 75) return '#10b981'
  if (score >= 60) return '#f59e0b'
  return '#f43f5e'
}

function gradeBadge(grade, score) {
  const color = scoreColor(score)
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      width: 28, height: 28, borderRadius: '6px',
      background: `${color}18`, border: `1px solid ${color}40`,
      fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', fontWeight: 700,
      color,
    }}>{grade || '—'}</span>
  )
}

function statusChip(status) {
  const map = {
    pending: { label: 'Pending', color: '#94a3b8', bg: '#f1f5f9', icon: Clock },
    running: { label: 'Running', color: '#3b82f6', bg: '#eff6ff', icon: Loader2 },
    complete: { label: 'Complete', color: '#10b981', bg: '#f0fdf4', icon: CheckCircle },
    failed: { label: 'Failed', color: '#f43f5e', bg: '#fff1f2', icon: AlertCircle },
    cancelled: { label: 'Cancelled', color: '#64748b', bg: '#f1f5f9', icon: Ban },
  }
  const s = map[status] || map.pending
  const Icon = s.icon
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: '5px',
      padding: '3px 9px', borderRadius: '999px',
      background: s.bg, color: s.color,
      fontSize: '11px', fontWeight: 600,
    }}>
      <Icon size={10} strokeWidth={2.5} />
      {s.label}
    </span>
  )
}

const CustomTooltip = ({ active, payload, label }) => {
  if (!active || !payload?.length) return null
  return (
    <div style={{
      background: '#0f172a', border: '1px solid rgba(255,255,255,0.1)',
      borderRadius: '8px', padding: '10px 14px',
    }}>
      <p style={{ margin: 0, color: 'rgba(255,255,255,0.5)', fontSize: '11px' }}>{label}</p>
      <p style={{
        margin: '4px 0 0', fontFamily: 'JetBrains Mono, monospace',
        fontSize: '18px', fontWeight: 700,
        color: scoreColor(payload[0].value),
      }}>{payload[0].value}</p>
    </div>
  )
}

export default function Dashboard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [auditErrorModal, setAuditErrorModal] = useState(null)
  const { data: reports = [], isLoading } = useQuery({
    queryKey: ['reports'],
    queryFn: () => api.getReports().then(r => r.data),
  })

  const cancelMutation = useMutation({
    mutationFn: (reportId) => api.cancelReport(reportId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reports'] }),
  })

  const completed = reports.filter(r => r.status === 'complete' && r.health_score !== null)
  const chartData = completed.slice(-12).map(r => ({
    date: new Date(r.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    score: r.health_score,
    account: r.platform === 'google'
      ? (r.google_account?.label || r.google_account?.account_name || r.google_account?.customer_id || 'Google')
      : (r.meta_account?.label || r.meta_account?.account_name || 'Meta'),
  }))

  const latestScore = completed[0]?.health_score
  const avgScore = completed.length
    ? Math.round(completed.reduce((s, r) => s + r.health_score, 0) / completed.length)
    : null

  return (
    <div style={{ padding: '32px', maxWidth: '1100px', margin: '0 auto' }}>
      {/* Header */}
      <div className="fade-up" style={{ marginBottom: '32px', display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
        <div>
          <h1 style={{
            fontFamily: 'Syne, sans-serif', fontSize: '26px', fontWeight: 700,
            color: '#0f172a', margin: 0, letterSpacing: '-0.03em',
          }}>
            Good {new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 17 ? 'afternoon' : 'evening'}, {user?.name?.split(' ')[0]}
          </h1>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: '14px' }}>
            {reports.length} audit{reports.length !== 1 ? 's' : ''} run · {completed.length} completed
          </p>
        </div>
        <button
          onClick={() => navigate('/reports/new')}
          style={{
            display: 'flex', alignItems: 'center', gap: '8px',
            padding: '10px 20px',
            background: 'linear-gradient(135deg, #3b82f6, #2563eb)',
            border: 'none', borderRadius: '10px', color: 'white',
            fontSize: '13px', fontWeight: 600, fontFamily: 'Syne, sans-serif',
            cursor: 'pointer', letterSpacing: '0.02em',
            boxShadow: '0 4px 16px rgba(59,130,246,0.3)',
            transition: 'all 0.15s',
          }}
          onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-1px)'}
          onMouseLeave={e => e.currentTarget.style.transform = 'none'}
        >
          <PlayCircle size={15} />
          Run New Audit
        </button>
      </div>

      {/* Stats row */}
      <div className="fade-up-delay-1" style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '16px', marginBottom: '24px' }}>
        {[
          { label: 'Latest Score', value: latestScore !== undefined && latestScore !== null ? latestScore : '—', unit: latestScore !== null ? '/100' : '', color: scoreColor(latestScore) },
          { label: 'Average Score', value: avgScore ?? '—', unit: avgScore ? '/100' : '', color: scoreColor(avgScore) },
          { label: 'Total Audits', value: reports.length, unit: '', color: '#3b82f6' },
        ].map(({ label, value, unit, color }) => (
          <div key={label} style={{
            background: 'white', border: '1px solid #e2e8f0',
            borderRadius: '12px', padding: '20px 24px',
          }}>
            <p style={{ margin: 0, fontSize: '11px', fontWeight: 600, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.08em' }}>{label}</p>
            <p style={{ margin: '8px 0 0', display: 'flex', alignItems: 'baseline', gap: '4px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '32px', fontWeight: 700, color, lineHeight: 1 }}>{value}</span>
              {unit && <span style={{ fontSize: '13px', color: '#94a3b8', fontFamily: 'JetBrains Mono, monospace' }}>{unit}</span>}
            </p>
          </div>
        ))}
      </div>

      {/* Chart */}
      {chartData.length > 1 && (
        <div className="fade-up-delay-2" style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '12px', padding: '24px', marginBottom: '24px',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '20px' }}>
            <div>
              <h2 style={{ fontFamily: 'Syne, sans-serif', fontSize: '15px', fontWeight: 700, color: '#0f172a', margin: 0 }}>
                Health Score Trend
              </h2>
              <p style={{ margin: '2px 0 0', fontSize: '12px', color: '#94a3b8' }}>Last {chartData.length} audits</p>
            </div>
            <BarChart2 size={16} color="#94a3b8" />
          </div>
          <ResponsiveContainer width="100%" height={180}>
            <LineChart data={chartData} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
              <XAxis dataKey="date" tick={{ fontSize: 11, fill: '#94a3b8', fontFamily: 'JetBrains Mono, monospace' }} axisLine={false} tickLine={false} />
              <YAxis domain={[0, 100]} tick={{ fontSize: 11, fill: '#94a3b8', fontFamily: 'JetBrains Mono, monospace' }} axisLine={false} tickLine={false} />
              <Tooltip content={<CustomTooltip />} />
              <Line
                type="monotone" dataKey="score" stroke="#3b82f6" strokeWidth={2.5}
                dot={{ fill: '#3b82f6', r: 4, strokeWidth: 2, stroke: 'white' }}
                activeDot={{ r: 6, fill: '#3b82f6', stroke: 'white', strokeWidth: 2 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Reports table */}
      <div className="fade-up-delay-3" style={{
        background: 'white', border: '1px solid #e2e8f0', borderRadius: '12px', overflow: 'hidden',
      }}>
        <div style={{ padding: '20px 24px', borderBottom: '1px solid #f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <h2 style={{ fontFamily: 'Syne, sans-serif', fontSize: '15px', fontWeight: 700, color: '#0f172a', margin: 0 }}>
            Recent Audits
          </h2>
          <span style={{ fontSize: '12px', color: '#94a3b8' }}>{reports.length} total</span>
        </div>

        {isLoading ? (
          <div style={{ padding: '48px', textAlign: 'center' }}>
            <div style={{ width: 32, height: 32, border: '3px solid #e2e8f0', borderTopColor: '#3b82f6', borderRadius: '50%', margin: '0 auto', animation: 'spin-slow 1s linear infinite' }} />
          </div>
        ) : reports.length === 0 ? (
          <div style={{ padding: '60px 24px', textAlign: 'center' }}>
            <div style={{ width: 56, height: 56, background: '#f1f5f9', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px' }}>
              <BarChart2 size={24} color="#94a3b8" />
            </div>
            <p style={{ fontFamily: 'Syne, sans-serif', fontWeight: 700, color: '#0f172a', margin: '0 0 6px', fontSize: '15px' }}>No audits yet</p>
            <p style={{ fontSize: '13px', color: '#94a3b8', margin: '0 0 20px' }}>Run your first Meta or Google Ads audit to get started</p>
            <button
              onClick={() => navigate('/reports/new')}
              style={{
                padding: '9px 20px', background: '#3b82f6', border: 'none',
                borderRadius: '8px', color: 'white', fontSize: '13px', fontWeight: 600,
                fontFamily: 'Syne, sans-serif', cursor: 'pointer',
              }}
            >Run First Audit</button>
          </div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                {['Account', 'Date', 'Score', 'Grade', 'Status', 'Actions'].map(h => (
                  <th key={h} style={{
                    padding: '10px 24px', textAlign: 'left',
                    fontSize: '11px', fontWeight: 600, color: '#94a3b8',
                    textTransform: 'uppercase', letterSpacing: '0.06em',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {reports.map((report, i) => (
                <tr
                  key={report.id}
                  onClick={() => report.status === 'complete' && navigate(`/reports/${report.id}`)}
                  style={{
                    borderBottom: i < reports.length - 1 ? '1px solid #f8fafc' : 'none',
                    cursor: report.status === 'complete' ? 'pointer' : 'default',
                    transition: 'background 0.1s',
                  }}
                  onMouseEnter={e => { if (report.status === 'complete') e.currentTarget.style.background = '#f8fafc' }}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                >
                  <td style={{ padding: '14px 24px' }}>
                    <p style={{ margin: 0, fontWeight: 600, fontSize: '13px', color: '#0f172a' }}>
                      {report.platform === 'google'
                        ? (report.google_account?.label || report.google_account?.account_name || `Google ${report.google_account?.customer_id || ''}`)
                        : (report.meta_account?.label || report.meta_account?.account_name || `Account #${report.meta_account_id}`)}
                      <span style={{
                        marginLeft: '8px', fontSize: '10px', fontWeight: 700, padding: '2px 6px', borderRadius: '4px',
                        background: report.platform === 'google' ? '#fef3c7' : '#eff6ff',
                        color: report.platform === 'google' ? '#b45309' : '#2563eb',
                        verticalAlign: 'middle',
                      }}>{report.platform === 'google' ? 'Google' : 'Meta'}</span>
                    </p>
                  </td>
                  <td style={{ padding: '14px 24px' }}>
                    <span style={{ fontSize: '12px', color: '#64748b', fontFamily: 'JetBrains Mono, monospace' }}>
                      {new Date(report.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    </span>
                  </td>
                  <td style={{ padding: '14px 24px' }}>
                    <span style={{
                      fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700,
                      color: scoreColor(report.health_score),
                    }}>
                      {report.health_score ?? '—'}
                    </span>
                  </td>
                  <td style={{ padding: '14px 24px' }}>{gradeBadge(report.grade, report.health_score)}</td>
                  <td style={{ padding: '14px 24px' }}>
                    {report.status === 'failed' && report.error_message ? (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          setAuditErrorModal({
                            title: `Audit #${report.id} failed`,
                            message: report.error_message,
                          })
                        }}
                        title="View error details"
                        style={{
                          background: 'none', border: 'none', padding: 0, cursor: 'pointer',
                          font: 'inherit', textAlign: 'left',
                        }}
                      >
                        {statusChip('failed')}
                      </button>
                    ) : (
                      statusChip(report.status)
                    )}
                  </td>
                  <td style={{ padding: '14px 24px' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '8px' }}>
                      {(report.status === 'pending' || report.status === 'running') && (
                        <button
                          type="button"
                          title="Stop this audit"
                          disabled={cancelMutation.isPending}
                          onClick={(e) => {
                            e.stopPropagation()
                            if (!window.confirm('Stop this audit? Work will end after the current step.')) return
                            cancelMutation.mutate(report.id)
                          }}
                          style={{
                            display: 'inline-flex', alignItems: 'center', gap: '5px',
                            padding: '5px 10px', borderRadius: '8px',
                            border: '1px solid #fecdd3', background: '#fff1f2',
                            color: '#be123c', fontSize: '11px', fontWeight: 600,
                            fontFamily: 'inherit', cursor: cancelMutation.isPending ? 'wait' : 'pointer',
                          }}
                        >
                          <Square size={10} fill="currentColor" />Stop
                        </button>
                      )}
                      {report.status === 'complete' && <ChevronRight size={15} color="#cbd5e1" />}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <AuditErrorModal
        open={!!auditErrorModal}
        onClose={() => setAuditErrorModal(null)}
        title={auditErrorModal?.title}
        message={auditErrorModal?.message}
      />
    </div>
  )
}
