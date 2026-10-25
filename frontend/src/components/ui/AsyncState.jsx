export function AsyncState({ query, empty = false, loadingText = 'Loading…', errorText = 'This data could not be loaded.', emptyText = 'No matching data.', onRetry, children }) {
  if (query.isPending) return <div className="async-state loading" role="status" aria-live="polite"><i className="async-state-skeleton"/>{loadingText}</div>
  if (query.isError) return <div className="async-state error" role="alert"><span>{errorText}</span><button type="button" onClick={onRetry || (() => query.refetch())}>Retry</button></div>
  if (empty) return <div className="async-state empty">{emptyText}</div>
  return children
}

export function AsyncStateNotice({ queries, stale = false, staleText = 'The displayed data may be stale.', partialText = 'Some data could not be loaded. Available sections remain visible.' }) {
  const failed = queries.filter(query => query.isError)
  const hasData = queries.some(query => query.isSuccess)
  if (failed.length) return <div className="async-state-notice error" role="alert" aria-live="polite"><span>{hasData ? partialText : 'Data could not be loaded.'}</span><button type="button" onClick={() => failed.forEach(query => query.refetch())}>Retry failed requests</button></div>
  if (stale) return <div className="async-state-notice stale" role="status">{staleText}</div>
  return null
}
