import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider, useAuth } from './context/AuthContext'
import Layout from './components/Layout'
import { commandCenterPath } from './lib/performanceRoutes'
import { pageTitle } from './lib/pageTitle'

const LoginPage = lazy(() => import('./pages/LoginPage'))
const PrivacyPolicyPage = lazy(() => import('./pages/PrivacyPolicyPage'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const RunAuditPage = lazy(() => import('./pages/RunAuditPage'))
const ReportPage = lazy(() => import('./pages/ReportPage'))
const PublicReportPage = lazy(() => import('./pages/PublicReportPage'))
const PrintReportPage = lazy(() => import('./pages/PrintReportPage'))
const DataInspectorPage = lazy(() => import('./pages/DataInspectorPage'))
const AiLogsPage = lazy(() => import('./pages/AiLogsPage'))
const AiGovernancePage = lazy(() => import('./pages/AiGovernancePage'))
const GovernancePage = lazy(() => import('./pages/GovernancePage'))
const UsersPage = lazy(() => import('./pages/UsersPage'))
const DataHealthPage = lazy(() => import('./pages/DataHealthPage'))
const GoogleCallbackPage = lazy(() => import('./pages/GoogleCallbackPage'))
const ClientsPage = lazy(() => import('./pages/ClientsPage'))
const ClientHubPage = lazy(() => import('./pages/ClientHubPage'))
const AlertInboxPage = lazy(() => import('./pages/AlertInboxPage'))
const AlertRulesPage = lazy(() => import('./pages/AlertRulesPage'))
const ScheduledReportsPage = lazy(() => import('./pages/ScheduledReportsPage'))
const PublicPerformanceReportPage = lazy(() => import('./pages/PublicPerformanceReportPage'))
const ConnectionsPage = lazy(() => import('./pages/ConnectionsPage'))
const AdLibraryPage = lazy(() => import('./pages/AdLibraryPage'))
const AdLibraryAuditPage = lazy(() => import('./pages/AdLibraryAuditPage'))
const AdLibraryAuditReportPage = lazy(() => import('./pages/AdLibraryAuditReportPage'))
const AiModelSettingsPage = lazy(() => import('./pages/AiModelSettingsPage'))
const PerformanceDrilldownPage = lazy(() => import('./pages/PerformanceDrilldownPage'))
const LegacyPerformanceRedirect = lazy(() => import('./pages/LegacyPerformanceRedirect'))

function RouteFallback() { return <div className="route-loading" role="status"><span/>Loading workspace…</div> }

function DocumentTitle() {
  const { pathname } = useLocation()
  useEffect(() => { document.title = pageTitle(pathname) }, [pathname])
  return null
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
    },
  },
})

function ProtectedRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f8fafc' }}>
      <div style={{
        width: 32, height: 32, border: '3px solid #e2e8f0', borderTopColor: '#3b82f6',
        borderRadius: '50%', animation: 'spin-slow 1s linear infinite',
      }} />
    </div>
  )
  if (!user) return <Navigate to="/login" replace />
  return children
}

function GuestRoute({ children }) {
  const { user, loading } = useAuth()
  if (loading) return null
  if (user) return <Navigate to={commandCenterPath} replace />
  return children
}

function AdminRoute({ children }) {
  const { user } = useAuth()
  return user?.role === 'admin' ? children : <Navigate to={commandCenterPath} replace />
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter><DocumentTitle/><Suspense fallback={<RouteFallback/>}>
        <AuthProvider>
          <Routes>
            <Route path="/privacy-policy" element={<PrivacyPolicyPage />} />
            <Route path="/share/reports/:token" element={<PublicReportPage />} />
            <Route path="/shared/reports/:token" element={<PublicPerformanceReportPage />} />
            <Route path="/login" element={<GuestRoute><LoginPage /></GuestRoute>} />
            <Route
              path="/"
              element={<ProtectedRoute><Layout /></ProtectedRoute>}
            >
              <Route index element={<Navigate to={commandCenterPath} replace />} />
              <Route path="command-center" element={<Dashboard />} />
              <Route path="command-center/clients/:clientId/ad-accounts/:accountId" element={<PerformanceDrilldownPage />} />
              <Route path="command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId" element={<PerformanceDrilldownPage />} />
              <Route path="command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId/ad-groups/:groupId" element={<PerformanceDrilldownPage />} />
              <Route path="command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId/ad-groups/:groupId/ads/:adId" element={<PerformanceDrilldownPage />} />
              <Route path="accounts/:accountId" element={<LegacyPerformanceRedirect />} />
              <Route path="accounts/:accountId/campaigns/:campaignId" element={<LegacyPerformanceRedirect />} />
              <Route path="accounts/:accountId/campaigns/:campaignId/groups/:groupId" element={<LegacyPerformanceRedirect />} />
              <Route path="clients" element={<ClientsPage />} />
              <Route path="clients/:clientId" element={<ClientHubPage />} />
              <Route path="connections" element={<ConnectionsPage />} />
              <Route path="alerts" element={<AlertInboxPage />} />
              <Route path="alerts/:id" element={<AlertInboxPage />} />
              <Route path="alert-rules" element={<AlertRulesPage />} />
              <Route path="reports" element={<ScheduledReportsPage />} />
              <Route path="scheduled-reports" element={<ScheduledReportsPage />} />
              <Route path="accounts" element={<Navigate to="/connections" replace />} />
              <Route path="accounts/new" element={<Navigate to="/connections" replace />} />
              <Route path="accounts/:id/edit" element={<Navigate to="/connections" replace />} />
              <Route path="reports/new" element={<RunAuditPage />} />
              <Route path="reports/:id" element={<ReportPage />} />
              <Route path="reports/:id/print" element={<PrintReportPage />} />
              <Route path="inspector" element={<DataInspectorPage />} />
              <Route path="ai-logs" element={<AdminRoute><AiLogsPage /></AdminRoute>} />
              <Route path="ai-governance" element={<AdminRoute><AiGovernancePage /></AdminRoute>} />
              <Route path="governance" element={<AdminRoute><GovernancePage /></AdminRoute>} />
              <Route path="users" element={<AdminRoute><UsersPage /></AdminRoute>} />
              <Route path="data-health" element={<DataHealthPage />} />
              <Route path="settings/ai-models" element={<AdminRoute><AiModelSettingsPage /></AdminRoute>} />
              <Route path="ad-library" element={<AdLibraryPage />} />
              <Route path="ad-library/audits" element={<AdLibraryAuditPage />} />
              <Route path="ad-library/audits/:id" element={<AdLibraryAuditPage />} />
              <Route path="ad-library/audits/:id/report" element={<AdLibraryAuditReportPage />} />
              <Route path="accounts/google/callback" element={<GoogleCallbackPage />} />
            </Route>
            <Route path="*" element={<Navigate to={commandCenterPath} replace />} />
          </Routes>
        </AuthProvider>
      </Suspense></BrowserRouter>
    </QueryClientProvider>
  )
}
