"use strict";

function blacklistShouldAskCancel(row) {
  if (!row) return false;
  const status = String(row.status || "").toLowerCase();
  const delivery = String(row.deliveryState || "").toLowerCase();
  if (["cancelled", "canceled", "returned", "refunded", "closed"].indexOf(status) !== -1) return false;
  if (["returned", "delivered"].indexOf(delivery) !== -1) return false;
  return true;
}

function blacklistAskCancelMessage(name, recordId) {
  const who = String(name || "這位客人");
  const id = String(recordId || "").trim();
  return (
    "已把「" + who + "」列入黑名單。\n"
    + "這張訂單要不要一起取消、整單回庫？\n"
    + (id ? id + "\n" : "")
    + "確定＝退單（商品回倉）；取消＝只封鎖，訂單留著。"
  );
}

function recordIdFromBlacklistCard(card) {
  if (!card) return "";
  const attr = String(
    (card.getAttribute && (
      card.getAttribute("data-order-id")
      || card.getAttribute("data-order-card")
      || card.getAttribute("data-preorder-card")
      || card.getAttribute("data-preorder-id")
    )) || ""
  ).trim();
  if (attr) return attr;
  const nested = card.querySelector && card.querySelector(
    "[data-freight-fifo-cancel-order], [data-freight-fifo-blacklist], [data-freight-fifo-order], [data-freight-fifo-return-order]"
  );
  if (!nested || !nested.getAttribute) return "";
  return String(
    nested.getAttribute("data-freight-fifo-cancel-order")
    || nested.getAttribute("data-freight-fifo-blacklist")
    || nested.getAttribute("data-freight-fifo-order")
    || nested.getAttribute("data-freight-fifo-return-order")
    || ""
  ).trim();
}

function blacklistAskCancelUsesExistingCancel(js) {
  const src = String(js || "");
  return src.indexOf("function askCancelAfterBlacklist(") !== -1
    && src.indexOf("openFreightFifoCancelOrder(") !== -1
    && src.indexOf("LingzanzanAdminActions") !== -1;
}

function blacklistAskCancelHooksRiskToggle(js) {
  const src = String(js || "");
  return src.indexOf("askCancelAfterBlacklist") !== -1
    && src.indexOf("data-risk-record-id") !== -1
    && src.indexOf("payload.action !== 'save'") !== -1;
}

function blacklistAskCancelDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  blacklistShouldAskCancel,
  blacklistAskCancelMessage,
  recordIdFromBlacklistCard,
  blacklistAskCancelUsesExistingCancel,
  blacklistAskCancelHooksRiskToggle,
  blacklistAskCancelDoesNotRestyleActive,
};
