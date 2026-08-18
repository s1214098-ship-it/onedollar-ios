(function () {
  const API_URL = "api/shared-db.php";
  const PUBLIC_READ = !/admin\.html\.html/i.test(location.pathname);
  const SYNC_KEYS = [
    "properties",
    "borrowItems",
    "sameStoreItems",
    "peerDevelopmentItems",
    "archivedObjects",
    "importLogs",
    "customers",
    "targets",
    "ycutFollowIds",
    "employees",
    "passwordRecords",
    "layouts",
    "types",
    "expireStart",
    "expireLimit"
  ];
  const ADMIN_FETCH_KEYS = [
    "properties",
    "borrowItems",
    "sameStoreItems",
    "archivedObjects",
    "importLogs",
    "customers",
    "targets",
    "ycutFollowIds",
    "employees",
    "passwordRecords",
    "layouts",
    "types",
    "expireStart",
    "expireLimit",
    "agentInfo"
  ].join(",");
  const PUBLIC_FETCH_KEYS = "properties,layouts,types,agentInfo,sameStoreItems,borrowItems";

  let isHydrating = false;
  let saveTimer = null;
  let pendingItems = {};

  const nativeSetItem = Storage.prototype.setItem;
  const nativeGetItem = Storage.prototype.getItem;
  const nativeRemoveItem = Storage.prototype.removeItem;
  const nativeClear = Storage.prototype.clear;

  function setLocalOnly(key, value) {
    nativeSetItem.call(localStorage, key, value);
  }

  function safeSetLocalOnly(key, value) {
    const oldValue = nativeGetItem.call(localStorage, key);
    try {
      setLocalOnly(key, value);
      return true;
    } catch (error) {
      window.HUOMANGE_SHARED_SKIPPED_KEYS = window.HUOMANGE_SHARED_SKIPPED_KEYS || [];
      if (!window.HUOMANGE_SHARED_SKIPPED_KEYS.includes(key)) {
        window.HUOMANGE_SHARED_SKIPPED_KEYS.push(key);
      }
      if (oldValue != null) {
        try {
          setLocalOnly(key, oldValue);
        } catch (restoreError) {}
      }
      console.warn("PHT-SR shared data is too large for browser localStorage; kept previous local copy:", key, error);
      return false;
    }
  }

  function normalizeSharedValue(value) {
    if (typeof value === "string") return value;
    if (value === null || value === undefined) return "";
    return JSON.stringify(value);
  }

  function post(payload) {
    return fetch(API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      keepalive: true
    }).catch(function (error) {
      console.warn("PHT-SR shared sync failed:", error);
    });
  }

  function flushPending(useBeacon) {
    if (!Object.keys(pendingItems).length) return;
    window.clearTimeout(saveTimer);
    const items = pendingItems;
    pendingItems = {};
    const payload = JSON.stringify({ action: "bulk", items: items });
    if (useBeacon && navigator.sendBeacon) {
      try {
        const blob = new Blob([payload], { type: "application/json" });
        if (navigator.sendBeacon(API_URL, blob)) return;
      } catch (error) {}
    }
    post({ action: "bulk", items: items });
  }

  function parseListValue(value) {
    try {
      const parsed = JSON.parse(value || "[]");
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function imageCount(rows) {
    return (rows || []).reduce(function (sum, item) {
      if (!item || typeof item !== "object") return sum;
      let count = 0;
      ["images", "photos", "photoList", "gallery"].forEach(function (key) {
        if (Array.isArray(item[key])) count += item[key].length;
      });
      ["image", "imageUrl", "img", "photo", "cover", "thumb", "thumbnail"].forEach(function (key) {
        if (item[key]) count += 1;
      });
      return sum + count;
    }, 0);
  }

  function shouldBlockSameStoreSync(value, oldValue) {
    const rows = parseListValue(value);
    const oldRows = parseListValue(oldValue);
    const photos = imageCount(rows);
    const oldPhotos = imageCount(oldRows);
    if (rows.length < 20) return true;
    if (rows.length >= 20 && photos === 0) return true;
    if (oldRows.length >= 100 && rows.length < oldRows.length * 0.8) return true;
    if (oldPhotos >= 100 && photos < oldPhotos * 0.35) return true;
    return false;
  }

  function scheduleSet(key, value, oldValue) {
    if (isHydrating || !SYNC_KEYS.includes(key)) return;
    if (key === "sameStoreItems" && shouldBlockSameStoreSync(value, oldValue || "")) {
      console.warn("Blocked unsafe sameStoreItems sync to PHT-SR shared storage.");
      return;
    }
    pendingItems[key] = value;
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(function () {
      flushPending(false);
    }, 120);
  }

  Storage.prototype.setItem = function (key, value) {
    const oldValue = this === localStorage ? nativeGetItem.call(this, key) : null;
    nativeSetItem.call(this, key, value);
    if (this === localStorage) scheduleSet(String(key), String(value), oldValue);
  };

  Storage.prototype.removeItem = function (key) {
    nativeRemoveItem.call(this, key);
    if (this === localStorage && SYNC_KEYS.includes(String(key))) {
      flushPending(false);
      post({ action: "remove", key: String(key) });
    }
  };

  Storage.prototype.clear = function () {
    nativeClear.call(this);
    if (this === localStorage) post({ action: "clear" });
  };

  async function fetchSharedData() {
    const publicUrl = API_URL + "?public=1&keys=" + PUBLIC_FETCH_KEYS;
    const adminUrl = API_URL + "?keys=" + ADMIN_FETCH_KEYS;
    const candidates = PUBLIC_READ ? [publicUrl] : [adminUrl, publicUrl];
    let lastError = null;
    for (let i = 0; i < candidates.length; i += 1) {
      const url = candidates[i];
      try {
        const response = await fetch(url, { cache: "no-store", credentials: "same-origin" });
        if (!response.ok) throw new Error(url + " returned " + response.status);
        const payload = await response.json();
        window.HUOMANGE_SHARED_PUBLIC_FALLBACK = !PUBLIC_READ && /public=1/.test(url);
        return payload && payload.data ? payload.data : (payload || {});
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError || new Error("No shared data source available");
  }

  async function hydrate() {
    try {
      const data = await fetchSharedData();
      window.HUOMANGE_SHARED_DATA = data || {};
      window.HUOMANGE_SHARED_SKIPPED_KEYS = [];

      isHydrating = true;
      Object.keys(data).forEach(function (key) {
        if (SYNC_KEYS.includes(key) && data[key] !== undefined) {
          const normalized = normalizeSharedValue(data[key]);
          const existing = nativeGetItem.call(localStorage, key);
          const isPeerPool = key === "peerDevelopmentItems" || key === "storeDevelopmentItems";
          const isLargeSharedList = isPeerPool || key === "sameStoreItems" || normalized.length > 750000;

          if (isPeerPool) {
            window.HUOMANGE_SHARED_SKIPPED_KEYS.push(key);
            return;
          }

          if (key === "sameStoreItems" && existing && shouldBlockSameStoreSync(normalized, existing)) {
            window.HUOMANGE_SHARED_SKIPPED_KEYS.push(key);
            return;
          }

          if (isLargeSharedList) {
            const saved = safeSetLocalOnly(key, normalized);
            if (!saved) window.HUOMANGE_SHARED_SKIPPED_KEYS.push(key);
            return;
          }
          safeSetLocalOnly(key, normalized);
        }
      });
      isHydrating = false;

      window.HUOMANGE_SHARED_READY = true;
    } catch (error) {
      isHydrating = false;
      window.HUOMANGE_SHARED_READY = false;
      console.warn("PHT-SR shared data could not be loaded. Local browser data will be used.", error);
    }
  }

  window.HUOMANGE_SHARED_STORAGE = {
    ready: hydrate(),
    reload: hydrate,
    flush: function () { flushPending(false); }
  };

  window.addEventListener("beforeunload", function () {
    flushPending(true);
  });

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flushPending(true);
  });
})();
