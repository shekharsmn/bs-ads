import { useState, createElement } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { api } from '../api/client'
import {
  Search, ChevronDown, ChevronRight, Video, Image, AlertCircle,
  Zap, TrendingUp, Clock, Eye, DollarSign, MousePointer, Activity,
  BarChart2, Users, CheckCircle, XCircle, Copy, Check,
} from 'lucide-react'

const PERIODS = [
  { value: 'last_7d', label: 'Last 7 Days' },
  { value: 'last_30d', label: 'Last 30 Days' },
  { value: 'last_90d', label: 'Last 90 Days' },
]

function fmt(val, digits = 2) {
  if (val === null || val === undefined || val === '') return '—'
  const n = parseFloat(val)
  return isNaN(n) ? '—' : n.toFixed(digits)
}
function fmtInt(val) {
  const n = parseInt(val)
  return isNaN(n) ? '—' : n.toLocaleString()
}
function fmtMoney(val, currency = 'USD') {
  const n = parseFloat(val)
  if (isNaN(n)) return '—'
  return n.toLocaleString('en-US', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtPct(val) {
  const n = parseFloat(val)
  return isNaN(n) ? '—' : `${(n * 100).toFixed(1)}%`
}

/** Axios / Laravel validation / custom { error } / Google Ads API bodies */
function inspectFetchErrorMessage(err) {
  if (!err) return 'Unknown error.'
  const status = err.response?.status
  const d = err.response?.data

  if (typeof d === 'string') {
    const trimmed = d.trim()
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      try {
        const j = JSON.parse(trimmed)
        if (typeof j.error === 'string' && j.error) return j.error
        if (typeof j.message === 'string' && j.message) return j.message
      } catch {
        /* fall through */
      }
    }
    return trimmed || `HTTP ${status || 'error'}`
  }

  if (d && typeof d === 'object') {
    if (typeof d.error === 'string' && d.error) return d.error
    if (typeof d.message === 'string' && d.message) return d.message
    if (d.errors && typeof d.errors === 'object') {
      const lines = []
      for (const [key, val] of Object.entries(d.errors)) {
        if (Array.isArray(val)) lines.push(`${key}: ${val.join(' ')}`)
        else lines.push(`${key}: ${JSON.stringify(val)}`)
      }
      if (lines.length) return lines.join('\n')
    }
  }

  if (err.message === 'Network Error') {
    return 'Network error — check that the API server is running and the dev proxy can reach it.'
  }
  if (typeof err.message === 'string' && err.message) return err.message
  return status ? `Request failed (HTTP ${status}).` : 'Failed to fetch data.'
}

// ─── JSON Viewer ─────────────────────────────────────────────────────────────
function JsonViewer({ data, depth = 0 }) {
  const [open, setOpen] = useState(depth < 1)
  if (data === null || data === undefined) return <span style={{ color: '#94a3b8' }}>null</span>
  if (typeof data !== 'object') {
    const color = typeof data === 'number' ? '#f59e0b' : typeof data === 'boolean' ? '#f43f5e' : '#10b981'
    return <span style={{ color, fontFamily: 'JetBrains Mono, monospace', fontSize: '11px' }}>{JSON.stringify(data)}</span>
  }
  const isArr = Array.isArray(data)
  const keys = Object.keys(data)
  if (keys.length === 0) return <span style={{ color: '#94a3b8' }}>{isArr ? '[]' : '{}'}</span>

  return (
    <span>
      <button onClick={() => setOpen(o => !o)} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, color: '#64748b', fontSize: '11px' }}>
        {open ? '▾' : '▸'} {isArr ? `[${keys.length}]` : `{${keys.length}}`}
      </button>
      {open && (
        <div style={{ paddingLeft: '14px', borderLeft: '1px solid #e2e8f0', marginLeft: '4px' }}>
          {keys.map(k => (
            <div key={k} style={{ marginTop: '2px' }}>
              <span style={{ color: '#3b82f6', fontFamily: 'JetBrains Mono, monospace', fontSize: '11px' }}>{isArr ? k : `"${k}"`}</span>
              <span style={{ color: '#94a3b8', fontSize: '11px' }}>: </span>
              <JsonViewer data={data[k]} depth={depth + 1} />
            </div>
          ))}
        </div>
      )}
    </span>
  )
}

// ─── Metric Cell ──────────────────────────────────────────────────────────────
function M({ label, value, highlight }) {
  return (
    <div style={{
      background: highlight ? '#eff6ff' : '#f8fafc',
      border: `1px solid ${highlight ? '#bfdbfe' : '#e2e8f0'}`,
      borderRadius: '6px', padding: '8px 10px', minWidth: '80px',
    }}>
      <div style={{ fontSize: '10px', color: '#94a3b8', marginBottom: '2px' }}>{label}</div>
      <div style={{
        fontFamily: 'JetBrains Mono, monospace', fontSize: '12px',
        fontWeight: 700, color: highlight ? '#2563eb' : '#0f172a',
      }}>{value}</div>
    </div>
  )
}

// ─── Ad Row ───────────────────────────────────────────────────────────────────
function AdRow({ ad, currency = 'USD' }) {
  const [open, setOpen] = useState(false)
  const [rawOpen, setRawOpen] = useState(false)
  const isVideo = !!ad.video_metrics
  const ins = ad.insights
  const vm = ad.video_metrics

  // find lead action
  const leads = ins?.actions?.find(a => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'].includes(a.action_type))?.value ?? null
  const cpl = leads && ins?.spend ? fmtMoney(parseFloat(ins.spend) / parseFloat(leads), currency) : '—'

  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: '8px', background: 'white', marginBottom: '8px' }}>
      <div
        onClick={() => setOpen(o => !o)}
        style={{ padding: '12px 16px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '10px' }}
      >
        <div style={{
          width: 28, height: 28, borderRadius: '6px', flexShrink: 0,
          background: isVideo ? '#eff6ff' : '#f0fdf4',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
        }}>
          {isVideo ? <Video size={13} color="#3b82f6" /> : <Image size={13} color="#10b981" />}
        </div>

        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: '12px', fontWeight: 700, color: '#0f172a', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {ad.name}
          </div>
          <div style={{ fontSize: '10px', color: '#94a3b8', fontFamily: 'JetBrains Mono, monospace' }}>{ad.id}</div>
        </div>

        <div style={{ display: 'flex', gap: '8px', flexShrink: 0, flexWrap: 'wrap' }}>
          {ins && <>
            <M label="Spend" value={fmtMoney(ins.spend, currency)} />
            <M label="Impr." value={fmtInt(ins.impressions)} />
            <M label="CTR" value={`${fmt(ins.ctr)}%`} />
            <M label="Leads" value={leads ?? '—'} />
            <M label="CPL" value={cpl} />
            {isVideo && <M label="Play Rate" value={fmtPct(vm.video_play_rate)} />}
            {isVideo && <M label="Hook Rate" value={fmtPct(vm.hook_rate)} highlight />}
            {isVideo && <M label="ThruPlay Rate" value={fmtPct(vm.thruplay_rate)} highlight />}
          </>}
        </div>
        {open ? <ChevronDown size={13} color="#94a3b8" /> : <ChevronRight size={13} color="#94a3b8" />}
      </div>

      {open && (
        <div style={{ padding: '0 16px 16px', display: 'flex', flexDirection: 'column', gap: '12px' }}>

          {/* Creative info */}
          {ad.creative && (
            <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '10px 12px' }}>
              <div style={{ fontSize: '10px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>Creative</div>
              <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap', fontSize: '12px', color: '#475569' }}>
                <span><strong>Type:</strong> {ad.creative.object_type || '—'}</span>
                {ad.creative.video_id && <span><strong>Video ID:</strong> <span style={{ fontFamily: 'JetBrains Mono, monospace' }}>{ad.creative.video_id}</span></span>}
                {ad.creative.duration && <span><strong>Duration:</strong> {ad.creative.duration}s</span>}
                {ad.creative.title && <span><strong>Title:</strong> {ad.creative.title}</span>}
              </div>
            </div>
          )}

          {/* Video metrics raw */}
          {vm && (
            <div>
              <div style={{ fontSize: '10px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>
                Video Metrics (Raw API Response)
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '8px', marginBottom: '8px' }}>
                <M label="Impressions" value={fmtInt(ins?.impressions)} />
                <M label="3-Sec Plays" value={fmtInt(vm.plays_3s)} highlight />
                <M label="All Plays" value={fmtInt(vm.all_plays)} />
                <M label="ThruPlays" value={fmtInt(vm.thruplays)} highlight />
                <M label="Avg Watch (s)" value={fmt(vm.avg_watch_time_sec, 2)} highlight />
                <M label="Hook Rate" value={fmtPct(vm.hook_rate)} highlight />
                <M label="Video Play Rate" value={fmtPct(vm.video_play_rate)} />
                <M label="ThruPlay Rate" value={fmtPct(vm.thruplay_rate)} highlight />
              </div>
              <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '6px', padding: '8px 12px', marginBottom: '8px', fontSize: '11px', color: '#166534' }}>
                <strong>Hook Rate</strong> = <code>actions[video_view]</code> / Impressions &nbsp;·&nbsp;
                <strong>Video Play Rate</strong> = <code>video_play_actions</code> / Impressions (all plays, any duration)
              </div>
              <div style={{ background: '#0f172a', borderRadius: '6px', padding: '10px 12px', fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' }}>
                <div style={{ color: '#94a3b8', marginBottom: '4px' }}>actions[video_view] — 3-sec plays (hook rate source):</div>
                <div style={{ color: '#f59e0b' }}>{vm.plays_3s}</div>
                <div style={{ color: '#94a3b8', marginTop: '6px', marginBottom: '4px' }}>video_play_actions — all plays (any duration):</div>
                <div style={{ color: '#10b981' }}>{JSON.stringify(vm.video_play_actions)}</div>
                <div style={{ color: '#94a3b8', marginTop: '6px', marginBottom: '4px' }}>video_thruplay_watched_actions:</div>
                <div style={{ color: '#10b981' }}>{JSON.stringify(vm.video_thruplay_watched_actions)}</div>
                <div style={{ color: '#94a3b8', marginTop: '6px', marginBottom: '4px' }}>video_avg_time_watched_actions:</div>
                <div style={{ color: '#10b981' }}>{JSON.stringify(vm.video_avg_time_watched_actions)}</div>
              </div>
            </div>
          )}

          {/* All actions */}
          {ins?.actions && (
            <div>
              <div style={{ fontSize: '10px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>All Actions</div>
              <div style={{ background: '#0f172a', borderRadius: '6px', padding: '10px 12px' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <tbody>
                    {ins.actions.map((a, i) => (
                      <tr key={i}>
                        <td style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#60a5fa', padding: '2px 0' }}>{a.action_type}</td>
                        <td style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#10b981', padding: '2px 0 2px 16px', textAlign: 'right' }}>{a.value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Raw JSON toggle */}
          <button
            onClick={() => setRawOpen(o => !o)}
            style={{
              background: 'none', border: '1px solid #e2e8f0', borderRadius: '6px',
              padding: '6px 12px', fontSize: '11px', color: '#64748b',
              cursor: 'pointer', fontFamily: 'inherit', width: 'fit-content',
            }}
          >
            {rawOpen ? 'Hide' : 'Show'} full raw data
          </button>
          {rawOpen && (
            <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '12px', overflowX: 'auto' }}>
              <JsonViewer data={ad} depth={0} />
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ─── Section ──────────────────────────────────────────────────────────────────
function Section({ title, count, children, defaultOpen = false, accent }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <div style={{
      background: 'white', border: '1px solid #e2e8f0',
      borderRadius: '12px', overflow: 'hidden', marginBottom: '16px',
    }}>
      <button
        onClick={() => setOpen(o => !o)}
        style={{
          width: '100%', display: 'flex', alignItems: 'center', gap: '10px',
          padding: '14px 20px', background: 'none', border: 'none', cursor: 'pointer',
          borderLeft: `3px solid ${accent || '#e2e8f0'}`,
        }}
      >
        {open ? <ChevronDown size={14} color="#94a3b8" /> : <ChevronRight size={14} color="#94a3b8" />}
        <span style={{ fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>{title}</span>
        {count !== undefined && (
          <span style={{
            marginLeft: 'auto', fontSize: '11px', fontWeight: 600, padding: '2px 8px',
            background: '#f1f5f9', color: '#64748b', borderRadius: 99,
          }}>{count}</span>
        )}
      </button>
      {open && <div style={{ padding: '0 20px 20px' }}>{children}</div>}
    </div>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────
export default function DataInspectorPage() {
  const [form, setForm] = useState({ platform: 'meta', account_id: '', period: 'last_30d' })
  const [result, setResult] = useState(null)
  const [copied, setCopied] = useState(false)
  const [copiedGoogle, setCopiedGoogle] = useState(null)
  const [adFilter, setAdFilter] = useState('')

  const { data: metaAccounts = [] } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(r => r.data),
    enabled: form.platform === 'meta',
  })

  const { data: googleConnections = [] } = useQuery({
    queryKey: ['connections'],
    queryFn: () => api.getConnections().then(r => r.data),
    enabled: form.platform === 'google',
  })

  const googleAccounts = googleConnections
    .filter(connection => connection.platform === 'google')
    .flatMap(connection => (connection.ad_accounts || []).map(account => ({
      id: account.id,
      label: account.name,
      customer_id: account.external_id,
      client_name: account.client?.name,
      connection_name: connection.name,
      account_source: 'operational',
    })))
  const accounts = form.platform === 'meta' ? metaAccounts : googleAccounts

  const mutation = useMutation({
    mutationFn: (data) => api.inspect(data),
    onSuccess: (res) => setResult(res.data),
  })

  const handleFetch = (e) => {
    e.preventDefault()
    setResult(null)
    setCopiedGoogle(null)
    setAdFilter('')
    mutation.mutate({
      platform: form.platform,
      account_id: parseInt(form.account_id, 10),
      account_source: form.platform === 'google' ? 'operational' : undefined,
      period: form.period,
    })
  }

  const handleCopyRaw = () => {
    navigator.clipboard.writeText(JSON.stringify(result, null, 2))
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const handleCopyGoogle = (kind) => {
    const text =
      kind === 'payload'
        ? JSON.stringify(result?.payload ?? {}, null, 2)
        : JSON.stringify(result ?? {}, null, 2)
    void navigator.clipboard.writeText(text)
    setCopiedGoogle(kind)
    setTimeout(() => setCopiedGoogle(null), 2200)
  }

  const currency = result?.account?.currency || 'USD'

  const filteredAds = result?.ads?.filter(ad =>
    !adFilter || ad.name.toLowerCase().includes(adFilter.toLowerCase()) || ad.id.includes(adFilter)
  ) ?? []

  const videoAds = filteredAds.filter(a => a.video_metrics)
  const nonVideoAds = filteredAds.filter(a => !a.video_metrics)

  return (
    <div style={{ padding: '32px', maxWidth: '1100px', margin: '0 auto' }}>
      <div className="fade-up" style={{ marginBottom: '28px' }}>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700, color: '#0f172a', margin: '0 0 4px', letterSpacing: '-0.02em' }}>
          Data Inspector
        </h1>
        <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>
          Fetch raw Meta or Google Ads API payloads to verify data before running an audit.
        </p>
      </div>

      {/* Controls */}
      <div className="fade-up-delay-1" style={{
        background: 'white', border: '1px solid #e2e8f0',
        borderRadius: '12px', padding: '20px 24px', marginBottom: '24px',
      }}>
        <form onSubmit={handleFetch} style={{ display: 'flex', gap: '12px', alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div style={{ flex: 0, minWidth: '120px' }}>
            <label style={{ display: 'block', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>
              Platform
            </label>
            <select
              value={form.platform}
              onChange={e => setForm(f => ({ ...f, platform: e.target.value, account_id: '' }))}
              style={{
                width: '100%', padding: '9px 12px',
                border: '1px solid #e2e8f0', borderRadius: '8px',
                fontSize: '13px', color: '#0f172a', background: '#f8fafc',
                fontFamily: 'inherit', outline: 'none',
              }}
            >
              <option value="meta">Meta</option>
              <option value="google">Google Ads</option>
            </select>
          </div>
          <div style={{ flex: 2, minWidth: '200px' }}>
            <label style={{ display: 'block', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>
              Account
            </label>
            <select
              required
              value={form.account_id}
              onChange={e => setForm(f => ({ ...f, account_id: e.target.value }))}
              style={{
                width: '100%', padding: '9px 12px',
                border: '1px solid #e2e8f0', borderRadius: '8px',
                fontSize: '13px', color: '#0f172a', background: '#f8fafc',
                fontFamily: 'inherit', outline: 'none',
              }}
            >
              <option value="">{form.platform === 'google' && accounts.length === 0 ? 'No assigned client accounts' : 'Select account...'}</option>
              {accounts.map(a => (
                <option key={a.id} value={a.id}>
                  {a.label}{form.platform === 'google' && a.customer_id ? ` · ${a.customer_id}${a.client_name ? ` · ${a.client_name}` : ''}` : ''}
                </option>
              ))}
            </select>
            {form.platform === 'google' && accounts.length === 0 && (
              <small style={{ display: 'block', marginTop: '6px', color: '#b45309', fontSize: '10px' }}>
                Discover and assign a client account under Ad Platforms first. Manager accounts cannot be queried for metrics.
              </small>
            )}
          </div>

          <div style={{ flex: 1, minWidth: '160px' }}>
            <label style={{ display: 'block', fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' }}>
              Period
            </label>
            <div style={{ display: 'flex', gap: '6px' }}>
              {PERIODS.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => setForm(f => ({ ...f, period: value }))}
                  style={{
                    flex: 1, padding: '8px 4px', fontSize: '11px', fontWeight: 600,
                    border: form.period === value ? '2px solid #3b82f6' : '1px solid #e2e8f0',
                    borderRadius: '7px', cursor: 'pointer', fontFamily: 'inherit',
                    background: form.period === value ? '#eff6ff' : '#f8fafc',
                    color: form.period === value ? '#2563eb' : '#64748b',
                  }}
                >{label.replace('Last ', '')}</button>
              ))}
            </div>
          </div>

          <button
            type="submit"
            disabled={!form.account_id || mutation.isPending}
            style={{
              display: 'flex', alignItems: 'center', gap: '8px',
              padding: '9px 20px',
              background: mutation.isPending || !form.account_id ? '#e2e8f0' : 'linear-gradient(135deg, #3b82f6, #2563eb)',
              border: 'none', borderRadius: '8px', color: 'white',
              fontSize: '13px', fontWeight: 700, fontFamily: 'Syne, sans-serif',
              cursor: mutation.isPending || !form.account_id ? 'not-allowed' : 'pointer',
              boxShadow: form.account_id ? '0 4px 12px rgba(59,130,246,0.3)' : 'none',
            }}
          >
            <Search size={14} />
            {mutation.isPending ? 'Fetching...' : 'Fetch Data'}
          </button>
        </form>

        {mutation.isError && (
          <div style={{
            marginTop: '12px',
            background: '#fff1f2', border: '1px solid #fecdd3', borderRadius: '8px',
            padding: '12px 14px', color: '#9f1239',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' }}>
              <AlertCircle size={16} color="#e11d48" style={{ flexShrink: 0 }} />
              <span style={{ fontSize: '13px', fontWeight: 700, color: '#be123c' }}>Fetch failed</span>
              {mutation.error?.response?.status && (
                <span style={{
                  marginLeft: 'auto', fontSize: '11px', fontWeight: 600,
                  fontFamily: 'JetBrains Mono, monospace', color: '#94a3b8',
                }}>HTTP {mutation.error.response.status}</span>
              )}
            </div>
            <pre style={{
              margin: 0, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace',
              whiteSpace: 'pre-wrap', wordBreak: 'break-word', lineHeight: 1.45,
              maxHeight: '280px', overflowY: 'auto', color: '#881337',
            }}>{inspectFetchErrorMessage(mutation.error)}</pre>
          </div>
        )}
      </div>

      {/* Loading */}
      {mutation.isPending && (
        <div style={{ textAlign: 'center', padding: '60px' }}>
          <div style={{
            width: 36, height: 36, border: '3px solid #e2e8f0', borderTopColor: '#3b82f6',
            borderRadius: '50%', margin: '0 auto 16px', animation: 'spin-slow 1s linear infinite',
          }} />
          <p style={{ fontSize: '13px', color: '#64748b' }}>Fetching data from {form.platform === 'google' ? 'Google Ads' : 'Meta'} API...</p>
        </div>
      )}

      {/* Results — Google Ads */}
      {result && result.platform === 'google' && (
        <div className="fade-up">
          <div style={{
            background: 'white', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '20px 24px', marginBottom: '16px',
          }}>
            <h3 style={{ margin: '0 0 8px', fontFamily: 'Syne, sans-serif', fontSize: '14px', fontWeight: 700, color: '#0f172a' }}>
              Google Ads — raw fetch payload
            </h3>
            <p style={{ margin: '0 0 12px', fontSize: '12px', color: '#64748b' }}>
              Customer <span style={{ fontFamily: 'JetBrains Mono, monospace' }}>{result.customer_id}</span> · period {result.period}
            </p>
            {result.payload?._data_inspector?.note && (
              <p style={{
                margin: '0 0 12px', padding: '10px 12px', borderRadius: '8px',
                background: '#fffbeb', border: '1px solid #fde68a', fontSize: '11px', color: '#92400e', lineHeight: 1.45,
              }}>{result.payload._data_inspector.note}</p>
            )}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', justifyContent: 'flex-end', marginBottom: '12px' }}>
              <button
                type="button"
                onClick={() => handleCopyGoogle('full')}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  padding: '6px 14px', background: '#f8fafc', border: '1px solid #e2e8f0',
                  borderRadius: '6px', cursor: 'pointer', fontSize: '12px',
                  color: '#475569', fontFamily: 'inherit',
                }}
              >
                {copiedGoogle === 'full' ? <Check size={12} color="#10b981" /> : <Copy size={12} />}
                {copiedGoogle === 'full' ? 'Copied' : 'Copy full response'}
              </button>
              <button
                type="button"
                onClick={() => handleCopyGoogle('payload')}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  padding: '6px 14px', background: '#f8fafc', border: '1px solid #e2e8f0',
                  borderRadius: '6px', cursor: 'pointer', fontSize: '12px',
                  color: '#475569', fontFamily: 'inherit',
                }}
              >
                {copiedGoogle === 'payload' ? <Check size={12} color="#10b981" /> : <Copy size={12} />}
                {copiedGoogle === 'payload' ? 'Copied' : 'Copy payload only'}
              </button>
            </div>
            <JsonViewer data={Object.fromEntries(
              Object.entries(result.payload || {}).filter(([k]) => k !== '_data_inspector'),
            )} />
          </div>
        </div>
      )}

      {/* Results — Meta */}
      {result && result.platform !== 'google' && (
        <div className="fade-up">
          {/* Summary bar */}
          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))',
            gap: '12px', marginBottom: '20px',
          }}>
            {[
              { label: 'Campaigns', value: result.summary.total_campaigns, icon: BarChart2, color: '#3b82f6' },
              { label: 'Ad Sets', value: result.summary.total_adsets, icon: Activity, color: '#8b5cf6' },
              { label: 'Ads', value: result.summary.total_ads, icon: Image, color: '#10b981' },
              { label: 'Video Ads', value: result.summary.video_ads, icon: Video, color: '#f59e0b' },
              { label: 'Total Spend', value: fmtMoney(result.summary.total_spend, currency), icon: DollarSign, color: '#f43f5e' },
              { label: 'Impressions', value: fmtInt(result.summary.total_impressions), icon: Eye, color: '#06b6d4' },
              { label: 'Clicks', value: fmtInt(result.summary.total_clicks), icon: MousePointer, color: '#64748b' },
            ].map(({ label, value, icon, color }) => (
              <div key={label} style={{
                background: 'white', border: '1px solid #e2e8f0',
                borderRadius: '10px', padding: '14px 16px',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '6px' }}>
                  {createElement(icon, { size: 12, color })}
                  <span style={{ fontSize: '10px', color: '#94a3b8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</span>
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700, color: '#0f172a' }}>{value}</div>
              </div>
            ))}
          </div>

          {/* Account info */}
          <Section title="Account Info" accent="#3b82f6" defaultOpen>
            <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap', paddingTop: '4px' }}>
              {Object.entries(result.account).map(([k, v]) => (
                <div key={k} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '8px 12px' }}>
                  <div style={{ fontSize: '10px', color: '#94a3b8' }}>{k}</div>
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: '#0f172a', fontWeight: 600 }}>{String(v)}</div>
                </div>
              ))}
            </div>
          </Section>

          {/* Pixels */}
          {result.pixels?.length > 0 && (
            <Section title="Pixels" count={result.pixels.length} accent="#8b5cf6" defaultOpen>
              <div style={{ paddingTop: '8px', display: 'flex', flexDirection: 'column', gap: '10px' }}>
                {result.pixels.map(p => (
                  <div key={p.id} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '12px 14px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                      <div style={{ width: 8, height: 8, borderRadius: '50%', background: p.is_unavailable ? '#f43f5e' : '#10b981' }} />
                      <span style={{ fontSize: '13px', fontWeight: 700, color: '#0f172a' }}>{p.name}</span>
                      <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#94a3b8' }}>{p.id}</span>
                      {p.last_fired_time && <span style={{ fontSize: '11px', color: '#64748b', marginLeft: 'auto' }}>Last fired: {new Date(p.last_fired_time).toLocaleDateString()}</span>}
                    </div>
                    {p.stats?.length > 0 && (
                      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px' }}>
                        <thead>
                          <tr>
                            <th style={{ textAlign: 'left', color: '#94a3b8', fontWeight: 600, padding: '2px 0' }}>Event</th>
                            <th style={{ textAlign: 'right', color: '#94a3b8', fontWeight: 600, padding: '2px 0' }}>Count</th>
                          </tr>
                        </thead>
                        <tbody>
                          {p.stats.map((s, i) => (
                            <tr key={i}>
                              <td style={{ color: '#475569', padding: '2px 0', fontFamily: 'JetBrains Mono, monospace' }}>{s.event_name}</td>
                              <td style={{ color: '#0f172a', fontWeight: 700, textAlign: 'right', fontFamily: 'JetBrains Mono, monospace' }}>{fmtInt(s.count)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                ))}
              </div>
            </Section>
          )}

          {/* Campaigns */}
          <Section title="Campaigns" count={result.campaigns.length} accent="#10b981">
            <div style={{ overflowX: 'auto', paddingTop: '8px' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '12px' }}>
                <thead>
                  <tr style={{ background: '#f8fafc' }}>
                    {['Name', 'Objective', 'Status', 'Spend', 'Impressions', 'Clicks', 'CTR'].map(h => (
                      <th key={h} style={{ padding: '8px 12px', textAlign: 'left', color: '#94a3b8', fontWeight: 600, fontSize: '10px', textTransform: 'uppercase', whiteSpace: 'nowrap' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {result.campaigns.map(c => {
                    const ins = result.campaign_insights.find(i => i.campaign_id === c.id)
                    return (
                      <tr key={c.id} style={{ borderBottom: '1px solid #f8fafc' }}>
                        <td style={{ padding: '8px 12px', fontWeight: 600, color: '#0f172a' }}>{c.name}</td>
                        <td style={{ padding: '8px 12px', color: '#64748b' }}>{c.objective}</td>
                        <td style={{ padding: '8px 12px' }}>
                          <span style={{ fontSize: '10px', padding: '2px 6px', borderRadius: 4, background: c.status === 'ACTIVE' ? '#f0fdf4' : '#f8fafc', color: c.status === 'ACTIVE' ? '#10b981' : '#94a3b8', fontWeight: 600 }}>{c.status}</span>
                        </td>
                        <td style={{ padding: '8px 12px', fontFamily: 'JetBrains Mono, monospace' }}>{ins ? fmtMoney(ins.spend, currency) : '—'}</td>
                        <td style={{ padding: '8px 12px', fontFamily: 'JetBrains Mono, monospace' }}>{ins ? fmtInt(ins.impressions) : '—'}</td>
                        <td style={{ padding: '8px 12px', fontFamily: 'JetBrains Mono, monospace' }}>{ins ? fmtInt(ins.clicks) : '—'}</td>
                        <td style={{ padding: '8px 12px', fontFamily: 'JetBrains Mono, monospace' }}>{ins ? `${fmt(ins.ctr)}%` : '—'}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </Section>

          {/* Ads */}
          <Section title="Ads" count={result.ads.length} accent="#f59e0b" defaultOpen>
            <div style={{ paddingTop: '12px' }}>
              {/* Filter */}
              <div style={{ position: 'relative', marginBottom: '12px' }}>
                <Search size={13} color="#94a3b8" style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)' }} />
                <input
                  type="text"
                  placeholder="Filter by name or ad ID..."
                  value={adFilter}
                  onChange={e => setAdFilter(e.target.value)}
                  style={{
                    width: '100%', padding: '8px 12px 8px 30px',
                    border: '1px solid #e2e8f0', borderRadius: '7px',
                    fontSize: '12px', fontFamily: 'inherit', color: '#0f172a',
                    background: '#f8fafc', outline: 'none',
                  }}
                />
              </div>

              {videoAds.length > 0 && (
                <>
                  <div style={{ fontSize: '11px', fontWeight: 700, color: '#3b82f6', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                    <Video size={11} /> Video Ads ({videoAds.length})
                  </div>
                  {videoAds.map(ad => <AdRow key={ad.id} ad={ad} currency={currency} />)}
                </>
              )}

              {nonVideoAds.length > 0 && (
                <>
                  <div style={{ fontSize: '11px', fontWeight: 700, color: '#10b981', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px', marginTop: videoAds.length ? '16px' : 0, display: 'flex', alignItems: 'center', gap: '6px' }}>
                    <Image size={11} /> Static / Other Ads ({nonVideoAds.length})
                  </div>
                  {nonVideoAds.map(ad => <AdRow key={ad.id} ad={ad} currency={currency} />)}
                </>
              )}

              {filteredAds.length === 0 && (
                <p style={{ fontSize: '13px', color: '#94a3b8', textAlign: 'center', padding: '24px' }}>No ads match the filter.</p>
              )}
            </div>
          </Section>

          {/* Full raw JSON */}
          <Section title="Full Raw API Response" accent="#94a3b8">
            <div style={{ paddingTop: '8px', display: 'flex', justifyContent: 'flex-end', marginBottom: '8px' }}>
              <button
                onClick={handleCopyRaw}
                style={{
                  display: 'flex', alignItems: 'center', gap: '6px',
                  padding: '6px 14px', background: '#f8fafc', border: '1px solid #e2e8f0',
                  borderRadius: '6px', cursor: 'pointer', fontSize: '12px',
                  color: '#475569', fontFamily: 'inherit',
                }}
              >
                {copied ? <Check size={12} color="#10b981" /> : <Copy size={12} />}
                {copied ? 'Copied!' : 'Copy JSON'}
              </button>
            </div>
            <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '14px', maxHeight: '400px', overflowY: 'auto' }}>
              <JsonViewer data={result} depth={0} />
            </div>
          </Section>
        </div>
      )}
    </div>
  )
}
