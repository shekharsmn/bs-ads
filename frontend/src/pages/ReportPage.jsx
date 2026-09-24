import { useState, createElement } from 'react'
import ReactMarkdown from 'react-markdown'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import AuditErrorModal from '../components/AuditErrorModal'
import GoogleReportTabs from '../components/report/GoogleReportTabs'
import MetaReportTabs from '../components/report/MetaReportTabs'
import {
  ArrowLeft, AlertTriangle, AlertCircle, Info, CheckCircle,
  ChevronDown, ChevronRight, Video, Image, Zap, Target,
  TrendingUp, Eye, BarChart2, Clock, DollarSign, MousePointer,
  Users, Activity,
} from 'lucide-react'

// ─── helpers ─────────────────────────────────────────────────────────────────

function scoreColor(score) {
  if (score === null || score === undefined) return '#94a3b8'
  if (score >= 75) return '#10b981'
  if (score >= 60) return '#f59e0b'
  return '#f43f5e'
}

function severityConfig(sev) {
  const map = {
    Critical: { color: '#f43f5e', bg: '#fff1f2', border: '#fecdd3', icon: AlertCircle },
    High:     { color: '#f97316', bg: '#fff7ed', border: '#fed7aa', icon: AlertTriangle },
    Medium:   { color: '#f59e0b', bg: '#fffbeb', border: '#fde68a', icon: Info },
    Low:      { color: '#94a3b8', bg: '#f8fafc', border: '#e2e8f0', icon: Info },
  }
  return map[sev] || map.Low
}

function statusConfig(status) {
  const map = {
    FAIL: { color: '#f43f5e', bg: '#fff1f2', label: 'FAIL' },
    WARN: { color: '#f59e0b', bg: '#fffbeb', label: 'WARN' },
    PASS: { color: '#10b981', bg: '#f0fdf4', label: 'PASS' },
    SKIP: { color: '#64748b', bg: '#f1f5f9', label: 'SKIP' },
  }
  return map[status] || map.PASS
}

function fmt(val, digits = 1) {
  if (val === null || val === undefined || val === '') return '—'
  const n = parseFloat(val)
  if (isNaN(n)) return '—'
  return n.toFixed(digits)
}

function fmtPct(val) {
  const n = parseFloat(val)
  if (isNaN(n)) return '—'
  return `${(n * 100).toFixed(1)}%`
}

function fmtMoney(val) {
  const n = parseFloat(val)
  if (isNaN(n)) return '—'
  return `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

// Gemini 2.5 Flash Lite pricing (per 1M tokens)
const GEMINI_PRICE = { input: 0.075, output: 0.30 }

function calcGeminiCost(inputTokens, outputTokens) {
  return (inputTokens / 1_000_000) * GEMINI_PRICE.input
       + (outputTokens / 1_000_000) * GEMINI_PRICE.output
}

function fmtTokens(n) {
  if (!n) return '0'
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(2)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`
  return String(n)
}

function fmtMoneyCurrency(amount, currency = 'USD') {
  const n = parseFloat(amount)
  if (isNaN(n)) return '—'
  try {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(n)
  } catch {
    return fmtMoney(n)
  }
}

function microsToCurrency(micros, currency = 'USD') {
  if (micros == null || micros === '') return '—'
  const n = Number(micros) / 1_000_000
  if (!Number.isFinite(n)) return '—'
  return fmtMoneyCurrency(n, currency)
}

function googleEnumLabel(v) {
  if (v == null || v === '') return '—'
  return String(v).split('_').map((w) => w.charAt(0) + w.slice(1).toLowerCase()).join(' ')
}

// ─── sub-components ───────────────────────────────────────────────────────────

function GradientGauge({ score }) {
  const color = scoreColor(score)
  const pct = score ?? 0
  // SVG arc gauge
  const r = 54
  const cx = 70
  const cy = 70
  const startAngle = -210
  const endAngle = 30
  const totalDeg = endAngle - startAngle
  const valueDeg = startAngle + (totalDeg * pct) / 100

  function polarToCartesian(cx, cy, r, deg) {
    const rad = ((deg - 90) * Math.PI) / 180
    return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) }
  }

  function arcPath(cx, cy, r, s, e) {
    const start = polarToCartesian(cx, cy, r, s)
    const end = polarToCartesian(cx, cy, r, e)
    const large = e - s > 180 ? 1 : 0
    return `M ${start.x} ${start.y} A ${r} ${r} 0 ${large} 1 ${end.x} ${end.y}`
  }

  return (
    <div style={{ position: 'relative', width: 140, height: 110 }}>
      <svg width={140} height={110} viewBox="0 0 140 110">
        {/* track */}
        <path
          d={arcPath(cx, cy, r, startAngle, endAngle)}
          fill="none" stroke="#e2e8f0" strokeWidth={10}
          strokeLinecap="round"
        />
        {/* value */}
        {pct > 0 && (
          <path
            d={arcPath(cx, cy, r, startAngle, valueDeg)}
            fill="none" stroke={color} strokeWidth={10}
            strokeLinecap="round"
            style={{ filter: `drop-shadow(0 0 6px ${color}60)` }}
          />
        )}
        {/* needle dot */}
        {pct > 0 && (() => {
          const pt = polarToCartesian(cx, cy, r, valueDeg)
          return <circle cx={pt.x} cy={pt.y} r={5} fill={color} />
        })()}
      </svg>
      <div style={{
        position: 'absolute', bottom: 0, left: 0, right: 0,
        textAlign: 'center', lineHeight: 1,
      }}>
        <div style={{
          fontFamily: 'JetBrains Mono, monospace', fontSize: '36px',
          fontWeight: 800, color: color,
        }}>{score ?? '—'}</div>
        <div style={{ fontSize: '11px', color: '#94a3b8', marginTop: '2px' }}>/ 100</div>
      </div>
    </div>
  )
}

function SectionBar({ label, score, weight }) {
  const color = scoreColor(score)
  return (
    <div style={{ marginBottom: '14px' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
        <span style={{ fontSize: '12px', fontWeight: 600, color: '#475569' }}>{label}</span>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <span style={{ fontSize: '10px', color: '#94a3b8' }}>{(weight != null && weight <= 1 ? Math.round(weight * 100) : Math.round(weight ?? 0))}% weight</span>
          <span style={{
            fontFamily: 'JetBrains Mono, monospace', fontSize: '13px',
            fontWeight: 700, color,
          }}>{fmt(score, 0)}</span>
        </div>
      </div>
      <div style={{ height: 6, background: '#f1f5f9', borderRadius: 99, overflow: 'hidden' }}>
        <div style={{
          height: '100%', width: `${score ?? 0}%`,
          background: color,
          borderRadius: 99,
          transition: 'width 0.8s ease',
          boxShadow: `0 0 8px ${color}60`,
        }} />
      </div>
    </div>
  )
}

function CheckRow({ check }) {
  const sc = statusConfig(check.status)
  const sv = severityConfig(check.severity)
  return (
    <tr style={{ borderBottom: '1px solid #f8fafc' }}>
      <td style={{ padding: '12px 16px' }}>
        <span style={{
          display: 'inline-block', padding: '2px 8px',
          background: sc.bg, color: sc.color,
          borderRadius: 4, fontSize: '10px', fontWeight: 700,
          fontFamily: 'JetBrains Mono, monospace',
        }}>{sc.label}</span>
      </td>
      <td style={{ padding: '12px 8px' }}>
        {check.severity ? (
          <span style={{
            display: 'inline-block', padding: '2px 8px',
            background: sv.bg, color: sv.color,
            border: `1px solid ${sv.border}`,
            borderRadius: 4, fontSize: '10px', fontWeight: 600,
          }}>{check.severity}</span>
        ) : (
          <span style={{ fontSize: '11px', color: '#94a3b8' }}>—</span>
        )}
      </td>
      <td style={{ padding: '12px 8px', fontSize: '12px', fontWeight: 600, color: '#0f172a' }}>
        {check.name}
      </td>
      <td style={{ padding: '12px 8px', fontSize: '12px', color: '#64748b', maxWidth: 280 }}>
        {check.finding}
      </td>
    </tr>
  )
}

function AiCostChip({ inputTokens, outputTokens }) {
  const [showDetail, setShowDetail] = useState(false)
  const cost = calcGeminiCost(inputTokens, outputTokens)

  return (
    <div style={{ position: 'relative' }}>
      <button
        onClick={() => setShowDetail(d => !d)}
        style={{
          display: 'inline-flex', alignItems: 'center', gap: '5px',
          padding: '5px 12px', background: '#f0f9ff', color: '#0284c7',
          border: '1px solid #bae6fd', borderRadius: 99,
          fontSize: '12px', fontWeight: 600, fontFamily: 'JetBrains Mono, monospace',
          cursor: 'pointer',
        }}
        title="Gemini AI cost for this audit"
      >
        AI ${cost < 0.001 ? '<$0.001' : `$${cost.toFixed(4)}`}
      </button>
      {showDetail && (
        <div style={{
          position: 'absolute', top: '100%', right: 0, marginTop: '6px', zIndex: 100,
          background: 'white', border: '1px solid #e2e8f0', borderRadius: '10px',
          padding: '14px 16px', boxShadow: '0 8px 24px rgba(0,0,0,0.1)',
          minWidth: '240px', fontSize: '12px', color: '#475569',
        }}>
          <p style={{ margin: '0 0 10px', fontWeight: 700, color: '#0f172a', fontSize: '13px' }}>
            Gemini 2.5 Flash Lite Cost
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
            {[
              { label: 'Input tokens', value: fmtTokens(inputTokens), sub: `$${((inputTokens / 1_000_000) * GEMINI_PRICE.input).toFixed(5)}` },
              { label: 'Output tokens', value: fmtTokens(outputTokens), sub: `$${((outputTokens / 1_000_000) * GEMINI_PRICE.output).toFixed(5)}` },
            ].map(({ label, value, sub }) => (
              <div key={label} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ color: '#64748b' }}>{label}</span>
                <span style={{ fontFamily: 'JetBrains Mono, monospace', fontWeight: 600, color: '#0f172a' }}>
                  {value} <span style={{ color: '#94a3b8', fontWeight: 400 }}>({sub})</span>
                </span>
              </div>
            ))}
            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: '8px', marginTop: '2px', display: 'flex', justifyContent: 'space-between' }}>
              <span style={{ fontWeight: 700, color: '#0f172a' }}>Total cost</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontWeight: 700, color: '#0284c7' }}>
                ${cost.toFixed(5)}
              </span>
            </div>
          </div>
          <p style={{ margin: '10px 0 0', fontSize: '10px', color: '#94a3b8' }}>
            Rates: $0.075/1M input · $0.30/1M output
          </p>
        </div>
      )}
    </div>
  )
}

function TranscriptBlock({ transcript }) {
  const [open, setOpen] = useState(false)
  return (
    <div style={{ marginBottom: '10px', border: '1px solid #e2e8f0', borderRadius: '6px', overflow: 'hidden' }}>
      <button
        onClick={() => setOpen(o => !o)}
        style={{
          width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '7px 10px', background: '#1e293b', border: 'none', cursor: 'pointer',
        }}
      >
        <span style={{ fontSize: '11px', fontWeight: 600, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          Video Transcript
        </span>
        <span style={{ fontSize: '10px', color: '#64748b' }}>{open ? '▲ hide' : '▼ show'}</span>
      </button>
      {open && (
        <pre style={{
          margin: 0, padding: '10px 12px', background: '#0f172a',
          fontSize: '11px', color: '#94a3b8', lineHeight: '1.7',
          whiteSpace: 'pre-wrap', wordBreak: 'break-word',
          maxHeight: '200px', overflowY: 'auto',
          fontFamily: 'JetBrains Mono, monospace',
        }}>
          {transcript}
        </pre>
      )}
    </div>
  )
}

function AdCard({ ad }) {
  const [expanded, setExpanded] = useState(false)
  const isVideo = ad.format?.toLowerCase().includes('video')
  const vm = ad.video_metrics
  const ai = ad.visual_analysis
  const copy = ad.copy_analysis

  return (
    <div style={{
      background: 'white', border: '1px solid #e2e8f0',
      borderRadius: '12px', overflow: 'hidden', marginBottom: '12px',
    }}>
      {/* header */}
      <div style={{ padding: '16px 20px', borderBottom: expanded ? '1px solid #f1f5f9' : 'none' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flex: 1, minWidth: 0 }}>
            <div style={{
              width: 32, height: 32, borderRadius: '8px', flexShrink: 0,
              background: isVideo ? '#eff6ff' : '#f0fdf4',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>
              {isVideo ? <Video size={14} color="#3b82f6" /> : <Image size={14} color="#10b981" />}
            </div>
            <div style={{ minWidth: 0 }}>
              <p style={{ margin: 0, fontSize: '13px', fontWeight: 700, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {ad.ad_name || `Ad #${ad.meta_ad_id}`}
              </p>
              <span style={{
                display: 'inline-block', marginTop: '2px',
                fontSize: '10px', fontWeight: 600,
                color: isVideo ? '#3b82f6' : '#10b981',
                background: isVideo ? '#eff6ff' : '#f0fdf4',
                padding: '1px 6px', borderRadius: 4,
              }}>{ad.format || 'Unknown'}</span>
            </div>
          </div>

          {/* metrics strip */}
          <div style={{ display: 'flex', gap: '20px', flexShrink: 0, alignItems: 'center' }}>
            {[
              { label: 'Spend', value: fmtMoney(ad.spend), icon: DollarSign },
              { label: 'CTR', value: ad.ctr ? `${parseFloat(ad.ctr).toFixed(2)}%` : '—', icon: MousePointer },
              { label: 'Freq', value: fmt(ad.frequency), icon: Activity },
              { label: 'Leads', value: ad.leads ?? '—', icon: Users },
            ].map(({ label, value, icon }) => (
              <div key={label} style={{ textAlign: 'right' }}>
                <div style={{ fontSize: '10px', color: '#94a3b8', display: 'flex', alignItems: 'center', gap: '3px', justifyContent: 'flex-end' }}>
                  {createElement(icon, { size: 9 })}{label}
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', fontWeight: 700, color: '#0f172a' }}>{value}</div>
              </div>
            ))}
            <button
              onClick={() => setExpanded(e => !e)}
              style={{
                background: 'none', border: '1px solid #e2e8f0', borderRadius: '6px',
                cursor: 'pointer', color: '#94a3b8', padding: '4px 6px',
                display: 'flex', alignItems: 'center',
              }}
            >
              {expanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
            </button>
          </div>
        </div>

        {/* perf flags */}
        {Array.isArray(ad.perf_flags) && ad.perf_flags.length > 0 && (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '10px' }}>
            {ad.perf_flags.map((flag, i) => (
              <span key={i} style={{
                fontSize: '11px', padding: '3px 8px', borderRadius: 4,
                background: '#fffbeb', color: '#d97706',
                border: '1px solid #fde68a', fontWeight: 500,
              }}>{flag}</span>
            ))}
          </div>
        )}
      </div>

      {/* expanded body */}
      {expanded && (
        <div style={{ padding: '16px 20px', display: 'flex', flexDirection: 'column', gap: '16px' }}>
          {/* video metrics */}
          {isVideo && vm && (
            <div>
              <p style={{ margin: '0 0 10px', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
                Video Performance
              </p>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '10px' }}>
                {[
                  { label: 'Hook Rate', value: fmtPct(vm.hook_rate), icon: Zap },
                  { label: 'ThruPlay', value: fmtPct(vm.thruplay_rate), icon: TrendingUp },
                  { label: 'Avg Watch', value: vm.avg_watch_time ? `${fmt(vm.avg_watch_time)}s` : '—', icon: Clock },
                  { label: 'Duration', value: vm.duration ? `${fmt(vm.duration, 0)}s` : '—', icon: Video },
                ].map(({ label, value, icon }) => (
                  <div key={label} style={{
                    background: '#f8fafc', border: '1px solid #e2e8f0',
                    borderRadius: '8px', padding: '10px 12px', textAlign: 'center',
                  }}>
                    {createElement(icon, { size: 14, color: '#3b82f6', style: { marginBottom: '4px' } })}
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>{value}</div>
                    <div style={{ fontSize: '10px', color: '#94a3b8', marginTop: '2px' }}>{label}</div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* copy analysis */}
          {copy && (
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                <p style={{ margin: 0, fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>Copy Score</p>
                <span style={{
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '13px', fontWeight: 700,
                  color: scoreColor(copy.score),
                }}>{copy.score ?? '—'}/100</span>
              </div>
              {Array.isArray(copy.issues) && copy.issues.length > 0 && (
                <ul style={{ margin: 0, padding: '0 0 0 16px', display: 'flex', flexDirection: 'column', gap: '4px' }}>
                  {copy.issues.map((issue, i) => (
                    <li key={i} style={{ fontSize: '12px', color: '#64748b' }}>{issue}</li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {/* AI visual analysis */}
          {ai && (
            <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '12px 14px' }}>
              <p style={{ margin: '0 0 8px', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
                AI Visual Analysis
              </p>
              {ai.transcript && (
                <TranscriptBlock transcript={ai.transcript} />
              )}
              {ai.error ? (
                <p style={{ margin: 0, fontSize: '12px', color: '#f43f5e' }}>{ai.error}</p>
              ) : (
                <div className="ai-analysis-md">
                  <ReactMarkdown
                    components={{
                      h1: ({children}) => <h1 style={{ fontSize: '14px', fontWeight: 700, color: '#0f172a', margin: '16px 0 6px', fontFamily: 'Syne, sans-serif' }}>{children}</h1>,
                      h2: ({children}) => <h2 style={{ fontSize: '13px', fontWeight: 700, color: '#0f172a', margin: '14px 0 5px', fontFamily: 'Syne, sans-serif', borderBottom: '1px solid #f1f5f9', paddingBottom: '4px' }}>{children}</h2>,
                      h3: ({children}) => <h3 style={{ fontSize: '12px', fontWeight: 700, color: '#334155', margin: '12px 0 4px' }}>{children}</h3>,
                      p: ({children}) => <p style={{ margin: '0 0 8px', fontSize: '12px', color: '#475569', lineHeight: '1.65' }}>{children}</p>,
                      ul: ({children}) => <ul style={{ margin: '0 0 8px', paddingLeft: '18px', display: 'flex', flexDirection: 'column', gap: '3px' }}>{children}</ul>,
                      ol: ({children}) => <ol style={{ margin: '0 0 8px', paddingLeft: '18px', display: 'flex', flexDirection: 'column', gap: '3px' }}>{children}</ol>,
                      li: ({children}) => <li style={{ fontSize: '12px', color: '#475569', lineHeight: '1.55' }}>{children}</li>,
                      strong: ({children}) => <strong style={{ fontWeight: 700, color: '#0f172a' }}>{children}</strong>,
                      em: ({children}) => <em style={{ color: '#64748b' }}>{children}</em>,
                      code: ({children}) => <code style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', background: '#f1f5f9', padding: '1px 5px', borderRadius: '3px', color: '#0284c7' }}>{children}</code>,
                      hr: () => <hr style={{ border: 'none', borderTop: '1px solid #e2e8f0', margin: '10px 0' }} />,
                    }}
                  >
                    {ai.analysis_text || 'No analysis available.'}
                  </ReactMarkdown>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ─── tabs ─────────────────────────────────────────────────────────────────────

function GoogleAdsCampaignDataSection({ report }) {
  const snap = report.google_snapshot
  const currency = report.google_account?.currency || 'USD'
  if (!snap) {
    return (
      <div style={{
        marginTop: '20px', padding: '20px 24px', borderRadius: '12px',
        background: '#fffbeb', border: '1px solid #fde68a', color: '#92400e', fontSize: '13px',
      }}>
        No campaign snapshot on this report. Run a new Google Ads audit to store structured campaign metrics from the fetch step.
      </div>
    )
  }

  const campaigns = snap.campaigns || []
  const customer = snap.customer || {}
  const counts = snap.counts || {}
  const range = snap.date_range_literal || '—'

  return (
    <div style={{
      marginTop: '20px', background: 'white', border: '1px solid #e2e8f0',
      borderRadius: '12px', overflow: 'hidden',
    }}>
      <div style={{ padding: '16px 20px', borderBottom: '1px solid #f1f5f9' }}>
        <h3 style={{ margin: 0, fontFamily: 'Syne, sans-serif', fontSize: '15px', fontWeight: 700, color: '#0f172a' }}>
          Campaign data (at audit time)
        </h3>
        <p style={{ margin: '6px 0 0', fontSize: '12px', color: '#64748b' }}>
          <span style={{ fontWeight: 600, color: '#475569' }}>{customer.descriptive_name || 'Account'}</span>
          {' · '}
          <span style={{ fontFamily: 'JetBrains Mono, monospace' }}>{range}</span>
          {counts.campaigns != null && (
            <span style={{ marginLeft: '8px' }}>
              · {counts.campaigns} campaigns · {counts.keyword_rows ?? 0} keyword rows · {counts.rsa_ads ?? 0} RSA ads
            </span>
          )}
        </p>
      </div>
      {campaigns.length === 0 ? (
        <div style={{ padding: '28px', textAlign: 'center', color: '#94a3b8', fontSize: '13px' }}>
          No campaign rows in snapshot.
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '12px' }}>
            <thead>
              <tr style={{ background: '#fefce8', borderBottom: '1px solid #fde68a' }}>
                {['Campaign', 'Status', 'Channel', 'Bidding', 'Budget', 'Spend', 'Impr.', 'Clicks', 'Conv.', 'Conv. value'].map((h) => (
                  <th key={h} style={{
                    padding: '10px 14px', textAlign: h === 'Campaign' ? 'left' : 'right',
                    fontSize: '10px', fontWeight: 700, color: '#854d0e', textTransform: 'uppercase', letterSpacing: '0.05em', whiteSpace: 'nowrap',
                  }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {campaigns.map((c, i) => (
                <tr key={c.id || i} style={{ borderBottom: i < campaigns.length - 1 ? '1px solid #f8fafc' : 'none' }}>
                  <td style={{ padding: '11px 14px', fontWeight: 600, color: '#0f172a', maxWidth: '240px' }}>
                    <span style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={c.name}>
                      {c.name || `Campaign ${c.id}`}
                    </span>
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#94a3b8', fontWeight: 500 }}>{c.id}</span>
                  </td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', color: '#475569' }}>{googleEnumLabel(c.status)}</td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', color: '#475569' }}>{googleEnumLabel(c.advertising_channel_type)}</td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', color: '#475569', whiteSpace: 'nowrap' }}>{googleEnumLabel(c.bidding_strategy_type)}</td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#0f172a' }}>
                    {microsToCurrency(c.budget_amount_micros, currency)}
                    {c.budget_status && (
                      <span style={{ display: 'block', fontSize: '10px', color: '#94a3b8', fontWeight: 500 }}>{googleEnumLabel(c.budget_status)}</span>
                    )}
                  </td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#0f172a' }}>{microsToCurrency(c.cost_micros, currency)}</td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#475569' }}>
                    {(c.impressions ?? 0).toLocaleString()}
                  </td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#475569' }}>
                    {(c.clicks ?? 0).toLocaleString()}
                  </td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#475569' }}>
                    {c.conversions != null ? Number(c.conversions).toFixed(1) : '—'}
                  </td>
                  <td style={{ padding: '11px 14px', textAlign: 'right', fontFamily: 'JetBrains Mono, monospace', color: '#475569' }}>
                    {c.conversions_value != null ? fmtMoneyCurrency(c.conversions_value, currency) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {campaigns.length >= 150 && (
        <p style={{ margin: 0, padding: '10px 14px', fontSize: '11px', color: '#94a3b8', background: '#f8fafc', borderTop: '1px solid #f1f5f9' }}>
          Showing top 150 campaigns by spend from the audit fetch. Totals above reflect the full account fetch.
        </p>
      )}
    </div>
  )
}

function OverviewTab({ report }) {
  const sections = report.section_scores || []
  const sectionMap = {
    'Pixel/CAPI': 30,
    'Creative':   30,
    'Structure':  20,
    'Audience':   20,
  }
  const failWarn = (report.checks || [])
    .filter(c => c.status !== 'PASS' && c.status !== 'SKIP')
    .sort((a, b) => {
      const order = { Critical: 0, High: 1, Medium: 2, Low: 3 }
      return (order[a.severity] ?? 4) - (order[b.severity] ?? 4)
    })

  return (
    <div>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '20px' }}>
      {/* left: score + sections */}
      <div>
        <div style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '12px', padding: '28px 24px', textAlign: 'center', marginBottom: '16px',
        }}>
          <p style={{ margin: '0 0 16px', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
            Health Score
          </p>
          <div style={{ display: 'flex', justifyContent: 'center' }}>
            <GradientGauge score={report.health_score} />
          </div>
          <div style={{ marginTop: '16px' }}>
            <span style={{
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
              width: 40, height: 40, borderRadius: '10px',
              background: `${scoreColor(report.health_score)}18`,
              border: `2px solid ${scoreColor(report.health_score)}40`,
              fontFamily: 'JetBrains Mono, monospace', fontSize: '18px', fontWeight: 800,
              color: scoreColor(report.health_score),
            }}>{report.grade || '—'}</span>
          </div>
          <p style={{ margin: '8px 0 0', fontSize: '12px', color: '#94a3b8' }}>
            {report.period?.replace('last_', 'Last ').replace('d', ' Days')}
          </p>
        </div>

        <div style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '12px', padding: '20px 24px',
        }}>
          <p style={{ margin: '0 0 16px', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
            Section Scores
          </p>
          {sections.length === 0 ? (
            <p style={{ fontSize: '12px', color: '#94a3b8' }}>No section data</p>
          ) : (
            sections.map(s => (
              <SectionBar
                key={s.section}
                label={s.section}
                score={s.score}
                weight={s.weight ?? sectionMap[s.section] ?? 0}
              />
            ))
          )}
        </div>
      </div>

      {/* right: issues table */}
      <div style={{
        background: 'white', border: '1px solid #e2e8f0',
        borderRadius: '12px', overflow: 'hidden',
      }}>
        <div style={{ padding: '16px 20px', borderBottom: '1px solid #f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <h3 style={{ margin: 0, fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>
            Issues Found
          </h3>
          <span style={{ fontSize: '12px', color: '#94a3b8' }}>{failWarn.length} checks</span>
        </div>
        {failWarn.length === 0 ? (
          <div style={{ padding: '40px', textAlign: 'center' }}>
            <CheckCircle size={32} color="#10b981" style={{ marginBottom: '8px' }} />
            <p style={{ fontSize: '13px', color: '#64748b', margin: 0 }}>All checks passed!</p>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ background: '#f8fafc', borderBottom: '1px solid #f1f5f9' }}>
                  {['Status', 'Severity', 'Check', 'Finding'].map(h => (
                    <th key={h} style={{ padding: '10px 16px', textAlign: 'left', fontSize: '10px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', whiteSpace: 'nowrap' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {failWarn.map((c, i) => <CheckRow key={c.id || i} check={c} />)}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
    {report.platform === 'google' && (
      <GoogleAdsCampaignDataSection report={report} />
    )}
    </div>
  )
}

function CreativeTab({ report }) {
  const summary = report.creative_summary
  const ads = report.ads || []

  const formatKeys = summary?.format_counts ? Object.keys(summary.format_counts) : []

  return (
    <div>
      {/* format summary */}
      {formatKeys.length > 0 && (
        <div style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '12px', overflow: 'hidden', marginBottom: '20px',
        }}>
          <div style={{ padding: '16px 20px', borderBottom: '1px solid #f1f5f9' }}>
            <h3 style={{ margin: 0, fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>
              Creative Format Summary
            </h3>
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ background: '#f8fafc', borderBottom: '1px solid #f1f5f9' }}>
                  {['Format', 'Ads', 'Spend', 'Leads', 'CPL'].map(h => (
                    <th key={h} style={{ padding: '10px 20px', textAlign: 'left', fontSize: '10px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {formatKeys.map((fmt_key, i) => {
                  const count = summary.format_counts[fmt_key] ?? 0
                  const spend = summary.format_spend?.[fmt_key] ?? 0
                  const leads = summary.format_leads?.[fmt_key] ?? 0
                  const cpl = leads > 0 ? spend / leads : null
                  return (
                    <tr key={fmt_key} style={{ borderBottom: i < formatKeys.length - 1 ? '1px solid #f8fafc' : 'none' }}>
                      <td style={{ padding: '12px 20px', fontSize: '12px', fontWeight: 600, color: '#0f172a' }}>{fmt_key}</td>
                      <td style={{ padding: '12px 20px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#475569' }}>{count}</td>
                      <td style={{ padding: '12px 20px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#475569' }}>{fmtMoney(spend)}</td>
                      <td style={{ padding: '12px 20px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#475569' }}>{leads || '—'}</td>
                      <td style={{ padding: '12px 20px', fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#475569' }}>{cpl ? fmtMoney(cpl) : '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* per-ad cards */}
      <div style={{ marginBottom: '8px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h3 style={{ margin: 0, fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>
          Ad-Level Analysis
        </h3>
        <span style={{ fontSize: '12px', color: '#94a3b8' }}>{ads.length} ads</span>
      </div>

      {ads.length === 0 ? (
        <div style={{ background: 'white', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '40px', textAlign: 'center' }}>
          <BarChart2 size={28} color="#94a3b8" style={{ marginBottom: '8px' }} />
          <p style={{ fontSize: '13px', color: '#94a3b8', margin: 0 }}>No ad data available</p>
        </div>
      ) : (
        ads.map(ad => <AdCard key={ad.id} ad={ad} />)
      )}
    </div>
  )
}

function ActionPlanTab({ report }) {
  const checks = (report.checks || []).filter(c => c.status !== 'PASS' && c.status !== 'SKIP')
  const groups = { Critical: [], High: [], Medium: [], Low: [] }
  checks.forEach(c => {
    if (groups[c.severity]) groups[c.severity].push(c)
  })

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      {Object.entries(groups).map(([sev, items]) => {
        if (items.length === 0) return null
        const cfg = severityConfig(sev)
        const Icon = cfg.icon
        return (
          <div key={sev} style={{
            background: 'white', border: `1px solid ${cfg.border}`,
            borderRadius: '12px', overflow: 'hidden',
          }}>
            <div style={{
              padding: '14px 20px',
              background: cfg.bg,
              borderBottom: `1px solid ${cfg.border}`,
              display: 'flex', alignItems: 'center', gap: '8px',
            }}>
              <Icon size={15} color={cfg.color} />
              <span style={{ fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: cfg.color }}>
                {sev}
              </span>
              <span style={{
                marginLeft: 'auto', fontSize: '11px', fontWeight: 600,
                color: cfg.color, background: `${cfg.color}20`,
                padding: '2px 8px', borderRadius: 99,
              }}>{items.length} issue{items.length !== 1 ? 's' : ''}</span>
            </div>
            <div>
              {items.map((check, i) => (
                <div key={check.id || i} style={{
                  padding: '16px 20px',
                  borderBottom: i < items.length - 1 ? '1px solid #f8fafc' : 'none',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
                    <span style={{
                      fontFamily: 'JetBrains Mono, monospace', fontSize: '10px',
                      color: '#94a3b8', background: '#f1f5f9',
                      padding: '1px 6px', borderRadius: 3,
                    }}>{check.check_id}</span>
                    <span style={{ fontSize: '13px', fontWeight: 700, color: '#0f172a' }}>{check.name}</span>
                  </div>
                  <p style={{ margin: '0 0 8px', fontSize: '12px', color: '#64748b', lineHeight: '1.5' }}>
                    <strong style={{ color: '#475569' }}>Finding:</strong> {check.finding}
                  </p>
                  {check.recommendation && (
                    <div style={{
                      background: '#f0fdf4', border: '1px solid #bbf7d0',
                      borderRadius: '6px', padding: '8px 12px',
                    }}>
                      <p style={{ margin: 0, fontSize: '12px', color: '#166534', lineHeight: '1.5' }}>
                        <strong>Fix:</strong> {check.recommendation}
                      </p>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        )
      })}
      {checks.length === 0 && (
        <div style={{ background: 'white', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '60px', textAlign: 'center' }}>
          <CheckCircle size={36} color="#10b981" style={{ marginBottom: '12px' }} />
          <p style={{ fontFamily: 'Syne, sans-serif', fontWeight: 700, fontSize: '15px', color: '#0f172a', margin: '0 0 4px' }}>No issues found</p>
          <p style={{ fontSize: '13px', color: '#94a3b8', margin: 0 }}>All audit checks passed successfully.</p>
        </div>
      )}
    </div>
  )
}

function QuickWinsTab({ report }) {
  const wins = (report.checks || []).filter(c =>
    c.status !== 'PASS' && (c.severity === 'Critical' || c.severity === 'High')
  ).sort((a, b) => {
    const o = { Critical: 0, High: 1 }
    return (o[a.severity] ?? 2) - (o[b.severity] ?? 2)
  })

  return (
    <div>
      <div style={{ marginBottom: '16px' }}>
        <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>
          High-impact fixes that will improve your audit score immediately.
        </p>
      </div>

      {wins.length === 0 ? (
        <div style={{ background: 'white', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '60px', textAlign: 'center' }}>
          <Zap size={36} color="#10b981" style={{ marginBottom: '12px' }} />
          <p style={{ fontFamily: 'Syne, sans-serif', fontWeight: 700, fontSize: '15px', color: '#0f172a', margin: '0 0 4px' }}>No quick wins needed</p>
          <p style={{ fontSize: '13px', color: '#94a3b8', margin: 0 }}>No critical or high severity issues found.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          {wins.map((check, i) => {
            const cfg = severityConfig(check.severity)
            const Icon = cfg.icon
            return (
              <div key={check.id || i} style={{
                background: 'white',
                border: `1px solid ${cfg.border}`,
                borderLeft: `4px solid ${cfg.color}`,
                borderRadius: '10px', padding: '16px 20px',
              }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                  <div style={{
                    width: 28, height: 28, borderRadius: '6px',
                    background: cfg.bg, display: 'flex', alignItems: 'center', justifyContent: 'center',
                    flexShrink: 0,
                  }}>
                    <Icon size={13} color={cfg.color} />
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px', flexWrap: 'wrap' }}>
                      <span style={{
                        fontSize: '10px', fontWeight: 700, padding: '2px 7px', borderRadius: 4,
                        background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.border}`,
                      }}>{check.severity}</span>
                      <span style={{
                        fontFamily: 'JetBrains Mono, monospace', fontSize: '10px',
                        color: '#94a3b8',
                      }}>{check.check_id}</span>
                      <span style={{ fontSize: '13px', fontWeight: 700, color: '#0f172a' }}>{check.name}</span>
                    </div>
                    <p style={{ margin: '0 0 10px', fontSize: '12px', color: '#64748b', lineHeight: '1.5' }}>
                      {check.finding}
                    </p>
                    {check.recommendation && (
                      <div style={{
                        display: 'flex', alignItems: 'flex-start', gap: '8px',
                        background: '#f0fdf4', border: '1px solid #bbf7d0',
                        borderRadius: '6px', padding: '8px 12px',
                      }}>
                        <Target size={12} color="#10b981" style={{ marginTop: '1px', flexShrink: 0 }} />
                        <p style={{ margin: 0, fontSize: '12px', color: '#166534', lineHeight: '1.5' }}>
                          {check.recommendation}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ─── main page ────────────────────────────────────────────────────────────────

const TABS_ALL = [
  { id: 'overview',     label: 'Overview' },
  { id: 'creative',    label: 'Creative' },
  { id: 'action-plan', label: 'Action Plan' },
  { id: 'quick-wins',  label: 'Quick Wins' },
]

export default function ReportPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState('overview')
  const [failedErrorOpen, setFailedErrorOpen] = useState(false)
  const [shareHint, setShareHint] = useState('')

  const { data: report, isLoading, error } = useQuery({
    queryKey: ['report', id],
    queryFn: () => api.getReport(id).then(r => r.data),
  })

  const { data: compareData } = useQuery({
    queryKey: ['report-compare', id],
    queryFn: () => api.getReportCompare(id).then(r => r.data),
    enabled: !!report && report.status === 'complete' && (report.platform === 'google' || report.platform === 'meta'),
  })

  const platform = report?.platform || 'meta'

  if (isLoading) return (
    <div style={{ padding: '80px', textAlign: 'center' }}>
      <div style={{
        width: 36, height: 36, border: '3px solid #e2e8f0', borderTopColor: '#3b82f6',
        borderRadius: '50%', margin: '0 auto 16px', animation: 'spin-slow 1s linear infinite',
      }} />
      <p style={{ fontSize: '13px', color: '#64748b' }}>Loading report...</p>
    </div>
  )

  if (error || !report) return (
    <div style={{ padding: '48px', textAlign: 'center' }}>
      <AlertCircle size={32} color="#f43f5e" style={{ marginBottom: '12px' }} />
      <p style={{ fontSize: '14px', color: '#f43f5e' }}>Failed to load report.</p>
      <button onClick={() => navigate('/')} style={{
        marginTop: '12px', padding: '8px 20px',
        background: '#f8fafc', border: '1px solid #e2e8f0',
        borderRadius: '8px', cursor: 'pointer', fontSize: '13px', color: '#475569',
        fontFamily: 'inherit',
      }}>Back to Dashboard</button>
    </div>
  )

  if (report.status !== 'complete') {
    const label = {
      pending: 'Queued',
      running: 'In progress',
      failed: 'Failed',
      cancelled: 'Cancelled',
    }[report.status] || report.status
    return (
      <div style={{ padding: '48px', maxWidth: '520px', margin: '0 auto' }}>
        <button
          type="button"
          onClick={() => navigate('/')}
          style={{
            display: 'flex', alignItems: 'center', gap: '6px',
            background: 'none', border: 'none', cursor: 'pointer',
            color: '#64748b', fontSize: '13px', fontFamily: 'inherit',
            padding: '0 0 20px',
          }}
        >
          <ArrowLeft size={14} />Back to Dashboard
        </button>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '22px', fontWeight: 700, color: '#0f172a', margin: '0 0 8px' }}>
          Report #{id} — {label}
        </h1>
        <p style={{ fontSize: '14px', color: '#64748b', margin: '0 0 20px', lineHeight: 1.5 }}>
          {report.status === 'running' || report.status === 'pending'
            ? 'This audit is not finished yet. Open the dashboard to see live status, or stop it from there if needed.'
            : (report.error_message || 'No score was produced for this run.')}
        </p>
        <button
          type="button"
          onClick={() => navigate('/')}
          style={{
            padding: '10px 20px', background: '#f8fafc',
            border: '1px solid #e2e8f0', borderRadius: '8px',
            color: '#475569', fontSize: '13px', fontWeight: 600,
            fontFamily: 'inherit', cursor: 'pointer',
          }}
        >Go to Dashboard</button>
      </div>
    )
  }

  const account = platform === 'google' ? report.google_account : report.meta_account
  const date = new Date(report.created_at).toLocaleDateString('en-US', {
    month: 'long', day: 'numeric', year: 'numeric',
  })

  const TABS = platform === 'google'
    ? TABS_ALL.filter(t => t.id !== 'creative')
    : TABS_ALL

  const displayTab = (platform === 'google' && activeTab === 'creative')
    ? 'overview'
    : activeTab

  const isGoogleDeep = platform === 'google'
  const isMetaDeep = platform === 'meta'

  const failCount = (report.checks || []).filter(c => c.status === 'FAIL').length
  const warnCount = (report.checks || []).filter(c => c.status === 'WARN').length

  return (
    <div style={{ padding: '32px', maxWidth: '1100px', margin: '0 auto' }}>
      {/* header */}
      <div className="fade-up" style={{ marginBottom: '28px' }}>
        <button
          onClick={() => navigate('/')}
          style={{
            display: 'flex', alignItems: 'center', gap: '6px',
            background: 'none', border: 'none', cursor: 'pointer',
            color: '#64748b', fontSize: '13px', fontFamily: 'inherit',
            padding: '0 0 16px', transition: 'color 0.15s',
          }}
          onMouseEnter={e => e.currentTarget.style.color = '#0f172a'}
          onMouseLeave={e => e.currentTarget.style.color = '#64748b'}
        >
          <ArrowLeft size={14} />Back to Dashboard
        </button>

        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '16px' }}>
          <div>
            <h1 style={{
              fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700,
              color: '#0f172a', margin: '0 0 4px', letterSpacing: '-0.02em',
            }}>
              {account?.label || account?.account_name || `Report #${id}`}
            </h1>
            <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>
              <span style={{
                display: 'inline-block', marginRight: '8px', padding: '2px 8px', borderRadius: '6px',
                fontSize: '11px', fontWeight: 700,
                background: platform === 'google' ? '#fef3c7' : '#eff6ff',
                color: platform === 'google' ? '#b45309' : '#2563eb',
              }}>{platform === 'google' ? 'Google Ads' : 'Meta'}</span>
              {report.status === 'failed' && report.error_message && (
                <button
                  type="button"
                  onClick={() => setFailedErrorOpen(true)}
                  title="View API / job error"
                  style={{
                    display: 'inline-flex', alignItems: 'center', gap: '4px',
                    marginLeft: '8px', padding: '2px 8px', borderRadius: '6px',
                    fontSize: '11px', fontWeight: 700, border: '1px solid #fecdd3',
                    background: '#fff1f2', color: '#e11d48', cursor: 'pointer',
                    fontFamily: 'inherit', verticalAlign: 'middle',
                  }}
                >
                  <AlertCircle size={11} />Failed — details
                </button>
              )}
              {date} · {report.period?.replace('last_', 'Last ').replace('d', ' Days')}
              {account?.account_id && (
                <span style={{ fontFamily: 'JetBrains Mono, monospace', marginLeft: '8px', fontSize: '12px' }}>
                  · {account.account_id}
                </span>
              )}
              {account?.customer_id && (
                <span style={{ fontFamily: 'JetBrains Mono, monospace', marginLeft: '8px', fontSize: '12px' }}>
                  · {account.customer_id}
                </span>
              )}
            </p>
          </div>

          {/* summary chips */}
          <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
            {failCount > 0 && (
              <span style={{
                display: 'inline-flex', alignItems: 'center', gap: '5px',
                padding: '5px 12px', background: '#fff1f2', color: '#f43f5e',
                border: '1px solid #fecdd3', borderRadius: 99,
                fontSize: '12px', fontWeight: 600,
              }}>
                <AlertCircle size={11} />{failCount} FAIL
              </span>
            )}
            {warnCount > 0 && (
              <span style={{
                display: 'inline-flex', alignItems: 'center', gap: '5px',
                padding: '5px 12px', background: '#fffbeb', color: '#d97706',
                border: '1px solid #fde68a', borderRadius: 99,
                fontSize: '12px', fontWeight: 600,
              }}>
                <AlertTriangle size={11} />{warnCount} WARN
              </span>
            )}
            <span style={{
              display: 'inline-flex', alignItems: 'center', gap: '5px',
              padding: '5px 12px',
              background: `${scoreColor(report.health_score)}15`,
              color: scoreColor(report.health_score),
              border: `1px solid ${scoreColor(report.health_score)}40`,
              borderRadius: 99, fontSize: '12px', fontWeight: 700,
              fontFamily: 'JetBrains Mono, monospace',
            }}>
              {report.health_score ?? '—'} / 100 · {report.grade}
            </span>
            {(report.gemini_input_tokens > 0 || report.gemini_output_tokens > 0) && (
              <AiCostChip
                inputTokens={report.gemini_input_tokens}
                outputTokens={report.gemini_output_tokens}
              />
            )}
          </div>
        </div>
      </div>

      {isGoogleDeep ? (
        <div className="fade-up-delay-1">
          <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', marginBottom: '18px', alignItems: 'center' }}>
            <a
              href={`/api/reports/${id}/export.csv`}
              style={{ fontSize: '13px', fontWeight: 600, color: '#2563eb' }}
            >Export CSV</a>
            <button
              type="button"
              onClick={() => window.open(`/reports/${id}/print`, '_blank', 'noopener,noreferrer')}
              style={{
                fontSize: '13px', fontWeight: 600, color: '#475569', background: '#f8fafc',
                border: '1px solid #e2e8f0', borderRadius: '8px', padding: '6px 12px', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Print / PDF</button>
            <button
              type="button"
              onClick={async () => {
                try {
                  const { data } = await api.enableReportShare(id)
                  const path = `/share/reports/${data.token}`
                  setShareHint(`${window.location.origin}${path}`)
                  await navigator.clipboard?.writeText?.(`${window.location.origin}${path}`)
                } catch {
                  setShareHint('Could not create share link.')
                }
              }}
              style={{
                fontSize: '13px', fontWeight: 600, color: '#475569', background: '#fff',
                border: '1px solid #e2e8f0', borderRadius: '8px', padding: '6px 12px', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Copy share link</button>
            <button
              type="button"
              onClick={async () => {
                try {
                  await api.disableReportShare(id)
                  setShareHint('Share link revoked.')
                } catch {
                  setShareHint('Could not revoke share.')
                }
              }}
              style={{
                fontSize: '12px', color: '#94a3b8', background: 'none', border: 'none', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Revoke share</button>
          </div>
          {shareHint && <p style={{ fontSize: '12px', color: '#64748b', marginBottom: '12px' }}>{shareHint}</p>}
          <GoogleReportTabs report={report} compare={compareData} />
        </div>
      ) : isMetaDeep ? (
        <div className="fade-up-delay-1">
          <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', marginBottom: '18px', alignItems: 'center' }}>
            <a
              href={`/api/reports/${id}/export.csv`}
              style={{ fontSize: '13px', fontWeight: 600, color: '#2563eb' }}
            >Export CSV</a>
            <button
              type="button"
              onClick={() => window.open(`/reports/${id}/print`, '_blank', 'noopener,noreferrer')}
              style={{
                fontSize: '13px', fontWeight: 600, color: '#475569', background: '#f8fafc',
                border: '1px solid #e2e8f0', borderRadius: '8px', padding: '6px 12px', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Print / PDF</button>
            <button
              type="button"
              onClick={async () => {
                try {
                  const { data } = await api.enableReportShare(id)
                  const path = `/share/reports/${data.token}`
                  setShareHint(`${window.location.origin}${path}`)
                  await navigator.clipboard?.writeText?.(`${window.location.origin}${path}`)
                } catch {
                  setShareHint('Could not create share link.')
                }
              }}
              style={{
                fontSize: '13px', fontWeight: 600, color: '#475569', background: '#fff',
                border: '1px solid #e2e8f0', borderRadius: '8px', padding: '6px 12px', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Copy share link</button>
            <button
              type="button"
              onClick={async () => {
                try {
                  await api.disableReportShare(id)
                  setShareHint('Share link revoked.')
                } catch {
                  setShareHint('Could not revoke share.')
                }
              }}
              style={{
                fontSize: '12px', color: '#94a3b8', background: 'none', border: 'none', cursor: 'pointer', fontFamily: 'inherit',
              }}
            >Revoke share</button>
          </div>
          {shareHint && <p style={{ fontSize: '12px', color: '#64748b', marginBottom: '12px' }}>{shareHint}</p>}
          <MetaReportTabs report={report} compare={compareData} />
        </div>
      ) : (
        <>
          <div className="fade-up-delay-1" style={{ marginBottom: '24px' }}>
            <div style={{
              display: 'flex', gap: '2px',
              background: '#f1f5f9', borderRadius: '10px',
              padding: '4px', width: 'fit-content',
            }}>
              {TABS.map(tab => (
                <button
                  key={tab.id}
                  onClick={() => setActiveTab(tab.id)}
                  style={{
                    padding: '8px 20px',
                    border: 'none', borderRadius: '8px',
                    cursor: 'pointer', fontSize: '13px', fontWeight: 600,
                    fontFamily: 'inherit', transition: 'all 0.15s',
                    background: displayTab === tab.id ? 'white' : 'transparent',
                    color: displayTab === tab.id ? '#0f172a' : '#64748b',
                    boxShadow: displayTab === tab.id ? '0 1px 4px rgba(0,0,0,0.08)' : 'none',
                  }}
                >{tab.label}</button>
              ))}
            </div>
          </div>

          <div className="fade-up-delay-2">
            {displayTab === 'overview'     && <OverviewTab report={report} />}
            {displayTab === 'creative'     && <CreativeTab report={report} />}
            {displayTab === 'action-plan'  && <ActionPlanTab report={report} />}
            {displayTab === 'quick-wins'   && <QuickWinsTab report={report} />}
          </div>
        </>
      )}

      <AuditErrorModal
        open={failedErrorOpen}
        onClose={() => setFailedErrorOpen(false)}
        title={`Report #${id} failed`}
        message={report.error_message || ''}
      />
    </div>
  )
}
