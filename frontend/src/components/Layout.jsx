import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart3, BellRing, Building2, Cable, CalendarClock, ChevronDown, ChevronRight,
  Activity, ClipboardCheck, Cpu, LibraryBig, LogOut, Menu, PlayCircle, Search, Settings,
  ShieldCheck, SlidersHorizontal, User, X,
} from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { ToastProvider } from '../context/ToastContext'
import { WorkspaceProvider, useWorkspace } from '../context/WorkspaceContext'
import ActivityCenter from './ActivityCenter'
import OnboardingChecklist from './OnboardingChecklist'

const navGroups = [
  { label: 'Operate', items: [
    { to: '/', icon: BarChart3, label: 'Command Center', exact: true },
    { to: '/alerts', icon: BellRing, label: 'Alert Inbox' },
    { to: '/alert-rules', icon: SlidersHorizontal, label: 'Alert Policies' },
  ] },
  { label: 'Research', items: [
    { to: '/ad-library', icon: LibraryBig, label: 'Ad Library', feature: 'ad_library' },
    { to: '/ad-library/audits', icon: ClipboardCheck, label: 'Content Audits', feature: 'ad_library' },
    { to: '/reports/new', icon: PlayCircle, label: 'Account Audits' },
    { to: '/inspector', icon: Search, label: 'Data Inspector' },
  ] },
  { label: 'Deliver', items: [
    { to: '/scheduled-reports', icon: CalendarClock, label: 'Reports' },
  ] },
  { label: 'Configure', items: [
    { to: '/clients', icon: Building2, label: 'Clients' },
    { to: '/connections', icon: Cable, label: 'Connections' },
    { to: '/data-health', icon: Activity, label: 'Data Health' },
    { to: '/ai-logs', icon: Cpu, label: 'AI Usage', adminOnly: true },
    { to: '/ai-governance', icon: Cpu, label: 'AI Governance', adminOnly: true },
    { to: '/governance', icon: ShieldCheck, label: 'Governance', adminOnly: true },
    { to: '/settings/ai-models', icon: Settings, label: 'Settings', adminOnly: true },
  ] },
]

export default function Layout() {
  return <ToastProvider><WorkspaceProvider><AppShell /></WorkspaceProvider></ToastProvider>
}

function AppShell() {
  const { user, features, logout } = useAuth()
  const { clients, clientId, range, startDate, endDate, isResearch, updateContext } = useWorkspace()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [activitiesOpen, setActivitiesOpen] = useState(false)
  const onboarding = useQuery({ queryKey: ['onboarding'], queryFn: () => api.getOnboarding().then(r => r.data), enabled: user?.role === 'admin', staleTime: 60_000 })

  const handleLogout = async () => { await logout(); navigate('/login') }
  const filteredGroups = navGroups.map(group => ({ ...group, items: group.items.filter(item => (!item.feature || features?.[item.feature]) && (!item.adminOnly || user?.role === 'admin')) })).filter(group => group.items.length)

  return <div className="app-shell">
    <button className="mobile-menu-button" onClick={() => setMobileOpen(true)} aria-label="Open navigation"><Menu size={19}/></button>
    {mobileOpen && <button className="sidebar-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)}/>}
    <aside className={`app-sidebar ${mobileOpen ? 'open' : ''}`}>
      <div className="brand-lockup"><span><BarChart3 size={17}/></span><div><strong>Pulse</strong><small>Ad intelligence</small></div><button onClick={() => setMobileOpen(false)} aria-label="Close navigation"><X size={17}/></button></div>
      <nav className="grouped-nav" aria-label="Primary navigation">
        {filteredGroups.map(group => <section key={group.label}><p>{group.label}</p>{group.items.map(item => {
          const Icon = item.icon
          return <NavLink key={item.to} to={item.to} end={item.exact} onClick={() => setMobileOpen(false)}><Icon size={15}/><span>{item.label}</span><ChevronRight size={12}/></NavLink>
        })}</section>)}
      </nav>
      <div className="sidebar-user"><span><User size={14}/></span><div><strong>{user?.name}</strong><small>{user?.role}</small></div><button onClick={handleLogout} aria-label="Sign out"><LogOut size={14}/></button></div>
    </aside>

    <main className="app-main">
      <header className="workspace-bar">
        <div className="workspace-context">
          {isResearch ? <div className="context-scope"><LibraryBig size={15}/><span><small>Scope</small><strong>Company research</strong></span></div> : <label><span>Client</span><select value={clientId} onChange={event => updateContext({ client_id: event.target.value })}>{clients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select><ChevronDown size={13}/></label>}
          {!isResearch && <label><span>Period</span><select value={range} onChange={event => {
            const value = event.target.value
            const end = new Date(); const start = new Date(); start.setDate(end.getDate() - (value === '7d' ? 6 : value === '90d' ? 89 : 29))
            updateContext({ range: value, start_date: start.toISOString().slice(0, 10), end_date: end.toISOString().slice(0, 10) })
          }}><option value="7d">Last 7 days</option><option value="30d">Last 30 days</option><option value="90d">Last 90 days</option><option value="custom">Custom</option></select><ChevronDown size={13}/></label>}
          {!isResearch && range === 'custom' && <div className="context-dates"><input type="date" value={startDate} onChange={event => updateContext({ start_date: event.target.value })}/><span>to</span><input type="date" value={endDate} onChange={event => updateContext({ end_date: event.target.value })}/></div>}
        </div>
        <button className="activity-trigger" onClick={() => setActivitiesOpen(true)}><span><BellRing size={15}/>{user?.role === 'admin' && onboarding.data && onboarding.data.completed < onboarding.data.total ? <i/> : null}</span>Activity</button>
      </header>
      {user?.role === 'admin' && onboarding.data?.completed < onboarding.data?.total && <OnboardingChecklist data={onboarding.data}/>}
      <div className="app-content"><Outlet /></div>
    </main>
    <ActivityCenter open={activitiesOpen} onClose={() => setActivitiesOpen(false)}/>
  </div>
}
