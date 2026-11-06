/**
 * Security & Access Control System (Authentication & RBAC)
 * For NewdataPD & datamanbhs Integration
 */

(function() {
  const USERS_STORAGE_KEY = 'datamanbhs_users_v2';
  const SESSION_STORAGE_KEY = 'datamanbhs_auth_session';

  // Standard Page Modules Definition
  const MODULES = [
    { key: 'waste',       label: 'Waste Dashboard',  icon: 'fa-recycle',          file: 'index.html' },
    { key: 'output',      label: 'ติดตาม Output',    icon: 'fa-truck-ramp-box',   file: 'output.html' },
    { key: 'budget',      label: 'Budget Control',   icon: 'fa-calculator',       file: 'budget.html' },
    { key: 'targets',     label: 'ตั้งค่าเป้าหมาย',  icon: 'fa-bullseye',         file: 'targets.html' }
  ];

  // Default Seed Users (Admin: 12345, Staff: staff, Art: 12345)
  const DEFAULT_USERS = [
    {
      id: 1,
      username: 'Admin',
      password: '12345',
      full_name: 'ผู้ดูแลระบบสูงสุด (Master Admin)',
      role: 'admin',
      can_edit: 1,
      accessible_pages: ['dashboard', 'employees', 'chart', 'attendance', 'kpi', 'output', 'waste', 'budget', 'targets', 'users'],
      status: 'active',
      created_at: new Date().toISOString()
    },
    {
      id: 2,
      username: 'ART',
      password: '12345',
      full_name: 'อิศราวุธ คงเจริญ (Admin)',
      role: 'admin',
      can_edit: 1,
      accessible_pages: ['dashboard', 'employees', 'chart', 'attendance', 'kpi', 'output', 'waste', 'budget', 'targets', 'users'],
      status: 'active',
      created_at: new Date().toISOString()
    },
    {
      id: 3,
      username: 'staff',
      password: 'staff',
      full_name: 'เจ้าหน้าที่ทั่วไป (Staff)',
      role: 'user',
      can_edit: 0,
      accessible_pages: ['dashboard', 'employees', 'chart', 'attendance', 'output'],
      status: 'active',
      created_at: new Date().toISOString()
    },
    {
      id: 4,
      username: 'hostbhs',
      password: '123',
      full_name: 'หัวหน้ากะ BHS',
      role: 'user',
      can_edit: 1,
      accessible_pages: ['dashboard', 'employees', 'chart', 'attendance', 'kpi', 'output'],
      status: 'active',
      created_at: new Date().toISOString()
    }
  ];

  function getUsers() {
    const raw = localStorage.getItem(USERS_STORAGE_KEY);
    if (!raw) {
      localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(DEFAULT_USERS));
      return DEFAULT_USERS;
    }
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed) && parsed.length > 0) return parsed;
    } catch(e) {}
    localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(DEFAULT_USERS));
    return DEFAULT_USERS;
  }

  function saveUsers(users) {
    localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(users));
  }

  function getAuthUser() {
    const raw = localStorage.getItem(SESSION_STORAGE_KEY) || sessionStorage.getItem(SESSION_STORAGE_KEY);
    if (!raw) return null;
    try {
      const u = JSON.parse(raw);
      // Validate still active in database
      const users = getUsers();
      const current = users.find(x => x.id === u.id || x.username.toLowerCase() === u.username.toLowerCase());
      if (!current || current.status !== 'active') {
        logout(false);
        return null;
      }
      return current;
    } catch(e) {
      return null;
    }
  }

  function setAuthUser(user, remember = true) {
    const data = JSON.stringify(user);
    if (remember) {
      localStorage.setItem(SESSION_STORAGE_KEY, data);
    } else {
      sessionStorage.setItem(SESSION_STORAGE_KEY, data);
    }
  }

  function isLoggedIn() {
    return getAuthUser() !== null;
  }

  function isAdmin() {
    const u = getAuthUser();
    return u !== null && u.role === 'admin';
  }

  function canAccessPage(pageKey) {
    const u = getAuthUser();
    if (!u) return false;
    if (u.role === 'admin') return true;
    if (!Array.isArray(u.accessible_pages)) return false;
    return u.accessible_pages.includes(pageKey);
  }

  function login(username, password) {
    const users = getUsers();
    const uname = (username || '').trim().toLowerCase();
    const pass = (password || '').trim();

    const user = users.find(u => u.username.toLowerCase() === uname);
    if (!user) {
      return { success: false, message: 'ไม่พบชื่อผู้ใช้งานนี้ในระบบ' };
    }
    if (user.status !== 'active') {
      return { success: false, message: 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ' };
    }
    if (user.password !== pass) {
      return { success: false, message: 'รหัสผ่านไม่ถูกต้อง' };
    }

    setAuthUser(user, true);
    return { success: true, user: user };
  }

  function logout(redirectToLogin = true) {
    localStorage.removeItem(SESSION_STORAGE_KEY);
    sessionStorage.removeItem(SESSION_STORAGE_KEY);
    if (redirectToLogin) {
      window.location.href = 'login.html';
    }
  }

  /**
   * Check access and gatekeep page
   * @param {string} pageKey - e.g. 'dashboard', 'attendance', 'employees', 'chart', 'kpi', 'users'
   */
  function requireAuth(pageKey) {
    const u = getAuthUser();
    const rawPop = window.location.pathname.split('/').pop();
    const currentPath = (!rawPop || rawPop === 'dashboard.html') ? 'index.html' : rawPop;

    if (!u) {
      // Not logged in -> Redirect to login.html with return url
      const redirectParam = encodeURIComponent(currentPath + window.location.search);
      window.location.replace(`login.html?redirect=${redirectParam}`);
      return false;
    }

    // Check permission
    if (pageKey && !canAccessPage(pageKey)) {
      document.addEventListener('DOMContentLoaded', () => {
        renderAccessDenied(pageKey, u);
      });
      return false;
    }

    // Insert user badge & logout button in navbar if exists
    document.addEventListener('DOMContentLoaded', () => {
      injectNavbarUserBadge(u);
    });

    return true;
  }

  function renderAccessDenied(pageKey, user) {
    const allowed = (user.role === 'admin') 
      ? MODULES 
      : MODULES.filter(m => (user.accessible_pages || []).includes(m.key));

    const linksHtml = allowed.map(m => `
      <a href="${m.file}" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-orange-50 hover:text-orange-700 rounded-xl text-xs font-bold text-slate-700 border border-slate-200 transition">
        <i class="fa-solid ${m.icon}"></i> ${m.label}
      </a>
    `).join('');

    document.body.innerHTML = `
      <div class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl border border-slate-200 shadow-2xl p-8 max-w-lg w-full text-center space-y-5 animate-scaleIn">
          <div class="w-20 h-20 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center text-3xl mx-auto border-4 border-rose-200">
            <i class="fa-solid fa-shield-halved"></i>
          </div>
          <div>
            <h2 class="text-xl font-black text-slate-900">ไม่มีสิทธิ์เข้าถึงหน้านี้ (Access Denied)</h2>
            <p class="text-xs text-slate-500 mt-1">
              บัญชี <strong>${user.full_name || user.username}</strong> ไม่มีสิทธิ์เข้าใช้งานหน้านี้
            </p>
          </div>
          <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-left text-xs space-y-2">
            <span class="font-bold text-slate-700 block">หน้าอื่นๆ ที่คุณมีสิทธิ์เข้าถึงได้:</span>
            <div class="flex flex-wrap gap-2 pt-1">
              ${linksHtml || '<span class="text-slate-400">ยังไม่ได้รับการกำหนดสิทธิ์</span>'}
            </div>
          </div>
          <div class="flex items-center justify-center gap-3 pt-2">
            <button onclick="Auth.logout()" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold transition">
              <i class="fa-solid fa-right-from-bracket mr-1"></i> ออกจากระบบ / สลับบัญชี
            </button>
          </div>
        </div>
      </div>
    `;
  }

  function injectNavbarUserBadge(user) {
    const navs = document.querySelectorAll('header nav');
    if (!navs.length) return;

    navs.forEach(nav => {
      // Check if user badge already exists
      if (nav.querySelector('#nav-user-badge')) return;

      const isAdminUser = user.role === 'admin';
      const badgeHtml = `
        <div id="nav-user-badge" class="flex items-center gap-2 pl-2 border-l border-slate-200 ml-1">
          <div class="hidden sm:flex flex-col text-right">
            <span class="text-xs font-bold text-slate-800 leading-tight">${user.full_name || user.username}</span>
            <span class="text-[10px] font-bold ${isAdminUser ? 'text-rose-600' : 'text-blue-600'}">${isAdminUser ? 'Admin' : 'Staff'}</span>
          </div>
          ${isAdminUser ? `
            <a href="users.html" class="p-1.5 rounded-xl bg-slate-100 hover:bg-orange-100 text-slate-700 hover:text-orange-700 text-xs transition border border-slate-200" title="จัดการผู้ใช้งาน">
              <i class="fa-solid fa-user-gear"></i>
            </a>
          ` : ''}
          <button onclick="Auth.logout()" class="p-1.5 px-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold transition border border-rose-200" title="ออกจากระบบ">
            <i class="fa-solid fa-right-from-bracket mr-1"></i> ออก
          </button>
        </div>
      `;
      nav.insertAdjacentHTML('beforeend', badgeHtml);
    });
  }

  // Export to Global Window Object
  window.Auth = {
    MODULES,
    getUsers,
    saveUsers,
    getAuthUser,
    isLoggedIn,
    isAdmin,
    canAccessPage,
    login,
    logout,
    requireAuth
  };

})();
