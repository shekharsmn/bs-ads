import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { AsyncState, AsyncStateNotice } from './AsyncState'

afterEach(cleanup)

describe('AsyncState', () => {
  it('distinguishes loading, empty, and failed states', () => {
    const retry = vi.fn()
    const { rerender } = render(<AsyncState query={{ isPending: true }}>content</AsyncState>)
    expect(screen.getByRole('status')).toHaveTextContent('Loading')
    rerender(<AsyncState query={{ isPending: false, isError: false }} empty emptyText="Nothing here">content</AsyncState>)
    expect(screen.getByText('Nothing here')).toBeInTheDocument()
    rerender(<AsyncState query={{ isPending: false, isError: true, refetch: retry }}>content</AsyncState>)
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(retry).toHaveBeenCalledOnce()
  })

  it('keeps partial data and retries only failed queries', () => {
    const failed = { isError: true, refetch: vi.fn() }
    const successful = { isError: false, isSuccess: true, refetch: vi.fn() }
    render(<AsyncStateNotice queries={[failed, successful]}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed requests' }))
    expect(failed.refetch).toHaveBeenCalledOnce()
    expect(successful.refetch).not.toHaveBeenCalled()
  })
})
