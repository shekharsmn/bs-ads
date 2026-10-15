import { useMemo, useState } from 'react'
import {
  googleCampaignsUrl, googleKeywordsUrl, googleSearchTermsUrl, googleConversionsUrl,
} from '../../lib/googleDeepLinks'
import CheckCard from './CheckCard'
import FiltersBar from './FiltersBar'

function fmtMoney(n) {
  const x = parseFloat(n)
  if (Number.isNaN(x)) return '—'
  return `$${x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function micros(m, cur) {
  if (m == null) return '—'
  return fmtMoney(Number(m) / 1e6, cur)
}

const TAB_DEF = [
  { id: 'overview', label: 'Overview' },
  { id: 'account', label: 'Account' },
  { id: 'campaigns', label: 'Campaigns' },
  { id: 'keywords', label: 'Keywords' },
  { id: 'search_terms', label: 'Search terms' },
  { id: 'ads', label: 'Ads & assets' },
  { id: 'conv', label: 'Conversion tracking' },
  { id: 'action-plan', label: 'Action plan' },
  { id: 'quick-wins', label: 'Quick wins' },
]

export default function GoogleReportTabs({ report, compare }) {
  const [tab, setTab] = useState('overview')
  const [sectionFilter, setSectionFilter] = useState('')
  const [severityFilter, setSeverityFilter] = useState('')
  const cid = report.google_account?.customer_id
  const snap = report.google_snapshot || {}
  const methodology = report.methodology || {}
  const checks = useMemo(() => report.checks || [], [report.checks])
  const currency = snap.currency || report.google_account?.currency || 'USD'

  const sections = useMemo(
    () => [...new Set(checks.map(c => c.section).filter(Boolean))].sort(),
    [checks],
  )
  const nonPass = checks.filter(c => c.status !== 'PASS' && c.status !== 'SKIP')
  const filtered = nonPass.filter(c =>
    (!sectionFilter || c.section === sectionFilter)
    && (!severityFilter || c.severity === severityFilter),
  )
  const byImpactAll = [...nonPass].sort((a, b) => (b.impact_score ?? 0) - (a.impact_score ?? 0))
  const byImpact = [...filtered].sort((a, b) => (b.impact_score ?? 0) - (a.impact_score ?? 0))
  const quick = byImpact.filter(c => c.severity === 'Critical' || c.severity === 'High').slice(0, 10)

  const convChecks = checks.filter(c => c.section === 'Conversion Tracking')

  const tabBar = (
    <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap', background: '#f1f5f9', padding: '4px', borderRadius: '10px', marginBottom: '20px' }}>
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
                    <div style={{ height: '100%', width: `${s.score ?? 0}%`, background: '#3b82f6', borderRadius: 99 }} />
                  </div>
                </div>
              ))}
            </div>
          </div>
          <p>Use the tabs for KPIs, entity browsers, and impact-ranked fixes. Each check includes methodology from the API.</p>
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
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px', marginBottom: '20px' }}>
            {Object.entries(snap.kpis).filter(([k]) => k !== 'currency').map(([k, v]) => (
              <div key={k} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '12px 16px', minWidth: '100px' }}>
                <div style={{ fontSize: '10px', color: '#94a3b8', textTransform: 'uppercase' }}>{k.replace(/_/g, ' ')}</div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '16px', fontWeight: 700 }}>{typeof v === 'number' ? v.toLocaleString() : v}</div>
              </div>
            ))}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px' }}>
              <h4 style={{ margin: '0 0 8px', fontSize: '13px' }}>Bidding mix</h4>
              {(snap.bidding_mix || []).map((r, i) => (
                <div key={i} style={{ fontSize: '12px', display: 'flex', justifyContent: 'space-between' }}>
                  <span>{r.key}</span><span>{r.campaign_count} camps · {fmtMoney(r.spend)}</span>
                </div>
              ))}
            </div>
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px' }}>
              <h4 style={{ margin: '0 0 8px', fontSize: '13px' }}>Channel mix</h4>
              {(snap.channel_mix || []).map((r, i) => (
                <div key={i} style={{ fontSize: '12px', display: 'flex', justifyContent: 'space-between' }}>
                  <span>{r.key}</span><span>{r.campaign_count} camps · {fmtMoney(r.spend)}</span>
                </div>
              ))}
            </div>
          </div>
          {snap.qs_histogram && (
            <div style={{ marginTop: '16px', background: '#fff', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '14px' }}>
              <h4 style={{ margin: '0 0 8px', fontSize: '13px' }}>QS histogram</h4>
              <div style={{ display: 'flex', alignItems: 'flex-end', gap: '4px', height: '80px' }}>
                {Object.entries(snap.qs_histogram).map(([qs, n]) => (
                  <div key={qs} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                    <div style={{
                      width: '100%', maxWidth: '24px', background: '#3b82f6',
                      height: `${Math.min(100, (n / (Math.max(...Object.values(snap.qs_histogram)) || 1)) * 100)}%`,
                      borderRadius: '4px 4px 0 0',
                    }} title={`QS ${qs}: ${n}`} />
                    <span style={{ fontSize: '9px', color: '#94a3b8' }}>{qs}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
          <p style={{ marginTop: '12px', fontSize: '12px' }}>
            <a href={googleConversionsUrl(cid)} target="_blank" rel="noreferrer">Open conversions in Google Ads</a>
          </p>
        </div>
      )}

      {tab === 'campaigns' && (
        <div>
          <a href={googleCampaignsUrl(cid)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open campaigns</a>
          <div style={{ overflowX: 'auto', marginTop: '10px' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Campaign', 'Spend', 'Conv.'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.campaigns || []).map((c, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{c.name}</td>
                    <td style={{ padding: '8px' }}>{micros(c.cost_micros, currency)}</td>
                    <td style={{ padding: '8px' }}>{c.conversions ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'keywords' && (
        <div>
          <a href={googleKeywordsUrl(cid)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open keywords</a>
          <div style={{ overflowX: 'auto', marginTop: '10px' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
              <thead><tr style={{ background: '#f8fafc' }}>
                {['Keyword', 'Campaign', 'QS', 'Clicks', 'Cost'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {(snap.keywords || []).slice(0, 200).map((r, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px' }}>{r.keyword}</td>
                    <td style={{ padding: '8px' }}>{r.campaign}</td>
                    <td style={{ padding: '8px' }}>{r.qs || '—'}</td>
                    <td style={{ padding: '8px' }}>{r.clicks}</td>
                    <td style={{ padding: '8px' }}>{fmtMoney((r.cost_micros || 0) / 1e6)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'search_terms' && (
        <div>
          <a href={googleSearchTermsUrl(cid)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open search terms</a>
          <h4 style={{ fontSize: '13px' }}>Top by cost</h4>
          <ul style={{ fontSize: '12px' }}>
            {(snap.top_search_terms_cost || []).slice(0, 25).map((r, i) => (
              <li key={i}>{r.search_term} — {fmtMoney(r.cost)}</li>
            ))}
          </ul>
          <h4 style={{ fontSize: '13px' }}>High spend, zero conv</h4>
          <ul style={{ fontSize: '12px' }}>
            {(snap.top_search_terms_wasted || []).slice(0, 25).map((r, i) => (
              <li key={i}>{r.search_term} — {fmtMoney(r.cost)}</li>
            ))}
          </ul>
        </div>
      )}

      {tab === 'ads' && (
        <div style={{ fontSize: '12px' }}>
          <p>RSA summary (snapshot)</p>
          <table style={{ width: '100%', borderCollapse: 'collapse', background: '#fff' }}>
            <thead><tr style={{ background: '#f8fafc' }}>
              {['Campaign', 'Ad group', 'Headlines', 'Strength'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
            </tr></thead>
            <tbody>
              {(snap.rsa_ads_flat || []).map((r, i) => (
                <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '8px' }}>{r.campaign_name}</td>
                  <td style={{ padding: '8px' }}>{r.ad_group_name}</td>
                  <td style={{ padding: '8px' }}>{r.headline_count}</td>
                  <td style={{ padding: '8px' }}>{r.ad_strength}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'conv' && (
        <div>
          <a href={googleConversionsUrl(cid)} target="_blank" rel="noreferrer" style={{ fontSize: '12px' }}>Open conversion actions</a>
          <table style={{ width: '100%', marginTop: '12px', borderCollapse: 'collapse', fontSize: '11px', background: '#fff' }}>
            <thead><tr style={{ background: '#f8fafc' }}>
              {['Name', 'Category', 'Primary', 'Origin'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px' }}>{h}</th>)}
            </tr></thead>
            <tbody>
              {(snap.conversion_actions_full || []).map((r, i) => (
                <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '8px' }}>{r.name}</td>
                  <td style={{ padding: '8px' }}>{r.category}</td>
                  <td style={{ padding: '8px' }}>{r.primary_for_goal ? 'Yes' : '—'}</td>
                  <td style={{ padding: '8px' }}>{r.origin}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <h4 style={{ marginTop: '20px', fontSize: '13px' }}>Conversion checks</h4>
          <ul style={{ fontSize: '12px' }}>
            {convChecks.map(c => (
              <li key={c.id}><strong>{c.check_id}</strong> {c.status} — {c.finding}</li>
            ))}
          </ul>
        </div>
      )}

      {tab === 'action-plan' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          <FiltersBar
            sections={sections}
            section={sectionFilter}
            onSection={setSectionFilter}
            severity={severityFilter}
            onSeverity={setSeverityFilter}
            onClear={() => { setSectionFilter(''); setSeverityFilter('') }}
          />
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
      )}

      {tab === 'quick-wins' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
          <FiltersBar
            sections={sections}
            section={sectionFilter}
            onSection={setSectionFilter}
            severity={severityFilter}
            onSeverity={setSeverityFilter}
            onClear={() => { setSectionFilter(''); setSeverityFilter('') }}
          />
          {quick.map(c => (
            <div key={c.id} style={{ background: '#fff', borderLeft: '4px solid #f43f5e', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '12px' }}>
              <strong>{c.check_id}</strong> {c.severity} · impact {c.impact_score ?? '—'}
              <div style={{ fontSize: '13px', marginTop: '6px' }}>{c.name}</div>
              <div style={{ fontSize: '12px', color: '#64748b' }}>{c.finding}</div>
            </div>
          ))}
          {!quick.length && (
            <p style={{ color: '#64748b', fontSize: '13px' }}>No Critical/High issues match the current filters.</p>
          )}
        </div>
      )}
    </div>
  )
}
