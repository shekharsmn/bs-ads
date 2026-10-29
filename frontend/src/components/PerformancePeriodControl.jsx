import { completedDateRange } from '../lib/performance'

export default function PerformancePeriodControl({ range, startDate, endDate, timezone, onChange }) {
  const choose = value => value === 'custom' ? onChange({ range: value, start_date: startDate, end_date: endDate }) : onChange({ range: value, ...completedDateRange(Number(value.replace('d', '')), timezone) })
  return <div className="performance-period" aria-label="Reporting period"><label><span>Period</span><select value={range} onChange={event => choose(event.target.value)}><option value="7d">Last 7 days</option><option value="30d">Last 30 days</option><option value="90d">Last 90 days</option><option value="custom">Custom</option></select></label>{range === 'custom' && <div className="performance-custom-dates"><input aria-label="Start date" type="date" value={startDate} onChange={event => onChange({ range, start_date: event.target.value, end_date: endDate })}/><span>to</span><input aria-label="End date" type="date" value={endDate} onChange={event => onChange({ range, start_date: startDate, end_date: event.target.value })}/></div>}</div>
}
