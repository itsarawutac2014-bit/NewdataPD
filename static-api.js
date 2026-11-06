/**
 * static-api.js
 * ระบบจำลอง Backend PHP ฝั่ง Browser (Client-Side Serverless Engine)
 * ทำให้เว็บไซต์ทำงานบน GitHub Pages / Vercel / Cloudflare Pages ได้ฟรีตลอดชีพ 100%
 * โดยดึงข้อมูลสด (Real-time) จาก Google Sheets ผ่าน JSONP + ไฟล์ JSON ในโฟลเดอร์ data/
 */
(function () {
  'use strict';

  const DEFAULT_SHEET_ID = '1viioRJ5I3BmqiD7KLwSG9_uNQ_9u_a8LYR-4GaPGuYI';
  const origFetch = window.fetch.bind(window);

  const MONTH_NAMES_TH = {
    1: 'มกราคม', 2: 'กุมภาพันธ์', 3: 'มีนาคม', 4: 'เมษายน',
    5: 'พฤษภาคม', 6: 'มิถุนายน', 7: 'กรกฎาคม', 8: 'สิงหาคม',
    9: 'กันยายน', 10: 'ตุลาคม', 11: 'พฤศจิกายน', 12: 'ธันวาคม'
  };
  const MONTH_SHORT_TH = {
    1: 'ม.ค.', 2: 'ก.พ.', 3: 'มี.ค.', 4: 'เม.ย.',
    5: 'พ.ค.', 6: 'มิ.ย.', 7: 'ก.ค.', 8: 'ส.ค.',
    9: 'ก.ย.', 10: 'ต.ค.', 11: 'พ.ย.', 12: 'ธ.ค.'
  };
  const DAYS_IN_MONTH = {
    1: 31, 2: 28, 3: 31, 4: 30, 5: 31, 6: 30,
    7: 31, 8: 31, 9: 30, 10: 31, 11: 30, 12: 31
  };

  // Cache สำหรับเก็บข้อมูล Google Sheets ชั่วคราว (ลดการโหลดซ้ำเมื่อสลับเดือน/เครื่อง)
  const gvizCache = {};
  const CACHE_TTL_MS = 45 * 1000;

  function fetchGvizJsonp(sheetId, queryParam, forceRefresh = false) {
    const cacheKey = `${sheetId}__${queryParam}`;
    const now = Date.now();
    if (!forceRefresh && gvizCache[cacheKey] && (now - gvizCache[cacheKey].ts < CACHE_TTL_MS)) {
      return Promise.resolve(gvizCache[cacheKey].data);
    }

    return new Promise((resolve) => {
      const cbName = '__gviz_cb_' + Math.random().toString(36).slice(2) + '_' + Date.now();
      const script = document.createElement('script');
      let settled = false;

      const cleanup = () => {
        try { delete window[cbName]; } catch (e) { window[cbName] = undefined; }
        if (script.parentNode) script.parentNode.removeChild(script);
      };

      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(gvizCache[cacheKey] ? gvizCache[cacheKey].data : null);
      }, 14000);

      window[cbName] = function (resp) {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        cleanup();
        if (resp && resp.table) {
          gvizCache[cacheKey] = { ts: Date.now(), data: resp };
          resolve(resp);
        } else {
          resolve(null);
        }
      };

      script.onerror = function () {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        cleanup();
        resolve(gvizCache[cacheKey] ? gvizCache[cacheKey].data : null);
      };

      script.src = `https://docs.google.com/spreadsheets/d/${sheetId}/gviz/tq?tqx=responseHandler:${cbName}&${queryParam}`;
      document.head.appendChild(script);
    });
  }

  async function fetchStaticJson(relPath) {
    try {
      const res = await origFetch(relPath, { cache: 'no-cache' });
      if (!res.ok) return null;
      return await res.json();
    } catch (e) {
      return null;
    }
  }

  function makeJsonResponse(obj, status = 200) {
    return new Response(JSON.stringify(obj), {
      status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
  }

  function round(val, decimals = 0) {
    const num = Number(val) || 0;
    if (decimals <= 0) return Math.round(num);
    const factor = Math.pow(10, decimals);
    return Math.round((num + Number.EPSILON) * factor) / factor;
  }

  function floatval(v) {
    if (typeof v === 'number') return isNaN(v) ? 0 : v;
    if (v === null || v === undefined || v === '') return 0;
    const s = String(v).replace(/,/g, '').trim();
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }

  function mapCodeToDeptItemKey(code, desc) {
    code = String(code || '').trim().toUpperCase();
    desc = String(desc || '').trim();
    const descLow = desc.toLowerCase();

    // PL
    if (code === 'BL32' || descLow.includes('trim')) return 'pl_trim';
    if (code === 'BL33' || desc.includes('ไซโล')) return 'pl_silo';
    if (code === 'BL34' || desc.includes('วางแผน')) return 'pl_plan';

    // RM
    if (desc.includes('สกปรก') || desc.includes('คราบน้ำมัน')) return 'rm_dirty';
    if (desc.includes('ตัดก่อนคลังม้วน เต็ม')) return 'rm_cut_full';
    if (code === 'BL06' || desc.includes('ตัดก่อนคลังม้วน')) return 'rm_cut_wh';
    if (code === 'BL13' || desc.includes('ตัดก่อนผลิต')) return 'rm_cut_pd';
    if (code === 'BL64' || desc.includes('เศษติดแกน+แกน')) return 'rm_core_plus';
    if (code === 'BL14' || desc === 'แกน-ผลิต') return 'rm_core_pd';
    if (code === 'BL05' || desc.includes('เศษติดแกน-คลัง')) return 'rm_core_wh';
    if (code === 'BL07' || desc.includes('กรอม้วน')) return 'rm_rewind';
    if (code === 'BL08') {
      if (desc.includes('ปลวก') && desc.includes('แกนบี้')) return 'rm_termite_crush';
      if (desc.includes('แกนบี้')) return 'rm_core_crush';
      return 'rm_termite_crush';
    }
    if (desc.includes('ปลวก')) return 'rm_termite';
    if (code === 'BL66' || desc.includes('กระแทก')) return 'rm_impact';
    if (code === 'BL09' || desc.includes('ม้วนกรอ') || desc.includes('รอยยับ')) return 'rm_splice';
    if (desc.includes('ม้วนหัวแตก (ผลิต)')) return 'rm_head_split_pd';
    if (code === 'BL03' || desc.includes('ม้วนหัวแตก')) return 'rm_head_split';
    if (desc.includes('เปียกน้ำ')) return 'rm_wet';

    // WH
    if (code === 'BL29' || descLow.includes('คลังfg')) return 'wh_fg';
    if (code === 'BL62' || desc.includes('ซ่อมงานผิดพลาด')) return 'wh_repair';
    if (code === 'BL27' || descLow.includes('งาน ct')) return 'wh_ct';
    if (code === 'BL28' || descLow.includes('เศษva')) return 'wh_va';

    // LTS
    if (code === 'BL30' || desc.includes('ของเสีย จัดส่ง')) return 'lts_waste';
    if (code === 'BL31' || descLow.includes('reject จัดส่ง')) return 'lts_reject';

    // MK
    if (code === 'BL35' || desc.includes('ลูกค้ายกเลิก')) return 'mk_cancel';
    if (code === 'BL36' || desc.includes('งานตัดจบ')) return 'mk_close';
    if (code === 'BL37' || descLow.includes('reject ตลาด')) return 'mk_reject';
    if (code === 'BL61' || descLow.includes('mkt ตัวอย่าง')) return 'mk_sample';

    return null;
  }

  function getISOWeekNumber(isoDateStr) {
    const parts = isoDateStr.split('-').map(Number);
    const d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
  }

  // ==========================================================
  // 1. จำลอง api.php
  // ==========================================================
  async function handleApiPhp(urlObj) {
    const params = urlObj.searchParams;
    const sheetId = params.get('sheetId') || DEFAULT_SHEET_ID;
    const machine = (params.get('machine') || 'BHS').trim().toUpperCase();
    let month = parseInt(params.get('month') || String(new Date().getMonth() + 1), 10);
    if (isNaN(month) || month < 1 || month > 12) month = new Date().getMonth() + 1;
    const monthPad = String(month).padStart(2, '0');
    const totalDays = DAYS_IN_MONTH[month] || 31;

    // กรณีขอ raw sheet
    if (params.has('raw') || params.has('sheet')) {
      const q = params.has('sheet')
        ? `sheet=${encodeURIComponent(params.get('sheet'))}`
        : `gid=${encodeURIComponent(params.get('gid') || '0')}`;
      const resp = await fetchGvizJsonp(sheetId, q);
      if (resp) return makeJsonResponse(resp);
      return makeJsonResponse({ error: 'Unable to fetch sheet' });
    }

    const colMap = {
      ISOWA: { consum: 1, output: 2, waste: 5 },
      BHS:   { consum: 7, output: 8, waste: 11 },
      YUELI: { consum: 13, output: 14, waste: 17 },
      TOTAL: { consum: 19, output: 20, waste: 23 }
    };
    const mCols = colMap[machine] || colMap.BHS;

    // ดึง Sheet 1 (gid=0) และ Sheet 2 (sheet=Waste ) พร้อมกัน
    const [j1, j2Try1, importedFileJson] = await Promise.all([
      fetchGvizJsonp(sheetId, 'gid=0'),
      fetchGvizJsonp(sheetId, 'sheet=' + encodeURIComponent('Waste ')),
      fetchStaticJson('data/imported_data.json')
    ]);

    let j2 = j2Try1;
    let usedSheetName = 'Waste ';
    if (!j2 || !j2.table || !j2.table.rows || j2.table.rows.length <= 50 || (j2.table.cols?.[0]?.label === 'Day of Posting Date')) {
      const j2Try2 = await fetchGvizJsonp(sheetId, 'sheet=Waste');
      if (j2Try2 && j2Try2.table && j2Try2.table.rows && j2Try2.table.rows.length > 50) {
        j2 = j2Try2;
        usedSheetName = 'Waste';
      }
    }

    const dailySummary = {};
    let totalConsumMTD = 0;
    let totalOutputMTD = 0;
    let totalWasteKgMTD = 0;

    const machineSummaries = {
      BHS:   { consum: 0, output: 0, wasteKg: 0, wastePct: 0 },
      YUELI: { consum: 0, output: 0, wasteKg: 0, wastePct: 0 },
      ISOWA: { consum: 0, output: 0, wasteKg: 0, wastePct: 0 },
      TOTAL: { consum: 0, output: 0, wasteKg: 0, wastePct: 0 }
    };

    const reMonth1 = new RegExp(`(\\d{1,2})\\/${monthPad}\\/`);
    const reMonth2 = new RegExp(`(\\d{1,2})\\/${month}\\/`);

    if (j1 && j1.table && Array.isArray(j1.table.rows)) {
      j1.table.rows.forEach(r => {
        const cArr = r.c || [];
        const dateVal = String(cArr[0]?.v ?? cArr[0]?.f ?? '');
        const dm = dateVal.match(reMonth1) || dateVal.match(reMonth2);
        if (dm) {
          const day = parseInt(dm[1], 10);
          const rowBhsC = floatval(cArr[7]?.v);
          const rowBhsO = Math.max(0, floatval(cArr[8]?.v));
          const rowYueliC = floatval(cArr[13]?.v);
          const rowYueliO = Math.max(0, floatval(cArr[14]?.v));
          const rowIsowaC = floatval(cArr[1]?.v);
          const rowIsowaO = Math.max(0, floatval(cArr[2]?.v));
          let rowTotC = floatval(cArr[19]?.v);
          let rowTotO = Math.max(0, floatval(cArr[20]?.v));
          if (rowTotC <= 0 && rowTotO <= 0) {
            rowTotC = rowBhsC + rowYueliC + rowIsowaC;
            rowTotO = rowBhsO + rowYueliO + rowIsowaO;
          }

          machineSummaries.BHS.consum += rowBhsC;
          machineSummaries.BHS.output += rowBhsO;
          machineSummaries.YUELI.consum += rowYueliC;
          machineSummaries.YUELI.output += rowYueliO;
          machineSummaries.ISOWA.consum += rowIsowaC;
          machineSummaries.ISOWA.output += rowIsowaO;
          machineSummaries.TOTAL.consum += rowTotC;
          machineSummaries.TOTAL.output += rowTotO;

          let c = 0, o = 0, w = 0;
          if (machine === 'TOTAL') {
            c = rowTotC;
            o = rowTotO;
            w = floatval(cArr[23]?.v);
          } else {
            c = floatval(cArr[mCols.consum]?.v);
            o = Math.max(0, floatval(cArr[mCols.output]?.v));
            w = mCols.waste !== -1 ? floatval(cArr[mCols.waste]?.v) : 0;
          }
          dailySummary[day] = { consum: c, output: o, wasteKg: w };
          totalConsumMTD += c;
          totalOutputMTD += o;
          totalWasteKgMTD += w;
        }
      });
    }

    ['BHS', 'YUELI', 'ISOWA', 'TOTAL'].forEach(mKey => {
      const mInfo = machineSummaries[mKey];
      mInfo.consum = round(mInfo.consum);
      mInfo.output = round(mInfo.output);
      mInfo.wasteKg = Math.max(0, mInfo.consum - mInfo.output);
      mInfo.wastePct = mInfo.consum > 0 ? round(((mInfo.consum - mInfo.output) / mInfo.consum) * 100, 2) : 0;
    });

    const deptItemKeys = [
      'pl_trim', 'pl_silo', 'pl_plan',
      'rm_dirty', 'rm_cut_wh', 'rm_cut_pd', 'rm_cut_full', 'rm_core_plus',
      'rm_core_pd', 'rm_core_wh', 'rm_rewind', 'rm_termite_crush',
      'rm_core_crush', 'rm_termite', 'rm_impact', 'rm_splice',
      'rm_head_split', 'rm_head_split_pd', 'rm_wet',
      'wh_fg', 'wh_repair', 'wh_ct', 'wh_va',
      'lts_waste', 'lts_reject',
      'mk_cancel', 'mk_close', 'mk_reject', 'mk_sample'
    ];

    const dailyBreakdown = {};
    const dailyDeptBreakdown = {};
    const dailySheetTotalBlKg = {};
    const baseCol = (month - 1) * 9;

    const reDayExact1 = new RegExp(`^(\\d{1,2})\\/${month}\\/\\d{2,4}$`);
    const reDayExact2 = new RegExp(`^(\\d{1,2})\\/${monthPad}\\/\\d{2,4}$`);

    if (j2 && j2.table && Array.isArray(j2.table.rows)) {
      const rows2 = j2.table.rows;
      let currentDay = null;
      let dayHasCol6 = false;

      for (let r = 0; r < rows2.length; r++) {
        const cArr = rows2[r].c || [];
        const cCode = String(cArr[baseCol]?.v ?? '').trim();
        const cDesc = String(cArr[baseCol + 1]?.v ?? '').trim();

        const dm = cCode.match(reDayExact1) || cCode.match(reDayExact2);
        if (dm) {
          currentDay = parseInt(dm[1], 10);
          dailyBreakdown[currentDay] = {
            ncp: 0, def: 0, web: 0, control: 0, om: 0,
            order: 0, core: 0, spec: 0, conveyor: 0, qc: 0, reject: 0,
            totalKg: 0
          };
          dailyDeptBreakdown[currentDay] = {};
          deptItemKeys.forEach(k => { dailyDeptBreakdown[currentDay][k] = 0; });

          dayHasCol6 = false;
          for (let k = r + 1; k < Math.min(rows2.length, r + 35); k++) {
            const checkCode = String(rows2[k].c?.[baseCol]?.v ?? '').trim();
            if (reDayExact1.test(checkCode) || reDayExact2.test(checkCode)) break;
            if (checkCode === 'Total') {
              dayHasCol6 = floatval(rows2[k].c?.[baseCol + 6]?.v) > 0;
              break;
            }
          }
          continue;
        }

        const bhsVal = floatval(cArr[baseCol + 3]?.v);
        const isoVal = dayHasCol6 ? floatval(cArr[baseCol + 4]?.v) : 0;
        const yueVal = dayHasCol6 ? floatval(cArr[baseCol + 5]?.v) : floatval(cArr[baseCol + 4]?.v);
        const totVal = dayHasCol6 ? floatval(cArr[baseCol + 6]?.v) : floatval(cArr[baseCol + 5]?.v);

        let cKg = bhsVal;
        if (machine === 'BHS') cKg = bhsVal;
        else if (machine === 'ISOWA') cKg = isoVal;
        else if (machine === 'YUELI') cKg = yueVal;
        else if (machine === 'TOTAL') cKg = totVal;

        if (currentDay !== null && cCode === 'Total') {
          dailySheetTotalBlKg[currentDay] = cKg;
          continue;
        }

        if (currentDay !== null && cCode !== '' && cCode !== 'Reason Code' && cCode !== 'Total') {
          const b = dailyBreakdown[currentDay];
          if (cCode === 'BL18') { b.ncp += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL17') { b.def += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL16') { b.web += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL21') { b.control += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL20') { b.om += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL15') { b.order += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL12') { b.core += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL19') { b.spec += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL22') { b.conveyor += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL24') { b.qc += cKg; b.totalKg += cKg; }
          else if (cCode === 'BL25') { b.reject += cKg; b.totalKg += cKg; }
          else {
            const deptItemKey = mapCodeToDeptItemKey(cCode, cDesc);
            if (deptItemKey !== null) {
              let deptKg = cKg;
              if (deptKg === 0 && totVal > 0 && bhsVal === 0 && isoVal === 0 && yueVal === 0 && (machine === 'BHS' || machine === 'YUELI')) {
                deptKg = totVal / 2.0;
              }
              dailyDeptBreakdown[currentDay][deptItemKey] = (dailyDeptBreakdown[currentDay][deptItemKey] || 0) + deptKg;
            }
          }
        }
      }
    }

    // รวมข้อมูลจาก imported_data.json
    let importedJson = importedFileJson;
    try {
      const localImp = localStorage.getItem('imported_data_store_static');
      if (localImp) importedJson = JSON.parse(localImp);
    } catch (e) {}

    if (importedJson && typeof importedJson === 'object') {
      if (importedJson.con_out && typeof importedJson.con_out === 'object') {
        Object.entries(importedJson.con_out).forEach(([dateKey, cRow]) => {
          const dm = dateKey.match(reMonth1) || dateKey.match(reMonth2);
          if (dm) {
            const day = parseInt(dm[1], 10);
            let c = 0, o = 0;
            if (machine === 'BHS') { c = floatval(cRow.bhs_consum); o = floatval(cRow.bhs_output); }
            else if (machine === 'YUELI') { c = floatval(cRow.yueli_consum); o = floatval(cRow.yueli_output); }
            else if (machine === 'ISOWA') { c = floatval(cRow.isowa_consum); o = floatval(cRow.isowa_output); }
            else if (machine === 'TOTAL') {
              c = floatval(cRow.bhs_consum) + floatval(cRow.yueli_consum) + floatval(cRow.isowa_consum);
              o = floatval(cRow.bhs_output) + floatval(cRow.yueli_output) + floatval(cRow.isowa_output);
            }
            if (c > 0 || o > 0) {
              if (dailySummary[day]) {
                totalConsumMTD -= dailySummary[day].consum;
                totalOutputMTD -= dailySummary[day].output;
              }
              dailySummary[day] = { consum: c, output: o, wasteKg: 0 };
              totalConsumMTD += c;
              totalOutputMTD += o;
            }
          }
        });
      }
    }

    const transformed = [];
    const transformedWeight = [];
    const weightTotals = {
      ncp: 0, def: 0, web: 0, control: 0, om: 0,
      order: 0, core: 0, spec: 0, conveyor: 0, qc: 0, reject: 0,
      totalAll: 0
    };

    for (let d = 1; d <= totalDays; d++) {
      const consum = dailySummary[d]?.consum ?? 0;
      const output = dailySummary[d]?.output ?? 0;
      const b = dailyBreakdown[d] || {};

      const ncpKg = b.ncp || 0;
      const defKg = b.def || 0;
      const webKg = b.web || 0;
      const ctrlKg = b.control || 0;
      const omKg = b.om || 0;
      const orderKg = b.order || 0;
      const coreKg = b.core || 0;
      const specKg = b.spec || 0;
      const conveyorKg = b.conveyor || 0;
      const qcKg = b.qc || 0;
      const rejectKg = b.reject || 0;

      const dayWeightTotal = ncpKg + defKg + webKg + ctrlKg + omKg + orderKg + coreKg + specKg + conveyorKg + qcKg + rejectKg;

      weightTotals.ncp += ncpKg;
      weightTotals.def += defKg;
      weightTotals.web += webKg;
      weightTotals.control += ctrlKg;
      weightTotals.om += omKg;
      weightTotals.order += orderKg;
      weightTotals.core += coreKg;
      weightTotals.spec += specKg;
      weightTotals.conveyor += conveyorKg;
      weightTotals.qc += qcKg;
      weightTotals.reject += rejectKg;
      weightTotals.totalAll += dayWeightTotal;

      transformedWeight.push({
        date: `${d}/${month}/2026`,
        ncp: ncpKg, def: defKg, web: webKg, control: ctrlKg, om: omKg,
        order: orderKg, core: coreKg, spec: specKg, conveyor: conveyorKg, qc: qcKg, reject: rejectKg,
        totalWeight: dayWeightTotal
      });

      let ncp = 0, def = 0, web = 0, ctrl = 0, om = 0, order = 0, core = 0, spec = 0, conveyor = 0, qc = 0, reject = 0, allWaste = 0;
      if (consum > 0) {
        ncp = round((ncpKg / consum) * 100, 2);
        def = round((defKg / consum) * 100, 2);
        web = round((webKg / consum) * 100, 2);
        ctrl = round((ctrlKg / consum) * 100, 2);
        om = round((omKg / consum) * 100, 2);
        order = round((orderKg / consum) * 100, 2);
        core = round((coreKg / consum) * 100, 2);
        spec = round((specKg / consum) * 100, 2);
        conveyor = round((conveyorKg / consum) * 100, 2);
        qc = round((qcKg / consum) * 100, 2);
        reject = round((rejectKg / consum) * 100, 2);
        allWaste = round(ncp + def + web + ctrl + om + order + core + spec + conveyor + qc + reject, 2);
      }

      transformed.push({
        date: `${d}/${month}/2026`,
        ncp, def, web, control: ctrl, om, order, core, spec, conveyor, qc, reject,
        allWaste,
        consumption: round(consum),
        output: round(output)
      });
    }

    let totalConsumWithWaste = 0;
    for (let d = 1; d <= totalDays; d++) {
      const c = dailySummary[d]?.consum ?? 0;
      const b = dailyBreakdown[d];
      if (b && c > 0) {
        const dayKg = (b.ncp || 0) + (b.def || 0) + (b.web || 0) + (b.control || 0) + (b.om || 0) + (b.order || 0) + (b.core || 0) + (b.spec || 0) + (b.conveyor || 0) + (b.qc || 0) + (b.reject || 0);
        if (dayKg > 0) totalConsumWithWaste += c;
      }
    }

    const denom = totalConsumWithWaste > 0 ? totalConsumWithWaste : totalConsumMTD;
    const actualMTD = (denom > 0 && weightTotals.totalAll > 0) ? round((weightTotals.totalAll / denom) * 100, 2) : 0;

    const summary = {
      consumption: round(totalConsumMTD),
      output: round(totalOutputMTD),
      consumptionWithWaste: round(totalConsumWithWaste),
      allWaste: actualMTD,
      ncp: denom > 0 ? round((weightTotals.ncp / denom) * 100, 2) : 0,
      def: denom > 0 ? round((weightTotals.def / denom) * 100, 2) : 0,
      web: denom > 0 ? round((weightTotals.web / denom) * 100, 2) : 0,
      control: denom > 0 ? round((weightTotals.control / denom) * 100, 2) : 0,
      om: denom > 0 ? round((weightTotals.om / denom) * 100, 2) : 0,
      order: denom > 0 ? round((weightTotals.order / denom) * 100, 2) : 0,
      core: denom > 0 ? round((weightTotals.core / denom) * 100, 2) : 0,
      spec: denom > 0 ? round((weightTotals.spec / denom) * 100, 2) : 0,
      conveyor: denom > 0 ? round((weightTotals.conveyor / denom) * 100, 2) : 0,
      qc: denom > 0 ? round((weightTotals.qc / denom) * 100, 2) : 0,
      reject: denom > 0 ? round((weightTotals.reject / denom) * 100, 2) : 0
    };

    // คำนวณข้อมูลของเสียแยกตามแผนก (PL, RM, WH, LTS, MK) และ Diff waste
    const deptGroupsMap = {
      PL: ['pl_trim', 'pl_silo', 'pl_plan'],
      RM: [
        'rm_dirty', 'rm_cut_wh', 'rm_cut_pd', 'rm_cut_full', 'rm_core_plus',
        'rm_core_pd', 'rm_core_wh', 'rm_rewind', 'rm_termite_crush',
        'rm_core_crush', 'rm_termite', 'rm_impact', 'rm_splice',
        'rm_head_split', 'rm_head_split_pd', 'rm_wet'
      ],
      WH: ['wh_fg', 'wh_repair', 'wh_ct', 'wh_va'],
      LTS: ['lts_waste', 'lts_reject'],
      MK: ['mk_cancel', 'mk_close', 'mk_reject', 'mk_sample']
    };

    const deptTargets = {
      PD: 5.00, PL: 3.50, RM: 1.58, WH: 0.01, LTS: 0.02, MK: 0.04, DIFF: 0.85
    };

    const deptDailyPct = [];
    const deptDailyKg = [];
    const deptItemTotalsKg = {};
    deptItemKeys.forEach(k => { deptItemTotalsKg[k] = 0; });
    deptItemTotalsKg.pd_waste = 0;
    deptItemTotalsKg.diff_waste = 0;

    const deptGroupTotalsKg = { PD: 0, PL: 0, RM: 0, WH: 0, LTS: 0, MK: 0, DIFF: 0 };
    let totalSheetBlKgMTD = 0;

    for (let d = 1; d <= totalDays; d++) {
      const consum = dailySummary[d]?.consum ?? 0;
      const output = dailySummary[d]?.output ?? 0;
      const dItems = dailyDeptBreakdown[d] || {};
      const pdRow = dailyBreakdown[d];
      const pdDayKg = pdRow ? floatval(pdRow.totalKg) : 0;

      const rowKg = { date: `${d}/${month}/2026`, consumption: round(consum), output: round(output) };
      const rowPct = { date: `${d}/${month}/2026`, consumption: round(consum), output: round(output) };

      rowKg.pd_waste = round(pdDayKg, 1);
      rowKg.dept_PD = round(pdDayKg, 1);
      rowPct.pd_waste = consum > 0 ? round((pdDayKg / consum) * 100, 2) : 0;
      rowPct.dept_PD = rowPct.pd_waste;
      deptItemTotalsKg.pd_waste += pdDayKg;
      deptGroupTotalsKg.PD += pdDayKg;

      let dayOtherBlSum = 0;
      Object.entries(deptGroupsMap).forEach(([gKey, keysList]) => {
        let gDayKg = 0;
        keysList.forEach(ik => {
          const valKg = floatval(dItems[ik]);
          rowKg[ik] = round(valKg, 1);
          rowPct[ik] = consum > 0 ? round((valKg / consum) * 100, 2) : 0;
          deptItemTotalsKg[ik] += valKg;
          gDayKg += valKg;
        });
        rowKg[`dept_${gKey}`] = round(gDayKg, 1);
        rowPct[`dept_${gKey}`] = consum > 0 ? round((gDayKg / consum) * 100, 2) : 0;
        deptGroupTotalsKg[gKey] += gDayKg;
        dayOtherBlSum += gDayKg;
      });

      const sheetBlTotal = dailySheetTotalBlKg[d] !== undefined ? floatval(dailySheetTotalBlKg[d]) : (pdDayKg + dayOtherBlSum);
      totalSheetBlKgMTD += sheetBlTotal;

      let diffDayKg = 0;
      if (consum > 0 && (pdDayKg > 0 || sheetBlTotal > 0 || output > 0)) {
        diffDayKg = (consum - output) - sheetBlTotal;
      }
      rowKg.diff_waste = round(diffDayKg, 1);
      rowKg.dept_DIFF = round(diffDayKg, 1);
      rowKg.weighed_total = round(sheetBlTotal, 1);

      rowPct.diff_waste = consum > 0 ? round((diffDayKg / consum) * 100, 2) : 0;
      rowPct.dept_DIFF = rowPct.diff_waste;

      deptItemTotalsKg.diff_waste += diffDayKg;
      deptGroupTotalsKg.DIFF += diffDayKg;

      deptDailyKg.push(rowKg);
      deptDailyPct.push(rowPct);
    }

    const deptItemTotalsPct = {};
    Object.keys(deptItemTotalsKg).forEach(ik => {
      deptItemTotalsPct[ik] = denom > 0 ? round((deptItemTotalsKg[ik] / denom) * 100, 2) : 0;
      deptItemTotalsKg[ik] = round(deptItemTotalsKg[ik], 1);
    });

    const deptGroupTotalsPct = {};
    Object.keys(deptGroupTotalsKg).forEach(gk => {
      deptGroupTotalsPct[gk] = denom > 0 ? round((deptGroupTotalsKg[gk] / denom) * 100, 2) : 0;
      deptGroupTotalsKg[gk] = round(deptGroupTotalsKg[gk], 1);
    });
    deptGroupTotalsPct.PD = actualMTD;
    deptGroupTotalsKg.PD = round(weightTotals.totalAll, 1);

    const deptComparison = [
      { key: 'PD', code: 'Waste Production', nameTh: 'แผนกผลิต (11 รายการ)', target: deptTargets.PD, actualPct: actualMTD, actualKg: round(weightTotals.totalAll, 1), diffPct: round(actualMTD - deptTargets.PD, 2) },
      { key: 'PL', code: 'Waste Planning', nameTh: 'แผนกวางแผน (Waste PL)', target: deptTargets.PL, actualPct: deptGroupTotalsPct.PL, actualKg: deptGroupTotalsKg.PL, diffPct: round(deptGroupTotalsPct.PL - deptTargets.PL, 2) },
      { key: 'RM', code: 'Waste RM', nameTh: 'แผนกวัตถุดิบ/คลังม้วน (Waste RM)', target: deptTargets.RM, actualPct: deptGroupTotalsPct.RM, actualKg: deptGroupTotalsKg.RM, diffPct: round(deptGroupTotalsPct.RM - deptTargets.RM, 2) },
      { key: 'MK', code: 'Waste Maketting', nameTh: 'แผนกการตลาด (Waste MK)', target: deptTargets.MK, actualPct: deptGroupTotalsPct.MK, actualKg: deptGroupTotalsKg.MK, diffPct: round(deptGroupTotalsPct.MK - deptTargets.MK, 2) },
      { key: 'WH', code: 'Waste WH', nameTh: 'แผนกคลังสินค้า FG (Waste WH)', target: deptTargets.WH, actualPct: deptGroupTotalsPct.WH, actualKg: deptGroupTotalsKg.WH, diffPct: round(deptGroupTotalsPct.WH - deptTargets.WH, 2) },
      { key: 'LTS', code: 'Waste LTS', nameTh: 'แผนกจัดส่ง (Waste LTS)', target: deptTargets.LTS, actualPct: deptGroupTotalsPct.LTS, actualKg: deptGroupTotalsKg.LTS, diffPct: round(deptGroupTotalsPct.LTS - deptTargets.LTS, 2) },
      { key: 'DIFF', code: 'Diff waste', nameTh: 'ส่วนต่าง นน.เข้า-ออก หักของเสีย (Diff waste)', target: deptTargets.DIFF, actualPct: deptGroupTotalsPct.DIFF, actualKg: deptGroupTotalsKg.DIFF, diffPct: round(deptGroupTotalsPct.DIFF - deptTargets.DIFF, 2) }
    ];

    // คำนวณแนวโน้มรายปี (Yearly Trend 12 เดือน) และรายเดือนแยกแผนก
    const allDaysData = {};
    const monthlyDeptAgg = {};
    for (let m = 1; m <= 12; m++) {
      const itemsInit = {};
      deptItemKeys.forEach(k => { itemsInit[k] = 0; });
      monthlyDeptAgg[m] = { consum: 0, output: 0, consumWithWaste: 0, pd_waste: 0, sheetTotalBl: 0, items: itemsInit };
    }

    if (j1 && j1.table && Array.isArray(j1.table.rows)) {
      j1.table.rows.forEach(r => {
        const cArr = r.c || [];
        const dateVal = String(cArr[0]?.v ?? cArr[0]?.f ?? '');
        const dm = dateVal.match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
        if (dm) {
          const d = parseInt(dm[1], 10);
          const mo = parseInt(dm[2], 10);
          let yr = parseInt(dm[3], 10);
          if (yr < 100) yr += 2000;
          const isoDate = `${String(yr).padStart(4, '0')}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

          let c = 0, o = 0;
          if (machine === 'BHS') { c = floatval(cArr[7]?.v); o = floatval(cArr[8]?.v); }
          else if (machine === 'YUELI') { c = floatval(cArr[13]?.v); o = floatval(cArr[14]?.v); }
          else if (machine === 'ISOWA') { c = floatval(cArr[1]?.v); o = floatval(cArr[2]?.v); }
          else if (machine === 'TOTAL') {
            c = floatval(cArr[7]?.v) + floatval(cArr[13]?.v) + floatval(cArr[1]?.v);
            o = floatval(cArr[8]?.v) + floatval(cArr[14]?.v) + floatval(cArr[2]?.v);
          }

          allDaysData[isoDate] = { consum: c, output: o, waste11: 0, sheetTotalBl: 0, day: d, month: mo, year: yr };
        }
      });
    }

    if (j2 && j2.table && Array.isArray(j2.table.rows)) {
      const rows2All = j2.table.rows;
      const allowedCodes11 = ['BL18', 'BL17', 'BL16', 'BL21', 'BL20', 'BL15', 'BL12', 'BL19', 'BL22', 'BL24', 'BL25'];

      for (let mo = 1; mo <= 12; mo++) {
        const mBaseCol = (mo - 1) * 9;
        const moPad = String(mo).padStart(2, '0');
        const reMo1 = new RegExp(`^(\\d{1,2})\\/${mo}\\/\\d{2,4}$`);
        const reMo2 = new RegExp(`^(\\d{1,2})\\/${moPad}\\/\\d{2,4}$`);
        let currIso = null;
        let currD = 1;
        let dayHasCol6Year = false;

        for (let r = 0; r < rows2All.length; r++) {
          const cArr = rows2All[r].c || [];
          const cCode = String(cArr[mBaseCol]?.v ?? '').trim();
          const cDesc = String(cArr[mBaseCol + 1]?.v ?? '').trim();

          const dm = cCode.match(reMo1) || cCode.match(reMo2);
          if (dm) {
            currD = parseInt(dm[1], 10);
            currIso = `2026-${moPad}-${String(currD).padStart(2, '0')}`;
            dayHasCol6Year = false;
            for (let k = r + 1; k < Math.min(rows2All.length, r + 35); k++) {
              const checkCode = String(rows2All[k].c?.[mBaseCol]?.v ?? '').trim();
              if (reMo1.test(checkCode) || reMo2.test(checkCode)) break;
              if (checkCode === 'Total') {
                dayHasCol6Year = floatval(rows2All[k].c?.[mBaseCol + 6]?.v) > 0;
                break;
              }
            }
            continue;
          }

          const bhsVal = floatval(cArr[mBaseCol + 3]?.v);
          const isoVal = dayHasCol6Year ? floatval(cArr[mBaseCol + 4]?.v) : 0;
          const yueVal = dayHasCol6Year ? floatval(cArr[mBaseCol + 5]?.v) : floatval(cArr[mBaseCol + 4]?.v);
          const totVal = dayHasCol6Year ? floatval(cArr[mBaseCol + 6]?.v) : floatval(cArr[mBaseCol + 5]?.v);

          let cKg = bhsVal;
          if (machine === 'BHS') cKg = bhsVal;
          else if (machine === 'ISOWA') cKg = isoVal;
          else if (machine === 'YUELI') cKg = yueVal;
          else if (machine === 'TOTAL') cKg = totVal;

          if (currIso && cCode === 'Total') {
            if (!allDaysData[currIso]) {
              allDaysData[currIso] = { consum: 0, output: 0, waste11: 0, sheetTotalBl: 0, day: currD, month: mo, year: 2026 };
            }
            allDaysData[currIso].sheetTotalBl = cKg;
            continue;
          }

          if (currIso && cCode !== '' && cCode !== 'Reason Code' && cCode !== 'Total') {
            if (allowedCodes11.includes(cCode)) {
              if (!allDaysData[currIso]) {
                allDaysData[currIso] = { consum: 0, output: 0, waste11: 0, sheetTotalBl: 0, day: currD, month: mo, year: 2026 };
              }
              allDaysData[currIso].waste11 += cKg;
            } else {
              const deptItemKey = mapCodeToDeptItemKey(cCode, cDesc);
              if (deptItemKey !== null && monthlyDeptAgg[mo]) {
                let deptKg = cKg;
                if (deptKg === 0 && totVal > 0 && bhsVal === 0 && isoVal === 0 && yueVal === 0 && (machine === 'BHS' || machine === 'YUELI')) {
                  deptKg = totVal / 2.0;
                }
                monthlyDeptAgg[mo].items[deptItemKey] += deptKg;
              }
            }
          }
        }
      }
    }

    const yearlyTrend = [];
    for (let m = 1; m <= 12; m++) {
      yearlyTrend.push({
        month: m,
        label: MONTH_SHORT_TH[m],
        fullName: MONTH_NAMES_TH[m],
        consumption: 0,
        consumptionWithWaste: 0,
        output: 0,
        wasteKg: 0,
        wastePct: 0,
        daysCount: 0,
        hasData: false
      });
    }

    Object.values(allDaysData).forEach(val => {
      const mIdx = val.month - 1;
      const mo = val.month;
      if (yearlyTrend[mIdx]) {
        const c = val.consum;
        const o = val.output;
        const w = val.waste11;
        const sTot = val.sheetTotalBl || 0;
        yearlyTrend[mIdx].consumption += c;
        yearlyTrend[mIdx].output += o;
        yearlyTrend[mIdx].wasteKg += w;
        if (w > 0 && c > 0) yearlyTrend[mIdx].consumptionWithWaste += c;
        if (c > 0 || w > 0) yearlyTrend[mIdx].daysCount++;

        if (monthlyDeptAgg[mo]) {
          monthlyDeptAgg[mo].consum += c;
          monthlyDeptAgg[mo].output += o;
          monthlyDeptAgg[mo].pd_waste += w;
          monthlyDeptAgg[mo].sheetTotalBl += sTot;
          if ((w > 0 || sTot > 0) && c > 0) monthlyDeptAgg[mo].consumWithWaste += c;
        }
      }
    });

    yearlyTrend.forEach(y => {
      const denomY = y.consumption;
      y.wastePct = (denomY > 0 && y.wasteKg > 0) ? round((y.wasteKg / denomY) * 100, 2) : 0;
      y.consumption = round(y.consumption);
      y.consumptionWithWaste = round(y.consumptionWithWaste);
      y.output = round(y.output);
      y.wasteKg = round(y.wasteKg);
      y.hasData = (denomY > 0 || y.output > 0 || y.daysCount > 0);
    });

    const deptMonthlyKg = [];
    const deptMonthlyPct = [];
    for (let m = 1; m <= 12; m++) {
      const mAgg = monthlyDeptAgg[m];
      const mCon = mAgg.consum;
      const mOut = mAgg.output;
      const mDenom = mAgg.consumWithWaste > 0 ? mAgg.consumWithWaste : mCon;
      const mPdKg = mAgg.pd_waste;

      const mRowKg = {
        month: m,
        date: `${MONTH_SHORT_TH[m]} 2026`,
        fullName: `${MONTH_NAMES_TH[m]} 2026`,
        consumption: round(mCon),
        output: round(mOut),
        hasData: (mCon > 0 || mOut > 0 || mPdKg > 0)
      };
      const mRowPct = { ...mRowKg };

      mRowKg.pd_waste = round(mPdKg, 1);
      mRowKg.dept_PD = round(mPdKg, 1);
      mRowPct.pd_waste = mDenom > 0 ? round((mPdKg / mDenom) * 100, 2) : 0;
      mRowPct.dept_PD = mRowPct.pd_waste;

      let mOtherBlSum = 0;
      Object.entries(deptGroupsMap).forEach(([gKey, keysList]) => {
        let gMoKg = 0;
        keysList.forEach(ik => {
          const valKg = floatval(mAgg.items[ik]);
          mRowKg[ik] = round(valKg, 1);
          mRowPct[ik] = mDenom > 0 ? round((valKg / mDenom) * 100, 2) : 0;
          gMoKg += valKg;
        });
        mRowKg[`dept_${gKey}`] = round(gMoKg, 1);
        mRowPct[`dept_${gKey}`] = mDenom > 0 ? round((gMoKg / mDenom) * 100, 2) : 0;
        mOtherBlSum += gMoKg;
      });

      const mSheetTot = mAgg.sheetTotalBl > 0 ? mAgg.sheetTotalBl : (mPdKg + mOtherBlSum);
      const mDiffKg = (mCon > 0 && (mPdKg > 0 || mSheetTot > 0 || mOut > 0)) ? ((mCon - mOut) - mSheetTot) : 0;
      mRowKg.diff_waste = round(mDiffKg, 1);
      mRowKg.dept_DIFF = round(mDiffKg, 1);
      mRowPct.diff_waste = mDenom > 0 ? round((mDiffKg / mDenom) * 100, 2) : 0;
      mRowPct.dept_DIFF = mRowPct.diff_waste;

      deptMonthlyKg.push(mRowKg);
      deptMonthlyPct.push(mRowPct);
    }

    const deptWaste = {
      dailyPct: deptDailyPct,
      dailyKg: deptDailyKg,
      monthlyPct: deptMonthlyPct,
      monthlyKg: deptMonthlyKg,
      itemTotalsKg: deptItemTotalsKg,
      itemTotalsPct: deptItemTotalsPct,
      groupTotalsKg: deptGroupTotalsKg,
      groupTotalsPct: deptGroupTotalsPct,
      comparison: deptComparison,
      totalWeighedBlKg: round(totalSheetBlKgMTD, 1)
    };

    const weeklyMap = {};
    Object.entries(allDaysData).forEach(([iso, val]) => {
      const wNum = getISOWeekNumber(iso);
      const wKey = `W${String(wNum).padStart(2, '0')}`;
      if (!weeklyMap[wKey]) {
        weeklyMap[wKey] = {
          weekNum: wNum, weekKey: wKey, label: `W${wNum}`,
          consumption: 0, consumptionWithWaste: 0, output: 0, wasteKg: 0, wastePct: 0,
          startDate: iso, endDate: iso, daysCount: 0
        };
      }
      const c = val.consum;
      const w = val.waste11;
      weeklyMap[wKey].consumption += c;
      weeklyMap[wKey].output += val.output;
      weeklyMap[wKey].wasteKg += w;
      if (w > 0 && c > 0) weeklyMap[wKey].consumptionWithWaste += c;
      if (c > 0 || w > 0) weeklyMap[wKey].daysCount++;
      if (iso < weeklyMap[wKey].startDate) weeklyMap[wKey].startDate = iso;
      if (iso > weeklyMap[wKey].endDate) weeklyMap[wKey].endDate = iso;
    });

    const weeklyTrend = Object.keys(weeklyMap).sort().map(wKey => {
      const wObj = weeklyMap[wKey];
      const d = wObj.consumptionWithWaste > 0 ? wObj.consumptionWithWaste : wObj.consumption;
      wObj.wastePct = (d > 0 && wObj.wasteKg > 0) ? round((wObj.wasteKg / d) * 100, 2) : 0;
      wObj.consumption = round(wObj.consumption);
      wObj.consumptionWithWaste = round(wObj.consumptionWithWaste);
      wObj.output = round(wObj.output);
      wObj.wasteKg = round(wObj.wasteKg);
      return wObj;
    });

    return makeJsonResponse({
      success: true,
      source: 'google_sheet_filtered',
      usedSheet: usedSheetName,
      meta: {
        machine,
        month,
        monthNameTh: MONTH_NAMES_TH[month] || '',
        totalDays
      },
      data: transformed,
      summary,
      weightData: transformedWeight,
      weightSummary: weightTotals,
      deptWaste,
      yearlyTrend,
      weeklyTrend,
      machineSummaries
    });
  }

  // ==========================================================
  // 2. จำลอง output_targets_api.php
  // ==========================================================
  async function handleOutputTargetsApi(urlObj, init) {
    const method = (init?.method || 'GET').toUpperCase();
    const staticData = await fetchStaticJson('data/output_targets.json');
    const defaultTargets = staticData || {
      machines: {
        BHS: { name: 'เครื่อง BHS', dailyTarget: 126978, monthlyTarget: 3326000, unit: 'kg' },
        ISOWA: { name: 'เครื่อง ISOWA', dailyTarget: 35925, monthlyTarget: 941000, unit: 'kg' },
        YUELI: { name: 'เครื่อง YUELI', dailyTarget: 126978, monthlyTarget: 3326000, unit: 'kg' },
        TOTAL: { name: 'รวมทุกเครื่อง (TOTAL)', dailyTarget: 289881, monthlyTarget: 7593000, unit: 'kg' }
      },
      monthlyTargets: {
        '2026-01': { ISOWA: 614000, BHS: 3757000, YUELI: 3200000, TOTAL: 7571000 },
        '2026-02': { ISOWA: 593000, BHS: 3266000, YUELI: 2664000, TOTAL: 6523000 },
        '2026-03': { ISOWA: 858000, BHS: 3806000, YUELI: 3355000, TOTAL: 8019000 },
        '2026-04': { ISOWA: 1355000, BHS: 3761000, YUELI: 1706000, TOTAL: 6822000 },
        '2026-05': { ISOWA: 561000, BHS: 3526000, YUELI: 3144000, TOTAL: 7231000 },
        '2026-06': { ISOWA: 46000, BHS: 2722000, YUELI: 2444000, TOTAL: 5212000 },
        '2026-07': { ISOWA: 941000, BHS: 3326000, YUELI: 3326000, TOTAL: 7593000 },
        '2026-08': { ISOWA: 906000, BHS: 3202000, YUELI: 3202000, TOTAL: 7310000 },
        '2026-09': { ISOWA: 941000, BHS: 3326000, YUELI: 3326000, TOTAL: 7593000 },
        '2026-10': { ISOWA: 941000, BHS: 3326000, YUELI: 3326000, TOTAL: 7593000 },
        '2026-11': { ISOWA: 906000, BHS: 3202000, YUELI: 3202000, TOTAL: 7310000 },
        '2026-12': { ISOWA: 836000, BHS: 2956000, YUELI: 2956000, TOTAL: 6748000 }
      },
      updated_at: new Date().toISOString()
    };

    if (method === 'POST') {
      let payload = {};
      try { payload = JSON.parse(init.body || '{}'); } catch (e) {}
      if (payload.action === 'reset') {
        localStorage.setItem('output_targets_settings', JSON.stringify(defaultTargets));
        return makeJsonResponse({ success: true, data: defaultTargets });
      }
      const cur = Object.assign({}, defaultTargets, payload);
      cur.updated_at = new Date().toISOString();
      localStorage.setItem('output_targets_settings', JSON.stringify(cur));
      return makeJsonResponse({ success: true, data: cur });
    }

    // ดึงสดจากชีต Target Out-put
    const gsResp = await fetchGvizJsonp(DEFAULT_SHEET_ID, 'sheet=' + encodeURIComponent('Target Out-put'));
    if (gsResp && gsResp.table && Array.isArray(gsResp.table.rows) && gsResp.table.rows.length > 0) {
      const rows = gsResp.table.rows;
      const thaiMonths = {
        'มกราคม': '01', 'กุมภาพันธ์': '02', 'มีนาคม': '03', 'เมษายน': '04',
        'พฤษภาคม': '05', 'มิถุนายน': '06', 'กรกฎาคม': '07', 'สิงหาคม': '08',
        'กันยายน': '09', 'ตุลาคม': '10', 'พฤศจิกายน': '11', 'ธันวาคม': '12'
      };
      const monthlyTargets = { ...(defaultTargets.monthlyTargets || {}) };
      for (let i = 0; i < Math.min(12, rows.length); i++) {
        const r = rows[i].c || [];
        const mName = String(r[0]?.v ?? '').trim();
        const mCode = thaiMonths[mName] || String(i + 1).padStart(2, '0');
        const key = `2026-${mCode}`;
        const isoTon = floatval(r[1]?.v);
        const bhsTon = floatval(r[2]?.v);
        const yueTon = floatval(r[3]?.v);
        const totTon = floatval(r[4]?.v) || (isoTon + bhsTon + yueTon);
        monthlyTargets[key] = {
          ISOWA: round(isoTon * 1000),
          BHS: round(bhsTon * 1000),
          YUELI: round(yueTon * 1000),
          TOTAL: round(totTon * 1000)
        };
      }
      const liveData = {
        machines: defaultTargets.machines,
        monthlyTargets,
        source: 'google_sheet_target_output',
        updated_at: new Date().toISOString()
      };
      return makeJsonResponse({ success: true, data: liveData, source: 'google_sheet_live', is_default: false });
    }

    return makeJsonResponse({ success: true, data: defaultTargets, source: 'cached_file', is_default: false });
  }

  // ==========================================================
  // 3. จำลอง operating_days_api.php
  // ==========================================================
  async function handleOperatingDaysApi(urlObj, init) {
    const method = (init?.method || 'GET').toUpperCase();
    const staticData = await fetchStaticJson('data/operating_days.json');
    let current = staticData || {
      updated_at: new Date().toISOString(),
      defaultTargetDays: { BHS: 27, YUELI: 27, ISOWA: 27, TOTAL: 81 },
      monthlyTargetDays: {
        '2026-01': { ISOWA: 27, BHS: 27, YUELI: 27, TOTAL: 81 },
        '2026-02': { ISOWA: 25, BHS: 25, YUELI: 25, TOTAL: 75 },
        '2026-03': { ISOWA: 27, BHS: 27, YUELI: 27, TOTAL: 81 },
        '2026-04': { ISOWA: 24, BHS: 24, YUELI: 24, TOTAL: 72 },
        '2026-05': { ISOWA: 26, BHS: 26, YUELI: 26, TOTAL: 78 },
        '2026-06': { ISOWA: 26, BHS: 26, YUELI: 26, TOTAL: 78 },
        '2026-07': { ISOWA: 27, BHS: 27, YUELI: 27, TOTAL: 81 },
        '2026-08': { ISOWA: 26, BHS: 26, YUELI: 26, TOTAL: 78 },
        '2026-09': { ISOWA: 27, BHS: 27, YUELI: 27, TOTAL: 81 },
        '2026-10': { ISOWA: 27, BHS: 27, YUELI: 27, TOTAL: 81 },
        '2026-11': { ISOWA: 26, BHS: 26, YUELI: 26, TOTAL: 78 },
        '2026-12': { ISOWA: 24, BHS: 24, YUELI: 24, TOTAL: 72 }
      },
      records: {}
    };

    try {
      const saved = localStorage.getItem('operating_days_store_static');
      if (saved) current = Object.assign({}, current, JSON.parse(saved));
    } catch (e) {}

    if (method === 'POST') {
      let payload = {};
      try { payload = JSON.parse(init.body || '{}'); } catch (e) {}
      const monthKey = (payload.monthKey || '').trim();
      if (monthKey) {
        if (!current.records) current.records = {};
        if (!current.records[monthKey]) {
          current.records[monthKey] = { targetDays: current.defaultTargetDays, BHS: [], YUELI: [], ISOWA: [] };
        }
        if (payload.matrix) {
          ['BHS', 'YUELI', 'ISOWA'].forEach(m => {
            if (Array.isArray(payload.matrix[m])) current.records[monthKey][m] = payload.matrix[m];
          });
        }
        if (payload.targetDays) {
          current.records[monthKey].targetDays = payload.targetDays;
          current.monthlyTargetDays[monthKey] = payload.targetDays;
        }
      }
      if (payload.monthlyTargetDays) {
        Object.assign(current.monthlyTargetDays, payload.monthlyTargetDays);
      }
      current.updated_at = new Date().toISOString();
      try { localStorage.setItem('operating_days_store_static', JSON.stringify(current)); } catch (e) {}
      return makeJsonResponse({ success: true, data: current });
    }

    const monthKey = (urlObj.searchParams.get('monthKey') || '').trim();
    if (monthKey) {
      const monthRecord = current.records?.[monthKey] || null;
      const monthTargets = current.monthlyTargetDays?.[monthKey] || monthRecord?.targetDays || current.defaultTargetDays;
      return makeJsonResponse({
        success: true,
        monthKey,
        data: monthRecord,
        targetDays: monthTargets,
        defaultTargetDays: current.defaultTargetDays,
        monthlyTargetDays: current.monthlyTargetDays
      });
    }
    return makeJsonResponse({ success: true, data: current });
  }

  // ==========================================================
  // 4. จำลอง targets_api.php
  // ==========================================================
  async function handleTargetsApi(urlObj, init) {
    const method = (init?.method || 'GET').toUpperCase();
    const staticData = await fetchStaticJson('data/targets.json');
    let current = staticData || {
      allWaste: 5.00,
      items: {
        ncp: 2.00, def: 1.10, web: 0.15, control: 1.01, om: 0.05,
        order: 0.20, core: 0.15, spec: 0.15, conveyor: 0.03, qc: 0.07, reject: 0.09
      },
      updated_at: new Date().toISOString()
    };

    try {
      const saved = localStorage.getItem('waste_targets_store_static');
      if (saved) current = Object.assign({}, current, JSON.parse(saved));
    } catch (e) {}

    if (method === 'POST') {
      let payload = {};
      try { payload = JSON.parse(init.body || '{}'); } catch (e) {}
      if (payload.action === 'reset') {
        localStorage.removeItem('waste_targets_store_static');
        return makeJsonResponse({ success: true, data: current });
      }
      if (payload.allWaste !== undefined) current.allWaste = round(payload.allWaste, 2);
      if (payload.items && typeof payload.items === 'object') {
        Object.keys(current.items).forEach(k => {
          if (payload.items[k] !== undefined) current.items[k] = round(payload.items[k], 2);
        });
      }
      current.updated_at = new Date().toISOString();
      try { localStorage.setItem('waste_targets_store_static', JSON.stringify(current)); } catch (e) {}
      return makeJsonResponse({ success: true, data: current });
    }

    return makeJsonResponse({ success: true, data: current, is_default: false });
  }

  // ==========================================================
  // 5. จำลอง budget_monthly_api.php, budget_api.php, budget_cost_detail_api.php
  // ==========================================================
  async function handleBudgetMonthlyApi(urlObj) {
    let reqMachine = (urlObj.searchParams.get('machine') || 'TOTAL').trim().toUpperCase();
    if (!['TOTAL', 'BHS', 'ISOWA', 'YUELI'].includes(reqMachine)) reqMachine = 'TOTAL';
    const db = await fetchStaticJson('data/budget_database_v2.json');
    if (db && db.machines && db.machines[reqMachine]) {
      return makeJsonResponse({
        success: true,
        machine: reqMachine,
        data: db.machines[reqMachine],
        machines: ['TOTAL', 'BHS', 'ISOWA', 'YUELI'],
        updated_at: db.updated_at || new Date().toISOString(),
        cached: true
      });
    }
    return makeJsonResponse({ success: false, error: 'Budget data unavailable' });
  }

  async function handleBudgetCostDetailApi(urlObj) {
    let reqMachine = (urlObj.searchParams.get('machine') || 'TOTAL').trim().toUpperCase();
    if (!['TOTAL', 'BHS', 'ISOWA', 'YUELI'].includes(reqMachine)) reqMachine = 'TOTAL';

    const db = await fetchStaticJson('data/budget_database_v2.json');
    const machData = db?.machines?.[reqMachine] || db?.machines?.TOTAL;
    if (machData && machData.cost_target) {
      const cTarget = machData.cost_target;
      const cActual = machData.cost_actual || {};
      const catNames = {
        coal: 'ถ่านหิน', glue: 'กาว', water: 'ค่าน้ำประปา', air: 'ค่าลม',
        elec: 'ค่าไฟฟ้า', repair: 'ค่าซ่อมแซมเครื่องจักร', ot: 'ค่าล่วงเวลา',
        labor: 'ค่าแรง', total: 'Total VC+FC'
      };
      const costs = {};
      Object.entries(catNames).forEach(([catKey, catLabel]) => {
        costs[catLabel] = {};
        let allTv = 0, allAv = 0, allTq = 0, allAq = 0;
        for (let m = 1; m <= 12; m++) {
          const t = cTarget[m]?.[catKey] || { val: 0, qty: 0, bkg: 0 };
          const a = cActual[m]?.[catKey] || { val: 0, qty: 0, bkg: 0 };
          const tv = t.val || 0, av = a.val || 0;
          const tbk = t.bkg || 0, abk = a.bkg || 0;
          const pct = (tv > 0 && av > 0) ? round((av / tv) * 100, 1) : null;
          costs[catLabel]['m' + m] = {
            target_value: tv > 0 ? round(tv) : null,
            target_bahtkg: tbk > 0 ? round(tbk, 3) : null,
            actual_value: av > 0 ? round(av) : null,
            actual_bahtkg: abk > 0 ? round(abk, 3) : null,
            pct_actual: pct
          };
          allTv += tv; allAv += av;
          allTq += (t.qty || 0); allAq += (a.qty || 0);
        }
        costs[catLabel].ALL = {
          target_value: allTv > 0 ? round(allTv) : null,
          target_bahtkg: allTq > 0 ? round(allTv / allTq, 3) : null,
          actual_value: allAv > 0 ? round(allAv) : null,
          actual_bahtkg: allAq > 0 ? round(allAv / allAq, 3) : null,
          pct_actual: (allTv > 0 && allAv > 0) ? round((allAv / allTv) * 100, 1) : null
        };
      });
      return makeJsonResponse({
        success: true,
        machine: reqMachine,
        costs,
        updated_at: db.updated_at || new Date().toISOString()
      });
    }

    const costDetail = await fetchStaticJson('data/budget_cost_detail.json');
    if (costDetail && costDetail.costs) {
      return makeJsonResponse({
        success: true,
        machine: reqMachine,
        costs: costDetail.costs,
        updated_at: costDetail.updated_at || new Date().toISOString()
      });
    }
    return makeJsonResponse({ success: false, error: 'No cost detail data' });
  }

  async function handleBudgetApi(urlObj) {
    let selectedMonth = parseInt(urlObj.searchParams.get('month') || String(new Date().getMonth() + 1), 10);
    if (isNaN(selectedMonth) || selectedMonth < 1 || selectedMonth > 12) selectedMonth = new Date().getMonth() + 1;
    let selectedMachine = (urlObj.searchParams.get('machine') || 'TOTAL').trim().toUpperCase();
    if (!['TOTAL', 'BHS', 'YUELI', 'ISOWA'].includes(selectedMachine)) selectedMachine = 'TOTAL';

    const productionMonths = {
      1:  { con_t: 7418912, con_a: 6949630, out_t: 6602831, out_a: 6137701, w11: 11.68, w485: 5.55 },
      2:  { con_t: 6848226, con_a: 5827824, out_t: 6094921, out_a: 5169130, w11: 11.30, w485: 5.20 },
      3:  { con_t: 7418912, con_a: 7128650, out_t: 6602831, out_a: 6357217, w11: 10.82, w485: 4.83 },
      4:  { con_t: 6562883, con_a: 6507650, out_t: 5840966, out_a: 5826254, w11: 10.47, w485: 4.89 },
      5:  { con_t: 7418912, con_a: 8272089, out_t: 6602831, out_a: 7417037, w11: 10.34, w485: 5.10 },
      6:  { con_t: 6848226, con_a: 7588223, out_t: 6094921, out_a: 6777593, w11: 10.68, w485: 5.19 },
      7:  { con_t: 7418912, con_a: 8165325, out_t: 6602831, out_a: 7293680, w11: 10.67, w485: 5.24 },
      8:  { con_t: 7133569, con_a: 7405017, out_t: 6348876, out_a: 6560576, w11: 10.34, w485: 5.95 },
      9:  { con_t: 7418912, con_a: 7115974, out_t: 6602831, out_a: 6321601, w11: 9.73,  w485: 5.84 },
      10: { con_t: 7418912, con_a: 7571110, out_t: 6602831, out_a: 6770906, w11: 10.57, w485: 5.52 },
      11: { con_t: 7133569, con_a: 8188675, out_t: 6348876, out_a: 7274966, w11: 11.16, w485: 5.74 },
      12: { con_t: 6562883, con_a: 7257540, out_t: 5840966, out_a: 6430414, w11: 11.40, w485: 5.81 }
    };
    const costTargets = { coal: 0.282, glue: 0.376, water: 0.005, air: 0.025, elec: 0.227, repair: 0.150, ot: 0.051, labor: 0.112, total: 1.228 };
    const costActuals = {
      1:  { coal: 0.236, glue: 0.509, water: 0.005, air: 0.022, elec: 0.218, repair: 0.207, ot: 0.066, labor: 0.113, total: 1.376 },
      2:  { coal: 0.248, glue: 0.356, water: 0.004, air: 0.015, elec: 0.177, repair: 0.188, ot: 0.056, labor: 0.104, total: 1.149 },
      3:  { coal: 0.225, glue: 0.378, water: 0.004, air: 0.017, elec: 0.166, repair: 0.162, ot: 0.059, labor: 0.099, total: 1.109 },
      4:  { coal: 0.327, glue: 0.379, water: 0.005, air: 0.021, elec: 0.217, repair: 0.190, ot: 0.062, labor: 0.108, total: 1.309 },
      5:  { coal: 0.234, glue: 0.328, water: 0.004, air: 0.017, elec: 0.160, repair: 0.174, ot: 0.052, labor: 0.089, total: 1.066 },
      6:  { coal: 0.245, glue: 0.328, water: 0.005, air: 0.017, elec: 0.172, repair: 0.256, ot: 0.059, labor: 0.127, total: 1.209 },
      7:  { coal: 0.256, glue: 0.368, water: 0.004, air: 0.012, elec: 0.124, repair: 0.094, ot: 0.047, labor: 0.098, total: 1.013 },
      8:  { coal: 0.248, glue: 0.405, water: 0.004, air: 0.014, elec: 0.159, repair: 0.125, ot: 0.047, labor: 0.109, total: 1.111 },
      9:  { coal: 0.259, glue: 0.372, water: 0.004, air: 0.016, elec: 0.192, repair: 0.172, ot: 0.056, labor: 0.113, total: 1.183 },
      10: { coal: 0.201, glue: 0.374, water: 0.004, air: 0.017, elec: 0.190, repair: 0.245, ot: 0.046, labor: 0.089, total: 1.166 },
      11: { coal: 0.227, glue: 0.421, water: 0.004, air: 0.020, elec: 0.209, repair: 0.191, ot: 0.049, labor: 0.096, total: 1.207 },
      12: { coal: 0.241, glue: 0.366, water: 0.004, air: 0.017, elec: 0.199, repair: 0.143, ot: 0.052, labor: 0.093, total: 1.164 }
    };
    const financialMonths = {
      1:  { paper: -241436,  running: 1407369,  net: 1165932 },
      2:  { paper: -1794292, running: -1200913, net: -2995205 },
      3:  { paper: 118980,   running: -593332,  net: -474352 },
      4:  { paper: 504911,   running: -1290269, net: -785358 },
      5:  { paper: 845005,   running: -576945,  net: 268060 },
      6:  { paper: 757618,   running: -1018710, net: -261092 },
      7:  { paper: -791269,  running: -3516019, net: -4307288 },
      8:  { paper: 242033,   running: -1407204, net: -1165170 },
      9:  { paper: -212018,  running: -1299787, net: -1511805 },
      10: { paper: 0,        running: -4703785, net: 0 },
      11: { paper: 0,        running: -2835487, net: 0 },
      12: { paper: 0,        running: -34879,   net: 0 }
    };

    const db = await fetchStaticJson('data/budget_database_v2.json');
    const mMach = db?.machines?.[selectedMachine] || db?.machines?.TOTAL;
    if (mMach) {
      const mFin = mMach.financials;
      const mCt = mMach.cost_target;
      const mCa = mMach.cost_actual;
      if (mFin) {
        for (let i = 1; i <= 12; i++) {
          financialMonths[i] = {
            paper: round(mFin.profit_paper?.actual?.[i] || 0),
            running: round(mFin.running_cost?.actual?.[i] || 0),
            net: round(mFin.profit_net?.actual?.[i] || 0)
          };
        }
      }
      if (mCt && mCa && mCa[selectedMonth]) {
        ['coal', 'glue', 'water', 'air', 'elec', 'repair', 'ot', 'labor'].forEach(ck => {
          if (mCt[selectedMonth]?.[ck]?.bkg > 0) costTargets[ck] = mCt[selectedMonth][ck].bkg;
          if (mCa[selectedMonth]?.[ck]?.bkg > 0) costActuals[selectedMonth][ck] = mCa[selectedMonth][ck].bkg;
        });
      }
    }

    const p = productionMonths[selectedMonth] || productionMonths[1];
    const c = costActuals[selectedMonth] || costActuals[1];
    const f = financialMonths[selectedMonth] || financialMonths[1];
    const t = costTargets;

    const costItem = (name, tVal, aVal) => {
      const diff = round(tVal - aVal, 3);
      const pass = diff >= 0;
      const pct = aVal > 0 ? round((tVal / aVal) * 100, 1) : 100;
      return { title: name, target: tVal, actual: aVal, unit: 'บาท/kg', status: pass ? 'ได้เป้า' : 'เกินเป้า', achievedPct: pct, diff, pass, isCurrency: true };
    };

    const w11Pass = p.w11 <= 11.00;
    const w485Pass = p.w485 <= 4.85;
    const monthData = {
      consum: { title: 'Consumtion', target: p.con_t, actual: p.con_a, unit: 'กก.', status: 'ผลิตได้', achievedPct: round((p.con_a / p.con_t) * 100), diff: p.con_a - p.con_t, pass: true, isCurrency: false },
      output: { title: 'Out Put', target: p.out_t, actual: p.out_a, unit: 'กก.', status: 'ผลิตได้', achievedPct: round((p.out_a / p.out_t) * 100), diff: p.out_a - p.out_t, pass: true, isCurrency: false },
      waste11: { title: 'Waste 11.00%', target: 11.00, actual: p.w11, unit: '%', status: w11Pass ? 'ได้เป้า' : 'เกินเป้า', achievedPct: round((11.00 / p.w11) * 100), diff: round(11.00 - p.w11, 2), pass: w11Pass, isCurrency: false },
      waste485: { title: 'Waste 4.85%', target: 4.85, actual: p.w485, unit: '%', status: w485Pass ? 'ได้เป้า' : 'เกินเป้า', achievedPct: round((4.85 / p.w485) * 100), diff: round(4.85 - p.w485, 2), pass: w485Pass, isCurrency: false },
      cost_coal: costItem('ถ่านหิน', t.coal, c.coal),
      cost_glue: costItem('กาว', t.glue, c.glue),
      cost_water: costItem('ค่าน้ำประปา', t.water, c.water),
      cost_air: costItem('ค่าลม', t.air, c.air),
      cost_elec: costItem('ค่าไฟฟ้า', t.elec, c.elec),
      cost_repair: costItem('ค่าซ่อมแซม', t.repair, c.repair),
      cost_ot: costItem('ค่าล่วงเวลา', t.ot, c.ot),
      cost_labor: costItem('ค่าแรง', t.labor, c.labor),
      budget_b_kg: costItem('Budjet B/kg', t.total, c.total),
      profit_paper: { title: 'กำไรค่ากระดาษ', target: null, actual: f.paper, unit: 'บาท', status: f.paper < 0 ? 'ติดลบ' : 'ได้กำไร', note: f.paper < 0 ? 'คุมของเสียเกินเป้าหมาย' : 'ตามเป้าหมาย', diff: null, pass: f.paper >= 0, isCurrency: true },
      running_cost: { title: 'Runing cost', target: null, actual: f.running, unit: 'บาท', status: f.running < 0 ? 'ติดลบ' : 'ได้กำไร', note: f.running < 0 ? 'รวมรายได้หักค่าใช้จ่าย' : 'ตามเป้าหมาย', diff: null, pass: f.running >= 0, isCurrency: true },
      net_profit: { title: 'กำไรสุทธิ', target: null, actual: f.net, unit: 'บาท', status: f.net < 0 ? 'ติดลบ' : 'ได้กำไร', note: f.net < 0 ? 'รวมค่าต้นทุนกระดาษหัก...' : 'ตามเป้าหมาย', diff: null, pass: f.net >= 0, isCurrency: true }
    };

    let passCount = 0, failCount = 0, totalCount = 0;
    const diffChartItems = [];
    Object.entries(monthData).forEach(([key, item]) => {
      if (item.pass !== undefined) {
        totalCount++;
        if (item.pass) passCount++; else failCount++;
      }
      if (['cost_coal', 'cost_glue', 'cost_water', 'cost_air', 'cost_elec', 'cost_repair', 'cost_ot', 'cost_labor'].includes(key)) {
        diffChartItems.push({ key, name: item.title, diff: floatval(item.diff), target: floatval(item.target), actual: floatval(item.actual), pass: item.pass });
      }
    });
    const overallPct = totalCount > 0 ? round((passCount / totalCount) * 100) : 0;

    return makeJsonResponse({
      success: true,
      month: selectedMonth,
      machine: selectedMachine,
      summary: { passCount, failCount, totalCount, overallPct, scoreText: `${overallPct}%`, resultText: `ผลลัพธ์ ${passCount} / ${failCount}` },
      items: monthData,
      chart: diffChartItems,
      updated_at: new Date().toISOString()
    });
  }

  async function handleUploadApi(urlObj, init) {
    const method = (init?.method || 'GET').toUpperCase();
    const staticImp = await fetchStaticJson('data/imported_data.json');
    let current = staticImp || { con_out: {}, waste: {} };
    try {
      const saved = localStorage.getItem('imported_data_store_static');
      if (saved) current = Object.assign({}, current, JSON.parse(saved));
    } catch (e) {}

    if (method === 'POST') {
      let payload = {};
      try { payload = JSON.parse(init.body || '{}'); } catch (e) {}
      if (payload.action === 'clear') {
        localStorage.removeItem('imported_data_store_static');
        return makeJsonResponse({ success: true, message: 'ล้างข้อมูลนำเข้าเรียบร้อยแล้ว' });
      }
      Object.assign(current, payload);
      try { localStorage.setItem('imported_data_store_static', JSON.stringify(current)); } catch (e) {}
      return makeJsonResponse({ success: true, data: current });
    }
    return makeJsonResponse({ success: true, data: current });
  }

  // ==========================================================
  // ตัวดักจับ window.fetch (Interceptor)
  // ==========================================================
  function matchPhpRoute(urlStr) {
    try {
      const urlObj = new URL(urlStr, window.location.href);
      const path = urlObj.pathname;
      if (path.endsWith('/api.php') || path === 'api.php') return { handler: handleApiPhp, urlObj };
      if (path.endsWith('/output_targets_api.php') || path === 'output_targets_api.php') return { handler: handleOutputTargetsApi, urlObj };
      if (path.endsWith('/operating_days_api.php') || path === 'operating_days_api.php') return { handler: handleOperatingDaysApi, urlObj };
      if (path.endsWith('/targets_api.php') || path === 'targets_api.php') return { handler: handleTargetsApi, urlObj };
      if (path.endsWith('/budget_monthly_api.php') || path === 'budget_monthly_api.php') return { handler: handleBudgetMonthlyApi, urlObj };
      if (path.endsWith('/budget_cost_detail_api.php') || path === 'budget_cost_detail_api.php') return { handler: handleBudgetCostDetailApi, urlObj };
      if (path.endsWith('/budget_api.php') || path === 'budget_api.php') return { handler: handleBudgetApi, urlObj };
      if (path.endsWith('/upload_api.php') || path === 'upload_api.php') return { handler: handleUploadApi, urlObj };
    } catch (e) {}
    return null;
  }

  const isStaticHost = /\.github\.io$|\.vercel\.app$|\.pages\.dev$|\.netlify\.app$/i.test(window.location.hostname) || window.location.protocol === 'file:';

  window.fetch = async function (input, init) {
    const urlStr = typeof input === 'string' ? input : (input?.url || '');
    const matched = matchPhpRoute(urlStr);

    if (!matched) {
      return origFetch(input, init);
    }

    // ถ้าอยู่บน GitHub Pages / Vercel / Cloudflare Pages / Netlify หรือเปิดไฟล์ตรง ให้ใช้ Static Engine ทันที
    if (isStaticHost) {
      return matched.handler(matched.urlObj, init);
    }

    // ถ้าอยู่บนเซิร์ฟเวอร์อื่น ลองเรียก PHP ก่อน หากไม่สำเร็จหรือคืนค่าเป็นโค้ด <?php ให้สลับมาใช้ Static Engine อัตโนมัติ
    try {
      const res = await origFetch(input, init);
      if (!res.ok) {
        return matched.handler(matched.urlObj, init);
      }
      const cloned = res.clone();
      const txt = await cloned.text();
      if (txt.trim().startsWith('<?php') || txt.trim().startsWith('<html') || txt.trim().startsWith('<!DOCTYPE')) {
        return matched.handler(matched.urlObj, init);
      }
      return res;
    } catch (e) {
      return matched.handler(matched.urlObj, init);
    }
  };
})();
