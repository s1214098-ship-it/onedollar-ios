"use strict";

function fifoParcelGridJsHasCells(adminJs) {
  return String(adminJs || "").indexOf("freight-fifo-parcel-cell") !== -1
    && String(adminJs || "").indexOf("freight-fifo-parcel-grid") !== -1
    && String(adminJs || "").indexOf("<span>物流單號</span>") !== -1
    && String(adminJs || "").indexOf("<span>代收金額</span>") !== -1;
}

function fifoParcelGridJsKeepsStatusHook(adminJs) {
  return /data-freight-fifo-parcel-status/.test(String(adminJs || ""))
    && /data-freight-fifo-parcel-tracking/.test(String(adminJs || ""))
    && /data-freight-fifo-parcel-amount/.test(String(adminJs || ""));
}

function fifoParcelGridJsRenumbersInHead(adminJs) {
  return String(adminJs || "").indexOf("row.querySelector('.freight-fifo-parcel-head') || row") !== -1;
}

function fifoParcelGridCssHasRules(adminCss) {
  const css = String(adminCss || "");
  return css.indexOf(".freight-fifo-parcel-head") !== -1
    && css.indexOf(".freight-fifo-parcel-grid") !== -1
    && css.indexOf(".freight-fifo-parcel-cell + .freight-fifo-parcel-cell") !== -1
    && css.indexOf("border-left: 1px solid") !== -1
    && css.indexOf(".freight-fifo-parcel-status") !== -1
    && css.indexOf("border-top: 1px dashed") !== -1;
}

function fifoParcelGridDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  fifoParcelGridJsHasCells,
  fifoParcelGridJsKeepsStatusHook,
  fifoParcelGridJsRenumbersInHead,
  fifoParcelGridCssHasRules,
  fifoParcelGridDoesNotRestyleActive,
};
