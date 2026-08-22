"use strict";

function fifoParcelGridJsHasCells(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("freight-fifo-parcel-thead") !== -1
    && js.indexOf("<span>物流單號</span>") !== -1
    && js.indexOf("<span>代收金額</span>") !== -1
    && js.indexOf("<span>貨態</span>") !== -1
    && js.indexOf("freight-fifo-parcel-cell") === -1
    && js.indexOf("freight-fifo-parcel-head") === -1;
}

function fifoParcelGridJsKeepsStatusHook(adminJs) {
  return /data-freight-fifo-parcel-status/.test(String(adminJs || ""))
    && /data-freight-fifo-parcel-tracking/.test(String(adminJs || ""))
    && /data-freight-fifo-parcel-amount/.test(String(adminJs || ""));
}

function fifoParcelGridJsRenumbersInHead(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("wrap.querySelectorAll('[data-freight-fifo-parcel-row]').length") !== -1
    && js.indexOf("row.querySelector('.freight-fifo-parcel-action')") !== -1
    && js.indexOf("title.textContent = String(index + 1)") !== -1;
}

function fifoParcelGridCssHasRules(adminCss) {
  const css = String(adminCss || "");
  return css.indexOf(".freight-fifo-parcel-thead") !== -1
    && css.indexOf("grid-template-columns: 52px minmax(168px, 1.35fr) 108px minmax(160px, 1.45fr) 76px") !== -1
    && css.indexOf("border-right: 1px solid") !== -1
    && css.indexOf("min-width: 640px") !== -1
    && css.indexOf(".freight-fifo-parcel-head") === -1
    && css.indexOf(".freight-fifo-parcel-cell") === -1
    && !/\.freight-fifo-parcel-row\s*\{[\s\S]{0,120}grid-template-columns:\s*1fr/.test(css);
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
