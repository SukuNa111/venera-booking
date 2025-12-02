/* ===== Modern Calendar — Day / Week / Month ===== */
// This version of calendar.js is placed under the `js` folder.  It is identical
// to the root-level calendar.js but adds support for doctor-specific working
// hours in the header labels and off‑hours shading.  When updating the
// application, ensure this file is served by index.php via
// `<script src="js/calendar.js?v=10"></script>` so that the latest
// improvements take effect.

// Base URL for API calls.  When this script is loaded from `index.php` in the
// `public` directory, relative paths resolve based on the page URL rather than
// the script location.  Therefore we reference `api.php` in the same folder
// as `index.php` (which is `public/api.php`).  Do not prefix with `../` or
// the calls will incorrectly point outside the public directory and fail.
const API = './api.php';

// Globals
let DOCTORS = [];
let CURRENT_DATE = new Date();
// Use server-provided clinic from window.CURRENT_CLINIC (set by index.php), otherwise default
let CURRENT_CLINIC = (typeof window !== 'undefined' && window.CURRENT_CLINIC) ? window.CURRENT_CLINIC : 'venera';

// Determine initial view mode.  If the page has defined
// `window.DEFAULT_VIEW_MODE` (set in index.php), honour that value.
// Fallback to week view when undefined.
let VIEW_MODE;
if (typeof window !== 'undefined' && window.DEFAULT_VIEW_MODE) {
  VIEW_MODE = window.DEFAULT_VIEW_MODE;
} else {
  VIEW_MODE = 'week';
}
const phoneLookupTimers = {};

const WORK_START = 9;
const WORK_END = 18;
const PX_PER_HOUR = 80; // Зүүн үнээс

/* ---- Utils ---- */
const q = s => document.querySelector(s);
const esc = s => (s ?? '').toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const fmtDate = d => {
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
};
const fetchJSON = (u, o = {}) =>
  fetch(u, Object.assign({ headers: { 'Content-Type': 'application/json' } }, o))
    .then(r => {
      return r.json();
    })
    .catch(e => {
      throw e;
    });
const hoursToY = t => {
  if (!t) return 0;
  const [h, m] = t.split(':').map(Number);
  return Math.max(0, (h * 60 + m - WORK_START * 60) * (PX_PER_HOUR / 60));
};
const minsBetween = (t1, t2) => {
  const [h1, m1] = t1.split(':').map(Number);
  const [h2, m2] = t2.split(':').map(Number);
  return h2 * 60 + m2 - (h1 * 60 + m1);
};
const isoMonday = d => {
  const x = new Date(d);
  const wd = x.getDay();
  const diff = wd === 0 ? -6 : 1 - wd;
  x.setDate(x.getDate() + diff);
  x.setHours(0, 0, 0, 0);
  return x;
};

/* Status styling - Modern gradient colors */
const statusConfig = {
  online: { color: '#3b82f6', bg: 'linear-gradient(135deg, #0f172a 0%, #1e293b 100%)', textColor: '#60a5fa', name: 'Онлайн', badge: '#1e40af' },
  arrived: { color: '#f59e0b', bg: 'linear-gradient(135deg, #1f1f1f 0%, #2d2d2d 100%)', textColor: '#fbbf24', name: 'Ирсэн', badge: '#a16207' },
  paid: { color: '#10b981', bg: 'linear-gradient(135deg, #0f2818 0%, #1a3a2a 100%)', textColor: '#6ee7b7', name: 'Төлсөн', badge: '#065f46' },
  pending: { color: '#a855f7', bg: 'linear-gradient(135deg, #2d1b69 0%, #3d2463 100%)', textColor: '#d8b4fe', name: 'Хүлээгдэж буй', badge: '#581c87' },
  cancelled: { color: '#ef4444', bg: 'linear-gradient(135deg, #3d0e0e 0%, #4a1515 100%)', textColor: '#f87171', name: 'Цуцлагдсан', badge: '#7f1d1d' }
};

function applyCssVars() {
  document.documentElement.style.setProperty('--hour-h', `${PX_PER_HOUR}px`);
}

/* ---- Loaders ---- */
async function loadDoctors() {
  try {
    const url = `${API}?action=doctors&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`;
    const r = await fetchJSON(url);
    DOCTORS = r?.ok ? r.data || [] : [];
    const options = (DOCTORS || []).map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    const selAdd = q('#doctor_id');
    const selEdit = q('#modalEdit select[name="doctor_id"]');
    if (selAdd) selAdd.innerHTML = options;
    if (selEdit) selEdit.innerHTML = options;
  } catch (e) {
    console.error('Error loading doctors:', e);
    showNotification('Error: Failed to load doctors');
  }
}

async function loadBookings() {
  if (VIEW_MODE === 'week') return loadWeekBookings();
  if (VIEW_MODE === 'month') return loadMonthBookings();
  const date = fmtDate(CURRENT_DATE);
  try {
    const r = await fetchJSON(`${API}?action=bookings&date=${date}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
    renderDayView(date, r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading bookings:', e);
    showNotification('Error: Failed to load bookings');
  }
}

async function loadWeekBookings() {
  const start = isoMonday(CURRENT_DATE);
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  try {
    const r = await fetchJSON(`${API}?action=bookings_week&start=${fmtDate(start)}&end=${fmtDate(end)}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
    renderWeekView(start, end, r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading week view:', e);
    showNotification('Error: Failed to load week view');
  }
}

async function loadMonthBookings() {
  const y = CURRENT_DATE.getFullYear();
  const m = String(CURRENT_DATE.getMonth() + 1).padStart(2, '0');
  try {
    const r = await fetchJSON(`${API}?action=bookings_month&month=${y}-${m}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
    renderMonthView(y, parseInt(m, 10), r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading month view:', e);
    showNotification('Error: Failed to load month view');
  }
}

/* ---- Renderers ---- */
function renderNoDoctors() {
  q('#dateLabel').textContent = `${fmtDate(CURRENT_DATE)}`;
  q('#timeCol').innerHTML = '';
  q('#calendarRow').innerHTML = `
    <div class="w-100 text-center text-muted p-5">
      <i class="fas fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i>
      <p>Энэ клиникт идэвхтэй эмч олдсонгүй.</p>
    </div>`;
}

function renderDayView(date, events) {
  if (!DOCTORS.length) return renderNoDoctors();
  q('#dateLabel').textContent = `📅 ${date}`;
  // Time column
  const timeCol = q('#timeCol');
  timeCol.innerHTML = '';
  for (let h = WORK_START - 1; h <= WORK_END; h++) {
    timeCol.innerHTML += `<div style="font-weight: 600; color: #94a3b8; font-size: 0.85rem;">${String(h).padStart(2, '0')}:00</div>`;
  }
  // Doctor columns
  const row = q('#calendarRow');
  row.innerHTML = '';
  (DOCTORS || []).forEach(d => {
    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #334155';
    col.style.backgroundColor = '#0f172a';
    const docColor = d.color || '#3b82f6';
    const dayOfWeek = new Date(date).getDay();
    const todayWorkHours = d.working_hours?.find(wh => parseInt(wh.day_of_week) === dayOfWeek);
    let workLabel = `${String(WORK_START).padStart(2, '0')}:00–${String(WORK_END).padStart(2, '0')}:00`;
    if (todayWorkHours) {
      if (parseInt(todayWorkHours.is_available) === 1) {
        workLabel = `${todayWorkHours.start_time}–${todayWorkHours.end_time}`;
      } else {
        workLabel = 'Ажиллахгүй';
      }
    }
    col.innerHTML = `
      <div class="head" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-bottom: 2px solid #334155;">
        <div style="font-weight: 700; margin-bottom: 0.5rem; color: #e2e8f0;">
          <i class="fas fa-user-md" style="color: ${docColor}; margin-right: 0.5rem;"></i>${esc(d.name)}
        </div>
        <span class="badge" style="background: ${docColor}40; color: ${docColor}; border: 1px solid ${docColor}60; padding: 0.4rem 0.8rem; font-size: 0.75rem;">
          ${workLabel}
        </span>
      </div>
      <div class="calendar-hours position-relative">
        <div class="calendar-grid"></div>
      </div>`;
    const hoursEl = col.querySelector('.calendar-hours');
    // Off‑hours shading
    const off = document.createElement('div');
    off.style.position = 'absolute';
    off.style.inset = '0';
    if (todayWorkHours && todayWorkHours.is_available) {
      const startH = todayWorkHours.start_time;
      const endH = todayWorkHours.end_time;
      off.style.background = `
        linear-gradient(to bottom,
          rgba(30, 41, 59, 0.8) 0,
          rgba(30, 41, 59, 0.8) ${hoursToY(startH)}px,
          transparent ${hoursToY(startH)}px,
          transparent ${hoursToY(endH)}px,
          rgba(30, 41, 59, 0.8) ${hoursToY(endH)}px,
          rgba(30, 41, 59, 0.8) 100%)`;
    } else {
      off.style.background = `
        linear-gradient(to bottom,
          rgba(30, 41, 59, 0.6) 0,
          rgba(30, 41, 59, 0.6) ${hoursToY(`${WORK_START}:00`)}px,
          transparent ${hoursToY(`${WORK_START}:00`)}px,
          transparent ${hoursToY(`${WORK_END}:00`)}px,
          rgba(30, 41, 59, 0.6) ${hoursToY(`${WORK_END}:00`)}px,
          rgba(30, 41, 59, 0.6) 100%)`;
    }
    off.style.pointerEvents = 'none';
    off.style.zIndex = '1';
    hoursEl.appendChild(off);
    // Add new booking on click
    hoursEl.addEventListener('click', e => {
      const rect = hoursEl.getBoundingClientRect();
      const y = e.clientY - rect.top + hoursEl.scrollTop;
      const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
      const hh = Math.floor(mins / 60);
      const mm = Math.floor((mins % 60) / 15) * 15;
      if (hh < WORK_START || hh >= WORK_END) return;
      const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
      const endH = hh + Math.floor((mm + 30) / 60);
      const endM = (mm + 30) % 60;
      const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      q('#doctor_id').value = d.id;
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = date;
      q('#start_time').value = start;
      q('#end_time').value = end;
      showModal('#modalAdd');
    });
    row.appendChild(col);
  });
  // Render events
  (events || []).forEach(ev => {
    const idx = (DOCTORS || []).findIndex(x => String(x.id) === String(ev.doctor_id));
    if (idx < 0) return;
    const col = document.querySelectorAll('.calendar-col')[idx];
    const hoursEl = col.querySelector('.calendar-hours');
    const cfg = statusConfig[ev.status] || statusConfig.online;
    const el = document.createElement('div');
    el.className = 'event';
    el.style.background = cfg.bg;
    el.style.borderLeft = `5px solid ${cfg.color}`;
    el.style.borderRadius = '8px';
    el.style.padding = '0.6rem 0.75rem';
    el.style.fontSize = '0.8rem';
    el.style.boxShadow = `0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px 0 ${cfg.color}40`;
    el.style.cursor = 'pointer';
    el.style.border = `1px solid ${cfg.color}40`;
    el.style.zIndex = '2';
    el.style.transition = 'all 0.3s ease';
    el.style.display = 'flex';
    el.style.flexDirection = 'column';
    const dur = Math.max(30, minsBetween(ev.start_time, ev.end_time));
    el.style.top = `${hoursToY(ev.start_time)}px`;
    el.style.height = `${Math.max(50, dur * (PX_PER_HOUR / 60))}px`;
    el.style.overflow = 'hidden';
    const tooltipLines = [ev.patient_name, ev.phone || '', ev.service_name ? `Үйлчилгээ: ${ev.service_name}` : '', `Тасаг: ${ev.department || '—'}`, `${ev.start_time}–${ev.end_time}`, `Статус: ${cfg.name}`].filter(Boolean);
    el.title = tooltipLines.join('\n');
    const serviceLabel = ev.service_name ? esc(ev.service_name) : 'Үйлчилгээ заагаагүй';
    const phoneLabel = esc(ev.phone || '—');
    el.innerHTML = `
      <div style="font-weight:700;color:${cfg.textColor};font-size:.9rem;line-height:1.2;display:flex;align-items:center;gap:6px;">
        <span>🕒</span>${ev.start_time}–${ev.end_time}
      </div>
      <div style="margin-top:6px;display:flex;align-items:center;gap:6px;color:#c7d2fe;font-weight:600;">
        <span>💆</span>
        <span style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${serviceLabel}</span>
      </div>
      <div style="margin-top:8px;font-size:.78rem;color:#cbd5e1;display:flex;align-items:center;gap:6px;">
        <span>📞</span><span>${phoneLabel}</span>
      </div>
    `;
    el.addEventListener('mouseenter', () => {
      el.style.boxShadow = `0 8px 25px rgba(0, 0, 0, 0.4), inset 0 1px 0 ${cfg.color}60`;
      el.style.transform = 'translateY(-2px)';
      el.style.borderColor = cfg.color;
    });
    el.addEventListener('mouseleave', () => {
      el.style.boxShadow = `0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px 0 ${cfg.color}40`;
      el.style.transform = 'translateY(0)';
      el.style.borderColor = `${cfg.color}40`;
    });
    el.addEventListener('click', e => {
      e.stopPropagation();
      openEdit(ev);
    });
    hoursEl.appendChild(el);
  });
  initScrollSync();
}

function renderWeekView(startDate, endDate, events) {
  q('#dateLabel').textContent = `📆 ${fmtDate(startDate)} → ${fmtDate(endDate)}`;
  const timeCol = q('#timeCol');
  timeCol.innerHTML = '';
  for (let h = WORK_START - 1; h <= WORK_END; h++) {
    timeCol.innerHTML += `<div style="font-weight: 600; color: #94a3b8; font-size: 0.85rem;">${String(h).padStart(2, '0')}:00</div>`;
  }
  const row = q('#calendarRow');
  row.innerHTML = '';
  const names = ['Дав', 'Мяг', 'Лха', 'Пүр', 'Баа', 'Бям', 'Ням'];
  for (let i = 0; i < 7; i++) {
    const d = new Date(startDate);
    d.setDate(d.getDate() + i);
    const ds = fmtDate(d);
    const dayOfWeek = d.getDay();
    const isToday = ds === fmtDate(new Date());
    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #334155';
    col.style.backgroundColor = '#0f172a';
    const headerBg = isToday ? 'linear-gradient(135deg, #1e3a8a 0%, #1e293b 100%)' : 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)';
    const headerColor = isToday ? '#60a5fa' : '#e2e8f0';
    col.innerHTML = `
      <div class="head text-center" style="background: ${headerBg}; border-bottom: 2px solid ${isToday ? '#3b82f6' : '#334155'};">
        <strong style="color: ${headerColor}; display: block; margin-bottom: 0.25rem;">${names[i]}</strong>
        <small style="color: ${isToday ? '#93c5fd' : '#94a3b8'}; opacity: 0.8;">${ds}</small>
      </div>
      <div class="calendar-hours position-relative"><div class="calendar-grid"></div></div>`;
    const hoursEl = col.querySelector('.calendar-hours');
    // Shade off‑hours based on doctors' schedules.  Overlay all doctors' unavailable times.
    (DOCTORS || [])
      .filter(doc => doc.working_hours && doc.working_hours.length > 0)
      .forEach(doc => {
        const whData = doc.working_hours.find(wh => parseInt(wh.day_of_week) === dayOfWeek);
        if (whData && parseInt(whData.is_available) === 1) {
          const [startH, startM] = whData.start_time.split(':').map(Number);
          const [endH, endM] = whData.end_time.split(':').map(Number);
          const startMins = startH * 60 + startM;
          const endMins = endH * 60 + endM;
          if (startMins > WORK_START * 60) {
            const offDiv = document.createElement('div');
            offDiv.style.position = 'absolute';
            offDiv.style.left = '0';
            offDiv.style.right = '0';
            offDiv.style.top = `${hoursToY(`${String(WORK_START).padStart(2, '0')}:00`)}px`;
            offDiv.style.height = `${(startMins - WORK_START * 60) * (PX_PER_HOUR / 60)}px`;
            offDiv.style.backgroundColor = 'rgba(107, 114, 128, 0.2)';
            offDiv.style.borderTop = '1px dashed #6b7280';
            offDiv.style.zIndex = '1';
            hoursEl.appendChild(offDiv);
          }
          if (endMins < WORK_END * 60) {
            const offDiv = document.createElement('div');
            offDiv.style.position = 'absolute';
            offDiv.style.left = '0';
            offDiv.style.right = '0';
            offDiv.style.top = `${hoursToY(whData.end_time)}px`;
            offDiv.style.height = `${(WORK_END * 60 - endMins) * (PX_PER_HOUR / 60)}px`;
            offDiv.style.backgroundColor = 'rgba(107, 114, 128, 0.2)';
            offDiv.style.borderBottom = '1px dashed #6b7280';
            offDiv.style.zIndex = '1';
            hoursEl.appendChild(offDiv);
          }
        } else if (whData && parseInt(whData.is_available) === 0) {
          const offDiv = document.createElement('div');
          offDiv.style.position = 'absolute';
          offDiv.style.left = '0';
          offDiv.style.right = '0';
          offDiv.style.top = '0';
          offDiv.style.height = '100%';
          offDiv.style.backgroundColor = 'rgba(107, 114, 128, 0.15)';
          offDiv.style.zIndex = '1';
          hoursEl.appendChild(offDiv);
        }
      });
    // Events for this day
    (events || [])
      .filter(ev => ev.date === ds)
      .forEach(ev => {
        const cfg = statusConfig[ev.status] || statusConfig.online;
        const el = document.createElement('div');
        el.className = 'event';
        el.style.background = cfg.bg;
        el.style.borderLeft = `4px solid ${cfg.color}`;
        el.style.borderRadius = '6px';
        el.style.padding = '0.5rem 0.6rem';
        el.style.fontSize = '0.75rem';
        el.style.boxShadow = `0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px 0 ${cfg.color}40`;
        el.style.cursor = 'pointer';
        el.style.border = `1px solid ${cfg.color}40`;
        el.style.zIndex = '2';
        el.style.transition = 'all 0.3s ease';
        el.style.display = 'flex';
        el.style.flexDirection = 'column';
        const dur = Math.max(15, minsBetween(ev.start_time, ev.end_time));
        el.style.top = `${hoursToY(ev.start_time)}px`;
        el.style.height = `${Math.max(45, dur * (PX_PER_HOUR / 60))}px`;
        const weekTooltip = [ev.patient_name, ev.phone || '', ev.service_name ? `Үйлчилгээ: ${ev.service_name}` : '', ev.department ? `Салаа: ${ev.department}` : '', `${ev.start_time}–${ev.end_time}`, `Эмч: ${ev.doctor_name}`, `Статус: ${cfg.name}`].filter(Boolean);
        el.title = weekTooltip.join('\n');
        const serviceLabel = ev.service_name ? esc(ev.service_name) : 'Үйлчилгээ заагаагүй';
        const phoneLabel = esc(ev.phone || '—');
        const deptLabel = ev.department ? esc(ev.department) : '';
        el.innerHTML = `
          <div style="font-weight:700;color:${cfg.textColor};display:flex;align-items:center;gap:4px;">🕒 ${ev.start_time}–${ev.end_time}</div>
          <div style="margin-top:4px;display:flex;align-items:center;gap:4px;color:#c7d2fe;font-size:0.75rem;font-weight:600;">
            <span>💆</span>
            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">${serviceLabel}</span>
          </div>
          ${deptLabel ? `<div style="margin-top:3px;color:#a78bfa;font-size:0.7rem;display:flex;align-items:center;gap:4px;">🏥 ${deptLabel}</div>` : ''}
          <div style="margin-top:6px;color:#cbd5e1;font-size:0.72rem;display:flex;align-items:center;gap:4px;">📞 ${phoneLabel}</div>`;
        el.addEventListener('mouseenter', () => {
          el.style.boxShadow = `0 8px 25px rgba(0, 0, 0, 0.4), inset 0 1px 0 ${cfg.color}60`;
          el.style.transform = 'translateY(-2px)';
        });
        el.addEventListener('mouseleave', () => {
          el.style.boxShadow = `0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px 0 ${cfg.color}40`;
          el.style.transform = 'translateY(0)';
        });
        el.addEventListener('click', e => {
          e.stopPropagation();
          openEdit(ev);
        });
        hoursEl.appendChild(el);
      });
    // Add booking on click
    hoursEl.addEventListener('click', e => {
      const rect = hoursEl.getBoundingClientRect();
      const y = e.clientY - rect.top + hoursEl.scrollTop;
      const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
      const hh = Math.floor(mins / 60);
      const mm = Math.floor((mins % 60) / 15) * 15;
      if (hh < WORK_START || hh >= WORK_END) return;
      const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
      const endH = hh + Math.floor((mm + 30) / 60);
      const endM = (mm + 30) % 60;
      const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      if (DOCTORS.length) q('#doctor_id').value = DOCTORS[0].id;
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = ds;
      q('#start_time').value = start;
      q('#end_time').value = end;
      showModal('#modalAdd');
    });
    row.appendChild(col);
  }
  initScrollSync();
}

function renderMonthView(y, m, events) {
  q('#dateLabel').textContent = `📊 ${y} оны ${String(m).padStart(2, '0')}-р сар`;
  q('#timeCol').innerHTML = '';
  const row = q('#calendarRow');
  row.innerHTML = '';
  const first = new Date(y, m - 1, 1);
  const last = new Date(y, m, 0);
  const grid = document.createElement('div');
  grid.style.display = 'grid';
  grid.style.gridTemplateColumns = 'repeat(7,1fr)';
  grid.style.gap = '8px';
  grid.style.padding = '1rem';
  row.appendChild(grid);
  // Weekday headers
  ['Дав','Мяг','Лха','Пүр','Баа','Бям','Ням'].forEach(n => {
    const hd = document.createElement('div');
    hd.className = 'text-center fw-bold';
    hd.style.color = '#94a3b8';
    hd.style.fontSize = '0.9rem';
    hd.style.padding = '0.5rem';
    hd.textContent = n;
    grid.appendChild(hd);
  });
  const jsDow = first.getDay();
  const isoOffset = jsDow === 0 ? 6 : jsDow - 1;
  for (let i = 0; i < isoOffset; i++) grid.appendChild(document.createElement('div'));
  for (let day = 1; day <= last.getDate(); day++) {
    const ds = fmtDate(new Date(y, m - 1, day));
    const isToday = ds === fmtDate(new Date());
    const cell = document.createElement('div');
    cell.style.background = isToday ? 'linear-gradient(135deg, #1e3a8a 0%, #1e293b 100%)' : '#1e293b';
    cell.style.border = isToday ? '2px solid #3b82f6' : '1px solid #334155';
    cell.style.padding = '0.75rem';
    cell.style.borderRadius = '8px';
    cell.style.cursor = 'pointer';
    cell.style.transition = 'all 0.3s ease';
    cell.style.minHeight = '110px';
    cell.style.position = 'relative';
    cell.innerHTML = `<div style="font-weight: 700; margin-bottom: 0.5rem; color: ${isToday ? '#60a5fa' : '#e2e8f0'};">${day}</div>`;
    cell.addEventListener('mouseenter', () => {
      cell.style.boxShadow = '0 8px 25px rgba(59, 130, 246, 0.2)';
      cell.style.transform = 'translateY(-2px)';
      cell.style.borderColor = '#3b82f6';
    });
    cell.addEventListener('mouseleave', () => {
      cell.style.boxShadow = 'none';
      cell.style.transform = 'translateY(0)';
      cell.style.borderColor = isToday ? '#3b82f6' : '#334155';
    });
    (events || [])
      .filter(ev => ev.date === ds)
      .slice(0, 5)
      .forEach(ev => {
        const cfg = statusConfig[ev.status] || statusConfig.online;
        const item = document.createElement('div');
        item.style.background = cfg.bg;
        item.style.borderLeft = `3px solid ${cfg.color}`;
        item.style.padding = '0.4rem 0.5rem';
        item.style.marginBottom = '0.3rem';
        item.style.borderRadius = '4px';
        item.style.fontSize = '0.7rem';
        item.style.cursor = 'pointer';
        item.style.border = `1px solid ${cfg.color}40`;
        const serviceLabel = esc(ev.service_name || 'Үйлчилгээ заагаагүй');
        const phoneLabel = esc(ev.phone || '—');
        item.title = `${ev.patient_name}\n${serviceLabel}\n${phoneLabel}`;
        item.innerHTML = `
          <div style="font-weight: 600; color: ${cfg.textColor}; margin-bottom: 0.2rem;">🕒 ${ev.start_time}</div>
          <div style="color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size:0.8rem;">
            💆 ${serviceLabel}
          </div>
          <div style="color:#94a3b8;font-size:0.7rem;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            📞 ${phoneLabel}
          </div>`;
        item.addEventListener('click', e => {
          e.stopPropagation();
          openEdit(ev);
        });
        cell.appendChild(item);
      });
    cell.addEventListener('click', () => {
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      if (DOCTORS.length) q('#doctor_id').value = DOCTORS[0].id;
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = ds;
      q('#start_time').value = `${String(WORK_START).padStart(2, '0')}:00`;
      q('#end_time').value = `${String(WORK_START).padStart(2, '0')}:30`;
      showModal('#modalAdd');
    });
    grid.appendChild(cell);
  }
}

/* ---- Edit / Actions ---- */
function openEdit(ev) {
  const f = document.getElementById('editForm');
  f.querySelector('[name="id"]').value = ev.id;
  f.querySelector('[name="clinic"]').value = ev.clinic;
  f.querySelector('[name="doctor_id"]').value = ev.doctor_id;
  f.querySelector('[name="date"]').value = ev.date;
  f.querySelector('[name="start_time"]').value = ev.start_time;
  f.querySelector('[name="end_time"]').value = ev.end_time;
  f.querySelector('[name="patient_name"]').value = ev.patient_name;
  f.querySelector('[name="service_name"]').value = ev.service_name || '';
  f.querySelector('[name="gender"]').value = ev.gender || '';
  f.querySelector('[name="visit_count"]').value = ev.visit_count || 1;
  f.querySelector('[name="phone"]').value = ev.phone;
  f.querySelector('[name="note"]').value = ev.note;
  f.querySelector('[name="status"]').value = ev.status || 'online';
  const modal = new bootstrap.Modal(document.getElementById('modalEdit'));
  modal.show();
}

async function saveEdit(e) {
  e.preventDefault();
  const f = e.target;
  const payload = {
    id: +f.querySelector('[name="id"]').value,
    doctor_id: +f.querySelector('[name="doctor_id"]').value,
    clinic: f.querySelector('[name="clinic"]').value || CURRENT_CLINIC,
    date: f.querySelector('[name="date"]').value,
    start_time: f.querySelector('[name="start_time"]').value,
    end_time: f.querySelector('[name="end_time"]').value,
    patient_name: f.querySelector('[name="patient_name"]').value,
    service_name: f.querySelector('[name="service_name"]').value || '',
    gender: f.querySelector('[name="gender"]').value || '',
    visit_count: +(f.querySelector('[name="visit_count"]').value || 1),
    phone: f.querySelector('[name="phone"]').value,
    note: f.querySelector('[name="note"]').value,
    status: f.querySelector('[name="status"]').value
  };
  try {
    const r = await fetchJSON(`${API}?action=update`, {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (!r?.ok) {
      showNotification(`Error: ${r?.msg || 'Update failed'}`);
      return;
    }
    hideModal('#modalEdit');
    await loadBookings();
    showNotification('Success: Booking updated');
  } catch (e) {
    console.error('Error saving:', e);
    showNotification('Error: Failed to update');
  }
}

async function deleteBooking() {
  const f = q('#editForm');
  if (!f) return;
  const id = +getFormValue(f, 'id');
  if (!id) return;
  if (!confirm('Энэ захиалгыг устгах уу?')) return;
  try {
    const r = await fetchJSON(`${API}?action=delete`, { method: 'POST', body: JSON.stringify({ id }) });
    if (!r?.ok) {
      showNotification(`Error: ${r?.msg || 'Delete failed'}`);
      return;
    }
    hideModal('#modalEdit');
    await loadBookings();
    showNotification('Booking deleted');
  } catch (e) {
    console.error('Error deleting:', e);
    showNotification('Error: Failed to delete');
  }
}

/* ---- Patient autofill ---- */
async function loadPatientInfo(phone, formId = 'addForm') {
  if (!phone) return;
  const normalized = phone.replace(/\D+/g, '');
  const queryPhone = normalized || phone;
  const form = document.getElementById(formId);
  if (!form) return;
  try {
    const url = `${API}?action=patient_info&clinic=${encodeURIComponent(CURRENT_CLINIC)}&phone=${encodeURIComponent(queryPhone)}&_=${Date.now()}`;
    const r = await fetchJSON(url);
    if (!r?.ok) return;
    if (r.data) {
      const nameField = form.querySelector('[name="patient_name"]');
      const serviceField = form.querySelector('[name="service_name"]');
      const genderField = form.querySelector('[name="gender"]');
      const noteField = form.querySelector('[name="note"]');
      const visitField = form.querySelector('[name="visit_count"]');
      if (visitField) visitField.value = r.data.visits > 1 ? '2' : '1';
      if (r.data.patient_name && nameField) nameField.value = r.data.patient_name;
      if (serviceField) {
        if (r.data.service_name) {
          serviceField.value = r.data.service_name;
          serviceField.classList.remove('input-error');
        }
      }
      if (r.data.gender && genderField) {
        const g = (r.data.gender || '').toString().toLowerCase();
        if (g.match(/эм|female|woman|girl/)) {
          genderField.value = 'female';
        } else if (g.match(/эр|male|man|boy/)) {
          genderField.value = 'male';
        }
      }
      if (r.data.note && noteField) noteField.value = r.data.note;
      if (formId === 'addForm') showNotification(`Previous service: ${r.data.service_name || 'No info'}`);
    } else {
      const visitField = form.querySelector('[name="visit_count"]');
      if (visitField) visitField.value = '1';
      if (formId === 'addForm') showNotification('First-time customer');
    }
  } catch (e) {
    console.error('Error loading patient info:', e);
  }
}

/* ---- Scroll sync ---- */
function initScrollSync() {
  const timeCol = q('#timeCol');
  const cols = Array.from(document.querySelectorAll('.calendar-hours'));
  if (!timeCol) return;
  timeCol.onscroll = () => cols.forEach(el => (el.scrollTop = timeCol.scrollTop));
  cols.forEach(el => {
    el.onscroll = () => (timeCol.scrollTop = el.scrollTop);
  });
}

/* ---- Notifications ---- */
function showNotification(msg) {
  const ntf = document.createElement('div');
  ntf.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #e2e8f0;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    border: 1px solid #334155;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    font-weight: 500;
    z-index: 9999;
    animation: slideIn 0.3s ease;
  `;
  ntf.textContent = msg;
  document.body.appendChild(ntf);
  setTimeout(() => {
    ntf.style.animation = 'slideOut 0.3s ease';
    setTimeout(() => ntf.remove(), 300);
  }, 3000);
}

/* ---- DOM helpers ---- */
function setFormValue(form, name, v) {
  const el = form.querySelector(`[name="${name}"]`);
  if (el) el.value = v ?? '';
}
function getFormValue(form, name) {
  const el = form.querySelector(`[name="${name}"]`);
  return el ? el.value : '';
}
function setAddValue(id, v) {
  const el = q(`#addForm #${id}`);
  if (el) el.value = v ?? '';
}
function showModal(sel) {
  const el = q(sel);
  if (el) bootstrap.Modal.getOrCreateInstance(el).show();
}
function hideModal(sel) {
  const el = q(sel);
  if (el) bootstrap.Modal.getOrCreateInstance(el).hide();
}

/* ---- Animation keyframes ---- */
const style = document.createElement('style');
style.textContent = `
  @keyframes slideIn {
    from { transform: translateX(400px); opacity: 0; }
    to   { transform: translateX(0);   opacity: 1; }
  }
  @keyframes slideOut {
    from { transform: translateX(0);   opacity: 1; }
    to   { transform: translateX(400px); opacity: 0; }
  }
`;
document.head.appendChild(style);

/* ---- Init ---- */
document.addEventListener('DOMContentLoaded', async () => {
  applyCssVars();
  const clinicSel = q('#clinic');
  if (clinicSel && clinicSel.value) CURRENT_CLINIC = clinicSel.value;
  await loadDoctors();
  await loadBookings();
  window.addEventListener('message', async e => {
    if (e.data.reloadDoctors) {
      await loadDoctors();
      await loadBookings();
      showNotification('🔄 Doctor hours updated');
    }
  });
  q('#prev')?.addEventListener('click', () => {
    if (VIEW_MODE === 'week') CURRENT_DATE.setDate(CURRENT_DATE.getDate() - 7);
    else if (VIEW_MODE === 'month') CURRENT_DATE.setMonth(CURRENT_DATE.getMonth() - 1);
    else CURRENT_DATE.setDate(CURRENT_DATE.getDate() - 1);
    loadBookings();
  });
  q('#next')?.addEventListener('click', () => {
    if (VIEW_MODE === 'week') CURRENT_DATE.setDate(CURRENT_DATE.getDate() + 7);
    else if (VIEW_MODE === 'month') CURRENT_DATE.setMonth(CURRENT_DATE.getMonth() + 1);
    else CURRENT_DATE.setDate(CURRENT_DATE.getDate() + 1);
    loadBookings();
  });
  q('#today')?.addEventListener('click', () => {
    CURRENT_DATE = new Date();
    loadBookings();
  });
  clinicSel?.addEventListener('change', async e => {
    CURRENT_CLINIC = e.target.value;
    await loadDoctors();
    await loadBookings();
  });
  const updateViewButtons = mode => {
    document.querySelectorAll('#viewDay, #viewWeek, #viewMonth').forEach(btn => {
      btn.classList.remove('active');
      btn.style.background = '';
      btn.style.color = '';
    });
    const activeBtn = mode === 'day' ? q('#viewDay') : mode === 'week' ? q('#viewWeek') : q('#viewMonth');
    if (activeBtn) {
      activeBtn.classList.add('active');
      activeBtn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%)';
      activeBtn.style.color = 'white';
      activeBtn.style.borderColor = 'transparent';
    }
  };
  q('#viewDay')?.addEventListener('click', () => {
    VIEW_MODE = 'day';
    updateViewButtons('day');
    loadBookings();
  });
  q('#viewWeek')?.addEventListener('click', () => {
    VIEW_MODE = 'week';
    updateViewButtons('week');
    loadBookings();
  });
  q('#viewMonth')?.addEventListener('click', () => {
    VIEW_MODE = 'month';
    updateViewButtons('month');
    loadBookings();
  });
  updateViewButtons(VIEW_MODE);
  q('#addForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target;
    const phoneField = f.querySelector('#phone');
    const phoneVal = phoneField ? phoneField.value.trim() : '';
    const serviceField = f.querySelector('#service_name');
    const serviceVal = serviceField ? serviceField.value.trim() : '';
    if (!phoneVal) {
      phoneField?.classList.add('input-error');
      phoneField?.focus();
      showNotification('Please enter phone number');
      return;
    }
    phoneField?.classList.remove('input-error');
    serviceField?.classList.remove('input-error');
    if (!serviceVal) {
      serviceField?.classList.add('input-error');
      serviceField?.focus();
      showNotification('Please enter service name');
      return;
    }
    if (typeof f.reportValidity === 'function' && !f.reportValidity()) {
      return;
    }
    const payload = {
      action: 'add',
      clinic: CURRENT_CLINIC,
      doctor_id: +f.querySelector('#doctor_id').value,
      date: f.querySelector('#date').value,
      start_time: f.querySelector('#start_time').value,
      end_time: f.querySelector('#end_time').value,
      patient_name: f.querySelector('#patient_name').value.trim(),
      phone: phoneVal,
      status: f.querySelector('#status').value || 'online',
      service_name: serviceVal,
      gender: f.querySelector('#gender')?.value || '',
      visit_count: +(f.querySelector('#visit_count')?.value || 1),
      note: (f.querySelector('#note')?.value || '').trim()
    };
    try {
      const r = await fetch(`api.php?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const j = await r.json();
      if (!j?.ok) {
        showNotification(`Error: ${j?.msg || 'Error'}`);
        return;
      }
      hideModal('#modalAdd');
      f.reset();
      await loadBookings();
      showNotification('Success: Booking added');
    } catch (err) {
      console.error('Error adding:', err);
      showNotification('Error: Failed to add booking');
    }
  });
  const schedulePatientLookup = (inputEl, formId) => {
    const phone = inputEl.value.trim();
    if (phoneLookupTimers[formId]) clearTimeout(phoneLookupTimers[formId]);
    if (phone.length >= 4) {
      phoneLookupTimers[formId] = setTimeout(() => {
        if (phone.length >= 7) loadPatientInfo(phone, formId);
      }, 500);
    }
  };
  const addPhoneInput = q('#addForm #phone');
  addPhoneInput?.addEventListener('blur', e => {
    const phone = e.target.value.trim();
    if (phone.length >= 7) loadPatientInfo(phone, 'addForm');
  });
  addPhoneInput?.addEventListener('input', e => {
    addPhoneInput.classList.remove('input-error');
    schedulePatientLookup(addPhoneInput, 'addForm');
  });
  const addServiceInput = q('#addForm #service_name');
  addServiceInput?.addEventListener('input', () => addServiceInput.classList.remove('input-error'));
  const editPhoneInput = q('#editForm [name="phone"]');
  editPhoneInput?.addEventListener('blur', e => {
    const phone = e.target.value.trim();
    if (phone.length >= 7) loadPatientInfo(phone, 'editForm');
  });
  editPhoneInput?.addEventListener('input', e => {
    schedulePatientLookup(editPhoneInput, 'editForm');
  });
  const editServiceInput = q('#editForm [name="service_name"]');
  editServiceInput?.addEventListener('input', () => editServiceInput.classList.remove('input-error'));
  document.getElementById('modalAdd')?.addEventListener('shown.bs.modal', () => {
    const phone = q('#addForm #phone');
    if (phone) {
      phone.classList.add('phone-reminder');
      phone.focus();
      setTimeout(() => phone.classList.remove('phone-reminder'), 1600);
    }
  });
  q('#editForm')?.addEventListener('submit', saveEdit);
  q('#btnDelete')?.addEventListener('click', deleteBooking);
});