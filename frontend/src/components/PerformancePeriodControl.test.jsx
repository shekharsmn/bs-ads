import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import PerformancePeriodControl from './PerformancePeriodControl'

describe('PerformancePeriodControl', () => {
  afterEach(() => vi.useRealTimers())

  it('offers Yesterday and MTD and resolves them as completed days in the account timezone', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-04T06:00:00Z'))
    const onChange = vi.fn()
    render(<PerformancePeriodControl range="30d" startDate="2026-10-05" endDate="2026-11-03" timezone="Asia/Kolkata" onChange={onChange}/>)

    const period = screen.getByRole('combobox', { name: 'Period' })
    expect(screen.getByRole('option', { name: 'Yesterday' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'MTD' })).toBeInTheDocument()

    fireEvent.change(period, { target: { value: 'yesterday' } })
    expect(onChange).toHaveBeenLastCalledWith({ range: 'yesterday', start_date: '2026-11-03', end_date: '2026-11-03' })

    fireEvent.change(period, { target: { value: 'mtd' } })
    expect(onChange).toHaveBeenLastCalledWith({ range: 'mtd', start_date: '2026-11-01', end_date: '2026-11-03' })
  })
})
