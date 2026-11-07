import { useMemo, useState } from 'react'
import { objectiveMetricKeys, performanceMetricColumnMap } from '../lib/performance'

export function sanitizeCampaignColumnKeys(value, fallback) {
  if (!Array.isArray(value)) return fallback
  const valid = [...new Set(value.filter(key => performanceMetricColumnMap.has(key)))]
  return valid.length ? valid : fallback
}

export default function useCampaignColumns({ userId, accountId, objective }) {
  const defaults = useMemo(() => objectiveMetricKeys(objective), [objective])
  const storageKey = userId && accountId ? `pulse:campaign-columns:${userId}:${accountId}:v1` : null
  const savedKeys = useMemo(() => {
    if (!storageKey) return defaults
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || 'null')
      return sanitizeCampaignColumnKeys(saved, defaults)
    } catch {
      return defaults
    }
  }, [defaults, storageKey])
  const [overrides, setOverrides] = useState({})
  const scopeKey = storageKey || 'unscoped'
  const keys = overrides[scopeKey] || savedKeys

  const update = nextValue => {
    setOverrides(currentOverrides => {
      const current = currentOverrides[scopeKey] || savedKeys
      const candidate = typeof nextValue === 'function' ? nextValue(current) : nextValue
      const next = sanitizeCampaignColumnKeys(candidate, defaults)
      if (storageKey) localStorage.setItem(storageKey, JSON.stringify(next))
      return { ...currentOverrides, [scopeKey]: next }
    })
  }

  const reset = () => {
    if (storageKey) localStorage.removeItem(storageKey)
    setOverrides(current => ({ ...current, [scopeKey]: defaults }))
  }

  return { keys, setKeys: update, reset, storageKey }
}
