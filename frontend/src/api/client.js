import axios from 'axios'

const client = axios.create({
  baseURL: '/',
  withCredentials: true,
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
    if (err.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export const api = {
  // Auth
  login: (data) => client.post('/api/login', data),
  register: (data) => client.post('/api/register', data),
  logout: () => client.post('/api/logout'),
  me: () => client.get('/api/me'),

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
