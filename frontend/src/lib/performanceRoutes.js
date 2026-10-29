export const commandCenterPath = '/command-center'

function withQuery(path, query = '') {
  return query ? `${path}?${String(query).replace(/^\?/, '')}` : path
}

export function accountPerformancePath(clientId, accountId, query = '') {
  return withQuery(`${commandCenterPath}/clients/${clientId}/ad-accounts/${accountId}`, query)
}

export function campaignPerformancePath(clientId, accountId, campaignId, query = '') {
  return withQuery(`${commandCenterPath}/clients/${clientId}/ad-accounts/${accountId}/campaigns/${campaignId}`, query)
}

export function adGroupPerformancePath(clientId, accountId, campaignId, groupId, query = '') {
  return withQuery(`${commandCenterPath}/clients/${clientId}/ad-accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}`, query)
}

export function adPerformancePath(clientId, accountId, campaignId, groupId, adId, query = '') {
  return withQuery(`${commandCenterPath}/clients/${clientId}/ad-accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}/ads/${adId}`, query)
}
