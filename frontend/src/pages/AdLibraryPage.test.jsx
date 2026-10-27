import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import AdLibraryPage from './AdLibraryPage'

vi.mock('../api/client', () => ({ api: {
  getAdLibrarySearches: vi.fn(), getAdLibraryRuns: vi.fn(), getClients: vi.fn(), getAdLibraryResults: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ features: { ad_library: true, ad_library_audits: false }, permissions: { manage_ad_library: true } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

const run = { id: 9, status: 'complete', result_count: 2, created_at: '2026-10-26T08:00:00Z', search: { name: 'Competitors' } }

describe('Ad Library search and creative filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getAdLibrarySearches.mockResolvedValue({ data: { searches: [], tags: [] } })
    api.getAdLibraryRuns.mockResolvedValue({ data: { data: [run] } })
    api.getClients.mockResolvedValue({ data: [{ id: 4, name: 'Northstar' }] })
    api.getAdLibraryResults.mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1, total: 0, raw_total: 0, duplicate_count: 0 } })
  })

  it('shows the relevant client link when the relationship changes', async () => {
    render(<MemoryRouter><AdLibraryPage /></MemoryRouter>)
    fireEvent.click(await screen.findByRole('button', { name: 'New search' }))

    const clientLink = screen.getByRole('combobox', { name: 'Competitor of client' })
    expect(clientLink).toHaveTextContent('Northstar')
    fireEvent.change(clientLink, { target: { value: '4' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Relationship' }), { target: { value: 'lead' } })

    expect(screen.getByRole('combobox', { name: 'Lead for client' })).toHaveValue('4')
  })

  it('requests minimum-age filtering and oldest-first sorting', async () => {
    render(<MemoryRouter><AdLibraryPage /></MemoryRouter>)
    fireEvent.click(await screen.findByRole('button', { name: /#9 · Competitors/ }))

    fireEvent.change(await screen.findByRole('combobox', { name: 'Filter by creative age' }), { target: { value: '90' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Sort creatives' }), { target: { value: 'oldest' } })

    await waitFor(() => expect(api.getAdLibraryResults).toHaveBeenLastCalledWith(9, expect.objectContaining({ min_age_days: '90', sort: 'oldest' })))
  })
})
