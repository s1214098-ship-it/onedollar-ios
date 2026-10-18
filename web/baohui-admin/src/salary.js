function parseDate(text) {
  const value = String(text || "").trim();
  if (!value) return null;
  const dt = new Date(value.includes("T") ? value : value.replace(" ", "T"));
  return Number.isNaN(dt.getTime()) ? null : dt;
}

export function monthsBetween(start, end = new Date()) {
  const a = parseDate(start);
  if (!a) return 0;
  let months = (end.getFullYear() - a.getFullYear()) * 12 + (end.getMonth() - a.getMonth());
  if (end.getDate() < a.getDate()) months -= 1;
  return Math.max(0, months);
}

export function statutoryAnnualLeaveDays(entryDate, asOf = new Date()) {
  const months = monthsBetween(entryDate, asOf);
  if (months < 6) return 0;
  if (months < 12) return 3;
  const years = Math.floor(months / 12);
  if (years < 2) return 7;
  if (years < 3) return 10;
  if (years < 5) return 14;
  if (years < 10) return 15;
  return Math.min(30, 15 + (years - 10));
}

export function calculateLeaveHours(startDate, startTime, endDate, endTime, lunchBreak = true) {
  const start = parseDate(`${startDate}T${startTime || "08:30"}`);
  const end = parseDate(`${endDate || startDate}T${endTime || "17:30"}`);
  if (!start || !end || end <= start) return 0;
  let total = (end - start) / 3600000;
  if (!lunchBreak) return Math.round(total * 100) / 100;
  const cursor = new Date(start);
  cursor.setHours(0, 0, 0, 0);
  const endDay = new Date(end);
  endDay.setHours(0, 0, 0, 0);
  while (cursor <= endDay) {
    const lunchStart = new Date(cursor);
    lunchStart.setHours(12, 0, 0, 0);
    const lunchEnd = new Date(cursor);
    lunchEnd.setHours(13, 0, 0, 0);
    const overlapStart = Math.max(start.getTime(), lunchStart.getTime());
    const overlapEnd = Math.min(end.getTime(), lunchEnd.getTime());
    if (overlapEnd > overlapStart) total -= (overlapEnd - overlapStart) / 3600000;
    cursor.setDate(cursor.getDate() + 1);
  }
  return Math.max(0, Math.round(total * 100) / 100);
}

export function getSalaryConfig(data, empName) {
  if (!data.salaryConfigs[empName]) {
    data.salaryConfigs[empName] = { basePay: 0, workBonus: data.bonus?.month || 0, items: [] };
  }
  if (typeof data.salaryConfigs[empName].workBonus === "undefined") {
    data.salaryConfigs[empName].workBonus = data.bonus?.month || 0;
  }
  if (!Array.isArray(data.salaryConfigs[empName].items)) data.salaryConfigs[empName].items = [];
  return data.salaryConfigs[empName];
}

function monthKeyFromDateText(dateText) {
  const text = String(dateText || "").trim();
  const match = text.match(/^(\d{4})-(\d{2})/);
  if (match) return `${match[1]}-${match[2]}`;
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

export function minimumWageForDate(data, dateText) {
  const wages = data.bonus?.minimumWages || {};
  const target = monthKeyFromDateText(dateText);
  const keys = Object.keys(wages).filter((k) => /^\d{4}-\d{2}$/.test(k)).sort();
  let selectedKey = keys[0] || "2026-01";
  keys.forEach((key) => {
    if (key <= target) selectedKey = key;
  });
  const selected = wages[selectedKey] || { monthly: 29500, hourly: 196 };
  return Number(selected.monthly) || 29500;
}

export function settleSalary(data, empName, month) {
  const emp = (data.employees || []).find((e) => e.name === empName);
  if (!emp) return null;
  const cfg = getSalaryConfig(data, empName);
  const basePay = Math.max(Number(cfg.basePay) || 0, minimumWageForDate(data, `${month}-01`));
  const workBonus = Math.max(0, Number(cfg.workBonus) || 0);
  const fullBonus = Number(data.bonus?.full) || 0;
  const pointMoney = Number(data.bonus?.pointMoney) || 100;

  const monthLeaves = (data.leaves || []).filter((leave) => {
    if (leave.employee !== empName) return false;
    if (leave.status && leave.status !== "核准" && leave.status !== "已核准") return false;
    return String(leave.startDate || leave.date || "").startsWith(month);
  });
  const leaveHours = monthLeaves.reduce((sum, leave) => sum + (Number(leave.hours) || 0), 0);
  const leaveDays = leaveHours / 8;
  const personalOrSick = monthLeaves.filter((l) => ["事假", "病假"].includes(l.leaveType || l.type));
  const fullAttendance = personalOrSick.length === 0;
  const earnedFull = fullAttendance ? fullBonus : 0;
  const leaveOffset = Math.round(leaveDays * (Number(data.bonus?.leaveOffsetDayMoney) || 1000));

  const points = (data.punish || [])
    .filter((p) => p.employee === empName && String(p.date || p.createdAt || "").startsWith(month))
    .reduce((sum, p) => sum + (Number(p.point) || 0), 0);
  const pointAmount = points * pointMoney;

  const extras = (cfg.items || []).reduce(
    (acc, item) => {
      const amount = Number(item.amount) || 0;
      if (item.type === "subtract") acc.subtract += amount;
      else acc.add += amount;
      return acc;
    },
    { add: 0, subtract: 0 }
  );

  const gross = basePay + workBonus + earnedFull + extras.add + Math.max(0, pointAmount);
  const deduct = extras.subtract + leaveOffset + Math.max(0, -pointAmount);
  const net = gross - deduct;
  const entitled = statutoryAnnualLeaveDays(emp.entryDate);
  const usedLeave = Number(emp.openingUsedLeave || 0) + (data.leaves || [])
    .filter((l) => l.employee === empName && ["核准", "已核准"].includes(l.status || "") && (l.leaveType || l.type) === "特休")
    .reduce((sum, l) => sum + (Number(l.hours) || 0) / 8, 0);

  return {
    employee: empName,
    department: emp.department || "",
    month,
    entryDate: emp.entryDate || "",
    entitledLeaveDays: entitled,
    usedLeaveDays: Math.round(usedLeave * 100) / 100,
    remainLeaveDays: Math.round((entitled - usedLeave) * 100) / 100,
    basePay,
    workBonus,
    fullBonus: earnedFull,
    extrasAdd: extras.add,
    extrasSubtract: extras.subtract,
    leaveHours: Math.round(leaveHours * 100) / 100,
    leaveOffset,
    points,
    pointAmount,
    gross,
    deduct,
    net,
    leaves: monthLeaves
  };
}

export function companyStats(data) {
  const pendingRepair = (data.workOrders || []).filter((o) => o.status !== "已完修" && o.status !== "完成").length;
  const pendingRecycle = (data.recycles || []).filter((o) => o.status !== "已完成" && o.status !== "完成").length;
  const pendingLeave = (data.leaves || []).filter((o) => !o.status || o.status === "待審核").length;
  const pendingTask = (data.tasks || []).filter((o) => o.status !== "已完成" && o.status !== "完成").length;
  const pendingRma = (data.rma || []).filter((o) => o.status !== "完成").length;
  const pendingCodex = (data.codexReports || []).filter((o) => o.status !== "已完成").length;
  return {
    members: (data.members || []).length,
    employees: (data.employees || []).length,
    pendingRepair,
    pendingRecycle,
    pendingLeave,
    pendingTask,
    pendingRma,
    pendingCodex,
    workOrders: (data.workOrders || []).length,
    recycles: (data.recycles || []).length,
    invoices: (data.invoices || []).length
  };
}
