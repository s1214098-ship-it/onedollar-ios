"use strict";

function preorderNormalizeCode(value) {
  return String(value || "").trim().toUpperCase();
}

function preorderRowProductCode(row) {
  row = row || {};
  const product = row.product || {};
  return preorderNormalizeCode(product.code || product.productLine || product.productCode || "");
}

function preorderRowsForProductCode(rows, query) {
  const raw = preorderNormalizeCode(query);
  if (!raw) return [];
  return (rows || []).filter(function (row) {
    return preorderRowProductCode(row) === raw;
  });
}

function preorderBarcodeEqualsProductCode(row, query) {
  const raw = preorderNormalizeCode(query);
  const code = preorderRowProductCode(row);
  const sku = (row && row.sku) || {};
  const barcode = preorderNormalizeCode(sku.barcode || sku.companyBarcode || "");
  return !!(raw && code && barcode && barcode === raw && barcode === code);
}

function preorderSearchExpandProductCode(query, rows, exactRows) {
  const productRows = preorderRowsForProductCode(rows, query);
  if (productRows.length <= 1) return [];
  if (!exactRows || !exactRows.length) return productRows;
  const exactIsGenericBarcode = exactRows.every(function (row) {
    return preorderBarcodeEqualsProductCode(row, query);
  });
  if (exactIsGenericBarcode && exactRows.length < productRows.length) return productRows;
  if (exactRows.length < productRows.length && preorderNormalizeCode(query) === preorderRowProductCode(productRows[0])) {
    return productRows;
  }
  return [];
}

function preorderSearchRowsPreferProductCode(query, rows, exactRows) {
  const expanded = preorderSearchExpandProductCode(query, rows, exactRows);
  if (expanded.length) return expanded;
  if (exactRows && exactRows.length) return exactRows.slice(0, 12);
  return [];
}

function preorderSuggestShouldUseGrouped(adminJs) {
  const src = String(adminJs || "");
  return src.indexOf("function renderManualPreorderSuggest() {\n    renderManualPreorderSuggestGrouped();") !== -1
    || src.indexOf("function renderManualPreorderSuggest() {\r\n    renderManualPreorderSuggestGrouped();") !== -1;
}

function preorderSearchExpandsProductCode(adminJs) {
  const src = String(adminJs || "");
  return src.indexOf("var productCodeRows = (rows || []).filter(function (row) {") !== -1
    && src.indexOf("if (productCodeRows.length > 1) return manualPreorderStableSortRows(productCodeRows).slice(0, 40);") !== -1;
}

function preorderEnterDoesNotAutopickFirst(adminJs) {
  const src = String(adminJs || "");
  return src.indexOf("if (directRows.length === 1) {\n          selectManualPreorderSku(manualPreorderSkuKey(directRows[0]));\n        } else {\n          renderManualPreorderSuggest();\n        }") !== -1
    || src.indexOf("if (directRows.length === 1) {\r\n          selectManualPreorderSku(manualPreorderSkuKey(directRows[0]));\r\n        } else {\r\n          renderManualPreorderSuggest();\r\n        }") !== -1;
}

module.exports = {
  preorderNormalizeCode,
  preorderRowProductCode,
  preorderRowsForProductCode,
  preorderBarcodeEqualsProductCode,
  preorderSearchExpandProductCode,
  preorderSearchRowsPreferProductCode,
  preorderSuggestShouldUseGrouped,
  preorderSearchExpandsProductCode,
  preorderEnterDoesNotAutopickFirst,
};
