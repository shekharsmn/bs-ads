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
  updatePerformancePulse: vi.fn(), deletePerformancePulse: vi.fn(), sendPerformancePulse: vi.fn(), createNotificationDestination: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'marketer' } }) }))

function renderPage(entry = '/reports?view=pulse&client_id=7&ad_account_id=9') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}><ToastProvider><MemoryRouter initialEntries={[entry]}><ScheduledReportsPage/></MemoryRouter></ToastProvider></QueryClientProvider>)
}

describe('Performance Pulse reports tab', () => {
  afterEach(cleanup)
  beforeEach(() => {
    vi.clearAllMocks()
    api.getClients.mockResolvedValue({ data: [{ id: 7, name: 'Kosala', ad_accounts: [{ id: 9, name: 'Kosala Ads', platform: 'google', timezone: 'Asia/Kolkata' }] }] })
    api.getReportTemplates.mockResolvedValue({ data: [] })
    api.getReportSchedules.mockResolvedValue({ data: { schedules: [], runs: [] } })
    api.getReports.mockResolvedValue({ data: { data: [] } })
    api.getPerformancePulses.mockResolvedValue({ data: [] })
    api.getPerformancePulseOptions.mockResolvedValue({ data: { destinations: [{ id: 4, name: 'Morning Chat', channel: 'google_chat', enabled: true }], campaigns: [{ id: 12, name: 'Lead Search' }], legacy_google_chat_configured: false } })
    api.createPerformancePulse.mockResolvedValue({ data: { id: 20 } })
    api.createNotificationDestination.mockResolvedValue({ data: { id: 8, name: 'Pulse Slack', channel: 'slack', enabled: true } })
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

  it('shows all configured pulses when no client or account is selected', async () => {
    api.getPerformancePulses.mockResolvedValue({ data: [{ id: 21, enabled: true, local_time: '09:00:00', periods: ['yesterday', 'month_to_date'], client: { id: 7, name: 'Kosala' }, ad_account: { id: 9, name: 'Kosala Ads', platform: 'google', timezone: 'Asia/Kolkata' }, campaign: null, destinations: [{ id: 4, name: 'Morning Chat', channel: 'google_chat' }], runs: [] }] })
    renderPage('/reports?view=pulse')

    expect(await screen.findByRole('heading', { name: 'All configured pulses' })).toBeInTheDocument()
    expect(await screen.findByText('Kosala Ads')).toBeInTheDocument()
    expect(screen.getByText(/Kosala · google/)).toBeInTheDocument()
    expect(api.getPerformancePulses).toHaveBeenCalledWith({ client_id: undefined, ad_account_id: undefined })
    expect(api.getPerformancePulseOptions).not.toHaveBeenCalled()
  })

  it('allows the default destination to be unchecked on a new paused pulse', async () => {
    renderPage()
    const destination = await screen.findByRole('checkbox', { name: /Morning Chat/ })
    expect(destination).toBeChecked()
    fireEvent.click(destination)
    expect(destination).not.toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'Create Performance Pulse' }))

    await waitFor(() => expect(api.createPerformancePulse).toHaveBeenCalledWith(expect.objectContaining({ destination_ids: [], enabled: false })))
  })

  it('creates and selects a destination without leaving Performance Pulse', async () => {
    api.getPerformancePulseOptions.mockResolvedValue({ data: { destinations: [], campaigns: [], legacy_google_chat_configured: false } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Add destination' }))
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Pulse Slack' } })
    fireEvent.change(screen.getByLabelText('Channel'), { target: { value: 'slack' } })
    fireEvent.change(screen.getByLabelText('HTTPS webhook URL'), { target: { value: 'https://hooks.slack.com/services/test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create and select' }))

    await waitFor(() => expect(api.createNotificationDestination).toHaveBeenCalledWith(expect.objectContaining({
      client_id: 7, name: 'Pulse Slack', channel: 'slack', events: [], enabled: true,
      configuration: { url: 'https://hooks.slack.com/services/test' },
    })))
    expect(await screen.findByRole('checkbox', { name: /Pulse Slack/ })).toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'Create Performance Pulse' }))
    await waitFor(() => expect(api.createPerformancePulse).toHaveBeenCalledWith(expect.objectContaining({ destination_ids: [8] })))
  })

  it('lets the backend create the legacy client chat destination', async () => {
    api.getPerformancePulseOptions.mockResolvedValue({ data: { destinations: [], campaigns: [], legacy_google_chat_configured: true } })
    renderPage()
    expect(await screen.findByRole('checkbox', { name: /Client default Google Chat/ })).toBeChecked()
    const createButton = screen.getByRole('button', { name: 'Create Performance Pulse' })
    await waitFor(() => expect(createButton).not.toBeDisabled())
    fireEvent.click(createButton)

    await waitFor(() => expect(api.createPerformancePulse).toHaveBeenCalled())
    expect(api.createPerformancePulse.mock.calls.at(-1)[0]).not.toHaveProperty('destination_ids')
  })

  it('allows the legacy client chat destination to be explicitly unchecked', async () => {
    api.getPerformancePulseOptions.mockResolvedValue({ data: { destinations: [], campaigns: [], legacy_google_chat_configured: true } })
    renderPage()
    fireEvent.click(await screen.findByRole('checkbox', { name: /Client default Google Chat/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Create Performance Pulse' }))

    await waitFor(() => expect(api.createPerformancePulse).toHaveBeenCalledWith(expect.objectContaining({ destination_ids: [], enabled: false })))
  })
})
