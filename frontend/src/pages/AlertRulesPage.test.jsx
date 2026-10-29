import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { api } from '../api/client'
import AlertRulesPage from './AlertRulesPage'

vi.mock('../api/client', () => ({
  api: {
    getClients: vi.fn(),
    getAlertRules: vi.fn(),
    getAlertTemplates: vi.fn(),
    createAlertRule: vi.fn(),
    createAlertTemplate: vi.fn(),
    deleteAlertRule: vi.fn(),
  },
}))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClients.mockResolvedValue({ data: [{ id: 2, name: 'Acme', ad_accounts: [{ id: 9, name: 'Acme Meta' }] }] })
  api.getAlertRules.mockResolvedValue({ data: [] })
  api.getAlertTemplates.mockResolvedValue({ data: [] })
})

function LocationProbe() {
  const location = useLocation()
  return <output aria-label="Current query">{location.search}</output>
}

it('keeps the client selection while choosing an account for a scoped policy', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={['/alert-rules?view=overrides']}><AlertRulesPage/><LocationProbe/></MemoryRouter></QueryClientProvider>)

  await screen.findByRole('option', { name: 'Acme' })
  const clientSelect = await screen.findByRole('combobox', { name: 'Filter by client' })
  fireEvent.change(clientSelect, { target: { value: '2' } })
  await waitFor(() => expect(clientSelect).toHaveValue('2'))

  const accountSelect = screen.getByRole('combobox', { name: 'Filter by ad account' })
  expect(accountSelect).toHaveTextContent('Acme Meta')
  fireEvent.change(accountSelect, { target: { value: '9' } })

  await waitFor(() => {
    expect(clientSelect).toHaveValue('2')
    expect(accountSelect).toHaveValue('9')
    expect(screen.getByRole('status', { name: 'Current query' })).toHaveTextContent('client_id=2')
    expect(screen.getByRole('status', { name: 'Current query' })).toHaveTextContent('ad_account_id=9')
  })
})
