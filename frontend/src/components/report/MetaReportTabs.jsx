import { useMemo, useState } from 'react'
import { metaCampaignsUrl, metaEventsManagerUrl } from '../../lib/metaDeepLinks'
import CheckCard from './CheckCard'
import FiltersBar from './FiltersBar'

function fmtMoney(n) {
  const x = parseFloat(n)
  if (Number.isNaN(x)) return '—'
  return `$${x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const TAB_DEF = [
  { id: 'overview', label: 'Overview' },
  { id: 'account', label: 'Account' },
  { id: 'campaigns', label: 'Campaigns' },
  { id: 'adsets', label: 'Ad sets' },
  { id: 'audiences', label: 'Audiences' },
  { id: 'creative', label: 'Creative' },
  { id: 'pixel', label: 'Pixel & conv.' },
  { id: 'action-plan', label: 'Action plan' },
  { id: 'quick-wins', label: 'Quick wins' },
]

export default function MetaReportTabs({ report, compare }) {
  const [tab, setTab] = useState('overview')
  const [sectionFilter, setSectionFilter] = useState('')
  const [severityFilter, setSeverityFilter] = useState('')

  const actId = report.meta_account?.account_id
  const snap = report.meta_snapshot || {}
  const methodology = report.methodology || {}
  const checks = useMemo(() => report.checks || [], [report.checks])

  const sections = useMemo(
    () => [...new Set(checks.map(c => c.section).filter(Boolean))].sort(),
    [checks],
  )

  const nonPass = checks.filter(c => c.status !== 'PASS' && c.status !== 'SKIP')
  const filtered = nonPass.filter(c =>
    (!sectionFilter || c.section === sectionFilter)
    && (!severityFilter || c.severity === severityFilter),
  )
  const byImpact = [...filtered].sort((a, b) => (b.impact_score ?? 0) - (a.impact_score ?? 0))
  const byImpactAll = [...nonPass].sort((a, b) => (b.impact_score ?? 0) - (a.impact_score ?? 0))
  const quick = byImpact.filter(c => c.severity === 'Critical' || c.severity === 'High').slice(0, 10)
  const pixelChecks = checks.filter(c => c.section === 'Pixel/CAPI')

  const summary = report.creative_summary
  const formatKeys = summary?.format_counts ? Object.keys(summary.format_counts) : []

  const tabBar = (
    <div style={{
      display: 'flex', gap: '4px', flexWrap: 'wrap', background: '#f1f5f9', padding: '4px',
      borderRadius: '10px', marginBottom: '20px',
    }}
    >
      {TAB_DEF.map(t => (
        <button
          key={t.id}
          type="button"
          onClick={() => setTab(t.id)}
          style={{
            padding: '8px 14px', border: 'none', borderRadius: '8px', cursor: 'pointer',
            fontSize: '12px', fontWeight: 600, fontFamily: 'inherit',
            background: tab === t.id ? '#fff' : 'transparent',
            color: tab === t.id ? '#0f172a' : '#64748b',
            boxShadow: tab === t.id ? '0 1px 4px rgba(0,0,0,0.08)' : 'none',
          }}
        >{t.label}</button>
      ))}
    </div>
  )

  return (
    <div>
      {compare?.score_delta != null && (
        <div style={{
          marginBottom: '16px', padding: '10px 14px', borderRadius: '8px',
          background: compare.score_delta >= 0 ? '#f0fdf4' : '#fff1f2',
          border: `1px solid ${compare.score_delta >= 0 ? '#bbf7d0' : '#fecdd3'}`,
          fontSize: '13px', color: '#475569',
        }}>
          vs last audit (#{compare.previous?.id}): score {compare.score_delta >= 0 ? '+' : ''}{compare.score_delta}
          {compare.new_check_ids?.length > 0 && (
            <span style={{ marginLeft: '10px' }}>New: {compare.new_check_ids.join(', ')}</span>
          )}
          {compare.resolved_check_ids?.length > 0 && (
            <span style={{ marginLeft: '10px' }}>Resolved: {compare.resolved_check_ids.join(', ')}</span>
          )}
        </div>
      )}
      {tabBar}

      {tab === 'overview' && (
        <div style={{ fontSize: '13px', color: '#64748b' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '140px 1fr', gap: '20px', marginBottom: '20px', alignItems: 'start' }}>
            <div style={{ textAlign: 'center' }}>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '42px', fontWeight: 800, color: '#0f172a' }}>
                {report.health_score ?? '—'}
              </div>
              <div style={{ fontSize: '11px', color: '#94a3b8' }}>/ 100 · {report.grade}</div>
            </div>
            <div>
              <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8', marginBottom: '8px', textTransform: 'uppercase' }}>Sections</div>
              {(report.section_scores || []).map(s => (
                <div key={s.section} style={{ marginBottom: '8px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '12px' }}>
                    <span style={{ fontWeight: 600, color: '#475569' }}>{s.section}</span>
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontWeight: 700 }}>{s.score}</span>
                  </div>
                  <div style={{ height: 4, background: '#f1f5f9', borderRadius: 99, overflow: 'hidden' }}>
                    <div style={{ height: '100%', width: `${s.score ?? 0}%`, background: '#2563eb', borderRadius: 99 }} />
                  </div>
                </div>
              ))}
            </div>
          </div>
          <p>Use the tabs for spend KPIs, entity samples, pixel context, and impact-ranked fixes.</p>
          <p style={{ marginTop: '12px' }}>Top issues by impact:</p>
          <ul>
            {byImpactAll.slice(0, 5).map(c => (
              <li key={c.id}><strong>{c.check_id}</strong> — {c.name} (impact {c.impact_score ?? '—'})</li>
            ))}
          </ul>
        </div>
      )}

      {tab === 'account' && snap.kpis && (
        <div>
          <a href={metaCampaignsUrl(actId)} target="_blank" rel="noreferrer" style={{ fontSize: '12px', fontWeight: 600 }}>Open Ads Manager</a>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px', margin: '14px 0 20px' }}>
            {Object.entries(snap.kpis).map(([k, v]) => (
              <div key={k} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '12px 16px', minWidth: '100px' }}>
                <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase' }}>{k.replace(/_/g, ' ')}</div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700 }}>{typeof v === 'number' ? v.toLocaleString() : v}</div>
              </div>
            ))}
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '12px 16px' }}>
              <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase' }}>Pixels</div>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700 }}>{snap.pixel_count ?? '—'}</div>
            </div>
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '12px 16px' }}>
              <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase' }}>Custom conversions</div>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700 }}>{snap.custom_conversions_count ?? '—'}</div>
            </div>
          </div>
          {snap.creative_format_mix && Object.keys(snap.creative_format_mix).length > 0 && (
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px' }}>
              <h4 style={{ margin: '0 0 8px', fontSize: '13px' }}>Creative object types (fetch sample)</h4>
              {Object.entries(snap.creative_format_mix).map(([k, n]) => (
                <div key={k} style={{ fontSize: '12px', display: 'flex', justifyContent: 'space-between' }}>
                  <span>{k}</span><span>{n}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {tab === 'campaigns' && (
        <div>
          <a href={metaCampaignsUrl(actId)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open campaigns</a>
          <div style={{ overflowX: 'auto', marginTop: '10px' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Name', 'Status', 'Objective'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.campaigns_sample || []).map((c, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{c.name}</td>
                    <td style={{ padding: '8px' }}>{c.status ?? '—'}</td>
                    <td style={{ padding: '8px' }}>{c.objective ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'adsets' && (
        <div>
          <a href={metaCampaignsUrl(actId)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open Ads Manager</a>
          <div style={{ overflowX: 'auto', marginTop: '10px' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Ad set', 'Destination', 'Bid strategy'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.adsets_sample || []).map((a, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{a.name}</td>
                    <td style={{ padding: '8px' }}>{a.destination_type ?? '—'}</td>
                    <td style={{ padding: '8px' }}>{a.bid_strategy ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'audiences' && (
        <div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Audience', 'Subtype'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.audiences_sample || []).map((a, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{a.name}</td>
                    <td style={{ padding: '8px' }}>{a.subtype ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'creative' && (
        <div style={{ fontSize: '12px', color: '#475569' }}>
          {(report.ads || []).length > 0 && (
            <p style={{ marginTop: 0 }}>Stored ad-level analysis rows: <strong>{report.ads.length}</strong> (from audit job).</p>
          )}
          {formatKeys.length > 0 && (
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', overflow: 'hidden', marginBottom: '16px' }}>
              <div style={{ padding: '12px 14px', borderBottom: '1px solid #f1f5f9', fontWeight: 700 }}>Creative summary (report)</div>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={{ background: '#f8fafc' }}>
                    {['Format', 'Ads', 'Spend', 'Leads', 'CPL'].map(h => (
                      <th key={h} style={{ textAlign: 'left', padding: '8px', fontSize: '10px', color: '#94a3b8' }}>{h}</th>
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
                        <td style={{ padding: '8px' }}>{fmt_key}</td>
                        <td style={{ padding: '8px', fontFamily: 'JetBrains Mono, monospace' }}>{count}</td>
                        <td style={{ padding: '8px', fontFamily: 'JetBrains Mono, monospace' }}>{fmtMoney(spend)}</td>
                        <td style={{ padding: '8px', fontFamily: 'JetBrains Mono, monospace' }}>{leads || '—'}</td>
                        <td style={{ padding: '8px', fontFamily: 'JetBrains Mono, monospace' }}>{cpl ? fmtMoney(cpl) : '—'}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          <h4 style={{ fontSize: '13px', margin: '0 0 8px' }}>Top ads by spend (API snapshot)</h4>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', background: '#fff', fontSize: '11px' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Ad', 'Spend', 'Impr.', 'CTR', 'Freq'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.top_ads_by_spend || []).map((r, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{r.name || r.ad_id}</td>
                    <td style={{ padding: '8px' }}>{fmtMoney(r.spend)}</td>
                    <td style={{ padding: '8px' }}>{r.impressions ?? '—'}</td>
                    <td style={{ padding: '8px' }}>{r.ctr != null ? `${(r.ctr * 100).toFixed(2)}%` : '—'}</td>
                    <td style={{ padding: '8px' }}>{r.frequency ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'pixel' && (
        <div style={{ fontSize: '12px' }}>
          <p><a href={metaEventsManagerUrl()} target="_blank" rel="noreferrer">Open Events Manager</a></p>
          <h4 style={{ fontSize: '13px' }}>Pixels (snapshot)</h4>
          <ul>
            {(snap.pixels || []).map(p => (
              <li key={p.id || p.name}>{p.name} {p.last_fired_time ? `· last fired ${p.last_fired_time}` : ''}</li>
            ))}
          </ul>
          <h4 style={{ fontSize: '13px' }}>Pixel / CAPI checks</h4>
          <ul>
            {pixelChecks.map(c => (
              <li key={c.id}><strong>{c.check_id}</strong> {c.status} — {c.finding}</li>
            ))}
          </ul>
        </div>
      )}

      {tab === 'action-plan' && (
        <div>
          <FiltersBar
            sections={sections}
            section={sectionFilter}
            onSection={setSectionFilter}
            severity={severityFilter}
            onSeverity={setSeverityFilter}
            onClear={() => { setSectionFilter(''); setSeverityFilter('') }}
          />
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            {['Critical', 'High', 'Medium', 'Low'].map(sev => {
              const group = byImpact.filter(c => c.severity === sev)
              if (!group.length) return null
              return (
                <div key={sev} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px' }}>
                  <div style={{ fontWeight: 700, marginBottom: '8px' }}>{sev}</div>
                  {group.map(c => (
                    <CheckCard key={c.id} check={c} methodology={methodology} compare={compare} />
                  ))}
                </div>
              )
            })}
            {!byImpact.length && (
              <p style={{ color: '#64748b', fontSize: '13px' }}>No checks match the current filters.</p>
            )}
          </div>
        </div>
      )}

      {tab === 'quick-wins' && (
        <div>
          <FiltersBar
            sections={sections}
            section={sectionFilter}
            onSection={setSectionFilter}
            severity={severityFilter}
            onSeverity={setSeverityFilter}
            onClear={() => { setSectionFilter(''); setSeverityFilter('') }}
          />
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {quick.map(c => (
              <div key={c.id} style={{ background: '#fff', borderLeft: '4px solid #f43f5e', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '12px' }}>
                <strong>{c.check_id}</strong> {c.severity} · impact {c.impact_score ?? '—'}
                <div style={{ fontSize: '13px', marginTop: '6px' }}>{c.name}</div>
                <div style={{ fontSize: '12px', color: '#64748b' }}>{c.finding}</div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
