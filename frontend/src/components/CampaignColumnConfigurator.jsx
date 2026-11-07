import { useState } from 'react'
import { Check, ChevronDown, ChevronUp, GripVertical, RotateCcw, Settings2 } from 'lucide-react'
import { performanceMetricColumns, performanceMetricColumnMap } from '../lib/performance'

export default function CampaignColumnConfigurator({ columnKeys, onChange, onReset }) {
  const [open, setOpen] = useState(false)
  const [draggedKey, setDraggedKey] = useState(null)
  const selected = columnKeys.map(key => performanceMetricColumnMap.get(key)).filter(Boolean)
  const available = performanceMetricColumns.filter(column => !columnKeys.includes(column.key))

  const remove = key => columnKeys.length > 1 && onChange(columnKeys.filter(item => item !== key))
  const add = key => onChange([...columnKeys, key])
  const move = (key, direction) => {
    const from = columnKeys.indexOf(key)
    const to = from + direction
    if (from < 0 || to < 0 || to >= columnKeys.length) return
    const next = [...columnKeys]
    ;[next[from], next[to]] = [next[to], next[from]]
    onChange(next)
  }
  const drop = targetKey => {
    if (!draggedKey || draggedKey === targetKey) return setDraggedKey(null)
    const next = columnKeys.filter(key => key !== draggedKey)
    next.splice(next.indexOf(targetKey), 0, draggedKey)
    onChange(next)
    setDraggedKey(null)
  }

  return <div className="campaign-column-configurator">
    <button className="campaign-columns-trigger" type="button" aria-expanded={open} onClick={() => setOpen(value => !value)}><Settings2 size={14}/> Columns</button>
    {open && <div className="campaign-columns-popover" role="dialog" aria-label="Configure campaign columns">
      <header><div><strong>Campaign columns</strong><small>Choose metrics and drag to reorder.</small></div><button type="button" onClick={onReset}><RotateCcw size={13}/> Restore defaults</button></header>
      <div className="campaign-columns-list" aria-label="Shown columns">
        {selected.map((column, index) => <div className="campaign-column-row selected" key={column.key} draggable onDragStart={() => setDraggedKey(column.key)} onDragEnd={() => setDraggedKey(null)} onDragOver={event => event.preventDefault()} onDrop={() => drop(column.key)}>
          <GripVertical size={14} aria-hidden="true"/><button type="button" className="campaign-column-check" aria-label={`Hide ${column.label}`} disabled={columnKeys.length === 1} onClick={() => remove(column.key)}><Check size={12}/></button><span>{column.label}</span><div className="campaign-column-move"><button type="button" aria-label={`Move ${column.label} up`} disabled={index === 0} onClick={() => move(column.key, -1)}><ChevronUp size={13}/></button><button type="button" aria-label={`Move ${column.label} down`} disabled={index === selected.length - 1} onClick={() => move(column.key, 1)}><ChevronDown size={13}/></button></div>
        </div>)}
      </div>
      {available.length > 0 && <><p className="campaign-columns-label">Available metrics</p><div className="campaign-columns-available">{available.map(column => <button type="button" key={column.key} onClick={() => add(column.key)}><span aria-hidden="true"/> {column.label}</button>)}</div></>}
    </div>}
  </div>
}
