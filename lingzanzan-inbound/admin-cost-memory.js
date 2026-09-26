/* LZ_COST_MEM_20260926 excerpt — last typed 單件成本 carries to the next inbound line. */
function rememberedInboundCost() {
  var last = Math.round(Number(readInboundFieldMemory(INBOUND_COST_MEMORY_KEY).last || 0));
  return last > 0 ? last : 0;
}
function rememberInboundCost(value) {
  var last = Math.round(Number(value || 0));
  if (!(last > 0)) return;
  writeInboundFieldMemory(INBOUND_COST_MEMORY_KEY, { last: String(last), custom: [] });
}
