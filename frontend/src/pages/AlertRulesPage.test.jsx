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
    updateAlertRule: vi.fn(),
    createAlertTemplate: vi.fn(),
    updateAlertTemplate: vi.fn(),
    deleteAlertTemplate: vi.fn(),
    deleteAlertRule: vi.fn(),
    deleteWorkspaceAlertRule: vi.fn(),
  },
}))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClients.mockResolvedValue({ data: [{ id: 2, name: 'Acme', ad_accounts: [{ id: 9, name: 'Acme Meta' }] }] })
  api.getAlertRules.mockResolvedValue({ data: [] })
  api.getAlertTemplates.mockResolvedValue({ data: [] })
  api.createAlertRule.mockResolvedValue({ data: { id: 1 } })
  api.updateAlertRule.mockResolvedValue({ data: { id: 1 } })
  api.createAlertTemplate.mockResolvedValue({ data: { id: 1 } })
  api.updateAlertTemplate.mockResolvedValue({ data: { id: 1 } })
  api.deleteAlertTemplate.mockResolvedValue({})
  api.deleteAlertRule.mockResolvedValue({})
  api.deleteWorkspaceAlertRule.mockResolvedValue({})
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

it('edits and deletes an existing template without changing existing policies', async () => {
  const template = { id: 7, key: 'cpl-increase', name: 'CPL increased', description: 'Review lead efficiency.', metric: 'cpl', operator: 'percentage_increase', threshold: '25.000000', severity: 'warning', active: true }
  api.getAlertTemplates.mockResolvedValue({ data: [template] })
  renderPage()

  fireEvent.click(await screen.findByRole('button', { name: 'Edit CPL increased' }))
  expect(screen.getByRole('dialog', { name: 'Edit alert template' })).toBeInTheDocument()
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '30' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

  await waitFor(() => expect(api.updateAlertTemplate).toHaveBeenCalledWith(7, expect.objectContaining({ threshold: 30, name: 'CPL increased' })))
  expect(await screen.findByText('Alert template updated.')).toBeInTheDocument()

  fireEvent.click(await screen.findByRole('button', { name: 'Delete CPL increased' }))
  expect(screen.getByRole('alertdialog', { name: 'Delete alert template?' })).toHaveTextContent('Existing policies created from “CPL increased” will continue running')
  fireEvent.click(screen.getByRole('button', { name: 'Delete template' }))

  await waitFor(() => expect(api.deleteAlertTemplate).toHaveBeenCalledWith(7))
})

it('removes a client-scoped policy from the selected client', async () => {
  api.getAlertRules.mockResolvedValue({ data: [{ id: 11, client_id: 2, ad_account_id: null, name: 'CPL increased', metric: 'cpl', operator: 'percentage_increase', threshold: '25.000000', severity: 'warning', enabled: true, client: { id: 2, name: 'Acme' }, ad_account: null }] })
  renderPage('/alert-rules?view=policies&client_id=2')

  fireEvent.click(await screen.findByRole('button', { name: 'Remove CPL increased' }))
  expect(screen.getByRole('alertdialog', { name: 'Remove scoped policy?' })).toHaveTextContent('Future evaluations will stop')
  fireEvent.click(screen.getByRole('button', { name: 'Remove policy' }))

  await waitFor(() => expect(api.deleteAlertRule).toHaveBeenCalledWith(11))
  expect(await screen.findByText('Scoped policy removed.')).toBeInTheDocument()
})

it('creates, edits, and explicitly deletes an admin workspace policy', async () => {
  const workspacePolicy = { id: 17, client_id: null, ad_account_id: null, campaign_id: null, alert_template_id: null, name: 'Workspace CPL increased', metric: 'cpl', operator: 'percentage_increase', threshold: '25.000000', severity: 'warning', window_days: 7, minimum_spend: null, minimum_impressions: null, enabled: true }
  api.getAlertRules.mockResolvedValue({ data: [workspacePolicy] })
  renderPage('/alert-rules?view=workspace')

  expect(await screen.findByText('Workspace policies are live defaults for every client.')).toBeInTheDocument()
  await waitFor(() => expect(api.getAlertRules).toHaveBeenCalledWith({ scope: 'workspace' }))

  fireEvent.click(screen.getByRole('button', { name: 'Add workspace policy' }))
  fireEvent.change(screen.getByLabelText('Policy name'), { target: { value: 'Workspace spend stopped' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save policy' }))

  await waitFor(() => expect(api.createAlertRule).toHaveBeenCalledWith(expect.objectContaining({
    name: 'Workspace spend stopped',
    client_id: null,
    ad_account_id: null,
    campaign_id: null,
    window_days: 7,
  })))

  fireEvent.click(await screen.findByRole('button', { name: 'Edit Workspace CPL increased' }))
  fireEvent.change(screen.getByLabelText('Window (days)'), { target: { value: '14' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save policy' }))

  await waitFor(() => expect(api.updateAlertRule).toHaveBeenCalledWith(17, expect.objectContaining({
    window_days: 14,
    client_id: null,
    ad_account_id: null,
    campaign_id: null,
  })))

  fireEvent.click(await screen.findByRole('button', { name: 'Delete Workspace CPL increased' }))
  expect(screen.getByRole('alertdialog', { name: 'Delete workspace policy?' })).toHaveTextContent('for the entire workspace')
  fireEvent.click(screen.getByRole('button', { name: 'Delete workspace policy' }))

  await waitFor(() => expect(api.deleteWorkspaceAlertRule).toHaveBeenCalledWith(17))
  expect(api.deleteAlertRule).not.toHaveBeenCalledWith(17)
})
