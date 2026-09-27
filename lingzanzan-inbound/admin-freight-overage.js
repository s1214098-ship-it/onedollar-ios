/* LZ_RECV_OVERAGE_20260927
   實收多於原預報時，應到跟著實收走，才能正式入庫、儲存不會跳回舊件數。 */
function freightReceivingInspectionLinesFromWorkbench(modal) {
  return Array.from((modal && modal.querySelectorAll('[data-freight-inspection-actual]')) || []).map(function (input) {
    var inspectQty = Math.max(0, Math.floor(Number(input.value) || 0));
    var arrivedQty = freightSafeQty(input.getAttribute('data-arrived'), 0);
    var extraColor = input.getAttribute('data-extra-color') === '1';
    var actualQty = extraColor ? inspectQty : (arrivedQty + inspectQty);
    var expectedQty = freightSafeQty(input.getAttribute('data-expected'), 0);
    return {
      actualQty: actualQty,
      expectedQty: expectedQty,
      adjustedExpectedQty: Math.max(expectedQty, actualQty)
    };
  });
}

function saveFreightReceivingInspectionPayload(row) {
  return {
    actualQty: row.actualQty,
    adjustedExpectedQty: Math.max(Number(row.adjustedExpectedQty || 0), Number(row.actualQty || 0))
  };
}
