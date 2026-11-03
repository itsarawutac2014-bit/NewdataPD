/**
 * =========================================================================
 * Google Sheet Two-Way Sync Module for Workforce & Attendance (ฝ่ายผลิต PD)
 * =========================================================================
 */
const GoogleSheetSync = (function() {
  const WEBHOOK_KEY = 'pd_google_sheet_webhook_url';
  const LAST_SYNC_KEY = 'pd_google_sheet_last_sync';

  function getWebhookUrl() {
    return (localStorage.getItem(WEBHOOK_KEY) || '').trim();
  }

  function setWebhookUrl(url) {
    if (url) {
      localStorage.setItem(WEBHOOK_KEY, url.trim());
    } else {
      localStorage.removeItem(WEBHOOK_KEY);
    }
  }

  function hasWebhook() {
    const url = getWebhookUrl();
    return url.length > 10 && url.startsWith('http');
  }

  /**
   * ส่งข้อมูลไปยัง Google Apps Script Web App
   * ใช้ Content-Type: text/plain และ mode: no-cors เพื่อเลี่ยง CORS preflight options
   */
  async function postToWebhook(payload) {
    const url = getWebhookUrl();
    if (!url) {
      return { success: false, reason: 'no_webhook' };
    }

    try {
      // ส่ง payload
      await fetch(url, {
        method: 'POST',
        mode: 'no-cors',
        headers: {
          'Content-Type': 'text/plain;charset=utf-8'
        },
        body: JSON.stringify(payload)
      });

      localStorage.setItem(LAST_SYNC_KEY, new Date().toISOString());
      return { success: true };
    } catch (err) {
      console.warn('Google Sheet Webhook Sync Warning:', err);
      return { success: false, error: err };
    }
  }

  /**
   * ซิงค์พนักงานใหม่ 1 คนไปยัง Google Sheet
   */
  async function syncEmployee(employee) {
    if (!hasWebhook()) return false;
    showToast('กำลังส่งข้อมูลพนักงานไปที่ Google Sheet...', 'info');
    const res = await postToWebhook({
      action: 'add_employee',
      employee: employee
    });
    if (res.success) {
      showToast(`บันทึก ${employee.first_name || ''} ลง Google Sheet สำเร็จ ✅`, 'success');
    }
    return res.success;
  }

  /**
   * ซิงค์การลงเวลาทำงาน 1 รายการไปยัง Google Sheet
   */
  async function syncAttendance(record) {
    if (!hasWebhook()) return false;
    const res = await postToWebhook({
      action: 'save_attendance',
      record: record
    });
    return res.success;
  }

  /**
   * ซิงค์การลงเวลาทำงานแบบกลุ่ม (Bulk Attendance)
   */
  async function syncBulkAttendance(records) {
    if (!hasWebhook() || !records || records.length === 0) return false;
    showToast(`กำลังส่งบันทึกการทำงาน ${records.length} รายการไป Google Sheet...`, 'info');
    const res = await postToWebhook({
      action: 'bulk_attendance',
      records: records
    });
    if (res.success) {
      showToast(`บันทึกการทำงาน ${records.length} รายการลง Google Sheet สำเร็จ ✅`, 'success');
    }
    return res.success;
  }

  /**
   * แสดง Toast Notification สวยงามที่มุมขวาล่าง
   */
  function showToast(message, type = 'info') {
    let toastContainer = document.getElementById('gs-toast-container');
    if (!toastContainer) {
      toastContainer = document.createElement('div');
      toastContainer.id = 'gs-toast-container';
      toastContainer.className = 'fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none';
      document.body.appendChild(toastContainer);
    }

    const toast = document.createElement('div');
    const colors = {
      success: 'bg-emerald-600 text-white border-emerald-700',
      info: 'bg-indigo-600 text-white border-indigo-700',
      warning: 'bg-amber-600 text-white border-amber-700',
      error: 'bg-rose-600 text-white border-rose-700'
    };
    const icons = {
      success: 'fa-check-circle',
      info: 'fa-circle-info',
      warning: 'fa-triangle-exclamation',
      error: 'fa-circle-xmark'
    };

    toast.className = `flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg border text-sm font-bold pointer-events-auto transform transition-all duration-300 translate-y-2 opacity-0 ${colors[type] || colors.info}`;
    toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i> <span>${message}</span>`;
    toastContainer.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.remove('translate-y-2', 'opacity-0');
    });

    setTimeout(() => {
      toast.classList.add('opacity-0', 'translate-y-2');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }

  /**
   * เปิด Modal ตั้งค่าการเชื่อมต่อ Google Sheet
   */
  function openSettingsModal() {
    let modal = document.getElementById('gs-sync-settings-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'gs-sync-settings-modal';
      modal.className = 'fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4';
      document.body.appendChild(modal);
    }

    const currentUrl = getWebhookUrl();
    const lastSync = localStorage.getItem(LAST_SYNC_KEY);
    const lastSyncText = lastSync ? new Date(lastSync).toLocaleString('th-TH') : 'ยังไม่มีการซิงค์';
    const isConnected = hasWebhook();

    modal.innerHTML = `
      <div class="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 bg-gradient-to-r from-emerald-600 to-teal-700 text-white flex justify-between items-center">
          <div class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center text-xl">
              <i class="fa-solid fa-file-excel"></i>
            </span>
            <div>
              <h3 class="font-black text-lg">ตั้งค่าเชื่อมต่อ Google Sheet</h3>
              <p class="text-xs text-emerald-100 font-medium">ระเบียนพนักงาน & บันทึกการทำงานฝ่ายผลิต PD</p>
            </div>
          </div>
          <button onclick="GoogleSheetSync.closeSettingsModal()" class="text-white/80 hover:text-white text-xl p-1">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5 overflow-y-auto">
          <!-- Status Banner -->
          <div class="flex items-center gap-3 p-3.5 rounded-xl border ${isConnected ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-amber-50 border-amber-200 text-amber-800'}">
            <i class="fa-solid ${isConnected ? 'fa-circle-check text-emerald-600' : 'fa-triangle-exclamation text-amber-600'} text-2xl"></i>
            <div>
              <p class="font-black text-sm">${isConnected ? 'เชื่อมต่อ Google Sheet แล้ว ✅' : 'ยังไม่ได้เชื่อมต่อ Google Sheet'}</p>
              <p class="text-xs ${isConnected ? 'text-emerald-700' : 'text-amber-700'}">
                ${isConnected ? `ซิงค์ล่าสุดเมื่อ: ${lastSyncText}` : 'ข้อมูลจะถูกบันทึกในเบราว์เซอร์เครื่องนี้เท่านั้น (LocalStorage)'}
              </p>
            </div>
          </div>

          <!-- Input Webhook URL -->
          <div class="space-y-1.5">
            <label class="block text-xs font-black text-slate-700 uppercase tracking-wider">
              Google Apps Script Web App URL
            </label>
            <input id="gs-webhook-input" type="url" value="${currentUrl}" placeholder="https://script.google.com/macros/s/.../exec"
              class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500 bg-slate-50">
            <p class="text-[11px] text-slate-500 font-medium">
              วาง URL ของเว็บแอปพลิเคชันที่ Deploy จาก Google Apps Script (เลือก Execute as: Me และ Who has access: Anyone)
            </p>
          </div>

          <!-- Instructions Box -->
          <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs text-slate-600 space-y-2">
            <p class="font-bold text-slate-800 flex items-center gap-1.5">
              <i class="fa-solid fa-circle-question text-emerald-600"></i> ขั้นตอนการเชื่อมต่อง่ายๆ:
            </p>
            <ol class="list-decimal list-inside space-y-1 pl-1 text-[11px] leading-relaxed">
              <li>เปิด Google Sheet ของคุณ > ไปที่เมนู <b>"ส่วนขยาย" (Extensions) > "Apps Script"</b></li>
              <li>คัดลอกโค้ดจากไฟล์ <code>google_apps_script_attendance.js</code> ในระบบไปวางทั้งหมด</li>
              <li>เลือกรันฟังก์ชัน <b>setupSheetAndSeedEmployees</b> 1 ครั้ง (ระบบจะสร้างชีตและลงข้อมูลพนักงาน 41 คนให้ทันที)</li>
              <li>กด <b>"การทำให้ใช้งานได้" (Deploy) > "เว็บแอปพลิเคชัน"</b> แล้วนำ URL มาวางที่นี่</li>
            </ol>
          </div>

          <!-- Direct Download CSV fallback -->
          <div class="pt-2 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <span class="text-slate-500 font-medium">หรือนำเข้าไฟล์ CSV โดยตรง:</span>
            <div class="flex items-center gap-2">
              <a href="assets/data/ระเบียนพนักงาน_datamanbhs.csv" download class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold border border-slate-300">
                <i class="fa-solid fa-download text-xs text-emerald-600"></i> พนักงาน (41 คน)
              </a>
              <a href="assets/data/บันทึกการทำงาน_datamanbhs.csv" download class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold border border-slate-300">
                <i class="fa-solid fa-download text-xs text-emerald-600"></i> บันทึกงาน (195 แถว)
              </a>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3.5 bg-slate-100 border-t border-slate-200 flex justify-between items-center gap-2">
          ${isConnected ? `
            <button onclick="GoogleSheetSync.disconnect()" type="button" class="text-xs text-rose-600 hover:text-rose-700 font-bold">
              <i class="fa-solid fa-link-slash mr-1"></i> ยกเลิกการเชื่อมต่อ
            </button>
          ` : `<div></div>`}
          <div class="flex items-center gap-2">
            <button onclick="GoogleSheetSync.closeSettingsModal()" type="button" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-200">
              ยกเลิก
            </button>
            <button onclick="GoogleSheetSync.saveModalSettings()" type="button" class="px-4 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs">
              <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก URL
            </button>
          </div>
        </div>
      </div>
    `;

    modal.classList.remove('hidden');
  }

  function closeSettingsModal() {
    const modal = document.getElementById('gs-sync-settings-modal');
    if (modal) modal.classList.add('hidden');
  }

  function saveModalSettings() {
    const input = document.getElementById('gs-webhook-input');
    if (!input) return;
    const url = input.value.trim();
    if (url && !url.startsWith('https://script.google.com/')) {
      alert('กรุณากรอก URL ที่ขึ้นต้นด้วย https://script.google.com/macros/s/.../exec');
      return;
    }
    setWebhookUrl(url);
    closeSettingsModal();
    showToast(url ? 'บันทึกการเชื่อมต่อ Google Sheet เรียบร้อยแล้ว ✅' : 'ลบการเชื่อมต่อแล้ว', 'success');
    updateBadges();
  }

  function disconnect() {
    if (confirm('คุณต้องการยกเลิกการเชื่อมต่อ Google Sheet หรือไม่?')) {
      setWebhookUrl('');
      closeSettingsModal();
      showToast('ยกเลิกการเชื่อมต่อ Google Sheet แล้ว', 'info');
      updateBadges();
    }
  }

  function updateBadges() {
    const badges = document.querySelectorAll('.gs-sync-badge-target');
    const isConn = hasWebhook();
    badges.forEach(b => {
      b.innerHTML = isConn 
        ? `<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-2xs cursor-pointer hover:bg-emerald-200 transition" onclick="GoogleSheetSync.openSettingsModal()" title="คลิกเพื่อตั้งค่า Google Sheet"><i class="fa-solid fa-cloud text-emerald-600"></i> Google Sheet: เชื่อมต่อแล้ว</span>`
        : `<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-300 shadow-2xs cursor-pointer hover:bg-slate-200 transition" onclick="GoogleSheetSync.openSettingsModal()" title="คลิกเพื่อเชื่อมต่อ Google Sheet"><i class="fa-solid fa-cloud-arrow-up text-slate-400"></i> เชื่อมต่อ Google Sheet</span>`;
    });
  }

  // Initial badge update on load
  document.addEventListener('DOMContentLoaded', updateBadges);

  return {
    getWebhookUrl,
    setWebhookUrl,
    hasWebhook,
    syncEmployee,
    syncAttendance,
    syncBulkAttendance,
    openSettingsModal,
    closeSettingsModal,
    saveModalSettings,
    disconnect,
    updateBadges
  };
})();
