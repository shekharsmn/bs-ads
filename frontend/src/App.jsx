import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider, useAuth } from './context/AuthContext'
import Layout from './components/Layout'
import LoginPage from './pages/LoginPage'
import Dashboard from './pages/Dashboard'
import AccountsPage from './pages/AccountsPage'
import AddAccountPage from './pages/AddAccountPage'
import EditAccountPage from './pages/EditAccountPage'
import RunAuditPage from './pages/RunAuditPage'
import ReportPage from './pages/ReportPage'
import PublicReportPage from './pages/PublicReportPage'
import PrintReportPage from './pages/PrintReportPage'
import DataInspectorPage from './pages/DataInspectorPage'
import AiLogsPage from './pages/AiLogsPage'
import GoogleCallbackPage from './pages/GoogleCallbackPage'
import ClientsPage from './pages/ClientsPage'
import AlertInboxPage from './pages/AlertInboxPage'
import AlertRulesPage from './pages/AlertRulesPage'
import ScheduledReportsPage from './pages/ScheduledReportsPage'
import PublicPerformanceReportPage from './pages/PublicPerformanceReportPage'
import ConnectionsPage from './pages/ConnectionsPage'

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
  if (user) return <Navigate to="/" replace />
  return children
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <Routes>
            <Route path="/share/reports/:token" element={<PublicReportPage />} />
            <Route path="/shared/reports/:token" element={<PublicPerformanceReportPage />} />
            <Route path="/login" element={<GuestRoute><LoginPage /></GuestRoute>} />
            <Route
              path="/"
              element={<ProtectedRoute><Layout /></ProtectedRoute>}
            >
              <Route index element={<Dashboard />} />
              <Route path="clients" element={<ClientsPage />} />
              <Route path="connections" element={<ConnectionsPage />} />
              <Route path="alerts" element={<AlertInboxPage />} />
              <Route path="alerts/:id" element={<AlertInboxPage />} />
              <Route path="alert-rules" element={<AlertRulesPage />} />
              <Route path="scheduled-reports" element={<ScheduledReportsPage />} />
              <Route path="accounts" element={<AccountsPage />} />
              <Route path="accounts/new" element={<AddAccountPage />} />
              <Route path="accounts/:id/edit" element={<EditAccountPage />} />
              <Route path="reports/new" element={<RunAuditPage />} />
              <Route path="reports/:id" element={<ReportPage />} />
              <Route path="reports/:id/print" element={<PrintReportPage />} />
              <Route path="inspector" element={<DataInspectorPage />} />
              <Route path="ai-logs" element={<AiLogsPage />} />
              <Route path="accounts/google/callback" element={<GoogleCallbackPage />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
