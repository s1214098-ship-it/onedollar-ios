"use strict";

function blacklistActionLabel(isListed) {
  return isListed ? "解除黑名單" : "加入黑名單";
}

function blacklistCustomerStatus(risk) {
  if (!risk) return "";
  var reason = String(risk.reason || "").trim();
  return reason ? "此客為黑名單／" + reason : "此客為黑名單";
}

function blacklistActionHostSelector() {
  return [
    ".order-tracking-actions",
    ".order-card-actions",
    ".order-first-actions",
    ".freight-fifo-search-pending-actions",
    ".order-card-risk-tools",
  ].join(", ");
}

function cancelledBucketTitle() {
  return "已取消／已退回，仍可退貨打單";
}

function isActionLabelNotStatus(label) {
  return label === "加入黑名單" || label === "解除黑名單";
}

function isStatusNotAction(label) {
  return String(label || "").indexOf("此客為黑名單") === 0;
}

module.exports = {
  blacklistActionLabel,
  blacklistCustomerStatus,
  blacklistActionHostSelector,
  cancelledBucketTitle,
  isActionLabelNotStatus,
  isStatusNotAction,
};
