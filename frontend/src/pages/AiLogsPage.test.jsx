import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import AiLogsPage from './AiLogsPage'

vi.mock('../api/client', () => ({ api: { getAccounts: vi.fn(), getAiLogs: vi.fn() } }))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><MemoryRouter><AiLogsPage /></MemoryRouter></QueryClientProvider>)
}

describe('AI usage ledger', () => {
  beforeEach(() => {
    api.getAccounts.mockResolvedValue({ data: [{ id: 7, label: 'Primary account' }] })
    api.getAiLogs.mockResolvedValue({ data: {
      summary: { request_count: 2, input_tokens: 1200, output_tokens: 300, estimated_cost: 0.0012, estimated_request_count: 1, unpriced_request_count: 0 },
      meta: { total: 1, current_page: 1, last_page: 1, from: 1, to: 1 },
      data: [{
        group_id: 'report:9', log_source: 'report', report_id: 9, scope_name: 'Primary account', group_name: null,
        request_count: 2, failed_count: 0, input_tokens: 1200, output_tokens: 300, estimated_cost: 0.0012,
        estimated_request_count: 1, unpriced_request_count: 0, latest_request_at: '2026-10-24T10:00:00Z',
        requests: [{ id: 1, log_source: 'report', request_name: 'Creative A', call_type: 'static_analysis', provider: 'gemini', model: 'gemini-2.5-flash-lite', status: 'complete', input_tokens: 1200, output_tokens: 300, estimated_cost: 0.0012, cost_basis: 'current_catalog', created_at: '2026-10-24T10:00:00Z' }],
      }],
    } })
  })

  it('shows current-price estimates and filters the grouped ledger', async () => {
    renderPage()
    expect(await screen.findByText('Performance report #9')).toBeInTheDocument()
    expect(screen.getByText(/1 current-price estimates/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Expand request details' }))
    expect(await screen.findByText('current estimate')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Source'), { target: { value: 'content_audit' } })
    await waitFor(() => expect(api.getAiLogs).toHaveBeenLastCalledWith(expect.objectContaining({ source: 'content_audit' })))
  })
})
