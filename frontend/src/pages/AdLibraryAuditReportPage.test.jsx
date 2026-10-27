import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import { copyText } from '../utils/clipboard'
import AdLibraryAuditReportPage from './AdLibraryAuditReportPage'

vi.mock('../api/client', () => ({ api: {
  getAdLibraryAudit: vi.fn(),
  getAdLibraryAuditCreatives: vi.fn(),
  getAdLibraryAuditReportSheets: vi.fn(),
  getAdLibraryAuditReportXlsxUrl: vi.fn(() => '#report-xlsx'),
} }))
vi.mock('../utils/clipboard', () => ({ copyText: vi.fn() }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ features: { ad_library_audits: true } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

const audit = {
  id: 12,
  name: 'Acme content audit',
  company_name: 'Acme',
  company_type: 'competitor',
  status: 'complete',
  model: 'gemini-test',
  media_depth: 'visual',
  completed_at: '2026-10-26T08:00:00Z',
  website_context: { summary: 'A clear brand summary.', products: ['Core product'] },
  context_sources: [{ title: 'Acme home', url: 'https://acme.test' }],
  creative_summary: { complete: 1 },
  personas: [{ id: 1, name: 'Busy buyer', description: 'Needs clarity', confidence: 0.9, problems: ['Too much choice'], desired_outcomes: ['Decide quickly'] }],
  insights: {
    executive_summary: 'Clear product framing with an audience coverage gap.',
    observations_disclaimer: 'Content observations only.',
    persona_creative_coverage: [{ persona: 'Busy buyer', creative_count: 1 }],
    angle_distribution: [{ label: 'Clarity', count: 1 }],
    format_distribution: [{ label: 'Demo', count: 1 }],
    gaps: ['Only one audience is addressed'],
    content_opportunities: ['Add a proof-led concept'],
    test_recommendations: ['Test a shorter opener'],
  },
  creative_format_options: ['Problem-Agitation-Solution (PAS)', 'Native Ad', 'PR / Advertorial', 'Founder-Led', 'Comparison', 'Before / After', 'Listicle', 'Feature Callout', 'Offer-Led', 'Review / Social Proof', 'Product Hero Shot'],
}
const creative = { id: 4, library_ad_ids: ['ad-4'], source_snapshot: { title: 'Fast demo', media_type: 'video' }, product: 'Core product', angle: 'Clarity', format: 'Feature Callout', type: 'Video', confidence: 0.88, video_script_status: 'captured', video_script: 'Welcome.\nSee how quickly this works.', personas: [{ id: 1, name: 'Busy buyer', pivot: { role: 'primary' } }] }

function renderPage() {
  return render(<MemoryRouter initialEntries={['/ad-library/audits/12/report']}><Routes><Route path="/ad-library/audits/:id/report" element={<AdLibraryAuditReportPage/>}/></Routes></MemoryRouter>)
}

describe('Ad Library final report', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getAdLibraryAudit.mockResolvedValue({ data: audit })
    api.getAdLibraryAuditCreatives.mockResolvedValue({ data: { data: [creative] } })
    api.getAdLibraryAuditReportSheets.mockResolvedValue({ data: { workbook_name: 'Acme - Final report', tabs: [{ name: 'Overview', rows: [['Audit', 'Acme']], tsv: 'Field\tValue\nAudit\tAcme' }, { name: 'Creative Audit', rows: [['Fast demo']], tsv: 'Creative\nFast demo' }] } })
    copyText.mockResolvedValue()
  })
  afterEach(() => cleanup())

  it('presents the complete audit as a decision-focused final report', async () => {
    renderPage()
    expect(await screen.findByRole('heading', { name: 'Acme' })).toBeInTheDocument()
    expect(screen.getByText('Clear product framing with an audience coverage gap.')).toBeInTheDocument()
    expect(screen.getAllByText('Busy buyer')).toHaveLength(3)
    expect(screen.getByRole('link', { name: /Excel workbook/ })).toHaveAttribute('href', '#report-xlsx')
    expect(screen.getByRole('link', { name: /Fast demo/ })).toHaveAttribute('href', 'https://www.facebook.com/ads/library/?id=ad-4')
    expect(screen.getByRole('columnheader', { name: 'Format' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Type' })).toBeInTheDocument()
    expect(screen.getAllByText('Feature Callout')).toHaveLength(2)
    fireEvent.click(screen.getByText('View script'))
    expect(screen.getByText(/See how quickly this works/)).toBeInTheDocument()
  })

  it('prepares named tabs for an OAuth-free Google Sheets handoff', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /Google Sheets/ }))
    expect(await screen.findByRole('dialog', { name: /Build the report across tabs/ })).toBeInTheDocument()
    fireEvent.click(await screen.findByRole('button', { name: /Overview/ }))
    await waitFor(() => expect(copyText).toHaveBeenCalledWith('Field\tValue\nAudit\tAcme'))
    expect(screen.getByText('Creative Audit')).toBeInTheDocument()
  })
})
