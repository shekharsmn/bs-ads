import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { CheckCircle2, CircleAlert, X } from 'lucide-react'

const ToastContext = createContext(null)

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const remove = useCallback(id => setToasts(items => items.filter(item => item.id !== id)), [])
  const push = useCallback((message, tone = 'success') => {
    const id = `${Date.now()}-${Math.random()}`
    setToasts(items => [...items, { id, message, tone }])
    window.setTimeout(() => remove(id), 4500)
  }, [remove])
  const value = useMemo(() => ({ success: message => push(message), error: message => push(message, 'error') }), [push])

  return <ToastContext.Provider value={value}>
    {children}
    <div className="toast-stack" aria-live="polite">
      {toasts.map(toast => <div className={`toast ${toast.tone}`} key={toast.id}>
        {toast.tone === 'error' ? <CircleAlert size={17}/> : <CheckCircle2 size={17}/>}
        <span>{toast.message}</span><button onClick={() => remove(toast.id)} aria-label="Dismiss"><X size={14}/></button>
      </div>)}
    </div>
  </ToastContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export const useToast = () => useContext(ToastContext)
