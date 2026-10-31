import axios from 'axios'
import { isPublicRoute } from '../lib/publicRoutes'

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
    if (err.response?.status === 401 && !isPublicRoute(window.location.pathname) && !window.location.pathname.startsWith('/login')) {
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
  getActivities: (params) => client.get('/api/activities', { params }),
  getActivity: (id) => client.get(`/api/activities/${id}`),
  getActivityAttempts: (id, params) => client.get(`/api/activities/${id}/attempts`, { params }),
  activityAction: (id, action) => client.post(`/api/activities/${id}/actions`, { action }),
  getOperationsReadiness: () => client.get('/api/operations/readiness'),

  // Performance operations
  getClients: () => client.get('/api/clients'),
  getClient: (id) => client.get(`/api/clients/${id}`),
  createClient: (data) => client.post('/api/clients', data),
  updateClient: (id, data) => client.put(`/api/clients/${id}`, data),
  deleteClient: (id) => client.delete(`/api/clients/${id}`),
  testClientChat: (id) => client.post(`/api/clients/${id}/google-chat/test`),
  getConnections: (params) => client.get('/api/connections', { params }),
  createConnection: (data) => client.post('/api/connections', data),
  updateConnection: (id, data) => client.put(`/api/connections/${id}`, data),
  deleteConnection: (id) => client.delete(`/api/connections/${id}`),
  // Manager-account discovery can require several Google Ads hierarchy calls.
  discoverAccounts: (id) => client.post(`/api/connections/${id}/discover`, undefined, { timeout: 60_000 }),
  assignDiscoveredAccount: (id, data) => client.post(`/api/connections/${id}/accounts`, data),
  assignDiscoveredAccounts: (id, accounts) => client.post(`/api/connections/${id}/accounts/bulk`, { accounts }),
  deleteAdAccount: (id) => client.delete(`/api/ad-accounts/${id}`),
  googleConnectionUrl: (data) => `/api/connections/google/redirect?${new URLSearchParams(Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '' && value != null)))}`,
  getPerformanceAccounts: () => client.get('/api/performance/accounts'),
  refreshPerformanceAccounts: () => client.post('/api/performance/refresh'),
  getAccountPerformance: (id, params) => client.get(`/api/performance/accounts/${id}/overview`, { params }),
  getAccountCampaigns: (id, params) => client.get(`/api/performance/accounts/${id}/campaigns`, { params }),
  getCampaignAdGroups: (accountId, campaignId, params) => client.get(`/api/performance/accounts/${accountId}/campaigns/${campaignId}/ad-groups`, { params }),
  getAdGroupAds: (accountId, campaignId, groupId, params) => client.get(`/api/performance/accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}/ads`, { params }),
  getAlerts: (params) => client.get('/api/alerts', { params }),
  getAlert: (id) => client.get(`/api/alerts/${id}`),
  alertAction: (id, action, data = {}) => client.post(`/api/alerts/${id}/${action}`, data),
  bulkAlertAction: (ids, action, data = {}) => client.post('/api/alerts/bulk-actions', { ids, action, ...data }),
  getAlertTemplates: () => client.get('/api/alert-templates'),
  createAlertTemplate: (data) => client.post('/api/alert-templates', data),
  updateAlertTemplate: (id, data) => client.put(`/api/alert-templates/${id}`, data),
  deleteAlertTemplate: (id) => client.delete(`/api/alert-templates/${id}`),
  getAlertRules: (params) => client.get('/api/alert-rules', { params }),
  createAlertRule: (data) => client.post('/api/alert-rules', data),
  updateAlertRule: (id, data) => client.put(`/api/alert-rules/${id}`, data),
  deleteAlertRule: (id) => client.delete(`/api/alert-rules/${id}`),
  deleteWorkspaceAlertRule: (id) => client.delete(`/api/workspace-alert-rules/${id}`),
  getReportSchedules: (params) => client.get('/api/report-schedules', { params }),
  getReportTemplates: (params) => client.get('/api/report-templates', { params }),
  createReportTemplate: (data) => client.post('/api/report-templates', data),
  updateReportTemplate: (id, data) => client.put(`/api/report-templates/${id}`, data),
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
  getAiGovernanceSummary: () => client.get('/api/ai-governance/summary'),
  getAiBudgets: () => client.get('/api/ai-governance/budgets'),
  saveAiBudget: (data) => client.put('/api/ai-governance/budgets', data),

  // Governance and data health
  getGovernanceUsers: (params) => client.get('/api/governance/users', { params }),
  updateGovernanceUser: (id, data) => client.put(`/api/governance/users/${id}`, data),
  getGovernanceUserHistory: (id) => client.get(`/api/governance/users/${id}/history`),
  getUserDirectory: (params) => client.get('/api/user-management', { params }),
  inviteUser: (data) => client.post('/api/user-management/invitations', data),
  updateUserInvitation: (id, data) => client.put(`/api/user-management/invitations/${id}`, data),
  resendUserInvitation: (id) => client.post(`/api/user-management/invitations/${id}/resend`),
  revokeUserInvitation: (id) => client.post(`/api/user-management/invitations/${id}/revoke`),
  getAuditEvents: (params) => client.get('/api/audit-events', { params }),
  getAuditEventsExportUrl: () => '/api/audit-events/export.csv',
  getDataQualityIssues: (params) => client.get('/api/data-quality/issues', { params }),
  getMethodologies: () => client.get('/api/methodologies'),
  getNotificationDestinations: () => client.get('/api/notification-destinations'),
  createNotificationDestination: (data) => client.post('/api/notification-destinations', data),
  updateNotificationDestination: (id, data) => client.put(`/api/notification-destinations/${id}`, data),
  deleteNotificationDestination: (id) => client.delete(`/api/notification-destinations/${id}`),
  testNotificationDestination: (id) => client.post(`/api/notification-destinations/${id}/test`),
  getPerformancePulses: (params) => client.get('/api/performance-pulses', { params }),
  getPerformancePulseOptions: (params) => client.get('/api/performance-pulses/options', { params }),
  createPerformancePulse: (data) => client.post('/api/performance-pulses', data),
  updatePerformancePulse: (id, data) => client.put(`/api/performance-pulses/${id}`, data),
  deletePerformancePulse: (id) => client.delete(`/api/performance-pulses/${id}`),
  sendPerformancePulse: (id) => client.post(`/api/performance-pulses/${id}/send`),
  getPerformancePulseRuns: (id, params) => client.get(`/api/performance-pulses/${id}/runs`, { params }),

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
  createAdLibraryTag: (name) => client.post('/api/ad-library/tags', { name }),
  syncAdLibrarySearchTags: (id, tagIds) => client.put(`/api/ad-library/searches/${id}/tags`, { tag_ids: tagIds }),
  favoriteAdLibraryResult: (id, favorite) => client.put(`/api/ad-library/results/${id}/favorite`, { favorite }),
  getAdLibraryAuditModels: (mediaDepth) => client.get('/api/ad-library/audit-models', { params: { media_depth: mediaDepth } }),
  getAdLibraryAudits: (params) => client.get('/api/ad-library/audits', { params }),
  createAdLibraryAudit: (data) => client.post('/api/ad-library/audits', data),
  getAdLibraryAudit: (id) => client.get(`/api/ad-library/audits/${id}`),
  updateAdLibraryAudit: (id, data) => client.put(`/api/ad-library/audits/${id}`, data),
  deleteAdLibraryAudit: (id) => client.delete(`/api/ad-library/audits/${id}`),
  collectAdLibraryAuditContext: (id) => client.post(`/api/ad-library/audits/${id}/context/collect`),
  updateAdLibraryAuditContext: (id, data) => client.put(`/api/ad-library/audits/${id}/context`, data),
  confirmAdLibraryAuditContext: (id) => client.post(`/api/ad-library/audits/${id}/context/confirm`),
  generateAdLibraryAuditPersonas: (id) => client.post(`/api/ad-library/audits/${id}/personas/generate`),
  createAdLibraryAuditPersona: (id, data) => client.post(`/api/ad-library/audits/${id}/personas`, data),
  updateAdLibraryAuditPersona: (auditId, personaId, data) => client.put(`/api/ad-library/audits/${auditId}/personas/${personaId}`, data),
  deleteAdLibraryAuditPersona: (auditId, personaId) => client.delete(`/api/ad-library/audits/${auditId}/personas/${personaId}`),
  reorderAdLibraryAuditPersonas: (id, personaIds) => client.put(`/api/ad-library/audits/${id}/personas-order`, { persona_ids: personaIds }),
  mergeAdLibraryAuditPersona: (auditId, personaId, targetPersonaId) => client.post(`/api/ad-library/audits/${auditId}/personas/${personaId}/merge`, { target_persona_id: targetPersonaId }),
  confirmAdLibraryAuditPersonas: (id) => client.post(`/api/ad-library/audits/${id}/personas/confirm`),
  consolidateAdLibraryAuditPersonas: (id) => client.post(`/api/ad-library/audits/${id}/personas/consolidate`),
  analyzeAdLibraryAudit: (id, creativeIds) => client.post(`/api/ad-library/audits/${id}/analyze`, { creative_ids: creativeIds }),
  pauseAdLibraryAudit: (id) => client.post(`/api/ad-library/audits/${id}/pause`),
  resumeAdLibraryAudit: (id) => client.post(`/api/ad-library/audits/${id}/resume`),
  retryFailedAdLibraryAuditCreatives: (id) => client.post(`/api/ad-library/audits/${id}/retry-failed`),
  skipAdLibraryAuditCreative: (auditId, creativeId) => client.post(`/api/ad-library/audits/${auditId}/creatives/${creativeId}/skip`),
  getAdLibraryAuditCreatives: (id, params) => client.get(`/api/ad-library/audits/${id}/creatives`, { params }),
  updateAdLibraryAuditCreative: (auditId, creativeId, data) => client.put(`/api/ad-library/audits/${auditId}/creatives/${creativeId}`, data),
  markAllAdLibraryAuditCreativesReviewed: (id) => client.post(`/api/ad-library/audits/${id}/creatives/review-all`),
  confirmAdLibraryAuditGrid: (id) => client.post(`/api/ad-library/audits/${id}/grid/confirm`),
  generateAdLibraryAuditInsights: (id) => client.post(`/api/ad-library/audits/${id}/insights`),
  getAdLibraryAuditCsvUrl: (id) => `/api/ad-library/audits/${id}/export.csv`,
  getAdLibraryAuditXlsxUrl: (id) => `/api/ad-library/audits/${id}/export.xlsx`,
  getAdLibraryAuditSheets: (id) => client.get(`/api/ad-library/audits/${id}/export.sheets`, { responseType: 'text' }),
  getAdLibraryAuditReportXlsxUrl: (id) => `/api/ad-library/audits/${id}/report.xlsx`,
  getAdLibraryAuditReportSheets: (id) => client.get(`/api/ad-library/audits/${id}/report.sheets`),
  getAdLibraryAuditJsonUrl: (id) => `/api/ad-library/audits/${id}/export.json`,

  // Reports
  getReports: (params) => client.get('/api/reports', { params }),
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
