import { useEffect, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  BarChart3, BellRing, Building2, Cable, CalendarClock, ChevronRight,
  Activity, ClipboardCheck, Cpu, LibraryBig, LogOut, Menu, PlayCircle, Search, Settings,
  ShieldCheck, SlidersHorizontal, User, UserCog, X,
} from 'lucide-react'
import { useAuth } from '../context/AuthContext'
import { ToastProvider } from '../context/ToastContext'
import ActivityCenter from './ActivityCenter'

const navGroups = [
  { label: 'Operate', items: [
    { to: '/command-center', icon: BarChart3, label: 'Command Center' },
    { to: '/alerts', icon: BellRing, label: 'Alert Inbox' },
    { to: '/alert-rules', icon: SlidersHorizontal, label: 'Alert Policies' },
  ] },
  { label: 'Research', items: [
    { to: '/ad-library', icon: LibraryBig, label: 'Ad Library', feature: 'ad_library' },
    { to: '/ad-library/audits', icon: ClipboardCheck, label: 'Content Audits', feature: 'ad_library', permission: 'run_content_audits' },
    { to: '/reports/new', icon: PlayCircle, label: 'Account Audits', permission: 'run_performance_audits' },
    { to: '/inspector', icon: Search, label: 'Data Inspector', permission: 'use_data_inspector' },
  ] },
  { label: 'Deliver', items: [
    { to: '/reports', icon: CalendarClock, label: 'Reports', exact: true },
  ] },
  { label: 'Configure', items: [
    { to: '/clients', icon: Building2, label: 'Clients' },
    { to: '/connections', icon: Cable, label: 'Connections' },
    { to: '/data-health', icon: Activity, label: 'Data Health' },
    { to: '/ai-logs', icon: Cpu, label: 'AI Usage', adminOnly: true },
    { to: '/ai-governance', icon: Cpu, label: 'AI Governance', adminOnly: true },
    { to: '/users', icon: UserCog, label: 'Users', adminOnly: true },
    { to: '/governance', icon: ShieldCheck, label: 'Governance', adminOnly: true },
    { to: '/settings/ai-models', icon: Settings, label: 'Settings', adminOnly: true },
  ] },
]

export default function Layout() {
  return <ToastProvider><AppShell /></ToastProvider>
}

function AppShell() {
  const { user, permissions, features, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [activitiesOpen, setActivitiesOpen] = useState(false)

  useEffect(() => {
    if (!user?.id) return
    document.cookie = `pulse_selected_client_${user.id}=; Max-Age=0; Path=/; SameSite=Lax`
    document.cookie = `pulse_selected_period_${user.id}=; Max-Age=0; Path=/; SameSite=Lax`
    localStorage.removeItem(`pulse:workspace:${user.id}:v1`)
  }, [user?.id])

  const handleLogout = async () => { await logout(); navigate('/login') }
  const filteredGroups = navGroups.map(group => ({ ...group, items: group.items.filter(item => (!item.feature || features?.[item.feature]) && (!item.permission || permissions?.[item.permission]) && (!item.adminOnly || user?.role === 'admin')) })).filter(group => group.items.length)

  return <div className="app-shell">
    <button className="mobile-menu-button" onClick={() => setMobileOpen(true)} aria-label="Open navigation"><Menu size={19}/></button>
    {mobileOpen && <button className="sidebar-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)}/>}
    <aside className={`app-sidebar ${mobileOpen ? 'open' : ''}`}>
      <div className="brand-lockup"><span><BarChart3 size={17}/></span><div><strong>SharkAds</strong><small>Ad intelligence</small></div><button onClick={() => setMobileOpen(false)} aria-label="Close navigation"><X size={17}/></button></div>
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
        <div className="workspace-context"><span className="workspace-label">Performance operations</span></div>
        <button className="activity-trigger" onClick={() => setActivitiesOpen(true)}><span><BellRing size={15}/></span>Activity</button>
      </header>
      <div className="app-content"><Outlet /></div>
    </main>
    <ActivityCenter open={activitiesOpen} onClose={() => setActivitiesOpen(false)}/>
  </div>
}
