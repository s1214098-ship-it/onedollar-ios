// A clock boundary, closed tab, or failed request is not a terminal result.
// Only an acknowledged, known result may release the single browser worker.
export function handoffDecision({managed, receipt, outboxPending = false}) {
  if (!managed) return {release:true, reason:'unmanaged_terminal'};
  if (outboxPending || receipt?.writebackConfirmed !== true) return {release:false, reason:'waiting_backend_writeback'};
  if (receipt.resultKnown !== true || receipt.safeStopped !== true) return {release:false, reason:'result_unknown_read_only'};
  return {release:true, reason:'terminal_acknowledged'};
}

export function taskReceiptKey(payload = {}) {
  return payload.taskId ? 'auction:' + payload.taskId
    : 'ad:' + [payload.task_date, payload.slot, payload.targetId].map(v=>String(v||'')).join('|');
}
