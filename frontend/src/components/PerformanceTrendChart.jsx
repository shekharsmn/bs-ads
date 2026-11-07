import { useState } from 'react'
import { Area, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

const metricColors = {
  spend: '#2563eb', leads: '#059669', cpl: '#d97706', ctr: '#7c3aed', purchases: '#0f766e',
  cpp: '#ea580c', conversion_value: '#e11d48', roas: '#9333ea',
}
const moneyMetrics = new Set(['spend', 'cpl', 'cpp', 'conversion_value'])

export default function PerformanceTrendChart({ series = [], currency, columns, ariaLabel = `${currency} daily performance trend`, className = '' }) {
  const [hiddenMetrics, setHiddenMetrics] = useState([])
  const data = series.map(item => ({ date: item.date, shortDate: item.date.slice(5), ...(item.metrics || item) }))
  const toggleMetric = key => setHiddenMetrics(current => {
    if (!current.includes(key) && current.length === columns.length - 1) return current
    return current.includes(key) ? current.filter(item => item !== key) : [...current, key]
  })

  if (!data.length) return <div className={`performance-metric-trend ${className}`}><div className="performance-empty-row">No trend data in this period.</div></div>

  return <section className={`performance-metric-trend ${className}`} aria-label={ariaLabel}>
    <header className="performance-metric-trend-head">
      <div><strong>Daily performance trend</strong><span>Toggle metrics to compare movement over time.</span></div>
      <div className="performance-metric-legend" aria-label="Chart metrics">
        {columns.map(column => {
          const visible = !hiddenMetrics.includes(column.key)
          const lastVisible = visible && hiddenMetrics.length === columns.length - 1
          return <button type="button" key={column.key} aria-pressed={visible} disabled={lastVisible} onClick={() => toggleMetric(column.key)} className={visible ? '' : 'muted'}>
            <i style={{ backgroundColor: metricColors[column.key] }}/>{column.label}
          </button>
        })}
      </div>
    </header>
    <div className="performance-metric-chart">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 8 }}>
          <defs><linearGradient id={`performance-spend-${currency}`} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={metricColors.spend} stopOpacity=".16"/><stop offset="100%" stopColor={metricColors.spend} stopOpacity="0"/></linearGradient></defs>
          <CartesianGrid vertical={false} stroke="#e8eef6" strokeDasharray="3 4"/>
          <XAxis dataKey="shortDate" axisLine={false} tickLine={false}/>
          <YAxis yAxisId="money" axisLine={false} tickLine={false} width={68} tickFormatter={compactNumber}/>
          <YAxis yAxisId="results" orientation="right" axisLine={false} tickLine={false} width={56} tickFormatter={compactNumber}/>
          <Tooltip labelFormatter={(_, payload) => payload?.[0]?.payload?.date || ''} formatter={(value, key) => [columns.find(column => column.key === key)?.format(value, currency) ?? value, columns.find(column => column.key === key)?.label ?? key]}/>
          {!hiddenMetrics.includes('spend') && <Area type="monotone" yAxisId="money" dataKey="spend" stroke={metricColors.spend} strokeWidth={2.5} fill={`url(#performance-spend-${currency})`} dot={data.length === 1 ? { r: 4 } : false} activeDot={{ r: 4 }} connectNulls/>}
          {columns.filter(column => column.key !== 'spend' && !hiddenMetrics.includes(column.key)).map(column => <Line key={column.key} type="monotone" yAxisId={moneyMetrics.has(column.key) ? 'money' : 'results'} dataKey={column.key} stroke={metricColors[column.key]} strokeWidth={2.5} dot={data.length === 1 ? { r: 4 } : false} activeDot={{ r: 4 }} connectNulls/>)}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  </section>
}

function compactNumber(value) {
  return new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)
}
