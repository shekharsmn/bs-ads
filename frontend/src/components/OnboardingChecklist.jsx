import { Check, ChevronRight, Circle } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

export default function OnboardingChecklist({ data }) {
  const navigate = useNavigate()
  const next = data.steps.find(step => !step.complete)
  return <section className="onboarding-strip" aria-label="Workspace setup">
    <div><span>{data.completed}/{data.total}</span><div><strong>Workspace setup</strong><small>{next ? `Next: ${next.label}` : 'Ready to operate'}</small></div></div>
    <ol>{data.steps.map(step => <li className={step.complete ? 'done' : step === next ? 'next' : ''} key={step.key}>{step.complete ? <Check size={11}/> : <Circle size={9}/>}<span>{step.label}</span></li>)}</ol>
    {next && <button onClick={() => navigate(next.to)}>Continue <ChevronRight size={14}/></button>}
  </section>
}
