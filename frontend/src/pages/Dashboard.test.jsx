import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import Dashboard from './Dashboard'

vi.mock('../api/client', () => ({ api: {
  getClients: vi.fn(), getMetricSummary: vi.fn(), getMetricTrends: vi.fn(), getCampaignMetrics: vi.fn(),
  getAlerts: vi.fn(), getConnections: vi.fn(), getReportSchedules: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))
vi.mock('../context/WorkspaceContext', () => ({ useWorkspace: () => ({
  clients: [{ id: 7, name: 'Acme', currency: 'USD', ad_accounts_count: 1 }], clientId: '7',
  startDate: '2026-10-01', endDate: '2026-10-20',
}) }))
vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }))
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }) => <div>{children}</div>, AreaChart: ({ children }) => <div>{children}</div>,
  Area: () => null, XAxis: () => null, YAxis: () => null, Tooltip: () => null,
}))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><Dashboard/></QueryClientProvider>)
}

describe('Dashboard states', () => {
  afterEach(cleanup)

  beforeEach(() => {
    api.getClients.mockResolvedValue({ data: [{ id: 7, name: 'Acme' }] })
    api.getMetricSummary.mockResolvedValue({ data: { freshness_timestamp: null, currencies: [{ currency: 'USD', metrics: { spend: null, leads: 0, purchases: 0, roas: null, ctr: null, cpl: null } }] } })
    api.getMetricTrends.mockResolvedValue({ data: { series: [] } })
    api.getCampaignMetrics.mockResolvedValue({ data: { campaigns: [] } })
    api.getAlerts.mockResolvedValue({ data: { data: [], total: 0 } })
    api.getConnections.mockResolvedValue({ data: [] })
    api.getReportSchedules.mockResolvedValue({ data: { runs: [] } })
  })

  it('shows the selected date range and distinguishes unavailable values from zero', async () => {
    renderPage()
    expect((await screen.findAllByText(/2026-10-01 — 2026-10-20/)).length).toBeGreaterThan(0)
    await waitFor(() => expect(screen.getAllByText('—').length).toBeGreaterThan(0))
    expect(screen.getAllByText('0').length).toBeGreaterThan(0)
    expect(screen.getByText('No activity in this period.')).toBeInTheDocument()
  })

  it('keeps available sections visible and retries failed dashboard requests', async () => {
    api.getMetricSummary.mockRejectedValue(new Error('offline'))
    renderPage()
    expect(await screen.findByRole('alert')).toHaveTextContent('Some dashboard data could not be loaded')
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed requests' }))
    await waitFor(() => expect(api.getMetricSummary).toHaveBeenCalledTimes(2))
    expect(screen.getAllByText('No open incidents. Monitoring continues.').length).toBeGreaterThan(0)
  })
})
