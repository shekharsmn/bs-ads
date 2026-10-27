import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import { copyText } from '../utils/clipboard'
import AdLibraryAuditPage from './AdLibraryAuditPage'

vi.mock('../api/client', () => ({ api: {
  getAdLibraryAudit: vi.fn(), getAdLibraryAuditCreatives: vi.fn(), resumeAdLibraryAudit: vi.fn(), analyzeAdLibraryAudit: vi.fn(),
  getAdLibraryRuns: vi.fn(), getAdLibraryAudits: vi.fn(), getAdLibraryAuditModels: vi.fn(), getAdLibraryResults: vi.fn(), createAdLibraryAudit: vi.fn(), deleteAdLibraryAudit: vi.fn(),
  markAllAdLibraryAuditCreativesReviewed: vi.fn(), getAdLibraryAuditSheets: vi.fn(),
  getAdLibraryAuditCsvUrl: vi.fn(() => '#csv'), getAdLibraryAuditXlsxUrl: vi.fn(() => '#xlsx'), getAdLibraryAuditJsonUrl: vi.fn(() => '#json'),
} }))
vi.mock('../utils/clipboard', () => ({ copyText: vi.fn() }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ features: { ad_library_audits: true }, permissions: { manage_ad_library: true } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

const baseAudit = {
  id: 12, name: 'Acme audit', company_name: 'Acme', company_type: 'competitor', model: 'gemini-2.5-flash-lite', media_depth: 'visual',
  website_context: {}, context_sources: [], personas: [], insights: null, progress_total: 1, progress_completed: 0, progress_failed: 0,
}
const creative = { id: 44, processing_status: 'pending', source_snapshot: { title: 'Creative A', media_type: 'static' }, library_ad_ids: ['ad-1'] }

function renderPage(entry = '/ad-library/audits/12') {
  return render(<MemoryRouter initialEntries={[entry]}><Routes><Route path="/ad-library/audits/:id" element={<AdLibraryAuditPage />}/></Routes></MemoryRouter>)
}

function renderHome() {
  return render(<MemoryRouter initialEntries={['/ad-library/audits']}><Routes><Route path="/ad-library/audits" element={<AdLibraryAuditPage />}/></Routes></MemoryRouter>)
}

describe('Ad Library audit workflow', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getAdLibraryAuditCreatives.mockResolvedValue({ data: { data: [creative] } })
    api.resumeAdLibraryAudit.mockResolvedValue({ data: {} })
    api.analyzeAdLibraryAudit.mockResolvedValue({ data: {} })
    api.markAllAdLibraryAuditCreativesReviewed.mockResolvedValue({ data: {} })
    api.getAdLibraryAuditSheets.mockResolvedValue({ data: 'Creative\tProduct\nCreative A\tProduct A' })
    copyText.mockResolvedValue()
    api.getAdLibraryAudits.mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1, total: 0 } })
    api.getAdLibraryAuditModels.mockResolvedValue({ data: { models: [{ key: 'gemini:test', label: 'Gemini Test', input_price: 0.1, output_price: 0.4 }], default: 'gemini:test' } })
    api.getAdLibraryResults.mockResolvedValue({ data: { data: [{ id: 55, title: 'Creative source', body: 'Copy', media_type: 'static', variant_count: 1 }] } })
  })
  afterEach(() => cleanup())

  it('explains soft pause and resumes pending work', async () => {
    api.getAdLibraryAudit.mockResolvedValueOnce({ data: { ...baseAudit, status: 'creative_paused' } }).mockResolvedValue({ data: { ...baseAudit, status: 'analyzing_creatives' } })
    renderPage()
    expect(await screen.findByText(/already running may still finish safely/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Resume audit/ }))
    await waitFor(() => expect(api.resumeAdLibraryAudit).toHaveBeenCalledWith(12))
  })

  it('submits only the selected creatives', async () => {
    api.getAdLibraryAudit.mockResolvedValue({ data: { ...baseAudit, status: 'creative_selection' } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /Start audit \(1\)/ }))
    await waitFor(() => expect(api.analyzeAdLibraryAudit).toHaveBeenCalledWith(12, [44]))
  })

  it('opens a completed stage from the stage query parameter', async () => {
    api.getAdLibraryAudit.mockResolvedValue({ data: { ...baseAudit, status: 'complete', website_context: { summary: 'Approved public summary' }, insights: { executive_summary: 'Ready' } } })
    renderPage('/ad-library/audits/12?stage=context')
    expect(await screen.findByText('Approve brand context')).toBeInTheDocument()
    expect(screen.getByText('Approved public summary')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /^Final report$/ })).toHaveAttribute('href', '/ad-library/audits/12/report')
    expect(within(screen.getByRole('navigation', { name: 'Audit stages' })).getByRole('button', { name: /Context/ })).toHaveAttribute('aria-current', 'step')
  })

  it('renders context when source evidence is null', async () => {
    api.getAdLibraryAudit.mockResolvedValue({ data: { ...baseAudit, status: 'context_review', context_sources: null } })
    renderPage('/ad-library/audits/12?stage=context')
    expect(await screen.findByText('No source links captured')).toBeInTheDocument()
  })

  it('protects unsaved context when moving to another available stage', async () => {
    api.getAdLibraryAudit.mockResolvedValue({ data: { ...baseAudit, status: 'persona_review', website_context: { summary: 'Original' }, personas: [{ id: 7, name: 'Buyer', description: 'A buyer' }] } })
    renderPage('/ad-library/audits/12?stage=context')
    fireEvent.change(await screen.findByLabelText('Brand summary'), { target: { value: 'Unsaved edit' } })
    fireEvent.click(within(screen.getByRole('navigation', { name: 'Audit stages' })).getByRole('button', { name: /Personas/ }))
    expect(screen.getByRole('alertdialog', { name: /Discard unsaved context changes/ })).toBeInTheDocument()
  })

  it('starts a new audit from a focused two-step dialog', async () => {
    api.getAdLibraryRuns.mockResolvedValue({ data: { data: [{ id: 4, status: 'complete', result_count: 1, search: { name: 'Acme Ad Library audit', company_name: 'Acme', company_type: 'competitor', website_url: 'https://acme.test' } }] } })
    renderHome()
    fireEvent.click(await screen.findByRole('button', { name: /New content audit/ }))
    expect(screen.getByRole('dialog', { name: /Choose the source and analysis depth/ })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /Acme Ad Library audit/ })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Unknown company/ })).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Completed source run'), { target: { value: '4' } })
    expect(await screen.findByDisplayValue('Acme content audit')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Review 1 creatives/ })).toBeEnabled()
  })

  it('marks all completed creatives reviewed and exposes spreadsheet exports', async () => {
    const completedCreative = { ...creative, position: 0, processing_status: 'complete', review_status: 'pending', confidence: 0.9, personas: [] }
    api.getAdLibraryAudit.mockResolvedValue({ data: { ...baseAudit, status: 'creative_review', creative_summary: { complete: 1, pending_review: 1, reviewed: 0 } } })
    api.getAdLibraryAuditCreatives.mockResolvedValue({ data: { data: [completedCreative] } })
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    renderPage('/ad-library/audits/12?stage=creatives')

    fireEvent.click(await screen.findByRole('button', { name: /Mark all reviewed \(1\)/ }))
    await waitFor(() => expect(api.markAllAdLibraryAuditCreativesReviewed).toHaveBeenCalledWith(12))
    expect(within(screen.getByRole('table')).getByRole('columnheader', { name: '#' })).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByRole('cell', { name: '1' })).toBeInTheDocument()

    fireEvent.click(screen.getByText('Export'))
    expect(screen.getByRole('link', { name: /Comma-separated file/ })).toHaveAttribute('href', '#csv')
    expect(screen.getByRole('link', { name: /xlsx workbook/ })).toHaveAttribute('href', '#xlsx')
    fireEvent.click(screen.getByRole('button', { name: /Google Sheets/ }))
    await waitFor(() => expect(copyText).toHaveBeenCalledWith('Creative\tProduct\nCreative A\tProduct A'))
    expect(open).toHaveBeenCalledWith('https://sheets.new', '_blank', 'noopener,noreferrer')
    open.mockRestore()
  })

  it('shows the evidence-first analysis stage with creative candidates and the captured script', async () => {
    const evidenceCreative = {
      ...creative,
      position: 0,
      processing_status: 'complete',
      source_snapshot: { title: 'Spoken demo', media_type: 'video' },
      persona_candidates: [
        { name: 'Reassurance seeker', description: 'Needs confidence', evidence: ['Spoken promise'], confidence: 0.9 },
        { name: 'Time-conscious buyer', description: 'Needs speed', evidence: ['Quick result'], confidence: 0.8 },
      ],
      video_script_status: 'captured',
      video_script: 'Take your reading now.',
      personas: [{ id: 9, name: 'Reassurance seeker', pivot: { role: 'primary', rationale: 'Matches the spoken promise.', confidence: 0.92 } }],
    }
    api.getAdLibraryAudit.mockResolvedValue({ data: {
      ...baseAudit,
      workflow_version: 2,
      status: 'persona_review',
      personas: [{ id: 9, name: 'Reassurance seeker', description: 'Needs confidence' }],
      creative_summary: { selected: 1, complete: 1, failed: 0, skipped: 0 },
    } })
    api.getAdLibraryAuditCreatives.mockResolvedValue({ data: { data: [evidenceCreative] } })

    renderPage('/ad-library/audits/12?stage=analysis')

    expect(await screen.findByText('Analyze selected creatives')).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Creative analysis progress' })).toHaveAttribute('aria-valuenow', '1')
    expect(screen.getByText('100%')).toBeInTheDocument()
    expect(await screen.findByText('2 persona candidates · Script captured')).toBeInTheDocument()
    expect(within(screen.getByRole('navigation', { name: 'Audit stages' })).getByRole('button', { name: /Creative analysis/ })).toHaveAttribute('aria-current', 'step')
    fireEvent.click(screen.getByRole('button', { name: 'Preview creative evidence' }))
    expect(await screen.findByText('Video script')).toBeInTheDocument()
    expect(screen.getByText('Take your reading now.')).toBeInTheDocument()
    expect(screen.getByText('Matches the spoken promise.')).toBeInTheDocument()
  })
})
