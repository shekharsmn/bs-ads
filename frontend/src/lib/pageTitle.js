const routeTitles = [
  [/^\/login\/?$/, 'Sign in'],
  [/^\/command-center\/clients\/[^/]+\/ad-accounts\//, 'Account Performance'],
  [/^\/command-center\/?$/, 'Command Center'],
  [/^\/clients\/[^/]+\/?$/, 'Client Details'],
  [/^\/clients\/?$/, 'Clients'],
  [/^\/connections\/?$/, 'Connections'],
  [/^\/alerts\/[^/]+\/?$/, 'Alert Details'],
  [/^\/alerts\/?$/, 'Alert Inbox'],
  [/^\/alert-rules\/?$/, 'Alert Policies'],
  [/^\/reports\/new\/?$/, 'Account Audits'],
  [/^\/reports\/[^/]+\/print\/?$/, 'Print Report'],
  [/^\/reports\/[^/]+\/?$/, 'Account Audit Report'],
  [/^\/(reports|scheduled-reports)\/?$/, 'Reports'],
  [/^\/inspector\/?$/, 'Data Inspector'],
  [/^\/ai-logs\/?$/, 'AI Usage'],
  [/^\/ai-governance\/?$/, 'AI Governance'],
  [/^\/governance\/?$/, 'Governance'],
  [/^\/users\/?$/, 'Users'],
  [/^\/data-health\/?$/, 'Data Health'],
  [/^\/settings\/ai-models\/?$/, 'AI Model Settings'],
  [/^\/ad-library\/audits\/[^/]+\/report\/?$/, 'Content Audit Report'],
  [/^\/ad-library\/audits\//, 'Content Audit'],
  [/^\/ad-library\/audits\/?$/, 'Content Audits'],
  [/^\/ad-library\/?$/, 'Ad Library'],
  [/^\/accounts\/google\/callback\/?$/, 'Connecting Google Ads'],
  [/^\/share\/reports\//, 'Shared Audit Report'],
  [/^\/shared\/reports\//, 'Shared Performance Report'],
]

export function pageTitle(pathname) {
  const title = routeTitles.find(([pattern]) => pattern.test(pathname))?.[1]
  return title ? `${title} · SharkAds` : 'SharkAds'
}
