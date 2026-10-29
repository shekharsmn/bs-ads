import { useQuery } from '@tanstack/react-query'
import { Navigate, useLocation, useParams } from 'react-router-dom'
import { api } from '../api/client'
import { accountPerformancePath, adGroupPerformancePath, campaignPerformancePath } from '../lib/performanceRoutes'

export default function LegacyPerformanceRedirect() {
  const { accountId, campaignId, groupId } = useParams()
  const location = useLocation()
  const accounts = useQuery({ queryKey: ['performance-accounts'], queryFn: () => api.getPerformanceAccounts().then(response => response.data.accounts) })
  const account = accounts.data?.find(item => String(item.id) === String(accountId) || item.account_ids?.some(id => String(id) === String(accountId)))

  if (accounts.isPending) return <div className="route-loading" role="status"><span/>Resolving performance link…</div>
  if (!account?.client?.id) return <Navigate to="/command-center" replace />

  const query = location.search
  if (groupId) return <Navigate to={adGroupPerformancePath(account.client.id, accountId, campaignId, groupId, query)} replace />
  if (campaignId) return <Navigate to={campaignPerformancePath(account.client.id, accountId, campaignId, query)} replace />
  return <Navigate to={accountPerformancePath(account.client.id, accountId, query)} replace />
}
