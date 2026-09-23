import assert from "node:assert/strict";
import { test } from "node:test";
import { calculateLeaveHours, statutoryAnnualLeaveDays, monthsBetween } from "../src/salary.js";

test("leave hours deduct one lunch hour on a full workday", () => {
  assert.equal(calculateLeaveHours("2026-08-20", "08:30", "2026-08-20", "17:30", true), 8);
  assert.equal(calculateLeaveHours("2026-08-20", "08:30", "2026-08-20", "17:30", false), 9);
});

test("statutory annual leave follows labor baseline bands", () => {
  assert.equal(statutoryAnnualLeaveDays("2026-02-18", new Date("2026-08-18")), 3);
  assert.equal(statutoryAnnualLeaveDays("2025-08-18", new Date("2026-08-18")), 7);
  assert.equal(statutoryAnnualLeaveDays("2024-08-18", new Date("2026-08-18")), 10);
  assert.equal(statutoryAnnualLeaveDays("2023-08-18", new Date("2026-08-18")), 14);
  assert.equal(statutoryAnnualLeaveDays("2016-08-18", new Date("2026-08-18")), 15);
  assert.ok(monthsBetween("2020-01-01", new Date("2026-08-18")) >= 79);
});
