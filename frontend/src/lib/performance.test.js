import { describe, expect, it } from 'vitest'
import { completedPeriodRange } from './performance'

describe('completedPeriodRange', () => {
  const now = new Date('2026-11-04T06:00:00Z')

  it('returns the previous local day for Yesterday', () => {
    expect(completedPeriodRange('yesterday', 'Asia/Kolkata', now)).toEqual({ start_date: '2026-11-03', end_date: '2026-11-03' })
  })

  it('returns the completed portion of the reporting month for MTD', () => {
    expect(completedPeriodRange('mtd', 'Asia/Kolkata', now)).toEqual({ start_date: '2026-11-01', end_date: '2026-11-03' })
  })

  it('uses the account-local calendar before subtracting a completed day', () => {
    const nearUtcMidnight = new Date('2026-11-04T00:30:00Z')
    expect(completedPeriodRange('yesterday', 'America/Los_Angeles', nearUtcMidnight)).toEqual({ start_date: '2026-11-02', end_date: '2026-11-02' })
  })
})
