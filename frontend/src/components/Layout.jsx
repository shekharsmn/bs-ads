import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import {
  LayoutDashboard, PlayCircle, LogOut,
  BarChart3, ChevronRight, User, Search, Cpu, Building2, BellRing, SlidersHorizontal, CalendarClock, Cable, LibraryBig, Settings,
} from 'lucide-react'

const navItems = [
  { to: '/', icon: LayoutDashboard, label: 'Command Center', exact: true },
  { to: '/alerts', icon: BellRing, label: 'Alert Inbox' },
  { to: '/alert-rules', icon: SlidersHorizontal, label: 'Alert Policies' },
  { to: '/clients', icon: Building2, label: 'Clients' },
  { to: '/connections', icon: Cable, label: 'Ad Platforms' },
  { to: '/scheduled-reports', icon: CalendarClock, label: 'Reports' },
  { to: '/reports/new', icon: PlayCircle, label: 'Run Audit' },
  { to: '/inspector', icon: Search, label: 'Data Inspector' },
  { to: '/ai-logs', icon: Cpu, label: 'AI Request Log' },
  { to: '/ad-library', icon: LibraryBig, label: 'Ad Library', feature: 'ad_library' },
  { to: '/settings/ai-models', icon: Settings, label: 'Settings', adminOnly: true },
]

export default function Layout() {
  const { user, features, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div style={{ display: 'flex', height: '100vh', overflow: 'hidden' }}>
      {/* Sidebar */}
      <aside style={{
        width: '220px',
        minWidth: '220px',
        background: '#0a0f1e',
        display: 'flex',
        flexDirection: 'column',
        borderRight: '1px solid rgba(255,255,255,0.06)',
        position: 'relative',
        overflow: 'hidden',
      }}>
        {/* Background texture */}
        <div style={{
          position: 'absolute', inset: 0, opacity: 0.03,
          backgroundImage: `repeating-linear-gradient(45deg, #fff 0, #fff 1px, transparent 0, transparent 50%)`,
          backgroundSize: '8px 8px',
          pointerEvents: 'none',
        }} />

        {/* Logo */}
        <div style={{
          padding: '24px 20px 20px',
          borderBottom: '1px solid rgba(255,255,255,0.06)',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{
              width: 32, height: 32,
              background: 'linear-gradient(135deg, #3b82f6, #1d4ed8)',
              borderRadius: 8,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              boxShadow: '0 0 16px rgba(59,130,246,0.4)',
            }}>
              <BarChart3 size={16} color="white" strokeWidth={2.5} />
            </div>
            <span style={{
              fontFamily: 'Syne, sans-serif',
              fontSize: '18px',
              fontWeight: 700,
              color: 'white',
              letterSpacing: '-0.02em',
            }}>
              Pulse
            </span>
          </div>
        </div>

        {/* Nav */}
        <nav style={{ flex: 1, padding: '16px 12px', display: 'flex', flexDirection: 'column', gap: '4px' }}>
          <p style={{
            fontSize: '10px', fontWeight: 600, color: 'rgba(255,255,255,0.3)',
            letterSpacing: '0.1em', textTransform: 'uppercase',
            padding: '0 8px', marginBottom: '8px', marginTop: 0,
          }}>Operations</p>

          {navItems.filter(item => (!item.feature || features?.[item.feature]) && (!item.adminOnly || user?.role === 'admin')).map((item) => {
            const NavIcon = item.icon
            return <NavLink
              key={item.to}
              to={item.to}
              end={item.exact}
              style={({ isActive }) => ({
                display: 'flex',
                alignItems: 'center',
                gap: '10px',
                padding: '9px 12px',
                borderRadius: '8px',
                textDecoration: 'none',
                fontSize: '13.5px',
                fontWeight: 500,
                transition: 'all 0.15s ease',
                borderLeft: isActive ? '2px solid #3b82f6' : '2px solid transparent',
                background: isActive
                  ? 'linear-gradient(135deg, rgba(59,130,246,0.15), rgba(59,130,246,0.05))'
                  : 'transparent',
                color: isActive ? '#93c5fd' : 'rgba(255,255,255,0.5)',
                paddingLeft: isActive ? '10px' : '12px',
              })}
            >
              <NavIcon size={15} strokeWidth={2} />
              <span>{item.label}</span>
              <ChevronRight size={12} style={{ marginLeft: 'auto', opacity: 0.35 }} />
            </NavLink>
          })}
        </nav>

        {/* User section */}
        <div style={{
          padding: '16px 12px',
          borderTop: '1px solid rgba(255,255,255,0.06)',
        }}>
          <div style={{
            display: 'flex', alignItems: 'center', gap: '10px',
            padding: '8px 10px', borderRadius: 8,
          }}>
            <div style={{
              width: 28, height: 28, borderRadius: '50%',
              background: 'linear-gradient(135deg, #1e3a5f, #1d4ed8)',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              flexShrink: 0,
            }}>
              <User size={13} color="rgba(255,255,255,0.8)" />
            </div>
            <div style={{ minWidth: 0 }}>
              <p style={{ margin: 0, fontSize: '12px', fontWeight: 600, color: 'rgba(255,255,255,0.8)', truncate: true }}>
                {user?.name?.split(' ')[0]}
              </p>
              <p style={{ margin: 0, fontSize: '10px', color: 'rgba(255,255,255,0.3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {user?.email}
              </p>
            </div>
          </div>
          <button
            onClick={handleLogout}
            style={{
              display: 'flex', alignItems: 'center', gap: '8px',
              width: '100%', padding: '8px 10px', marginTop: '4px',
              background: 'transparent', border: 'none', cursor: 'pointer',
              borderRadius: 8, color: 'rgba(255,255,255,0.3)', fontSize: '12px',
              fontFamily: 'inherit', transition: 'all 0.15s',
            }}
            onMouseEnter={e => { e.currentTarget.style.background = 'rgba(244,63,94,0.1)'; e.currentTarget.style.color = '#fb7185' }}
            onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'rgba(255,255,255,0.3)' }}
          >
            <LogOut size={13} />
            Sign out
          </button>
        </div>
      </aside>

      {/* Main content */}
      <main style={{ flex: 1, overflow: 'auto', background: '#f8fafc' }}>
        <Outlet />
      </main>
    </div>
  )
}
