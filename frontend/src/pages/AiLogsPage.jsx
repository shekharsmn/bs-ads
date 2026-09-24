import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { Cpu, ExternalLink, ChevronLeft, ChevronRight } from 'lucide-react'

// Gemini 2.5 Flash Lite pricing per 1M tokens
const PRICE = { input: 0.075, output: 0.30 }

function calcCost(inputTokens, outputTokens) {
  return (inputTokens / 1_000_000) * PRICE.input
       + (outputTokens / 1_000_000) * PRICE.output
}

function fmtTokens(n) {
  if (!n) return '0'
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(2)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`
  return String(n)
}

function fmtDatetime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

const CALL_TYPE_LABELS = {
  transcript:      { label: 'Transcript',      color: '#7c3aed', bg: '#f5f3ff', border: '#ddd6fe' },
  static_analysis: { label: 'Static Analysis', color: '#0284c7', bg: '#f0f9ff', border: '#bae6fd' },
  video_analysis:  { label: 'Video Analysis',  color: '#0891b2', bg: '#ecfeff', border: '#a5f3fc' },
  unknown:         { label: 'Unknown',          color: '#94a3b8', bg: '#f8fafc', border: '#e2e8f0' },
}

function CallTypeBadge({ type }) {
  const cfg = CALL_TYPE_LABELS[type] ?? CALL_TYPE_LABELS.unknown
  return (
    <span style={{
      display: 'inline-block', fontSize: '11px', fontWeight: 600,
      padding: '2px 8px', borderRadius: 4,
      background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.border}`,
    }}>
      {cfg.label}
    </span>
  )
}

export default function AiLogsPage() {
  const [accountId, setAccountId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const { data: accountsData } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(r => r.data),
  })
  const accounts = accountsData ?? []

  const params = { page }
  if (accountId) params.account_id = accountId
  if (dateFrom) params.date_from = dateFrom
  if (dateTo) params.date_to = dateTo

  const { data, isLoading } = useQuery({
    queryKey: ['ai-logs', params],
    queryFn: () => api.getAiLogs(params).then(r => r.data),
    keepPreviousData: true,
  })

  const logs = data?.data ?? []
  const meta = data?.meta ?? {}

  // Aggregate totals for visible page
  const pageInputTotal  = logs.reduce((s, l) => s + (l.input_tokens  ?? 0), 0)
  const pageOutputTotal = logs.reduce((s, l) => s + (l.output_tokens ?? 0), 0)
  const pageCostTotal   = calcCost(pageInputTotal, pageOutputTotal)

  function applyFilters() {
    setPage(1)
  }

  function clearFilters() {
    setAccountId('')
    setDateFrom('')
    setDateTo('')
    setPage(1)
  }

  return (
    <div style={{ padding: '32px', maxWidth: '1200px', margin: '0 auto' }}>
      {/* Header */}
      <div className="fade-up" style={{ marginBottom: '28px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '6px' }}>
          <div style={{
            width: 36, height: 36, borderRadius: '10px',
            background: '#f0f9ff', border: '1px solid #bae6fd',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Cpu size={17} color="#0284c7" />
          </div>
          <h1 style={{
            fontFamily: 'Syne, sans-serif', fontSize: '22px', fontWeight: 700,
            color: '#0f172a', margin: 0, letterSpacing: '-0.02em',
          }}>AI Request Log</h1>
        </div>
        <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>
          Every Gemini API call made during audits — with token usage and cost.
          Rates: $0.075/1M input · $0.30/1M output (Gemini 2.5 Flash Lite).
        </p>
      </div>

      {/* Filters */}
      <div className="fade-up" style={{
        background: 'white', border: '1px solid #e2e8f0',
        borderRadius: '12px', padding: '16px 20px',
        display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'flex-end',
        marginBottom: '20px',
      }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
          <label style={{ fontSize: '11px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
            Account
          </label>
          <select
            value={accountId}
            onChange={e => setAccountId(e.target.value)}
            style={{
              padding: '7px 10px', border: '1px solid #e2e8f0', borderRadius: '8px',
              fontSize: '13px', color: '#0f172a', background: 'white',
              fontFamily: 'inherit', minWidth: '180px', cursor: 'pointer',
            }}
          >
            <option value="">All accounts</option>
            {accounts.map(a => (
              <option key={a.id} value={a.id}>
                {a.label || a.account_name}
              </option>
            ))}
          </select>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
          <label style={{ fontSize: '11px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
            From
          </label>
          <input
            type="date"
            value={dateFrom}
            onChange={e => setDateFrom(e.target.value)}
            style={{
              padding: '7px 10px', border: '1px solid #e2e8f0', borderRadius: '8px',
              fontSize: '13px', color: '#0f172a', background: 'white',
              fontFamily: 'inherit', cursor: 'pointer',
            }}
          />
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
          <label style={{ fontSize: '11px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
            To
          </label>
          <input
            type="date"
            value={dateTo}
            onChange={e => setDateTo(e.target.value)}
            style={{
              padding: '7px 10px', border: '1px solid #e2e8f0', borderRadius: '8px',
              fontSize: '13px', color: '#0f172a', background: 'white',
              fontFamily: 'inherit', cursor: 'pointer',
            }}
          />
        </div>

        <div style={{ display: 'flex', gap: '8px' }}>
          <button
            onClick={applyFilters}
            style={{
              padding: '8px 18px', background: '#0f172a', color: 'white',
              border: 'none', borderRadius: '8px', cursor: 'pointer',
              fontSize: '13px', fontWeight: 600, fontFamily: 'inherit',
            }}
          >
            Apply
          </button>
          {(accountId || dateFrom || dateTo) && (
            <button
              onClick={clearFilters}
              style={{
                padding: '8px 14px', background: 'white', color: '#64748b',
                border: '1px solid #e2e8f0', borderRadius: '8px', cursor: 'pointer',
                fontSize: '13px', fontFamily: 'inherit',
              }}
            >
              Clear
            </button>
          )}
        </div>

        {/* Page totals summary */}
        {logs.length > 0 && (
          <div style={{ marginLeft: 'auto', display: 'flex', gap: '20px', alignItems: 'center' }}>
            {[
              { label: 'Input', value: fmtTokens(pageInputTotal) },
              { label: 'Output', value: fmtTokens(pageOutputTotal) },
              { label: 'Cost', value: `$${pageCostTotal.toFixed(4)}` },
            ].map(({ label, value }) => (
              <div key={label} style={{ textAlign: 'right' }}>
                <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '13px', fontWeight: 700, color: '#0f172a' }}>{value}</div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Table */}
      <div className="fade-up" style={{
        background: 'white', border: '1px solid #e2e8f0',
        borderRadius: '12px', overflow: 'hidden',
      }}>
        {isLoading ? (
          <div style={{ padding: '60px', textAlign: 'center' }}>
            <div style={{
              width: 32, height: 32, border: '3px solid #e2e8f0', borderTopColor: '#3b82f6',
              borderRadius: '50%', margin: '0 auto 14px', animation: 'spin-slow 1s linear infinite',
            }} />
            <p style={{ margin: 0, fontSize: '13px', color: '#94a3b8' }}>Loading logs...</p>
          </div>
        ) : logs.length === 0 ? (
          <div style={{ padding: '60px', textAlign: 'center' }}>
            <Cpu size={28} color="#cbd5e1" style={{ marginBottom: '10px' }} />
            <p style={{ margin: 0, fontSize: '13px', color: '#94a3b8' }}>
              No AI requests found. Run an audit to generate logs.
            </p>
          </div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid #f1f5f9', background: '#f8fafc' }}>
                {['Date / Time', 'Account', 'Ad Name', 'Call Type', 'Input Tokens', 'Output Tokens', 'Est. Cost', 'Report'].map(h => (
                  <th key={h} style={{
                    padding: '10px 14px', textAlign: 'left',
                    fontSize: '11px', fontWeight: 700, color: '#94a3b8',
                    textTransform: 'uppercase', letterSpacing: '0.06em',
                    whiteSpace: 'nowrap',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {logs.map((log, i) => {
                const cost = calcCost(log.input_tokens ?? 0, log.output_tokens ?? 0)
                const accountName = log.meta_account?.label || log.meta_account?.account_name || '—'
                return (
                  <tr
                    key={log.id}
                    style={{
                      borderBottom: i < logs.length - 1 ? '1px solid #f8fafc' : 'none',
                      transition: 'background 0.1s',
                    }}
                    onMouseEnter={e => e.currentTarget.style.background = '#fafbfc'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                  >
                    <td style={{ padding: '11px 14px', fontSize: '12px', color: '#475569', whiteSpace: 'nowrap' }}>
                      {fmtDatetime(log.created_at)}
                    </td>
                    <td style={{ padding: '11px 14px', fontSize: '12px', fontWeight: 600, color: '#0f172a', whiteSpace: 'nowrap' }}>
                      {accountName}
                    </td>
                    <td style={{ padding: '11px 14px', fontSize: '12px', color: '#475569', maxWidth: '220px' }}>
                      <span style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {log.ad_name || <span style={{ color: '#94a3b8', fontStyle: 'italic' }}>—</span>}
                      </span>
                    </td>
                    <td style={{ padding: '11px 14px' }}>
                      <CallTypeBadge type={log.call_type} />
                    </td>
                    <td style={{ padding: '11px 14px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#0f172a', textAlign: 'right' }}>
                      {fmtTokens(log.input_tokens)}
                    </td>
                    <td style={{ padding: '11px 14px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#0f172a', textAlign: 'right' }}>
                      {fmtTokens(log.output_tokens)}
                    </td>
                    <td style={{ padding: '11px 14px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', fontWeight: 700, color: '#0284c7', textAlign: 'right' }}>
                      ${cost.toFixed(5)}
                    </td>
                    <td style={{ padding: '11px 14px' }}>
                      <Link
                        to={`/reports/${log.report_id}`}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '4px',
                          fontSize: '11px', color: '#64748b', textDecoration: 'none',
                          padding: '3px 8px', border: '1px solid #e2e8f0',
                          borderRadius: '5px', transition: 'all 0.15s',
                        }}
                        onMouseEnter={e => { e.currentTarget.style.color = '#0f172a'; e.currentTarget.style.borderColor = '#94a3b8' }}
                        onMouseLeave={e => { e.currentTarget.style.color = '#64748b'; e.currentTarget.style.borderColor = '#e2e8f0' }}
                      >
                        #{log.report_id} <ExternalLink size={10} />
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div style={{
          marginTop: '16px', display: 'flex', alignItems: 'center',
          justifyContent: 'space-between', fontSize: '13px', color: '#64748b',
        }}>
          <span>
            Showing {meta.from}–{meta.to} of {meta.total} requests
          </span>
          <div style={{ display: 'flex', gap: '6px' }}>
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page <= 1}
              style={{
                display: 'flex', alignItems: 'center', gap: '4px',
                padding: '6px 12px', border: '1px solid #e2e8f0',
                borderRadius: '8px', background: 'white', cursor: page <= 1 ? 'not-allowed' : 'pointer',
                fontSize: '13px', color: page <= 1 ? '#cbd5e1' : '#475569',
                fontFamily: 'inherit',
              }}
            >
              <ChevronLeft size={13} /> Prev
            </button>
            <span style={{
              padding: '6px 14px', border: '1px solid #e2e8f0',
              borderRadius: '8px', background: '#f8fafc',
              fontSize: '13px', fontWeight: 600, color: '#0f172a',
            }}>
              {page} / {meta.last_page}
            </span>
            <button
              onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
              disabled={page >= meta.last_page}
              style={{
                display: 'flex', alignItems: 'center', gap: '4px',
                padding: '6px 12px', border: '1px solid #e2e8f0',
                borderRadius: '8px', background: 'white', cursor: page >= meta.last_page ? 'not-allowed' : 'pointer',
                fontSize: '13px', color: page >= meta.last_page ? '#cbd5e1' : '#475569',
                fontFamily: 'inherit',
              }}
            >
              Next <ChevronRight size={13} />
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
