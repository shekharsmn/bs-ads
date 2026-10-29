import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import Dashboard from './Dashboard'

vi.mock('../api/client', () => ({ api: { getPerformanceAccounts: vi.fn(), refreshPerformanceAccounts: vi.fn() } }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))

const account = {
  id: 9, account_ids: [9], account_count: 1, platform: 'google', currency: 'USD', client: { id: 7, name: 'Acme' }, result_type: 'sales',
  windows: {
    yesterday: { results: 1, cpr: 20, spend: 20 },
    last_7_days: { results: 3, cpr: 51.33, spend: 154 },
    last_30_days: { results: 0, cpr: null, spend: 0 },
  },
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/command-center']}><Routes><Route path="/command-center" element={<Dashboard/>}/><Route path="/command-center/clients/:clientId/ad-accounts/:id" element={<div>Account detail</div>}/></Routes></MemoryRouter></QueryClientProvider>)
}

describe('account-first command center', () => {
  afterEach(cleanup)
  beforeEach(() => {
    vi.clearAllMocks()
    api.getPerformanceAccounts.mockResolvedValue({ data: { accounts: [account], refresh: { available: true, available_at: null, remaining_seconds: 0 } } })
    api.refreshPerformanceAccounts.mockResolvedValue({ data: { queued_accounts: 1, requested_at: '2026-10-29T05:00:00Z', refresh: cooldownState() } })
  })

  it('renders one winning result row with grouped period metrics', async () => {
    renderPage()
    expect(await screen.findByText('Acme')).toBeInTheDocument()
    expect(screen.getByText('Google Ads')).toBeInTheDocument()
    expect(screen.getByText('Sales')).toBeInTheDocument()
    expect(screen.queryByText('Leads')).not.toBeInTheDocument()
    expect(screen.getAllByText('Results')).toHaveLength(3)
    expect(screen.getAllByText('CPR')).toHaveLength(3)
    expect(screen.getAllByText('Spend')).toHaveLength(3)
    expect(screen.getByText('$51.33')).toBeInTheDocument()
    expect(screen.getByText('$154.00')).toBeInTheDocument()
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('opens the account from the client-platform row', async () => {
    renderPage()
    fireEvent.click((await screen.findByText('Sales')).closest('tr'))
    expect(await screen.findByText('Account detail')).toBeInTheDocument()
  })

  it('queues a forced collection and shows refresh progress', async () => {
    api.getPerformanceAccounts
      .mockResolvedValueOnce({ data: { accounts: [account], refresh: { available: true, available_at: null, remaining_seconds: 0 } } })
      .mockResolvedValue({ data: { accounts: [account], refresh: cooldownState() } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(api.refreshPerformanceAccounts).toHaveBeenCalledTimes(1))
    expect(await screen.findByText('Refresh queued for 1 account; updating automatically.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Refresh in 5:00|Refresh in 4:59/ })).toBeDisabled()
  })

  it('restores the global cooldown from the account response after a reload', async () => {
    api.getPerformanceAccounts.mockResolvedValue({ data: { accounts: [account], refresh: cooldownState() } })
    renderPage()
    expect(await screen.findByRole('button', { name: /Refresh in 5:00|Refresh in 4:59/ })).toBeDisabled()
  })

  it('automatically enables refresh when the cooldown expires', async () => {
    api.getPerformanceAccounts.mockResolvedValue({ data: { accounts: [account], refresh: cooldownState(100) } })
    renderPage()
    expect(await screen.findByRole('button', { name: 'Refresh in 0:01' })).toBeDisabled()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh' })).toBeEnabled(), { timeout: 2_000 })
  })

  it('adopts the server cooldown when another operator already refreshed', async () => {
    api.refreshPerformanceAccounts.mockRejectedValue({ response: { status: 429, data: { refresh: cooldownState() } } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Refresh' }))
    expect(await screen.findByRole('button', { name: /Refresh in 5:00|Refresh in 4:59/ })).toBeDisabled()
    expect(screen.queryByText('Refresh could not be started.')).not.toBeInTheDocument()
  })

  it('retries a failed account request', async () => {
    api.getPerformanceAccounts.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: { accounts: [account] } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /retry/i }))
    await waitFor(() => expect(api.getPerformanceAccounts).toHaveBeenCalledTimes(2))
    expect(await screen.findByText('Acme')).toBeInTheDocument()
  })
})

function cooldownState(milliseconds = 300_000) {
  return { available: false, available_at: new Date(Date.now() + milliseconds).toISOString(), remaining_seconds: Math.ceil(milliseconds / 1_000) }
}
