
/*
// Place this logic at the end of renderWeekView, after all columns are rendered
  setTimeout(() => {
    for (let i = 0; i < 7; i++) {
      const d = new Date(startDate);
      d.setDate(d.getDate() + i);
      const ds = fmtDate(d);
      const hoursEl = row.children[i]?.querySelector('.calendar-hours');
      if (!hoursEl) continue;
      const dayEvents = (events || []).filter(ev => ev.date === ds);
      const maxVisible = 4;
      if (dayEvents.length > maxVisible) {
        const moreDiv = document.createElement('div');
        moreDiv.className = 'week-more-pill';
        moreDiv.style.fontSize = '11px';
        moreDiv.style.color = '#6366f1';
        moreDiv.style.background = '#f1f5f9';
        moreDiv.style.borderRadius = '12px';
        moreDiv.style.padding = '2px 10px';
        moreDiv.style.display = 'inline-block';
        moreDiv.style.cursor = 'pointer';
        moreDiv.style.marginTop = '2px';
        moreDiv.textContent = `+${dayEvents.length - maxVisible}`;
        moreDiv.onclick = () => showWeekEventList(ds, dayEvents);
        hoursEl.appendChild(moreDiv);
      }
    }
  }, 0);

  function showWeekEventList(ds, events) {
    const body = document.getElementById('monthEventListBody');
    if (!body) return;
    const sorted = [...events].sort((a,b)=>a.start_time.localeCompare(b.start_time));
    body.innerHTML = `<div style="margin-bottom:10px;font-weight:600;color:#6366f1;">${ds} - ${sorted.length} захиалга</div>`;
    sorted.forEach(ev => {
      const cfg = statusConfig[ev.status] || statusConfig.online;
      const row = document.createElement('div');
      row.className = 'month-event-row';
      row.style.display = 'flex';
      row.style.alignItems = 'center';
      row.style.justifyContent = 'space-between';
      row.style.gap = '10px';
      row.style.padding = '7px 0';
      row.style.borderBottom = '1px solid #f1f5f9';
      row.style.cursor = 'pointer';
      row.onmouseenter = () => { row.style.background = '#f3f4f6'; };
      row.onmouseleave = () => { row.style.background = ''; };
      row.onclick = () => { openEdit(ev); hideModal('#monthEventModal'); };
      row.innerHTML = `
        <div style="font-weight:700;color:#374151;min-width:56px;">${hhmm(ev.start_time)}</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#22223b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.service_name||'')}</div>
          <div style="font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.patient_name||'')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
          <span style="width:12px;height:12px;border-radius:50%;background:${cfg.color};display:inline-block;margin-right:2px;"></span>
          ${ev.phone ? `<span style="background:#0d9488;color:white;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;opacity:.95;">${esc(ev.phone)}</span>` : ''}
        </div>
      `;
      body.appendChild(row);
    });
    showModal('#monthEventModal');
  }
*/
// Helper to safely parse HH:MM
function parseHHMM(t) {
  if (!t) return null;
  const s = String(t);
  if (!/^\d{1,2}:\d{2}/.test(s)) return null;
  const parts = s.split(':').map(Number);
  return { h: parts[0], m: parts[1] };
}

// Format time as HH:MM (removes :00 seconds)
function hhmm(t) {
  return (t || '').toString().slice(0, 5);
}
// --- Overlap Utils ---
function timeToMin(t) {
  const p = parseHHMM(t);
  if (!p) return 0;
  return p.h * 60 + p.m;
}

// Returns: { [event.id]: {laneIndex, laneCount} }
function buildOverlapLayout(list) {
  if (!Array.isArray(list) || !list.length) return {};
  // Sort by start_time, then end_time
  const events = [...list].sort((a, b) => {
    const sa = timeToMin(a.start_time), sb = timeToMin(b.start_time);
    if (sa !== sb) return sa - sb;
    return timeToMin(a.end_time) - timeToMin(b.end_time);
  });
  // Cluster by overlap
  let clusters = [];
  let cur = [];
  let lastEnd = -1;
  for (const ev of events) {
    const st = timeToMin(ev.start_time), et = timeToMin(ev.end_time);
    if (cur.length === 0 || st < lastEnd) {
      cur.push(ev);
      lastEnd = Math.max(lastEnd, et);
    } else {
      clusters.push(cur);
      cur = [ev];
      lastEnd = et;
    }
  }
  if (cur.length) clusters.push(cur);
  // Assign lanes within each cluster
  const result = {};
  for (const cluster of clusters) {
    const lanes = [];
    for (const ev of cluster) {
      let lane = 0;
      for (; lane < lanes.length; lane++) {
        if (timeToMin(ev.start_time) >= lanes[lane]) break;
      }
      lanes[lane] = timeToMin(ev.end_time);
      result[ev.id] = { laneIndex: lane, laneCount: lanes.length };
    }
  }
  return result;
}
const API = './api.php';
let DOCTORS = [];
let CURRENT_DATE = window.CURRENT_DATE = new Date();
let CURRENT_CLINIC = (typeof window !== 'undefined' && window.CURRENT_CLINIC) ? window.CURRENT_CLINIC : 'venera';
// Injected by PHP when available
const USER_ROLE = (typeof window !== 'undefined' && window.USER_ROLE) ? window.USER_ROLE : '';
const USER_DEPARTMENT = (typeof window !== 'undefined' && window.USER_DEPARTMENT) ? window.USER_DEPARTMENT : null;
let CURRENT_DEPARTMENT = new URLSearchParams(window.location.search).get('department') || null;
// For doctor/reception users, lock department to their own if provided
if ((USER_ROLE === 'doctor' || USER_ROLE === 'reception') && USER_DEPARTMENT) {
  CURRENT_DEPARTMENT = USER_DEPARTMENT;
}
let VIEW_MODE;
if (typeof window !== 'undefined' && window.DEFAULT_VIEW_MODE) {
  VIEW_MODE = window.DEFAULT_VIEW_MODE;
} else {
  VIEW_MODE = 'week';
}
const phoneLookupTimers = {};
const WORK_START = 9;
const WORK_END = 19;
const PX_PER_HOUR = 80;

const q = s => document.querySelector(s);
const esc = s => (s ?? '').toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
const fmtDate = window.fmtDate = d => {
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
};
const updateDateLabel = (txt) => {
  const el = q('#dateLabel');
  if (!el) return;
  const s = el.querySelector('strong');
  const span = el.querySelector('span');
  if (span) {
    if (VIEW_MODE === 'week') span.textContent = 'Долоо хоног: ';
    else if (VIEW_MODE === 'month') span.textContent = 'Сар: ';
    else span.textContent = 'Өдөр: ';
  }
  if (s) s.textContent = txt;
  else el.textContent = txt;
};
const fetchJSON = (u, o = {}) => fetch(u, Object.assign({ headers: { 'Content-Type': 'application/json' } }, o)).then(r => r.json()).catch(e => { throw e; });
const hoursToY = t => {
  const p = parseHHMM(t);
  if (!p) return 0;
  return Math.max(0, (p.h * 60 + p.m - WORK_START * 60) * (PX_PER_HOUR / 60));
};
const minsBetween = (t1, t2) => {
  const p1 = parseHHMM(t1);
  const p2 = parseHHMM(t2);
  if (!p1 || !p2) return 30;
  return p2.h * 60 + p2.m - (p1.h * 60 + p1.m);
};
const isoMonday = d => {
  const x = new Date(d);
  const wd = x.getDay();
  const diff = wd === 0 ? -6 : 1 - wd;
  x.setDate(x.getDate() + diff);
  x.setHours(0, 0, 0, 0);
  return x;
};

const statusConfig = {
  online: { color: '#3b82f6', bg: '#eff6ff', textColor: '#1e40af', name: 'Онлайн', badge: '#1e40af' },
  arrived: { color: '#f59e0b', bg: '#fffbeb', textColor: '#92400e', name: 'Ирсэн', badge: '#a16207' },
  paid: { color: '#10b981', bg: '#ecfdf5', textColor: '#065f46', name: 'Төлөгдсөн', badge: '#047857' },
  pending: { color: '#8b5cf6', bg: '#f5f3ff', textColor: '#5b21b6', name: 'Төлбөр хүлээгдэж буй', badge: '#581c87' },
  cancelled: { color: '#ef4444', bg: '#fef2f2', textColor: '#991b1b', name: 'Үйлчлүүлэгч цуцалсан', badge: '#7f1d1d' },
  doctor_cancelled: { color: '#06b6d4', bg: '#e0f2fe', textColor: '#075985', name: 'Эмч цуцалсан', badge: '#0284c7' }
};

const DEPARTMENT_COLORS = {
  'Мэс засал': '#ef4444',
  'Мэсийн бус': '#22c55e',
  'Уламжлалт': '#0ea5e9',
  'Шүд': '#8b5cf6',
  'Дусал': '#f59e0b',
  'Үзлэг': '#8b5cf6',
  'Массаж': '#f59e0b'
};

function hexToRgba(hex, alpha) {
  const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
  if (!m) return null;
  const r = parseInt(m[1], 16);
  const g = parseInt(m[2], 16);
  const b = parseInt(m[3], 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function shadeColor(color, percent) {
  let R = parseInt(color.substring(1, 3), 16);
  let G = parseInt(color.substring(3, 5), 16);
  let B = parseInt(color.substring(5, 7), 16);
  R = parseInt(R * (100 + percent) / 100);
  G = parseInt(G * (100 + percent) / 100);
  B = parseInt(B * (100 + percent) / 100);
  R = (R < 255) ? R : 255;
  G = (G < 255) ? G : 255;
  B = (B < 255) ? B : 255;
  const RR = ((R.toString(16).length === 1) ? "0" + R.toString(16) : R.toString(16));
  const GG = ((G.toString(16).length === 1) ? "0" + G.toString(16) : G.toString(16));
  const BB = ((B.toString(16).length === 1) ? "0" + B.toString(16) : B.toString(16));
  return "#" + RR + GG + BB;
}

function deptBg(color, fallback) {
  const c = hexToRgba(color, 1);
  if (c) return c;
  return fallback;
}

let TREATMENTS = [];

function applyCssVars() {
  document.documentElement.style.setProperty('--hour-h', `${PX_PER_HOUR}px`);
}

async function loadTreatments() {
  try {
    const url = `${API}?action=treatments&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`;
    const r = await fetchJSON(url);
    if (!r?.ok) {
      console.error('Treatments API Error:', r?.msg || 'Unknown error');
      return;
    }
    TREATMENTS = r.data || [];

    // Initialize searchable treatment select for both modals
    initTreatmentSearch('#addForm', '#treatment_search', '#treatment_dropdown', '#treatment_id', '#custom_treatment');
    initTreatmentSearch('#editForm', '#edit_treatment_search', '#edit_treatment_dropdown', '#edit_treatment_id', '#edit_custom_treatment');

  } catch (e) {
    console.error('Error loading treatments:', e);
  }
}

function initTreatmentSearch(formSelector, searchSelector, dropdownSelector, idSelector, customSelector) {
  const form = q(formSelector);
  const searchInput = q(searchSelector);
  const dropdown = q(dropdownSelector);
  const hiddenId = q(idSelector);
  const hiddenCustom = q(customSelector);

  if (!searchInput || !dropdown) return;

  function renderDropdown(searchTerm = '') {
    const term = searchTerm.toLowerCase().trim();
    let html = '';

    const terms = term.split(/\s+/).filter(x => x.length > 0);
    let filtered = TREATMENTS.filter(t => {
      if (terms.length === 0) return true;
      const name = (t.name || '').toLowerCase();
      const category = (t.category || '').toLowerCase();
      return terms.every(word => name.includes(word) || category.includes(word));
    });

    if (term && !filtered.some(t => t.name.toLowerCase() === term)) {
      html += `<div class="treatment-option custom-option" data-custom="${esc(searchTerm)}">
        <i class="fas fa-plus"></i> "${esc(searchTerm)}" гэж шинээр нэмэх
      </div>`;
    }

    const grouped = {};
    filtered.forEach(t => {
      const cat = t.category || 'Бусад';
      if (!grouped[cat]) grouped[cat] = [];
      grouped[cat].push(t);
    });

    Object.keys(grouped).sort().forEach(cat => {
      if (Object.keys(grouped).length > 1) {
        html += `<div class="treatment-category">${esc(cat)}</div>`;
      }
      grouped[cat].forEach(t => {
        const price = t.price > 0 ? `<span class="treatment-price">${Number(t.price).toLocaleString()}₮</span>` : '';
        const duration = t.duration_minutes || 60;
        const aftercare = (t.aftercare_days > 0) ? ` <i class="fas fa-magic text-info ms-1" title="After-care: ${t.aftercare_days} хоног"></i>` : '';
        html += `<div class="treatment-option" data-id="${t.id}" data-name="${esc(t.name)}" data-price="${t.price || 0}" data-duration="${duration}" data-aftercare="${t.aftercare_days || 0}">
          <span class="treatment-name">${esc(t.name)} <small>(${duration} мин)</small>${aftercare}</span>
          ${price}
        </div>`;
      });
    });

    if (!html) html = '<div class="treatment-option no-result">Олдсонгүй. Бичээд нэмнэ үү.</div>';
    dropdown.innerHTML = html;
  }

  searchInput.addEventListener('focus', () => { renderDropdown(searchInput.value); dropdown.classList.add('show'); });
  searchInput.addEventListener('input', () => {
    renderDropdown(searchInput.value);
    dropdown.classList.add('show');
    hiddenId.value = '';
    hiddenCustom.value = searchInput.value;
  });

  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.treatment-option');
    if (!opt || opt.style.cursor === 'default') return;

    if (opt.dataset.custom) {
      searchInput.value = opt.dataset.custom;
      hiddenId.value = '';
      hiddenCustom.value = opt.dataset.custom;
    } else if (opt.dataset.id) {
      searchInput.value = opt.dataset.name;
      hiddenId.value = opt.dataset.id;
      hiddenCustom.value = '';

      // Auto-fill price
      const priceField = form.querySelector('[name="price"]') || form.querySelector('#addPrice') || form.querySelector('#editPrice');
      if (priceField && opt.dataset.price > 0) {
        priceField.value = opt.dataset.price;
        const statusEl = form.querySelector('[name="status"]');
        if (statusEl && statusEl.value === 'paid') {
          const priceGroup = form.querySelector('.price-group') || q('#addPriceGroup') || q('#editPriceGroup');
          if (priceGroup) priceGroup.style.display = 'block';
        }
      }

      // Auto-calculate end_time
      const duration = parseInt(opt.dataset.duration) || 60;
      const startTimeInput = form.querySelector('input[name="start_time"]');
      const endTimeInput = form.querySelector('input[name="end_time"]');
      if (startTimeInput && startTimeInput.value && endTimeInput) {
        const [hours, minutes] = startTimeInput.value.split(':').map(Number);
        const endMinutes = hours * 60 + minutes + duration;
        endTimeInput.value = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
      }

      const aftercareDays = parseInt(opt.dataset.aftercare) || 0;
      if (aftercareDays > 0) showNotification(`✨ Дараах асаргаа (${aftercareDays} хоног) автоматаар төлөвлөгдлөө`, 'info');
    }
    dropdown.classList.remove('show');
    searchInput.classList.remove('input-error');
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.treatment-select-wrapper')) {
      const allDrops = document.querySelectorAll('.treatment-dropdown');
      allDrops.forEach(d => d.classList.remove('show'));
    }
  });

  searchInput.addEventListener('keydown', (e) => {
    const options = dropdown.querySelectorAll('.treatment-option:not(.no-result)');
    const active = dropdown.querySelector('.treatment-option.active');
    let idx = Array.from(options).indexOf(active);
    if (e.key === 'ArrowDown') { e.preventDefault(); idx = (idx < options.length - 1) ? idx + 1 : 0; options.forEach(o => o.classList.remove('active')); if (options[idx]) options[idx].classList.add('active'); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = (idx > 0) ? idx - 1 : options.length - 1; options.forEach(o => o.classList.remove('active')); if (options[idx]) options[idx].classList.add('active'); }
    else if (e.key === 'Enter') { e.preventDefault(); if (active) active.click(); else if (options[0]) options[0].click(); }
    else if (e.key === 'Escape') { dropdown.classList.remove('show'); }
  });

  const startTimeIn = form.querySelector('input[name="start_time"]');
  if (startTimeIn) {
    startTimeIn.addEventListener('change', () => {
      const selectedId = hiddenId.value;
      if (selectedId) {
        const treat = TREATMENTS.find(t => t.id == selectedId);
        if (treat) {
          const [h, m] = startTimeIn.value.split(':').map(Number);
          const endMin = h * 60 + m + (treat.duration_minutes || 60);
          const endIn = form.querySelector('input[name="end_time"]');
          if (endIn) endIn.value = `${String(Math.floor(endMin / 60)).padStart(2, '0')}:${String(endMin % 60).padStart(2, '0')}`;
        }
      }
    });
  }
}

async function loadDoctors() {
  try {
    const deptParam = CURRENT_DEPARTMENT ? `&department=${encodeURIComponent(CURRENT_DEPARTMENT)}` : '';
    const url = `${API}?action=doctors&clinic=${encodeURIComponent(CURRENT_CLINIC)}${deptParam}&_=${Date.now()}`;
    const r = await fetchJSON(url);
    if (!r?.ok) {
      console.error('API Error:', r?.msg || 'Unknown error');
      showNotification(`Алдаа: ${r?.msg || 'Эмчдийг ачаалахад сүтэл гарлаа'}`);
      return;
    }
    let doctors = r.data || [];

    // Server-side already filtered doctors by department (including those with relevant bookings)

    DOCTORS = doctors;
    console.log(`Loaded ${DOCTORS.length} doctors`);
    const blankOption = '<option value="">Эмчгүй</option>';
    const options = blankOption + (DOCTORS || []).map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    const selAdd = q('#doctor_id');
    const selEdit = q('#modalEdit select[name="doctor_id"]');
    if (selAdd) {
      selAdd.innerHTML = options;
      selAdd.value = '';
    }
    if (selEdit) {
      selEdit.innerHTML = options;
      selEdit.value = '';
    }
  } catch (e) {
    console.error('Error loading doctors:', e);
    showNotification('Алдаа: Эмчдийг ачаалахад сүтэл гарлаа');
  }
}

async function loadBookings() {
  if (VIEW_MODE === 'week') return loadWeekBookings();
  if (VIEW_MODE === 'month') return loadMonthBookings();
  const date = fmtDate(CURRENT_DATE);
  try {
    const deptParam = CURRENT_DEPARTMENT ? `&department=${encodeURIComponent(CURRENT_DEPARTMENT)}` : '';
    const r = await fetchJSON(`${API}?action=bookings&date=${date}&clinic=${encodeURIComponent(CURRENT_CLINIC)}${deptParam}&_=${Date.now()}`);
    renderDayView(date, r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading bookings:', e);
    showNotification('Алдаа: Захиалгуудыг ачаалахад сүтэл гарлаа');
  }
}

async function loadWeekBookings() {
  const start = isoMonday(CURRENT_DATE);
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  try {
    const deptParam = CURRENT_DEPARTMENT ? `&department=${encodeURIComponent(CURRENT_DEPARTMENT)}` : '';
    const r = await fetchJSON(`${API}?action=bookings_week&start=${fmtDate(start)}&end=${fmtDate(end)}&clinic=${encodeURIComponent(CURRENT_CLINIC)}${deptParam}&_=${Date.now()}`);
    if (!r?.ok) {
      console.error('API Error:', r?.msg || 'Unknown error');
      showNotification(`Алдаа: ${r?.msg || 'API алдаа'}`);
      return;
    }
    renderWeekView(start, end, r.data || []);
  } catch (e) {
    console.error('Error loading week view:', e);
    showNotification('Алдаа: Долоо хоног ачаалахад сүтэл гарлаа');
  }
}

async function loadMonthBookings() {
  const y = CURRENT_DATE.getFullYear();
  const m = String(CURRENT_DATE.getMonth() + 1).padStart(2, '0');
  try {
    const deptParam = CURRENT_DEPARTMENT ? `&department=${encodeURIComponent(CURRENT_DEPARTMENT)}` : '';
    const r = await fetchJSON(`${API}?action=bookings_month&month=${y}-${m}&clinic=${encodeURIComponent(CURRENT_CLINIC)}${deptParam}&_=${Date.now()}`);
    renderMonthView(y, parseInt(m, 10), r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading month view:', e);
    showNotification('Алдаа: Сарын хэсгийг ачаалахад сүтэл гарлаа');
  }
}

function renderNoDoctors() {
  updateDateLabel(`${fmtDate(CURRENT_DATE)}`);
  q('#timeCol').innerHTML = '';
  q('#calendarRow').innerHTML = '<div class="w-100 text-center text-muted p-5"><i class="fas fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i><p>Энэ клиникт идэвхтэй эмч олдсонгүй</p></div>';
}

function renderDayView(date, events) {
  const unassignedEvents = (events || []).filter(ev => !ev.doctor_id);
  // If no doctors and no unassigned events, show empty state
  if (!DOCTORS.length && !unassignedEvents.length) return renderNoDoctors();
  // Build doctor list + optional unassigned lane so bookings without doctor_id still show up
  const doctorsToRender = [...(DOCTORS || []).filter(d => parseInt(d.show_in_calendar) === 1)];
  if (unassignedEvents.length) {
    doctorsToRender.push({
      id: null,
      name: 'Эмчгүй',
      color: '#94a3b8',
      show_in_calendar: 1,
      working_hours: []
    });
  }
  updateDateLabel(date);
  const timeCol = q('#timeCol');
  timeCol.innerHTML = '';
  // 09:00-18:00 = 9 rows (09,10,11,12,13,14,15,16,17) - 18:00 is end time, not a row
  const totalHours = WORK_END - WORK_START;
  const gridHeight = totalHours * PX_PER_HOUR;
  for (let h = WORK_START; h < WORK_END; h++) {
    timeCol.innerHTML += `<div style="height:${PX_PER_HOUR}px;line-height:${PX_PER_HOUR}px;padding-left:8px;font-weight:600;color:#64748b;font-size:0.8rem;">${String(h).padStart(2, '0')}:00</div>`;
  }
  const row = q('#calendarRow');
  row.innerHTML = '';
  doctorsToRender.forEach(d => {
    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #e2e8f0';
    col.style.backgroundColor = '#f8fafc';
    const docColor = d.color || '#6366f1';
    const dayOfWeek = new Date(date).getDay();
    const todayWorkHours = d.working_hours?.find(wh => parseInt(wh.day_of_week) === dayOfWeek);
    let workLabel = `${String(WORK_START).padStart(2, '0')}:00–${String(WORK_END).padStart(2, '0')}:00`;
    if (todayWorkHours) {
      const st = todayWorkHours.start_time?.slice(0, 5) || '09:00';
      const et = todayWorkHours.end_time?.slice(0, 5) || '18:00';
      workLabel = parseInt(todayWorkHours.is_available) === 1 ? `${st}–${et}` : 'Ажиллахгүй';
    }
    col.innerHTML = `<div class="head" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 2px solid #e2e8f0;"><div style="font-weight: 700; margin-bottom: 0.5rem; color: #1e293b;"><i class="fas fa-user-md" style="color: ${docColor}; margin-right: 0.5rem;"></i>${esc(d.name)}</div><span class="badge" style="background: #dcfce7; color: #16a34a; border: 1px solid #86efac; padding: 0.35rem 0.7rem; font-size: 0.75rem; font-weight: 600;">${workLabel}</span></div><div class="calendar-hours position-relative"><div class="calendar-grid"></div></div>`;
    const hoursEl = col.querySelector('.calendar-hours');
    // Force consistent hours column height
    hoursEl.style.height = `${gridHeight}px`;
    hoursEl.style.overflow = 'visible';
    const calendarGrid = col.querySelector('.calendar-grid');
    if (calendarGrid) {
      calendarGrid.style.position = 'absolute';
      calendarGrid.style.left = '0';
      calendarGrid.style.right = '0';
      calendarGrid.style.top = '0';
      calendarGrid.style.height = `${gridHeight}px`;
      calendarGrid.style.pointerEvents = 'none';
      // Light gray hour lines for the light theme
      calendarGrid.style.background = `repeating-linear-gradient(to bottom, #e2e8f0 0px, #e2e8f0 1px, transparent 1px, transparent ${PX_PER_HOUR}px)`;
      calendarGrid.style.zIndex = '2';
    }
    // Show working hours as a light green area
    if (todayWorkHours && parseInt(todayWorkHours.is_available) === 1) {
      const startH = todayWorkHours.start_time;
      const endH = todayWorkHours.end_time;
      const startPx = Math.max(0, hoursToY(startH));
      // Calculate end position - add 1px buffer to ensure full coverage
      const endPx = hoursToY(endH);
      if (endPx > startPx + 2) {
        const workEl = document.createElement('div');
        workEl.style.position = 'absolute';
        workEl.style.left = '6px';
        workEl.style.right = '6px';
        workEl.style.top = `${startPx}px`;
        workEl.style.height = `${Math.max(4, endPx - startPx)}px`;
        workEl.style.background = 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)';
        workEl.style.border = '1px solid #a7f3d0';
        workEl.style.borderRadius = '8px';
        workEl.style.opacity = '1';
        workEl.style.pointerEvents = 'none';
        workEl.style.zIndex = '1';
        hoursEl.appendChild(workEl);
      }
    }

    // Add current time red line
    function drawCurrentTimeLine() {
      // Only show if today matches the calendar date
      const calendarDate = fmtDate(CURRENT_DATE);
      if (calendarDate !== fmtDate(new Date(date))) {
        const oldLine = hoursEl.querySelector('.current-time-line');
        if (oldLine) oldLine.remove();
        return;
      }
      const now = new Date();
      const currentHour = now.getHours();
      const currentMinute = now.getMinutes();
      if (currentHour < WORK_START || currentHour >= WORK_END) {
        const oldLine = hoursEl.querySelector('.current-time-line');
        if (oldLine) oldLine.remove();
        return;
      }
      const y = ((currentHour * 60 + currentMinute) - WORK_START * 60) * (PX_PER_HOUR / 60);
      let line = hoursEl.querySelector('.current-time-line');
      if (!line) {
        line = document.createElement('div');
        line.className = 'current-time-line';
        line.style.position = 'absolute';
        line.style.left = '0';
        line.style.right = '0';
        line.style.height = '2px';
        line.style.background = 'red';
        line.style.zIndex = '10';
        line.style.pointerEvents = 'none';
        hoursEl.appendChild(line);
      }
      line.style.top = `${y}px`;
    }
    drawCurrentTimeLine();
    // Update every minute
    if (!window._currentTimeLineInterval) {
      window._currentTimeLineInterval = setInterval(() => {
        document.querySelectorAll('.calendar-hours').forEach(el => {
          if (typeof el.drawCurrentTimeLine === 'function') el.drawCurrentTimeLine();
        });
      }, 60000);
    }
    hoursEl.drawCurrentTimeLine = drawCurrentTimeLine;

    hoursEl.addEventListener('click', e => {
      const rect = hoursEl.getBoundingClientRect();
      const y = e.clientY - rect.top + hoursEl.scrollTop;
      const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
      const hh = Math.floor(mins / 60);
      const mm = Math.floor((mins % 60) / 15) * 15;
      if (hh < WORK_START || hh >= WORK_END) return;
      const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
      const endH = hh + Math.floor((mm + 60) / 60);
      const endM = (mm + 60) % 60;
      const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      q('#doctor_id').value = d.id || '';
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = date;
      const startEl = q('#addForm input[name="start_time"]');
      const endEl = q('#addForm input[name="end_time"]');
      if (startEl) startEl.value = start;
      if (endEl) endEl.value = end;
      showModal('#modalAdd');
    });
    // Touch support: open add modal on tap
    hoursEl.addEventListener('touchend', e => {
      try {
        const t = e.changedTouches && e.changedTouches[0];
        if (!t) return;
        const rect = hoursEl.getBoundingClientRect();
        const y = t.clientY - rect.top + hoursEl.scrollTop;
        const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
        const hh = Math.floor(mins / 60);
        const mm = Math.floor((mins % 60) / 15) * 15;
        if (hh < WORK_START || hh >= WORK_END) return;
        const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
        const endH = hh + Math.floor((mm + 60) / 60);
        const endM = (mm + 60) % 60;
        const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
        const f = q('#addForm');
        if (!f) return;
        f.reset();
        q('#doctor_id').value = d.id || '';
        q('#clinic_in').value = CURRENT_CLINIC;
        q('#date').value = date;
        const startEl2 = q('#addForm input[name="start_time"]');
        const endEl2 = q('#addForm input[name="end_time"]');
        if (startEl2) startEl2.value = start;
        if (endEl2) endEl2.value = end;
        showModal('#modalAdd');
        e.preventDefault();
      } catch { }
    }, { passive: false });
    row.appendChild(col);
  });

  // Align time gutter with the header height so 09:00 lines up under doctor name
  try {
    const firstHead = document.querySelector('.calendar-col .head');
    const headerH = firstHead ? firstHead.offsetHeight : 0;
    if (headerH) {
      const tc = q('#timeCol');
      if (tc) tc.style.paddingTop = headerH + 'px';
    }
  } catch (e) { /* ignore */ }
  // Group events by doctor
  const eventsByDoctor = {};
  (events || []).forEach(ev => {
    const did = ev.doctor_id ? String(ev.doctor_id) : 'unassigned';
    if (!eventsByDoctor[did]) eventsByDoctor[did] = [];
    eventsByDoctor[did].push(ev);
  });
  doctorsToRender.forEach((doc, idx) => {
    const docKey = doc.id ? String(doc.id) : 'unassigned';
    const docEvents = eventsByDoctor[docKey] || [];
    const overlapMap = buildOverlapLayout(docEvents);
    const col = document.querySelectorAll('.calendar-col')[idx];
    const hoursEl = col.querySelector('.calendar-hours');
    const laneGap = 8;
    docEvents.forEach(ev => {
      const cfg = statusConfig[ev.status] || statusConfig.online;
      let finalColor = DEPARTMENT_COLORS[ev.doctor_department || ev.department] || cfg.color;

      // If paid, override department color with Paid color
      if (ev.status === 'paid') {
        finalColor = statusConfig.paid.color;
      }

      const el = document.createElement('div');
      el.className = 'cal-event';
      // Solid background with 0.25 opacity
      const solidBg = hexToRgba(finalColor, 0.25);
      el.style.background = solidBg || '#f1f5f9';
      el.style.borderLeft = `6px solid ${finalColor}`;
      el.style.padding = '6px 10px 6px 10px';
      el.style.fontSize = '0.85rem';
      el.style.cursor = 'pointer';
      el.style.border = `1px solid ${finalColor}80`;
      el.style.zIndex = '3';
      el.style.display = 'flex';
      el.style.flexDirection = 'column';
      el.style.overflow = 'hidden';
      el.style.position = 'absolute';
      const dur = Math.max(30, minsBetween(ev.start_time, ev.end_time));
      el.style.top = `${hoursToY(ev.start_time)}px`;
      el.style.height = `${Math.max(44, dur * (PX_PER_HOUR / 60))}px`;
      // Overlap lane logic
      const ov = overlapMap[ev.id] || { laneIndex: 0, laneCount: 1 };
      if (ov.laneCount > 1) {
        el.style.width = `calc(${100 / ov.laneCount}% - ${laneGap}px)`;
        el.style.left = `calc(${ov.laneIndex * (100 / ov.laneCount)}% + ${laneGap / 2}px)`;
      } else {
        el.style.left = '0';
        el.style.right = '0';
      }
      // Compact card layout with HH:MM time
      const timeLabel = `${hhmm(ev.start_time)}–${hhmm(ev.end_time)}`;
      el.innerHTML = `
        <div class=\"ev-row\" style=\"display:flex;align-items:center;justify-content:space-between;gap:6px;\">
          <div class=\"ev-time\" style=\"font-size:0.92em;color:#64748b;font-weight:600;\">${timeLabel}</div>
          ${ev.phone ? `<div class=\"ev-pill\" style=\"background:#0d9488;color:white;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;letter-spacing:0.5px;opacity:.95;\">${esc(ev.phone)}</div>` : ''}
        </div>
        <div class=\"ev-title\" style=\"font-weight:700;color:#22223b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;\">${esc(ev.service_name || '')}</div>
      `;
      el.title = `${timeLabel} • ${ev.service_name || ''} • ${ev.phone || ''}`;
      // Improved hover/focus
      el.classList.add('cal-event');
      el.addEventListener('mouseenter', () => { el.style.zIndex = '999'; el.style.boxShadow = '0 12px 30px rgba(0,0,0,.18)'; el.style.transform = 'translateY(-1px)'; });
      el.addEventListener('mouseleave', () => { el.style.zIndex = '3'; el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.10)'; el.style.transform = 'none'; });
      el.addEventListener('click', e => { e.stopPropagation(); openEdit(ev); });
      hoursEl.appendChild(el);
    });
  });
  initScrollSync();
  initScrollSync();
}

function renderWeekView(startDate, endDate, events) {
  updateDateLabel(`${fmtDate(startDate)} – ${fmtDate(endDate)}`);
  const timeCol = q('#timeCol');
  timeCol.innerHTML = '';
  const totalHours = WORK_END - WORK_START;
  const gridHeight = totalHours * PX_PER_HOUR;
  for (let h = WORK_START; h < WORK_END; h++) {
    timeCol.innerHTML += `<div style="height:${PX_PER_HOUR}px;line-height:${PX_PER_HOUR}px;padding-left:8px;font-weight:600;color:#64748b;font-size:0.8rem;">${String(h).padStart(2, '0')}:00</div>`;
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
    const dayEvents = (events || []).filter(ev => ev.date === ds && parseHHMM(ev.start_time) && parseHHMM(ev.end_time));

    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #e2e8f0';
    col.style.backgroundColor = '#f8fafc';

    const headerBg = isToday ? 'linear-gradient(135deg,#ede9fe 0%,#f1f5f9 100%)' : 'linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%)';
    const headerBorder = isToday ? '#7c3aed' : '#e2e8f0';
    const headerColor = isToday ? '#7c3aed' : '#1e293b';
    col.innerHTML = `
      <div class="head text-center" style="background: ${headerBg}; border-bottom: 2px solid ${headerBorder};">
        <strong style="color: ${headerColor}; display: block; margin-bottom: 0.25rem; font-size: 0.95rem;">${names[i]}</strong>
        <small style="color: ${isToday ? '#7c3aed' : '#64748b'}; font-weight: 500;">${ds}</small>
      </div>
      <div class="calendar-hours position-relative"><div class="calendar-grid"></div></div>
    `;

    const hoursEl = col.querySelector('.calendar-hours');
    hoursEl.style.height = `${gridHeight}px`;
    hoursEl.style.overflow = 'visible';
    const calendarGrid = col.querySelector('.calendar-grid');
    if (calendarGrid) {
      calendarGrid.style.position = 'absolute';
      calendarGrid.style.left = '0';
      calendarGrid.style.right = '0';
      calendarGrid.style.top = '0';
      calendarGrid.style.height = `${gridHeight}px`;
      calendarGrid.style.pointerEvents = 'none';
      calendarGrid.style.background = `repeating-linear-gradient(to bottom, #e2e8f0 0px, #e2e8f0 1px, transparent 1px, transparent ${PX_PER_HOUR}px)`;
      calendarGrid.style.zIndex = '2';
    }

    (DOCTORS || []).filter(doc => parseInt(doc.show_in_calendar) === 1).forEach(doc => {
      const whData = (doc.working_hours || []).find(wh => parseInt(wh.day_of_week) === dayOfWeek);
      if (whData && parseInt(whData.is_available) === 1) {
        const startTop = Math.max(0, hoursToY(whData.start_time));
        const endTop = hoursToY(whData.end_time);
        if (endTop > startTop + 2) {
          const workDiv = document.createElement('div');
          workDiv.style.position = 'absolute';
          workDiv.style.left = '6px';
          workDiv.style.right = '6px';
          workDiv.style.top = `${startTop}px`;
          workDiv.style.height = `${Math.max(6, endTop - startTop)}px`;
          workDiv.style.background = 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)';
          workDiv.style.border = '1px solid #a7f3d0';
          workDiv.style.boxShadow = 'none';
          workDiv.style.borderRadius = '8px';
          workDiv.style.zIndex = '1';
          workDiv.style.pointerEvents = 'none';
          hoursEl.appendChild(workDiv);
        }
      }
    });

    const overlapMap = buildOverlapLayout(dayEvents);
    const laneGap = 8;
    const eventsSorted = [...dayEvents].sort((a, b) => {
      const sa = timeToMin(a.start_time), sb = timeToMin(b.start_time);
      if (sa !== sb) return sa - sb;
      return timeToMin(a.end_time) - timeToMin(b.end_time);
    });
    const clusters = [];
    let cur = [];
    let lastEnd = -1;
    for (const ev of eventsSorted) {
      const st = timeToMin(ev.start_time), et = timeToMin(ev.end_time);
      if (cur.length === 0 || st < lastEnd) {
        cur.push(ev);
        lastEnd = Math.max(lastEnd, et);
      } else {
        clusters.push(cur);
        cur = [ev];
        lastEnd = et;
      }
    }
    if (cur.length) clusters.push(cur);

    for (const cluster of clusters) {
      for (const ev of cluster) {
        const cfg = statusConfig[ev.status] || statusConfig.online;
        let finalColor = cfg.color; // Default to status color (Online = Blue)

        // Venera and Luxor: Online status should use Department Color as full background
        const showDeptSpecial = (CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor');
        if (showDeptSpecial && ev.status === 'online' && (ev.doctor_department || ev.department)) {
          const deptColor = DEPARTMENT_COLORS[ev.doctor_department || ev.department];
          if (deptColor) finalColor = deptColor;
        }

        // Apply high-opacity styling globally
        let displayBg = hexToRgba(finalColor, 0.95);
        let borderLeftColor = (ev.status === 'paid' || ev.status === 'arrived') ? shadeColor(finalColor, -20) : finalColor;
        let borderColor = shadeColor(finalColor, -15);

        const el = document.createElement('div');
        el.className = 'cal-event';
        el.style.background = displayBg || '#f1f5f9';
        el.style.borderLeft = `6px solid ${borderLeftColor}`;
        el.style.padding = '6px 10px 6px 10px';
        el.style.fontSize = '0.85rem';
        el.style.cursor = 'pointer';
        el.style.border = `1px solid ${borderColor}`;
        el.style.zIndex = '3';
        el.style.display = 'flex';
        el.style.flexDirection = 'column';
        el.style.overflow = 'hidden';
        el.style.position = 'absolute';
        const dur = Math.max(15, minsBetween(ev.start_time, ev.end_time));
        el.style.top = `${hoursToY(ev.start_time)}px`;
        el.style.height = `${Math.max(44, dur * (PX_PER_HOUR / 60))}px`;
        const ov = overlapMap[ev.id] || { laneIndex: 0, laneCount: 1 };
        if (ov.laneCount > 1) {
          el.style.width = `calc(${100 / ov.laneCount}% - ${laneGap}px)`;
          el.style.left = `calc(${ov.laneIndex * (100 / ov.laneCount)}% + ${laneGap / 2}px)`;
        } else {
          el.style.left = '0';
          el.style.right = '0';
        }
        const timeLabel = `${hhmm(ev.start_time)}–${hhmm(ev.end_time)}`;
        const showDeptBadge = ((CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor') && (ev.doctor_department || ev.department));
        const deptBadge = showDeptBadge ? `<span style="background:${finalColor};color:#fff;padding:2px 6px;border-radius:6px;font-size:10px;font-weight:700;display:inline-block;">${esc(ev.doctor_department || ev.department)}</span>` : '';
        el.innerHTML = `
          <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
            <div style="font-size:0.92em;color:#fff;font-weight:600;">${timeLabel}</div>
            ${ev.phone ? `<div style="background:#0d9488;color:white;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;opacity:.95;">${esc(ev.phone)}</div>` : ''}
          </div>
          <div style="font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.service_name || '')}</div>
          ${deptBadge ? `<div style="margin-top:4px;">${deptBadge}</div>` : ''}
        `;
        el.title = `${timeLabel} • ${ev.service_name || ''} • ${ev.phone || ''}`;
        el.addEventListener('mouseenter', () => { el.style.zIndex = '999'; el.style.boxShadow = '0 12px 30px rgba(0,0,0,.18)'; el.style.transform = 'translateY(-1px)'; });
        el.addEventListener('mouseleave', () => { el.style.zIndex = '3'; el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.10)'; el.style.transform = 'none'; });
        el.addEventListener('click', e => { e.stopPropagation(); openEdit(ev); });
        hoursEl.appendChild(el);
      }
    }

    hoursEl.addEventListener('click', e => {
      const rect = hoursEl.getBoundingClientRect();
      const y = e.clientY - rect.top + hoursEl.scrollTop;
      const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
      const hh = Math.floor(mins / 60);
      const mm = Math.floor((mins % 60) / 15) * 15;
      if (hh < WORK_START || hh >= WORK_END) return;
      const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
      const endH = hh + Math.floor((mm + 60) / 60);
      const endM = (mm + 60) % 60;
      const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      const docSel = q('#doctor_id');
      if (docSel) docSel.value = '';
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = ds;
      const startElW = q('#addForm input[name="start_time"]');
      const endElW = q('#addForm input[name="end_time"]');
      if (startElW) startElW.value = start;
      if (endElW) endElW.value = end;
      showModal('#modalAdd');
    });

    hoursEl.addEventListener('touchend', e => {
      try {
        const t = e.changedTouches && e.changedTouches[0];
        if (!t) return;
        const rect = hoursEl.getBoundingClientRect();
        const y = t.clientY - rect.top + hoursEl.scrollTop;
        const mins = y / (PX_PER_HOUR / 60) + WORK_START * 60;
        const hh = Math.floor(mins / 60);
        const mm = Math.floor((mins % 60) / 15) * 15;
        if (hh < WORK_START || hh >= WORK_END) return;
        const start = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
        const endH = hh + Math.floor((mm + 60) / 60);
        const endM = (mm + 60) % 60;
        const end = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
        const f = q('#addForm');
        if (!f) return;
        f.reset();
        const docSelTouch = q('#doctor_id');
        if (docSelTouch) docSelTouch.value = '';
        q('#clinic_in').value = CURRENT_CLINIC;
        q('#date').value = ds;
        const startElWT = q('#addForm input[name="start_time"]');
        const endElWT = q('#addForm input[name="end_time"]');
        if (startElWT) startElWT.value = start;
        if (endElWT) endElWT.value = end;
        showModal('#modalAdd');
        e.preventDefault();
      } catch { }
    }, { passive: false });

    row.appendChild(col);
  }

  try {
    const firstHead = document.querySelector('.calendar-col .head');
    const headerH = firstHead ? firstHead.offsetHeight : 0;
    if (headerH) {
      const tc = q('#timeCol');
      if (tc) tc.style.paddingTop = headerH + 'px';
    }
  } catch (e) { }
  initScrollSync();
}

function renderMonthView(y, m, events) {
  updateDateLabel(`${y} оны ${String(m).padStart(2, '0')}-р сар`);
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
  ['Дав', 'Мяг', 'Лха', 'Пүр', 'Баа', 'Бям', 'Ням'].forEach(n => {
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
    cell.style.overflow = 'hidden';

    // Day number
    cell.innerHTML = `<div style="font-weight: 700; margin-bottom: 0.5rem; color: ${isToday ? '#60a5fa' : '#e2e8f0'}; font-size: 0.95rem;">${day}</div>`;

    // Events for this day
    const dayEvents = (events || []).filter(e => e.date === ds);
    const eventContainer = document.createElement('div');
    eventContainer.style.fontSize = '0.75rem';
    eventContainer.style.maxHeight = '90px';
    eventContainer.style.overflow = 'hidden';

    dayEvents.forEach((ev, idx) => {
      if (idx >= 2) return; // max 2 events per cell
      const eventDiv = document.createElement('div');
      const cfg = statusConfig[ev.status] || statusConfig.online;
      let statusColor = cfg.color;

      // Venera and Luxor: Online status uses Department Color
      const showDeptSpecial = (CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor');
      if (showDeptSpecial && ev.status === 'online' && (ev.doctor_department || ev.department)) {
        const deptColor = DEPARTMENT_COLORS[ev.doctor_department || ev.department];
        if (deptColor) statusColor = deptColor;
      }

      let borderLeftColor = shadeColor(statusColor, -20);
      let bgStyle = hexToRgba(statusColor, 0.95);
      eventDiv.style.background = bgStyle;
      eventDiv.style.padding = '0.25rem 0.5rem';
      eventDiv.style.borderRadius = '3px';
      eventDiv.style.marginBottom = '0.25rem';
      eventDiv.style.color = '#fff';
      eventDiv.style.display = 'flex';
      eventDiv.style.alignItems = 'center';
      eventDiv.style.justifyContent = 'space-between';
      eventDiv.style.gap = '8px';
      eventDiv.style.cursor = 'pointer';
      eventDiv.style.borderLeft = `4px solid ${borderLeftColor}`;

      const showDeptInMonth = ((CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor') && (ev.doctor_department || ev.department));
      const dotColor = showDeptInMonth ? (DEPARTMENT_COLORS[ev.doctor_department || ev.department] || statusColor) : statusColor;

      eventDiv.innerHTML = `
        <span style="display:inline-block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;min-width:0;">
          <span style="display:inline-flex;align-items:center;gap:6px;">
            <span style="width:10px;height:10px;border-radius:50%;background:${dotColor};flex-shrink:0;"></span>
            ${esc(ev.start_time)} <strong style="margin-left:6px;">${esc(ev.patient_name || '(no name)')}${ev.service_name ? ' — ' + esc(ev.service_name) : ''}</strong>
          </span>
        </span>
        <span style="display:flex;align-items:center;gap:6px;flex-shrink:0;margin-left:8px;">
          ${showDeptInMonth ? `<span style="background:${dotColor};color:#0f172a;padding:2px 6px;border-radius:8px;font-weight:700;font-size:10px;">${esc(ev.doctor_department || ev.department)}</span>` : ''}
          ${ev.phone ? `<span style="color:#93c5fd;font-weight:600;">📞 ${esc(ev.phone)}</span>` : ''}
        </span>
      `;
      eventDiv.addEventListener('click', (e) => {
        e.stopPropagation();
        openEdit(ev);
        showModal('#modalEdit');
      });
      eventContainer.appendChild(eventDiv);
    });

    if (dayEvents.length > 2) {
      const moreDiv = document.createElement('div');
      moreDiv.className = 'month-more-pill';
      moreDiv.style.fontSize = '11px';
      moreDiv.style.color = '#6366f1';
      moreDiv.style.background = '#f1f5f9';
      moreDiv.style.borderRadius = '12px';
      moreDiv.style.padding = '2px 10px';
      moreDiv.style.display = 'inline-block';
      moreDiv.style.cursor = 'pointer';
      moreDiv.style.marginTop = '2px';
      moreDiv.textContent = `+${dayEvents.length - 2}`;
      moreDiv.onclick = () => showMonthEventList(ds, dayEvents);
      eventContainer.appendChild(moreDiv);
    }

    // Modal for month event list
    if (!document.getElementById('monthEventModal')) {
      const modal = document.createElement('div');
      modal.id = 'monthEventModal';
      modal.className = 'modal fade';
      modal.tabIndex = -1;
      modal.innerHTML = `
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fas fa-calendar-day me-2"></i>Өдрийн бүх захиалга</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="monthEventListBody"></div>
      </div>
    </div>
  `;
      document.body.appendChild(modal);
    }

    function showMonthEventList(ds, events) {
      try {
        console.log('showMonthEventList called', ds, events?.length);
        const body = document.getElementById('monthEventListBody');
        if (!body) return;
        // Sort by start_time (be defensive against missing values)
        let sorted = [];
        try {
          sorted = [...(events || [])].sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
        } catch (e) {
          console.error('Error sorting events in showMonthEventList:', e);
          sorted = [...(events || [])];
        }
        body.innerHTML = `<div style="margin-bottom:10px;font-weight:600;color:#6366f1;">${ds} - ${sorted.length} захиалга</div>`;
        sorted.forEach(ev => {
          try {
            const cfg = statusConfig[ev.status] || statusConfig.online;
            let statusColor = cfg.color;

            // Venera-specific: Online status uses Department Color
            const showDeptSpecial = (CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor');
            if (showDeptSpecial && ev.status === 'online' && ev.department) {
              const deptColor = DEPARTMENT_COLORS[ev.department];
              if (deptColor) statusColor = deptColor;
            }

            let borderIndicatorColor = statusColor;

            const row = document.createElement('div');
            row.className = 'month-event-row';
            row.style.display = 'flex';
            row.style.alignItems = 'center';
            row.style.justifyContent = 'space-between';
            row.style.gap = '10px';
            row.style.padding = '7px 0';
            row.style.borderBottom = '1px solid #f1f5f9';
            row.style.cursor = 'pointer';
            row.onmouseenter = () => { row.style.background = '#f3f4f6'; };
            row.onmouseleave = () => { row.style.background = ''; };
            row.onclick = () => { openEdit(ev); hideModal('#monthEventModal'); };
            row.innerHTML = `
          <div style="font-weight:700;color:#374151;min-width:56px;">${hhmm(ev.start_time)}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;color:#22223b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.service_name || '')}</div>
            <div style="font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.patient_name || '')}</div>
          </div>
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="width:12px;height:12px;border-radius:50%;background:${borderIndicatorColor};display:inline-block;margin-right:2px;"></span>
            ${(ev.department && (CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor')) ? `<span style="background:${borderIndicatorColor};color:#1e293b;padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;">${esc(ev.department)}</span>` : ''}
            ${ev.phone ? `<span style="background:#0d9488;color:white;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;opacity:.95;">${esc(ev.phone)}</span>` : ''}
          </div>
        `;
            body.appendChild(row);
          } catch (innerErr) {
            console.error('Error rendering event row in showMonthEventList:', innerErr, ev);
          }
        });
        showModal('#monthEventModal');
      } catch (err) {
        console.error('Unexpected error in showMonthEventList:', err, ds, events);
      }
    }

    cell.appendChild(eventContainer);

    // Click to add new booking
    cell.addEventListener('click', (e) => {
      if (e.target !== cell && e.target.closest('[role="button"]')) return;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      const docSelTouch = q('#doctor_id');
      if (docSelTouch) docSelTouch.value = '';
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = ds;
      const startElM = q('#addForm input[name="start_time"]');
      const endElM = q('#addForm input[name="end_time"]');
      if (startElM) startElM.value = `${String(WORK_START).padStart(2, '0')}:00`;
      if (endElM) endElM.value = `${String(WORK_START + 1).padStart(2, '0')}:00`;
      showModal('#modalAdd');
    });
    // Touch support for month view
    cell.addEventListener('touchend', (e) => {
      if (e.target !== cell && e.target.closest('[role="button"]')) return;
      const f = q('#addForm');
      if (!f) return;
      f.reset();
      const docSelMonthTouch = q('#doctor_id');
      if (docSelMonthTouch) docSelMonthTouch.value = '';
      q('#clinic_in').value = CURRENT_CLINIC;
      q('#date').value = ds;
      const startElMT = q('#addForm input[name="start_time"]');
      const endElMT = q('#addForm input[name="end_time"]');
      if (startElMT) startElMT.value = `${String(WORK_START).padStart(2, '0')}:00`;
      if (endElMT) endElMT.value = `${String(WORK_START + 1).padStart(2, '0')}:00`;
      showModal('#modalAdd');
      e.preventDefault();
    }, { passive: false });
    grid.appendChild(cell);
  }
}

function openEdit(ev) {
  const f = document.getElementById('editForm');
  f.querySelector('[name="id"]').value = ev.id;
  f.querySelector('[name="clinic"]').value = ev.clinic;
  const docField = f.querySelector('[name="doctor_id"]');
  if (docField) {
    docField.value = ev.doctor_id ? ev.doctor_id : '';
    docField.classList.remove('input-error');
  }
  f.querySelector('[name="date"]').value = ev.date;
  f.querySelector('[name="start_time"]').value = ev.start_time;
  f.querySelector('[name="end_time"]').value = ev.end_time;
  f.querySelector('[name="patient_name"]').value = ev.patient_name;
  f.querySelector('[name="service_name"]').value = ev.service_name || '';
  const treatIdField = f.querySelector('[name="treatment_id"]');
  if (treatIdField) treatIdField.value = ev.treatment_id || '';
  f.querySelector('[name="gender"]').value = ev.gender || '';
  f.querySelector('[name="visit_count"]').value = ev.visit_count || 1;
  const deptEl = f.querySelector('[name="department"]');
  if (deptEl) deptEl.value = ev.department || '';
  f.querySelector('[name="phone"]').value = ev.phone;
  f.querySelector('[name="note"]').value = ev.note;
  f.querySelector('[name="status"]').value = ev.status || 'online';
  f.querySelector('[name="price"]').value = ev.price || 0;

  // Material Usage: Reset and Load
  document.getElementById('usageList').innerHTML = '<div class="text-center text-muted py-2"><i class="fas fa-spinner fa-spin me-2"></i>Уншиж байна...</div>';
  loadBookingUsage(ev.id);

  // Trigger visibility toggling for doctor field and price
  if (typeof toggleDoctorField === 'function') {
    toggleDoctorField(f.querySelector('[name="status"]'));
  }

  const modal = new bootstrap.Modal(document.getElementById('modalEdit'));
  modal.show();

  // Fetch Patient Summary
  fetchPatientSummary(ev.phone);
}

// === Material Usage Support ===
let INVENTORY_LOADED = false;

async function loadUsageMaterials() {
  if (INVENTORY_LOADED) return;
  try {
    const res = await fetchJSON(`${API}?action=get_inventory&t=${Date.now()}`);
    if (res.ok) {
      const select = document.getElementById('usageMaterialSelect');
      if (select) {
        select.innerHTML = '<option value="">-- Материал сонгох --</option>' +
          res.data.map(i => `<option value="${i.id}">${i.name} (${i.stock_quantity} ${i.unit || ''})</option>`).join('');
        INVENTORY_LOADED = true;
      }
    }
  } catch (e) { console.error('Load inventory error:', e); }
}

async function loadBookingUsage(bookingId) {
  try {
    const res = await fetchJSON(`${API}?action=get_usage&booking_id=${bookingId}&t=${Date.now()}`);
    if (res.ok) {
      renderBookingUsage(res.data);
    }
  } catch (e) {
    document.getElementById('usageList').innerHTML = '<div class="text-center text-danger small">Ачаалахад алдаа гарлаа</div>';
  }
}

function renderBookingUsage(data) {
  const list = document.getElementById('usageList');
  if (!data || !data.length) {
    list.innerHTML = '<div class="text-center text-muted py-2">Бичлэг байхгүй</div>';
    return;
  }
  let totalCost = 0;
  let html = '<table class="table table-sm table-borderless mb-0"><tbody>';
  data.forEach(u => {
    const cost = (u.quantity * u.cost_at_usage);
    totalCost += cost;
    html += `
      <tr>
        <td>${u.name}</td>
        <td class="text-end">${u.quantity}${u.unit || ''}</td>
        <td class="text-end fw-bold text-success">${new Intl.NumberFormat('mn-MN').format(cost)}₮</td>
        <td class="text-end">
          <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" onclick="deleteUsage(${u.id}, ${u.booking_id})">
            <i class="fas fa-times"></i>
          </button>
        </td>
      </tr>
    `;
  });
  html += `
    <tr class="border-top">
      <td colspan="2" class="fw-bold">Нийт зардал:</td>
      <td class="text-end fw-bold text-primary">${new Intl.NumberFormat('mn-MN').format(totalCost)}₮</td>
      <td></td>
    </tr>
  </tbody></table>`;
  list.innerHTML = html;
}

async function recordUsage() {
  const f = document.getElementById('editForm');
  const bookingId = f.querySelector('[name="id"]').value;
  const invId = document.getElementById('usageMaterialSelect').value;
  const qty = document.getElementById('usageQty').value;

  if (!invId || !qty) {
    alert('Материал болон тоог оруулна уу');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'record_usage');
  fd.append('booking_id', bookingId);
  fd.append('inventory_id', invId);
  fd.append('quantity', qty);

  try {
    const res = await fetch(`${API}`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      loadBookingUsage(bookingId);
      // Refresh inventory dropdown to reflect stock change
      INVENTORY_LOADED = false;
      loadUsageMaterials();
      // Clear inputs
      document.getElementById('usageQty').value = '';
    } else {
      alert('Алдаа: ' + (data.msg || 'Мэдэгдэхгүй алдаа'));
    }
  } catch (e) {
    console.error('Record usage error:', e);
    alert('Сүлжээний алдаа');
  }
}

async function deleteUsage(id, bookingId) {
  if (!confirm('Устгах уу?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_usage');
  fd.append('id', id);

  try {
    const res = await fetch(`${API}`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      loadBookingUsage(bookingId);
      INVENTORY_LOADED = false;
      loadUsageMaterials();
    } else {
      alert('Алдаа: ' + (data.msg || 'Устгахад алдаа гарлаа'));
    }
  } catch (e) {
    alert('Сүлжээний алдаа');
  }
}

// Global init for inventory selection
document.addEventListener('DOMContentLoaded', () => {
  const editModalEl = document.getElementById('modalEdit');
  if (editModalEl) {
    editModalEl.addEventListener('shown.bs.modal', loadUsageMaterials);
  }
});

async function fetchPatientSummary(phone, isAdd = false) {
  const suffix = isAdd ? 'Add' : '';
  const row = document.getElementById('patientInsightRow' + suffix);
  const text = document.getElementById('patientSummaryText' + suffix);
  const tag = document.getElementById('patientVisitTag' + suffix);
  const hist = document.getElementById('patientRecentHistory' + suffix);

  if (!phone || phone.length < 8) {
    row.style.display = 'none';
    return;
  }

  row.style.display = 'block';
  text.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Уншиж байна...';
  tag.style.display = 'none';
  hist.innerHTML = '';

  try {
    const res = await fetchJSON(`${API}?action=patient_summary&phone=${encodeURIComponent(phone)}`);
    if (res.ok) {
      text.innerText = `${res.visit_count}-дахь удаагийн үзлэг`;
      tag.innerText = res.visit_count > 1 ? 'Давтан өвчтөн' : 'Шинэ өвчтөн';
      tag.className = 'badge rounded-pill ' + (res.visit_count > 1 ? 'bg-success' : 'bg-primary');
      tag.style.display = 'inline-block';

      if (res.history && res.history.length > 0) {
        const items = res.history.map(h => `<span class="me-2"><i class="fas fa-check-circle text-success me-1"></i>${h.service_name} (${h.date})</span>`);
        hist.innerHTML = 'Сүүлийн үзлэгүүд: ' + items.join(' ');
      } else {
        hist.innerText = 'Өмнөх түүх байхгүй байна.';
      }
    } else {
      row.style.display = 'none';
    }
  } catch (e) {
    row.style.display = 'none';
  }
}


// Add event listeners for phone fields to trigger insights
function initPatientInsights() {
  const addPhone = document.getElementById('phone');
  const editPhone = document.querySelector('#editForm [name="phone"]');

  if (addPhone) {
    addPhone.addEventListener('input', (e) => {
      const val = e.target.value;
      clearTimeout(phoneLookupTimers.add);
      phoneLookupTimers.add = setTimeout(() => fetchPatientSummary(val, true), 500);
    });
  }

  if (editPhone) {
    editPhone.addEventListener('input', (e) => {
      const val = e.target.value;
      clearTimeout(phoneLookupTimers.edit);
      phoneLookupTimers.edit = setTimeout(() => fetchPatientSummary(val, false), 500);
    });
  }
}

async function saveEdit(e) {
  e.preventDefault();
  const f = e.target;
  const doctorVal = +f.querySelector('[name="doctor_id"]')?.value || 0;
  const payload = {
    id: +f.querySelector('[name="id"]').value,
    doctor_id: doctorVal > 0 ? doctorVal : null,
    clinic: f.querySelector('[name="clinic"]').value || CURRENT_CLINIC,
    date: f.querySelector('[name="date"]').value,
    start_time: f.querySelector('[name="start_time"]').value,
    end_time: f.querySelector('[name="end_time"]').value,
    patient_name: f.querySelector('[name="patient_name"]').value,
    service_name: f.querySelector('[name="service_name"]').value || '',
    gender: f.querySelector('[name="gender"]').value || '',
    visit_count: +(f.querySelector('[name="visit_count"]').value || 1),
    phone: f.querySelector('[name="phone"]').value,
    department: f.querySelector('[name="department"]').value || '',
    note: f.querySelector('[name="note"]').value,
    status: f.querySelector('[name="status"]').value,
    price: +(f.querySelector('[name="price"]')?.value || 0)
  };
  // Require department selection ONLY for Venera clinic
  if ((payload.clinic === 'venera' || payload.clinic === 'luxor') && !payload.department) {
    const deptEl = f.querySelector('[name="department"]');
    deptEl?.classList.add('input-error');
    deptEl?.focus();
    showNotification('Тасаг заавал сонгоно уу');
    return;
  }
  // Require doctor ONLY when setting status to 'paid' or 'confirmed'
  if ((payload.status === 'paid' || payload.status === 'confirmed') && !payload.doctor_id) {
    const docEl = f.querySelector('[name="doctor_id"]');
    if (docEl) { docEl.classList.add('input-error'); docEl.focus(); }
    showNotification('Төлбөрийн төлөвт шилжүүлэхэд эмчийг сонгоно уу');
    return;
  }
  // Require price when status is 'paid'
  if (payload.status === 'paid' && payload.price <= 0) {
    const priceEl = f.querySelector('[name="price"]');
    if (priceEl) { priceEl.classList.add('input-error'); priceEl.focus(); }
    showNotification('Төлбөрийн төлөвт шилжүүлэхэд үнийн дүн заавал оруулна уу');
    return;
  }
  // Check if material is pending (selected but not added)
  const matSel = document.getElementById('usageMaterialSelect');
  const matQty = document.getElementById('usageQty');
  if (matSel && matSel.value && matQty && matQty.value) {
    if (confirm('Та материалын мэдээлэл оруулсан боловч хадгалаагүй байна. "Тийм" гэвэл материалыг хадгалж, захиалгыг шинэчилнэ. "Үгүй" гэвэл зөвхөн захиалгыг шинэчилнэ.')) {
      await recordUsage(); // Auto-save usage
      // Wait a bit to ensure it processes
    }
  }
  try {
    const r = await fetchJSON(`${API}?action=update`, { method: 'POST', body: JSON.stringify(payload) });
    if (!r?.ok) { showNotification(`Алдаа: ${r?.msg || 'Шинэчлэхэд сүтэл гарлаа'}`); return; }
    hideModal('#modalEdit');
    INVENTORY_LOADED = false;
    await loadBookings();
    showNotification('✅ Захиалга амжилттай шинэчлэгдлээ');
  } catch (e) {
    console.error('Error saving:', e);
    showNotification('Алдаа: Захиалгу шинэчлэхэд сүтэл гарлаа');
  }
}

// Enforce that 'paid'/'confirmed' status requires a selected doctor in add/edit forms
function initDoctorPaidEnforcement() {
  const forms = [q('#addForm'), q('#editForm')];
  forms.forEach((f) => {
    if (!f) return;
    const docSel = f.querySelector('[name="doctor_id"]') || f.querySelector('#doctor_id');
    const statusSel = f.querySelector('[name="status"]') || f.querySelector('#status');

    function evaluate() {
      const hasDoc = docSel && (+docSel.value > 0);
      if (statusSel) {
        const optPaid = statusSel.querySelector('option[value="paid"]');
        const optConf = statusSel.querySelector('option[value="confirmed"]');
        if (optPaid) optPaid.disabled = !hasDoc;
        if (optConf) optConf.disabled = !hasDoc;
        if (!hasDoc && (statusSel.value === 'paid' || statusSel.value === 'confirmed')) {
          statusSel.value = 'online';
        }
      }
      if (docSel && !hasDoc) docSel.classList.remove('input-error');
    }

    // react to changes
    docSel && docSel.addEventListener('change', evaluate);
    if (statusSel) {
      statusSel.addEventListener('change', () => {
        // if user somehow selects paid while no doctor, prevent it
        const hasDoc = docSel && (+docSel.value > 0);
        if ((statusSel.value === 'paid' || statusSel.value === 'confirmed') && !hasDoc) {
          showNotification('Эмч сонгогдоогүй тул төлбөрийн төлөв рүү шилжүүлэх боломжгүй');
          statusSel.value = 'online';
        }
      });
    }

    // When modal opens, re-evaluate
    const modalEl = f.closest('.modal');
    if (modalEl) modalEl.addEventListener('show.bs.modal', evaluate);

    // initial
    setTimeout(evaluate, 0);
  });
}

// initialize enforcement
setTimeout(initDoctorPaidEnforcement, 50);

async function deleteBooking() {
  const f = q('#editForm');
  if (!f) return;
  const id = +getFormValue(f, 'id');
  if (!id) return;
  if (!confirm('Энэ захиалгыг устгахдаа зөв үү?')) return;
  try {
    const r = await fetchJSON(`${API}?action=delete`, { method: 'POST', body: JSON.stringify({ id }) });
    if (!r?.ok) { showNotification(`Алдаа: ${r?.msg || 'Устгахад сүтэл гарлаа'}`); return; }
    hideModal('#modalEdit');
    await loadBookings();
    showNotification('✅ Захиалга амжилттай устгалась');
  } catch (e) {
    console.error('Error deleting:', e);
    showNotification('Алдаа: Захиалгу устгахаад сүтэл гарлаа');
  }
}

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
      const treatmentSearchField = form.querySelector('#treatment_search');
      const treatmentIdField = form.querySelector('#treatment_id');
      const customTreatmentField = form.querySelector('#custom_treatment');
      const genderField = form.querySelector('[name="gender"]');
      const noteField = form.querySelector('[name="note"]');
      const visitField = form.querySelector('[name="visit_count"]');

      // Давтан үйлчилгээг шалгах (өмнө нэг удаа ирсэн байсан ч давтан гэж тооцно)
      if (visitField) {
        const visits = parseInt(r.data.visits) || 0;
        visitField.value = visits >= 1 ? '2' : '1';
      }

      // Өмнөх мэдээлэл дүүргэх
      if (r.data.patient_name && nameField) nameField.value = r.data.patient_name;
      // Edit form simple текст талбар
      if (serviceField && r.data.service_name) { serviceField.value = r.data.service_name; serviceField.classList.remove('input-error'); }
      // Add form searchable treatment сонголт
      if (formId === 'addForm' && r.data.service_name) {
        const prevName = (r.data.service_name || '').toString().trim();
        if (treatmentSearchField) treatmentSearchField.value = prevName;
        if (treatmentIdField && customTreatmentField) {
          const match = (TREATMENTS || []).find(t => (t.name || '').toLowerCase() === prevName.toLowerCase());
          if (match) {
            treatmentIdField.value = match.id;
            customTreatmentField.value = '';
            // Show treatment duration badge
            const durationBadge = q('#treatmentDurationBadge');
            if (durationBadge) {
              const duration = parseInt(match.duration_minutes) || 60;
              durationBadge.textContent = `${duration} мин`;
              durationBadge.style.display = 'inline-block';
            }
            // Автоматаар дуусах цагийг эмчилгээний үргэлжлэх хугацаагаар тооцоолох
            const startTimeInput = q('#addForm input[name="start_time"]');
            const endTimeInput = q('#addForm input[name="end_time"]');
            const duration = parseInt(match.duration_minutes) || 60;
            if (startTimeInput && startTimeInput.value && endTimeInput) {
              const [h, m] = startTimeInput.value.split(':').map(Number);
              const startMin = h * 60 + m;
              const endMin = startMin + duration;
              const eh = String(Math.floor(endMin / 60)).padStart(2, '0');
              const em = String(endMin % 60).padStart(2, '0');
              endTimeInput.value = `${eh}:${em}`;
            }
          } else {
            treatmentIdField.value = '';
            customTreatmentField.value = prevName;
            const durationBadge = q('#treatmentDurationBadge');
            if (durationBadge) durationBadge.style.display = 'none';
          }
        }
      }
      // Preselect doctor from patient's last visit
      if (formId === 'addForm' && r.data && DOCTORS.length > 0) {
        const doctorSelect = q('#addForm select[name="doctor_id"]');
        if (doctorSelect && r.data.doctor_name) {
          const doctorMatch = DOCTORS.find(d => (d.name || '').toLowerCase() === (r.data.doctor_name || '').toLowerCase());
          if (doctorMatch) {
            doctorSelect.value = doctorMatch.id;
            showNotification(`✅ Өмнөх эмч: ${doctorMatch.name}`);
          }
        }
      }
      if (r.data.gender && genderField) {
        const g = (r.data.gender || '').toString().toLowerCase();
        if (g.match(/эм|female|woman|girl|эмэ/)) {
          genderField.value = 'female';
        } else if (g.match(/эр|male|man|boy|эрэ/)) {
          genderField.value = 'male';
        } else {
          genderField.value = r.data.gender;
        }
      }
      if (r.data.note && noteField) noteField.value = r.data.note;

      if (formId === 'addForm') showNotification('Өмнөх үйлчилгээ: ' + (r.data.service_name || 'Мэдээлэл байхгүй'));
    } else {
      const visitField = form.querySelector('[name="visit_count"]');
      if (visitField) visitField.value = '1';
      if (formId === 'addForm') showNotification('Анхны үйлчилгээний хэрэглэгч');
    }
  } catch (e) {
    console.error('Error loading patient info:', e);
  }
}

function initScrollSync() {
  const timeCol = q('#timeCol');
  const cols = Array.from(document.querySelectorAll('.calendar-hours'));
  if (!timeCol) return;
  timeCol.onscroll = () => cols.forEach(el => (el.scrollTop = timeCol.scrollTop));
  cols.forEach(el => { el.onscroll = () => (timeCol.scrollTop = el.scrollTop); });
}

function showNotification(msg) {
  const ntf = document.createElement('div');
  ntf.style.cssText = 'position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #e2e8f0; padding: 1rem 1.5rem; border-radius: 8px; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3); font-weight: 500; z-index: 9999; animation: slideIn 0.3s ease;';
  ntf.textContent = msg;
  document.body.appendChild(ntf);
  setTimeout(() => { ntf.style.animation = 'slideOut 0.3s ease'; setTimeout(() => ntf.remove(), 300); }, 3000);
}

function setFormValue(form, name, v) {
  const el = form.querySelector(`[name="${name}"]`);
  if (el) el.value = v ?? '';
}
function getFormValue(form, name) {
  const el = form.querySelector(`[name="${name}"]`);
  return el ? el.value : '';
}
function showModal(sel) {
  const el = q(sel);
  if (el) bootstrap.Modal.getOrCreateInstance(el).show();
}
function hideModal(sel) {
  const el = q(sel);
  if (el) bootstrap.Modal.getOrCreateInstance(el).hide();
}

const style = document.createElement('style');
style.textContent = '@keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } } @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(400px); opacity: 0; } }';
document.head.appendChild(style);

document.addEventListener('DOMContentLoaded', async () => {
  applyCssVars();
  const clinicSel = q('#clinic');
  if (clinicSel && clinicSel.value) CURRENT_CLINIC = clinicSel.value;
  const deptSel = q('#departmentSelect');
  if (deptSel && deptSel.value) CURRENT_DEPARTMENT = deptSel.value || CURRENT_DEPARTMENT || null;
  // Lock department selector for restricted roles
  if ((USER_ROLE === 'doctor' || USER_ROLE === 'reception')) {
    if (deptSel) {
      if (USER_DEPARTMENT) {
        deptSel.value = USER_DEPARTMENT;
      }
      deptSel.setAttribute('disabled', 'disabled');
    }
  }
  // Show department selector only for Venera and Luxor clinics; clear filter when not
  if (deptSel) {
    const isVenera = CURRENT_CLINIC === 'venera';
    const isLuxor = CURRENT_CLINIC === 'luxor';
    if (!isVenera && !isLuxor) {
      CURRENT_DEPARTMENT = null;
      deptSel.value = '';
      deptSel.style.display = 'none';
    } else {
      deptSel.style.display = '';
    }
  }
  await loadDoctors();
  await loadTreatments();
  initDoctorPaidEnforcement();
  initPatientInsights();
  await loadBookings();
  window.addEventListener('resize', () => {
    loadBookings();
  });
  window.addEventListener('message', async e => {
    if (e.data.reloadDoctors) { await loadDoctors(); await loadBookings(); showNotification('Doctor hours updated'); }
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
  q('#today')?.addEventListener('click', () => { CURRENT_DATE = new Date(); loadBookings(); });
  clinicSel?.addEventListener('change', async e => {
    CURRENT_CLINIC = e.target.value;
    const deptSel = q('#departmentSelect');
    if (deptSel) {
      const isVenera = CURRENT_CLINIC === 'venera';
      const isLuxor = CURRENT_CLINIC === 'luxor';
      if (!isVenera && !isLuxor) {
        CURRENT_DEPARTMENT = null;
        deptSel.value = '';
        deptSel.style.display = 'none';
      } else {
        deptSel.style.display = '';
      }
    }
    await loadDoctors(); await loadTreatments(); await loadBookings();
  });
  deptSel?.addEventListener('change', async e => { CURRENT_DEPARTMENT = e.target.value || null; await loadDoctors(); await loadBookings(); });
  const updateViewButtons = mode => {
    document.querySelectorAll('#viewDay, #viewWeek, #viewMonth').forEach(btn => { btn.classList.remove('active'); btn.style.background = ''; btn.style.color = ''; });
    const activeBtn = mode === 'day' ? q('#viewDay') : mode === 'week' ? q('#viewWeek') : q('#viewMonth');
    if (activeBtn) { activeBtn.classList.add('active'); activeBtn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%)'; activeBtn.style.color = 'white'; activeBtn.style.borderColor = 'transparent'; }
  };
  q('#viewDay')?.addEventListener('click', () => { VIEW_MODE = 'day'; updateViewButtons('day'); loadBookings(); });
  q('#viewWeek')?.addEventListener('click', () => { VIEW_MODE = 'week'; updateViewButtons('week'); loadBookings(); });
  q('#viewMonth')?.addEventListener('click', () => { VIEW_MODE = 'month'; updateViewButtons('month'); loadBookings(); });
  updateViewButtons(VIEW_MODE);
  q('#addForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target;
    const phoneField = f.querySelector('#phone');
    const phoneVal = phoneField ? phoneField.value.trim() : '';
    const treatmentIdField = f.querySelector('#treatment_id');
    const customTreatmentField = f.querySelector('#custom_treatment');
    const treatmentSearchField = f.querySelector('#treatment_search');
    const deptField = f.querySelector('#department');
    const treatmentId = +(treatmentIdField?.value || 0);
    const customTreatment = customTreatmentField?.value?.trim() || '';

    if (!phoneVal) { phoneField?.classList.add('input-error'); phoneField?.focus(); showNotification('Утасны дугаар оруулна уу'); return; }
    phoneField?.classList.remove('input-error');

    // Validate treatment - either selected or custom entered
    if (!treatmentId && !customTreatment) {
      treatmentSearchField?.classList.add('input-error');
      treatmentSearchField?.focus();
      showNotification('Эмчилгээний төрөл сонгох эсвэл бичнэ үү');
      return;
    }
    treatmentSearchField?.classList.remove('input-error');

    // Require department selection ONLY for Venera clinic
    const deptVal = deptField ? deptField.value : '';
    if ((CURRENT_CLINIC === 'venera' || CURRENT_CLINIC === 'luxor') && !deptVal) {
      deptField?.classList.add('input-error');
      deptField?.focus();
      showNotification('Тасаг заавал сонгоно уу');
      return;
    }
    deptField?.classList.remove('input-error');

    if (typeof f.reportValidity === 'function' && !f.reportValidity()) return;

    // Get treatment name for service_name field
    let serviceName = customTreatment;
    if (treatmentId) {
      const selectedTreatment = TREATMENTS.find(t => t.id == treatmentId);
      serviceName = selectedTreatment ? selectedTreatment.name : '';
    }

    const docVal = +(f.querySelector('#doctor_id')?.value || 0);
    const payload = {
      action: 'add',
      clinic: CURRENT_CLINIC,
      doctor_id: docVal > 0 ? docVal : null,
      date: f.querySelector('#date').value,
      start_time: f.querySelector('input[name="start_time"]').value,
      end_time: f.querySelector('input[name="end_time"]').value,
      patient_name: f.querySelector('#patient_name').value.trim(),
      phone: phoneVal,
      status: f.querySelector('#status').value || 'online',
      service_name: serviceName,
      gender: f.querySelector('#gender')?.value || '',
      visit_count: +(f.querySelector('#visit_count')?.value || 1),
      note: (f.querySelector('#note')?.value || '').trim(),
      treatment_id: treatmentId || null,
      custom_treatment: customTreatment,
      department: deptVal,
      price: +(f.querySelector('#addPrice')?.value || 0)
    };
    // Require doctor ONLY when status is 'paid' or 'confirmed'
    if ((payload.status === 'paid' || payload.status === 'confirmed') && !payload.doctor_id) {
      const docEl = f.querySelector('#doctor_id');
      if (docEl) { docEl.classList.add('input-error'); docEl.focus(); }
      showNotification('Төлбөрийн төлөвт шилжүүлэхэд эмчийг сонгоно уу');
      return;
    }
    // Require price when status is 'paid'
    if (payload.status === 'paid' && payload.price <= 0) {
      const priceEl = f.querySelector('#addPrice');
      if (priceEl) { priceEl.classList.add('input-error'); priceEl.focus(); }
      showNotification('Төлбөрийн төлөвт шилжүүлэхэд үнийн дүн заавал оруулна уу');
      return;
    }
    try {
      const r = await fetch(`api.php?action=create`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const j = await r.json();
      if (!j?.ok) { showNotification(`Алдаа: ${j?.msg || 'Алдаа'}`); return; }
      hideModal('#modalAdd');
      f.reset();
      // Clear treatment search field
      if (treatmentSearchField) treatmentSearchField.value = '';
      if (treatmentIdField) treatmentIdField.value = '';
      if (customTreatmentField) customTreatmentField.value = '';
      // Hide duration badge
      const durationBadge = q('#treatmentDurationBadge');
      if (durationBadge) durationBadge.style.display = 'none';
      await loadBookings();
      showNotification(`✅ ${j?.msg || 'Шинэ захиалга амжилттай нэмэгдлээ'}`);
    } catch (err) {
      console.error('Error adding:', err);
      showNotification('Алдаа: Захиалга нэмэхэд сүтэл гарлаа');
    }
  });
  const schedulePatientLookup = (inputEl, formId) => {
    const phone = inputEl.value.trim();
    if (phoneLookupTimers[formId]) clearTimeout(phoneLookupTimers[formId]);
    if (phone.length >= 4) { phoneLookupTimers[formId] = setTimeout(() => { if (phone.length >= 7) loadPatientInfo(phone, formId); }, 500); }
  };
  const addPhoneInput = q('#addForm #phone');
  addPhoneInput?.addEventListener('blur', e => { const phone = e.target.value.trim(); if (phone.length >= 7) loadPatientInfo(phone, 'addForm'); });
  addPhoneInput?.addEventListener('input', e => { addPhoneInput.classList.remove('input-error'); schedulePatientLookup(addPhoneInput, 'addForm'); });
  const addTreatmentSelect = q('#addForm #treatment_id');
  addTreatmentSelect?.addEventListener('change', () => addTreatmentSelect.classList.remove('input-error'));
  // Update duration badge when treatment selected via treatment_id hidden field
  const treatmentIdField = q('#treatment_id');
  const customTreatmentField = q('#custom_treatment');
  const durationBadge = q('#treatmentDurationBadge');
  if (treatmentIdField && durationBadge) {
    treatmentIdField.addEventListener('change', () => {
      const treatmentId = treatmentIdField.value;
      if (treatmentId) {
        const treatment = (TREATMENTS || []).find(t => t.id == treatmentId);
        if (treatment) {
          const duration = parseInt(treatment.duration_minutes) || 60;
          durationBadge.textContent = `${duration} мин`;
          durationBadge.style.display = 'inline-block';
        }
      } else {
        durationBadge.style.display = 'none';
      }
    });
  }
  const editPhoneInput = q('#editForm [name="phone"]');
  editPhoneInput?.addEventListener('blur', e => { const phone = e.target.value.trim(); if (phone.length >= 7) loadPatientInfo(phone, 'editForm'); });
  editPhoneInput?.addEventListener('input', e => { schedulePatientLookup(editPhoneInput, 'editForm'); });
  document.getElementById('modalAdd')?.addEventListener('shown.bs.modal', () => {
    const phone = q('#addForm #phone');
    if (phone) {
      phone.classList.add('phone-reminder');
      phone.focus();
      setTimeout(() => phone.classList.remove('phone-reminder'), 1600);
    }

    // Өмнөх үйлчилгээний дараа өдөр автоматаар огноо бөглөх
    const dateField = q('#addForm input[name="date"]');
    if (dateField && !dateField.value) {
      const normalized = phone ? phone.value.replace(/\D+/g, '') : '';
      if (normalized) {
        const url = `${API}?action=patient_info&clinic=${encodeURIComponent(CURRENT_CLINIC)}&phone=${encodeURIComponent(normalized)}&_=${Date.now()}`;
        fetchJSON(url)
          .then(r => {
            if (r?.ok && r.data?.last_visit) {
              const lastDate = new Date(r.data.last_visit);
              const nextDate = new Date(lastDate);
              nextDate.setDate(nextDate.getDate() + 1);
              const formattedDate = nextDate.toISOString().split('T')[0];
              dateField.value = formattedDate;
            }
          })
          .catch(e => console.error('Error loading last visit:', e));
      }
    }
  });

  // Төлбөр төлөгдсөн статус сонгосон үед нэмэлт modal нээж эмч сонгуулах
  const editStatusSelect = q('#editForm [name="status"]') || q('#editStatusSelect');
  if (editStatusSelect) {
    editStatusSelect.addEventListener('change', function () {
      if (this.value === 'paid') {
        // Төлбөр төлөгдсөн болоход эмч сонгох modal нээх
        const editForm = q('#editForm');
        const doctorSelect = editForm ? editForm.querySelector('[name="doctor_id"]') : null;

        if (doctorSelect && !doctorSelect.value || doctorSelect.value == 0) {
          // Эмч сонгоогүй бол alert үзүүлж эмч сонгуулах
          showNotification('⚠️ Төлбөр төлөгдсөнд статусыг шилжүүлэхэд эмчийг сонгох шаардлагатай');
          setTimeout(() => {
            if (doctorSelect) {
              doctorSelect.focus();
              doctorSelect.classList.add('input-error');
            }
          }, 100);
        }
      }
    });
  }

  q('#editForm')?.addEventListener('submit', saveEdit);
  q('#btnDelete')?.addEventListener('click', deleteBooking);

  // Trigger app pickers initialization if defined in index.php
  if (typeof window.initAppPickers === 'function') {
    window.initAppPickers();
  }
});
