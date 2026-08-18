export function defaultMinimumWages() {
  return {
    "2024-01": { monthly: 27470, hourly: 183 },
    "2025-01": { monthly: 28590, hourly: 190 },
    "2026-01": { monthly: 29500, hourly: 196 }
  };
}

export function getDefaultData() {
  return {
    bonus: {
      month: 4500,
      full: 1000,
      year: 0,
      pointMoney: 100,
      leaveOffsetDayMoney: 1000,
      minimumWages: defaultMinimumWages(),
      assessmentPayouts: []
    },
    repair: [],
    recycle: [],
    rma: [],
    task: [],
    employees: [
      {
        id: 1,
        name: "曾麒",
        pwd: "1234",
        phone: "",
        birthDate: "",
        department: "行政部",
        addr: "",
        entryDate: "2024-01-01",
        usedLeave: 0,
        monthTotalDeduct: 0,
        hasLeave: false,
        openingUsedLeave: 0,
        openingSickDays: 0,
        openingPersonalDays: 0
      },
      {
        id: 2,
        name: "李御榛",
        pwd: "1234",
        phone: "",
        birthDate: "",
        department: "業務部",
        addr: "",
        entryDate: "2024-06-01",
        usedLeave: 0,
        monthTotalDeduct: 0,
        hasLeave: false,
        openingUsedLeave: 0,
        openingSickDays: 0,
        openingPersonalDays: 0
      },
      {
        id: 3,
        name: "李建宏",
        pwd: "1234",
        phone: "",
        birthDate: "",
        department: "維修部",
        addr: "",
        entryDate: "2023-03-01",
        usedLeave: 0,
        monthTotalDeduct: 0,
        hasLeave: false,
        openingUsedLeave: 0,
        openingSickDays: 0,
        openingPersonalDays: 0
      }
    ],
    punish: [],
    faultList: [
      { id: 1, name: "筆電重灌", price: 1200 },
      { id: 2, name: "無法開機", price: 0 },
      { id: 3, name: "系統異常／中毒", price: 800 },
      { id: 4, name: "資料備份／救援", price: 0 },
      { id: 5, name: "監視器／網路", price: 0 },
      { id: 6, name: "其他", price: 0 }
    ],
    workOrders: [],
    leaves: [],
    tasks: [],
    taskReports: [],
    codexReports: [],
    recycles: [],
    projects: [],
    members: [],
    quotes: [],
    invoices: [],
    customsDutyEntries: [],
    customsDutyBrokers: [],
    customsDutyLogistics: [],
    customsDutyDeclarants: [],
    customsDutyTaxFreeWarehouses: [],
    customsDutyTaxFreeLogistics: [],
    permissions: {
      曾麒: ["dashboard", "leaveManage", "electronicInvoices", "adminMemo", "member", "employeeSalary"],
      李御榛: ["dashboard", "repairOrder", "recycleOrder", "member", "task", "employeeSalary"],
      李建宏: ["dashboard", "repairOrder", "recycleOrder", "rma", "task", "employeeSalary"]
    },
    departments: ["維修部", "業務部", "行政部", "回收部", "倉管部", "管理部"],
    adminPwd: "",
    passwords: [],
    adminMemos: [],
    adminMemoReports: [],
    companyExpenses: [],
    activityLogs: [],
    codexChangeNotices: [],
    salaryConfigs: {},
    salaryFeedback: [],
    hardwareMarket: defaultHardwareMarketRows(),
    cryptoNotes: []
  };
}

export function defaultHardwareMarketRows() {
  const today = new Date().toISOString().slice(0, 10);
  return ["硬碟", "顯示卡", "記憶體", "處理器", "主機板"].flatMap((category) =>
    ["台灣", "中國", "日本", "美國"].map((country) => ({
      id: Number(String(Date.now()) + String(Math.floor(Math.random() * 1000))),
      category,
      country,
      item: category === "硬碟" ? "SSD / HDD 主流規格" : `${category} 主流規格`,
      price: 0,
      prevPrice: 0,
      currency: country === "中國" ? "CNY" : country === "日本" ? "JPY" : country === "美國" ? "USD" : "TWD",
      trend: "持平",
      risk: "中",
      source: "待填資料來源",
      note: "請依供應商、通路或國際報價更新",
      date: today,
      updatedAt: new Date().toISOString()
    }))
  );
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

export function ensureDataShape(input) {
  const data = input && typeof input === "object" ? input : getDefaultData();
  const defaults = getDefaultData();
  if (!data.permissions || typeof data.permissions !== "object" || Array.isArray(data.permissions)) data.permissions = {};
  if (!data.salaryConfigs || typeof data.salaryConfigs !== "object" || Array.isArray(data.salaryConfigs)) data.salaryConfigs = {};
  if (!data.bonus || typeof data.bonus !== "object") data.bonus = defaults.bonus;
  if (!data.bonus.minimumWages || typeof data.bonus.minimumWages !== "object") data.bonus.minimumWages = defaultMinimumWages();
  if (!Array.isArray(data.bonus.assessmentPayouts)) data.bonus.assessmentPayouts = [];

  const listKeys = [
    "punish", "leaves", "tasks", "taskReports", "codexReports", "employees", "workOrders",
    "recycles", "repair", "recycle", "rma", "task", "members", "projects", "quotes", "invoices",
    "customsDutyEntries", "customsDutyBrokers", "customsDutyLogistics", "customsDutyDeclarants",
    "customsDutyTaxFreeWarehouses", "customsDutyTaxFreeLogistics", "salaryFeedback",
    "companyExpenses", "activityLogs", "codexChangeNotices", "adminMemos", "adminMemoReports",
    "passwords", "faultList", "hardwareMarket", "cryptoNotes"
  ];
  for (const key of listKeys) data[key] = asArray(data[key]);

  if (!data.departments || !Array.isArray(data.departments) || data.departments.length === 0) {
    data.departments = defaults.departments.slice();
  }
  if (!data.departments.includes("未分配")) data.departments.push("未分配");
  data.employees.forEach((emp) => {
    if (!emp.department) emp.department = "未分配";
    if (typeof emp.openingUsedLeave === "undefined") emp.openingUsedLeave = Number(emp.usedLeave) || 0;
    if (typeof emp.openingSickDays === "undefined") emp.openingSickDays = 0;
    if (typeof emp.openingPersonalDays === "undefined") emp.openingPersonalDays = 0;
  });
  if (!data.faultList.length) data.faultList = defaults.faultList.slice();
  if (!data.hardwareMarket.length) data.hardwareMarket = defaultHardwareMarketRows();
  return data;
}

export const PAGES = [
  { id: "dashboard", label: "控制台", hint: "總覽待辦、工單與人員狀態。", group: "營運" },
  { id: "externalReport", label: "整體數據統計表", hint: "全公司數據統計與報表。", group: "營運" },
  { id: "faultSetting", label: "維修故障選單設定", hint: "設定維修故障選項，給工單使用。", group: "維修" },
  { id: "repairOrder", label: "維修工單管理", hint: "維修工單派工、進度與完成。", group: "維修" },
  { id: "recycleOrder", label: "回收工單管理", hint: "回收工單派工、進度與完成。", group: "維修" },
  { id: "member", label: "會員管理", hint: "會員資料查詢與管理。", group: "客戶" },
  { id: "hr", label: "員工管理", hint: "員工資料、部門與權限。", group: "人資" },
  { id: "leaveManage", label: "請假管理", hint: "審核請假與假別統計。", group: "人資" },
  { id: "employeeSalary", label: "我的薪資月報", hint: "查看自己的薪資月報。", group: "人資" },
  { id: "task", label: "工作任務", hint: "工作任務指派、回報與扣點。", group: "營運" },
  { id: "adminMemo", label: "備忘錄與每月事項", hint: "行政備忘錄、每月固定事項、登入提醒與完成回報。", group: "行政" },
  { id: "codexReport", label: "CODEX 回報", hint: "把後台問題、異常與修改需求交給開發處理。", group: "行政" },
  { id: "rma", label: "原廠送修管理", hint: "原廠送修追蹤與回件。", group: "維修" },
  { id: "punishSystem", label: "獎懲制度", hint: "獎懲、扣點與年度考核。", group: "人資" },
  { id: "salaryReports", label: "薪資報表", hint: "薪資結算與報表。", group: "人資" },
  { id: "electronicInvoices", label: "電子發票記帳", hint: "電子發票匯入、列印、入帳與往來戶。", group: "財務" },
  { id: "customsDuty", label: "關稅系統 / 關稅單號", hint: "關稅號碼核對。手續費一筆 30 元。", group: "財務" },
  { id: "hardwareMarket", label: "硬體行情", hint: "記憶體與硬體行情參考。", group: "採購" },
  { id: "cryptoMarket", label: "虛擬貨幣行情預測", hint: "虛擬貨幣與總經行情預測。", group: "採購" },
  { id: "quotes", label: "報價管理", hint: "維修、專案與回收報價單。", group: "營運" },
  { id: "passwordManager", label: "密碼管理備忘錄", hint: "內部帳號密碼備忘，請勿外流。", group: "行政" },
  { id: "permission", label: "權限設定", hint: "設定各員工可看哪些選單。", group: "行政" }
];
