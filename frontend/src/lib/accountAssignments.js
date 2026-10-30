export function buildAccountAssignmentPayload(accounts, selection, clients) {
  return accounts
    .filter(account => selection[account.external_id]?.selected)
    .map(account => {
      const client = clients.find(item => String(item.id) === String(selection[account.external_id]?.client_id || ''))
      if (!client) throw new Error(`Choose a client for ${account.name} before assigning it.`)

      return {
        client_id: client.id,
        external_id: account.external_id,
        manager_external_id: account.manager_external_id || null,
        name: account.name,
        currency: account.currency || client.currency,
        timezone: account.timezone || client.timezone,
      }
    })
}

export function hasCompleteAccountAssignments(selection, clients) {
  const selected = Object.values(selection).filter(item => item.selected)
  return selected.length > 0 && selected.every(item => clients.some(client => String(client.id) === String(item.client_id || '')))
}
