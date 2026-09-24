/** Meta Ads Manager deep links (act id without `act_` prefix in query). */
export function metaActParam(accountId) {
  if (accountId == null || accountId === '') return ''
  return String(accountId).replace(/^act_/i, '')
}

export function metaCampaignsUrl(accountId) {
  const act = metaActParam(accountId)
  if (!act) return 'https://adsmanager.facebook.com/adsmanager/manage/campaigns'
  return `https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${encodeURIComponent(act)}`
}

export function metaEventsManagerUrl() {
  return 'https://business.facebook.com/events_manager2'
}
