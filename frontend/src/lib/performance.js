export const formatNumber = value => value == null ? '—' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(value)
export const formatMoney = (value, currency = 'INR') => value == null ? '—' : new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(value)
export const formatRoas = value => value == null ? '—' : `${formatNumber(value)}×`
export const formatPercent = value => value == null ? '—' : `${formatNumber(value)}%`
export function dateInTimeZone(date, timezone) { const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date).map(part => [part.type, part.value])); return `${parts.year}-${parts.month}-${parts.day}` }
function shiftCalendarDate(value, days) { const [year, month, day] = value.split('-').map(Number); return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10) }
export function completedDateRange(days, timezone, now = new Date()) { const today = dateInTimeZone(now, timezone); const end = shiftCalendarDate(today, -1); return { start_date: shiftCalendarDate(end, -(days - 1)), end_date: end } }
export function completedPeriodRange(range, timezone, now = new Date()) {
  const dates = completedDateRange(range === 'yesterday' || range === 'mtd' ? 1 : Number(range === 'custom' ? 30 : range.replace('d', '')), timezone, now)
  return range === 'mtd' ? { start_date: `${dates.end_date.slice(0, 7)}-01`, end_date: dates.end_date } : dates
}
export const performanceMetricColumns = [
  { key: 'spend', label: 'Spend', format: formatMoney },
  { key: 'impressions', label: 'Impressions', format: formatNumber },
  { key: 'reach', label: 'Reach', format: formatNumber },
  { key: 'frequency', label: 'Frequency', format: formatNumber },
  { key: 'clicks', label: 'Clicks', format: formatNumber },
  { key: 'ctr', label: 'CTR', format: formatPercent },
  { key: 'cpc', label: 'CPC', format: formatMoney },
  { key: 'cpm', label: 'CPM', format: formatMoney },
  { key: 'conversions', label: 'Conversions', format: formatNumber },
  { key: 'conversion_rate', label: 'Conversion rate', format: formatPercent },
  { key: 'cpa', label: 'CPA', format: formatMoney },
  { key: 'leads', label: 'Leads', format: formatNumber },
  { key: 'cpl', label: 'CPL', format: formatMoney },
  { key: 'purchases', label: 'Purchases', format: formatNumber },
  { key: 'cpp', label: 'CPP', format: formatMoney },
  { key: 'conversion_value', label: 'Revenue', format: formatMoney },
  { key: 'roas', label: 'ROAS', format: formatRoas },
]

export const performanceMetricColumnMap = new Map(performanceMetricColumns.map(column => [column.key, column]))

export function objectiveMetricKeys(objective) {
  if (objective === 'ecommerce') return ['spend', 'purchases', 'cpp', 'conversion_value', 'roas']
  if (objective === 'both') return ['spend', 'leads', 'cpl', 'purchases', 'conversion_value', 'roas']
  return ['spend', 'leads', 'cpl', 'ctr']
}

export function metricColumnsFor(keys) {
  return keys.map(key => performanceMetricColumnMap.get(key)).filter(Boolean)
}

export const metricColumns = metricColumnsFor(['spend', 'leads', 'cpl', 'purchases', 'cpp', 'roas'])
