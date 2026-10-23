import axios from 'axios'

const client = axios.create({
  baseURL: '/',
  withCredentials: true,
  timeout: 15_000,
  headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
})

// Ensure CSRF cookie is set before mutating requests
async function ensureCsrf() {
  await client.get('/sanctum/csrf-cookie')
}

// Intercept mutating requests to ensure CSRF
client.interceptors.request.use(async (config) => {
  if (['post', 'put', 'patch', 'delete'].includes(config.method)) {
    await ensureCsrf()
  }
  return config
})

// On 401, redirect to login
client.interceptors.response.use(
  res => res,
  err => {
    const isPublicShare = window.location.pathname.startsWith('/share/') || window.location.pathname.startsWith('/shared/')
    if (err.response?.status === 401 && !isPublicShare && !window.location.pathname.startsWith('/login')) {
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export const api = {
  // Auth
  workspaceLoginUrl: '/api/auth/google/redirect',
  logout: () => client.post('/api/logout'),
  me: () => client.get('/api/me'),

  // Performance operations
  getClients: () => client.get('/api/clients'),
  getClient: (id) => client.get(`/api/clients/${id}`),
  createClient: (data) => client.post('/api/clients', data),
  updateClient: (id, data) => client.put(`/api/clients/${id}`, data),
  testClientChat: (id) => client.post(`/api/clients/${id}/google-chat/test`),
  getConnections: () => client.get('/api/connections'),
  createConnection: (data) => client.post('/api/connections', data),
  discoverAccounts: (id) => client.post(`/api/connections/${id}/discover`),
  assignDiscoveredAccount: (id, data) => client.post(`/api/connections/${id}/accounts`, data),
  assignDiscoveredAccounts: (id, accounts) => client.post(`/api/connections/${id}/accounts/bulk`, { accounts }),
  googleConnectionUrl: (data) => `/api/connections/google/redirect?${new URLSearchParams(Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '' && value != null)))}`,
  getMetricSummary: (params) => client.get('/api/metrics/summary', { params }),
  getMetricTrends: (params) => client.get('/api/metrics/trends', { params }),
  getCampaignMetrics: (params) => client.get('/api/metrics/campaigns', { params }),
  getAlerts: (params) => client.get('/api/alerts', { params }),
  getAlert: (id) => client.get(`/api/alerts/${id}`),
  alertAction: (id, action, data = {}) => client.post(`/api/alerts/${id}/${action}`, data),
  getAlertTemplates: () => client.get('/api/alert-templates'),
  getAlertRules: (params) => client.get('/api/alert-rules', { params }),
  createAlertRule: (data) => client.post('/api/alert-rules', data),
  updateAlertRule: (id, data) => client.put(`/api/alert-rules/${id}`, data),
  deleteAlertRule: (id) => client.delete(`/api/alert-rules/${id}`),
  getReportSchedules: () => client.get('/api/report-schedules'),
  saveReportSchedule: (data) => client.post('/api/report-schedules', data),
  generateReport: (clientId, data) => client.post(`/api/clients/${clientId}/report-runs`, data),

  // Accounts
  getAccounts: () => client.get('/api/accounts'),
  createAccount: (data) => client.post('/api/accounts', data),
  updateAccount: (id, data) => client.put(`/api/accounts/${id}`, data),
  deleteAccount: (id) => client.delete(`/api/accounts/${id}`),

  // Google Ads accounts
  getGoogleAccounts: () => client.get('/api/google-accounts'),
  getGoogleOAuthPending: () => client.get('/api/google-accounts/oauth-pending'),
  createGoogleAccount: (data) => client.post('/api/google-accounts', data),
  updateGoogleAccount: (id, data) => client.put(`/api/google-accounts/${id}`, data),
  deleteGoogleAccount: (id) => client.delete(`/api/google-accounts/${id}`),

  // Inspector
  inspect: (data) => client.post('/api/inspect', data),

  // AI logs
  getAiLogs: (params) => client.get('/api/ai-logs', { params }),

  // Admin settings
  getAiModels: () => client.get('/api/settings/ai-models'),
  createAiModel: (data) => client.post('/api/settings/ai-models', data),
  updateAiModel: (id, data) => client.put(`/api/settings/ai-models/${id}`, data),
  deleteAiModel: (id) => client.delete(`/api/settings/ai-models/${id}`),

  // Meta Ad Library research
  getAdLibrarySearches: (params) => client.get('/api/ad-library/searches', { params }),
  createAdLibrarySearch: (data) => client.post('/api/ad-library/searches', data),
  updateAdLibrarySearch: (id, data) => client.put(`/api/ad-library/searches/${id}`, data),
  deleteAdLibrarySearch: (id) => client.delete(`/api/ad-library/searches/${id}`),
  runAdLibrarySearch: (id) => client.post(`/api/ad-library/searches/${id}/runs`),
  getAdLibraryRuns: (params) => client.get('/api/ad-library/runs', { params }),
  getAdLibraryRun: (id) => client.get(`/api/ad-library/runs/${id}`),
  getAdLibraryRunJson: (id) => client.get(`/api/ad-library/runs/${id}/json`),
  getAdLibraryResults: (id, params) => client.get(`/api/ad-library/runs/${id}/ads`, { params }),
  deleteAdLibraryRun: (id) => client.delete(`/api/ad-library/runs/${id}`),
  getAdLibraryAuditModels: (mediaDepth) => client.get('/api/ad-library/audit-models', { params: { media_depth: mediaDepth } }),
  getAdLibraryAudits: (params) => client.get('/api/ad-library/audits', { params }),
  createAdLibraryAudit: (data) => client.post('/api/ad-library/audits', data),
  getAdLibraryAudit: (id) => client.get(`/api/ad-library/audits/${id}`),
  updateAdLibraryAudit: (id, data) => client.put(`/api/ad-library/audits/${id}`, data),
  deleteAdLibraryAudit: (id) => client.delete(`/api/ad-library/audits/${id}`),
  collectAdLibraryAuditContext: (id) => client.post(`/api/ad-library/audits/${id}/context/collect`),
  updateAdLibraryAuditContext: (id, data) => client.put(`/api/ad-library/audits/${id}/context`, data),
  generateAdLibraryAuditPersonas: (id) => client.post(`/api/ad-library/audits/${id}/personas/generate`),
  createAdLibraryAuditPersona: (id, data) => client.post(`/api/ad-library/audits/${id}/personas`, data),
  updateAdLibraryAuditPersona: (auditId, personaId, data) => client.put(`/api/ad-library/audits/${auditId}/personas/${personaId}`, data),
  deleteAdLibraryAuditPersona: (auditId, personaId) => client.delete(`/api/ad-library/audits/${auditId}/personas/${personaId}`),
  reorderAdLibraryAuditPersonas: (id, personaIds) => client.put(`/api/ad-library/audits/${id}/personas-order`, { persona_ids: personaIds }),
  confirmAdLibraryAuditPersonas: (id) => client.post(`/api/ad-library/audits/${id}/personas/confirm`),
  analyzeAdLibraryAudit: (id) => client.post(`/api/ad-library/audits/${id}/analyze`),
  retryFailedAdLibraryAuditCreatives: (id) => client.post(`/api/ad-library/audits/${id}/retry-failed`),
  getAdLibraryAuditCreatives: (id, params) => client.get(`/api/ad-library/audits/${id}/creatives`, { params }),
  updateAdLibraryAuditCreative: (auditId, creativeId, data) => client.put(`/api/ad-library/audits/${auditId}/creatives/${creativeId}`, data),
  confirmAdLibraryAuditGrid: (id) => client.post(`/api/ad-library/audits/${id}/grid/confirm`),
  generateAdLibraryAuditInsights: (id) => client.post(`/api/ad-library/audits/${id}/insights`),
  getAdLibraryAuditCsvUrl: (id) => `/api/ad-library/audits/${id}/export.csv`,
  getAdLibraryAuditJsonUrl: (id) => `/api/ad-library/audits/${id}/export.json`,

  // Reports
  getReports: () => client.get('/api/reports'),
  createReport: (data) => client.post('/api/reports', data),
  getReport: (id) => client.get(`/api/reports/${id}`),
  getReportStatus: (id) => client.get(`/api/reports/${id}/status`),
  cancelReport: (id) => client.post(`/api/reports/${id}/cancel`),
  deleteReport: (id) => client.delete(`/api/reports/${id}`),
  getReportCompare: (id) => client.get(`/api/reports/${id}/compare`),
  getReportExportUrl: (id) => `/api/reports/${id}/export.csv`,
  enableReportShare: (id) => client.post(`/api/reports/${id}/share`),
  disableReportShare: (id) => client.delete(`/api/reports/${id}/share`),
}

/** Public shared report (no auth cookies required). */
export async function fetchPublicReport(token) {
  const res = await fetch(`/api/public/reports/${token}`, {
    headers: { Accept: 'application/json' },
  })
  if (!res.ok) {
    const err = new Error('Failed to load shared report')
    err.status = res.status
    throw err
  }
  return res.json()
}

export default client
