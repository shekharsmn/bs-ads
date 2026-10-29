import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { api } from '../api/client'
import LegacyPerformanceRedirect from './LegacyPerformanceRedirect'

vi.mock('../api/client', () => ({ api: { getPerformanceAccounts: vi.fn() } }))

function Destination() {
  const location = useLocation()
  return <output>{location.pathname}{location.search}</output>
}

describe('legacy performance URLs', () => {
  it('redirects an old group URL to the canonical client hierarchy and preserves the period', async () => {
    api.getPerformanceAccounts.mockResolvedValue({ data: { accounts: [{ id: 9, account_ids: [9], client: { id: 7, name: 'Acme' } }] } })
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/accounts/9/campaigns/3/groups/4?range=7d']}><Routes>
      <Route path="/accounts/:accountId/campaigns/:campaignId/groups/:groupId" element={<LegacyPerformanceRedirect/>}/>
      <Route path="/command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId/ad-groups/:groupId" element={<Destination/>}/>
    </Routes></MemoryRouter></QueryClientProvider>)

    expect(await screen.findByText('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4?range=7d')).toBeInTheDocument()
  })
})
