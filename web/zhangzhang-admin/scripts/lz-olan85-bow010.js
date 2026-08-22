'use strict';

/**
 * Recode the Mickey dining-bowl arrival from OLAN85 / OLAN85P36593 to BOW010.
 * Category is already 餐碗類; OLAN is the wrong prefix. Keep product id p-olan85.
 * Do not merge into BOW007. Do not touch Nita. Do not reuse BOW009P31092.
 */

function recodeToken(value) {
  if (typeof value !== 'string') return value;
  if (value === 'p-olan85') return value;
  if (value.indexOf('OLAN85') === -1) return value;
  return value
    .replace(/OLAN85P36593-TW/g, 'BOW010P36593-TW')
    .replace(/OLAN85P36593/g, 'BOW010P36593')
    .replace(/OLAN85/g, 'BOW010');
}

function recodeTree(node) {
  if (Array.isArray(node)) return node.map(recodeTree);
  if (!node || typeof node !== 'object') return recodeToken(node);
  const out = Array.isArray(node) ? [] : {};
  Object.keys(node).forEach(function (key) {
    out[key] = recodeTree(node[key]);
  });
  return out;
}

function countNeedle(node, needle) {
  const raw = JSON.stringify(node);
  return (raw.match(new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length;
}

module.exports = { recodeToken, recodeTree, countNeedle };
