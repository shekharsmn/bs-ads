import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { api } from '../api/client'
import { ToastProvider } from '../context/ToastContext'
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
  api.createAlertTemplate.mockResolvedValue({ data: { id: 1 } })
})

function LocationProbe() {
  const location = useLocation()
  return <output aria-label="Current query">{location.search}</output>
}

function renderPage(initialEntry = '/alert-rules') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><ToastProvider><MemoryRouter initialEntries={[initialEntry]}><AlertRulesPage/><LocationProbe/></MemoryRouter></ToastProvider></QueryClientProvider>)
}

it('keeps the client selection while choosing an account for a scoped policy', async () => {
  renderPage('/alert-rules?view=overrides')

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

it('creates a template from a guided modal with a plain-language preview', async () => {
  renderPage()

  fireEvent.click(await screen.findByRole('button', { name: 'Add template' }))
  expect(screen.getByRole('dialog', { name: 'Create alert template' })).toBeInTheDocument()
  expect(screen.getByText('How templates work')).toBeInTheDocument()
  expect(screen.getByText('Percentage comparisons use equal, consecutive periods.')).toBeInTheDocument()

  fireEvent.change(screen.getByPlaceholderText('e.g. Cost per lead increased'), { target: { value: 'Cost per lead increased' } })
  expect(screen.getByPlaceholderText('cost-per-lead-increased')).toHaveValue('cost-per-lead-increased')
  expect(screen.getByRole('spinbutton')).toHaveValue(25)
  expect(screen.getByText('An alert opens when observed CPL is 25% higher than the preceding equal-length period.')).toBeInTheDocument()

  fireEvent.click(screen.getByRole('button', { name: 'Create template' }))

  await waitFor(() => expect(api.createAlertTemplate).toHaveBeenCalledWith({
    key: 'cost-per-lead-increased',
    name: 'Cost per lead increased',
    description: '',
    metric: 'cpl',
    operator: 'percentage_increase',
    threshold: 25,
    severity: 'warning',
    active: true,
  }))
  expect(await screen.findByText('Alert template created.')).toBeInTheDocument()
  expect(screen.queryByRole('dialog', { name: 'Create alert template' })).not.toBeInTheDocument()
})
