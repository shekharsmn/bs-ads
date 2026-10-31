import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { api } from '../api/client'
import { ToastProvider } from '../context/ToastContext'
import ScheduledReportsPage from './ScheduledReportsPage'

vi.mock('../api/client', () => ({ api: {
  getClients: vi.fn(), getReportTemplates: vi.fn(), getReportSchedules: vi.fn(), getReports: vi.fn(),
  getPerformancePulses: vi.fn(), getPerformancePulseOptions: vi.fn(), createPerformancePulse: vi.fn(),
  updatePerformancePulse: vi.fn(), deletePerformancePulse: vi.fn(), sendPerformancePulse: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'marketer' } }) }))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}><ToastProvider><MemoryRouter initialEntries={['/reports?view=pulse&client_id=7&ad_account_id=9']}><ScheduledReportsPage/></MemoryRouter></ToastProvider></QueryClientProvider>)
}

describe('Performance Pulse reports tab', () => {
  afterEach(cleanup)
  beforeEach(() => {
    api.getClients.mockResolvedValue({ data: [{ id: 7, name: 'Kosala', ad_accounts: [{ id: 9, name: 'Kosala Ads', platform: 'google', timezone: 'Asia/Kolkata' }] }] })
    api.getReportTemplates.mockResolvedValue({ data: [] })
    api.getReportSchedules.mockResolvedValue({ data: { schedules: [], runs: [] } })
    api.getReports.mockResolvedValue({ data: { data: [] } })
    api.getPerformancePulses.mockResolvedValue({ data: [] })
    api.getPerformancePulseOptions.mockResolvedValue({ data: { destinations: [{ id: 4, name: 'Morning Chat', channel: 'google_chat', enabled: true }], campaigns: [{ id: 12, name: 'Lead Search' }], legacy_google_chat_configured: false } })
    api.createPerformancePulse.mockResolvedValue({ data: { id: 20 } })
  })

  it('creates a disabled-by-default account pulse with two periods and the default chat destination', async () => {
    renderPage()
    expect(await screen.findByRole('heading', { name: 'New Performance Pulse' })).toBeInTheDocument()
    await waitFor(() => expect(api.getPerformancePulseOptions).toHaveBeenCalledWith({ client_id: '7', ad_account_id: '9' }))
    expect(await screen.findByText('Morning Chat')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /Enable daily schedule/ })).not.toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'Create Performance Pulse' }))

    await waitFor(() => expect(api.createPerformancePulse).toHaveBeenCalledWith(expect.objectContaining({
      client_id: 7, ad_account_id: 9, campaign_id: null, periods: ['yesterday', 'month_to_date'],
      destination_ids: [4], enabled: false,
    })))
  })

  it('allows campaign scope and removes the account breakdown option', async () => {
    renderPage()
    expect(await screen.findByText('Morning Chat')).toBeInTheDocument()
    const scope = await screen.findByLabelText('Scope')
    fireEvent.change(scope, { target: { value: '12' } })
    expect(screen.queryByText('Include campaign breakdown after account totals')).not.toBeInTheDocument()
  })
})
