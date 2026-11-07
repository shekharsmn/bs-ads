import { useEffect, useState } from 'react'
import { BarChart3, CheckCircle2, Mail, ShieldCheck } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'

export default function ClientLoginPage() {
  const [params] = useSearchParams()
  const token = params.get('token')
  const [email, setEmail] = useState(params.get('email') || '')
  const [state, setState] = useState(token ? 'verifying' : 'idle')
  const [message, setMessage] = useState('')

  useEffect(() => {
    if (!token) return
    let cancelled = false
    api.consumeClientMagicLink(token).then(response => {
      if (!cancelled) window.location.replace(response.data.redirect || '/portal')
    }).catch(error => {
      if (!cancelled) { setState('error'); setMessage(error.response?.data?.errors?.token?.[0] || error.response?.data?.message || 'This sign-in link is invalid or has expired.') }
    })
    return () => { cancelled = true }
  }, [token])

  const submit = async event => {
    event.preventDefault(); setState('sending'); setMessage('')
    try {
      const response = await api.requestClientMagicLink(email)
      setMessage(response.data.message); setState('sent')
    } catch (error) {
      setMessage(error.response?.data?.errors?.email?.[0] || 'Unable to request a sign-in link.'); setState('error')
    }
  }

  return <main className="client-login-page"><section className="client-login-card">
    <header><span><BarChart3 size={22}/></span><div><strong>SharkAds</strong><small>Client performance portal</small></div></header>
    {state === 'verifying' ? <div className="client-login-state"><i/><h1>Verifying your link</h1><p>This should only take a moment.</p></div> : <>
      <div className="client-login-copy"><ShieldCheck size={28}/><h1>Secure access to your reports</h1><p>Enter the email address your account manager invited. We’ll send a single-use link that expires in 15 minutes.</p></div>
      {state === 'sent' ? <div className="client-login-success"><CheckCircle2 size={22}/><div><strong>Check your inbox</strong><p>{message}</p></div></div> : <form onSubmit={submit}><label>Email address<input autoFocus required type="email" value={email} onChange={event => setEmail(event.target.value)} placeholder="you@company.com"/></label>{state === 'error' && <p className="client-login-error">{message}</p>}<button disabled={state === 'sending'}><Mail size={16}/>{state === 'sending' ? 'Sending…' : 'Email me a sign-in link'}</button></form>}
    </>}
    <footer><Link to="/login">Workspace team sign in</Link><Link to="/privacy-policy">Privacy Policy</Link></footer>
  </section></main>
}
