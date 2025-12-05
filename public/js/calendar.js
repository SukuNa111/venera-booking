const API = './api.php';
let DOCTORS = [];
let CURRENT_DATE = new Date();
let CURRENT_CLINIC = (typeof window !== 'undefined' && window.CURRENT_CLINIC) ? window.CURRENT_CLINIC : 'venera';
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
const fmtDate = d => {
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
};
const fetchJSON = (u, o = {}) => fetch(u, Object.assign({ headers: { 'Content-Type': 'application/json' } }, o)).then(r => r.json()).catch(e => { throw e; });
const hoursToY = t => {
  if (!t) return 0;
  // Handle both HH:MM and HH:MM:SS formats
  const parts = t.split(':').map(Number);
  const h = parts[0] || 0;
  const m = parts[1] || 0;
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

const statusConfig = {
  online: { color: '#3b82f6', bg: 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)', textColor: '#1e40af', name: 'Online', badge: '#1e40af' },
  arrived: { color: '#f59e0b', bg: 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)', textColor: '#92400e', name: 'Arrived', badge: '#a16207' },
  paid: { color: '#10b981', bg: 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)', textColor: '#065f46', name: 'Paid', badge: '#065f46' },
  pending: { color: '#8b5cf6', bg: 'linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%)', textColor: '#5b21b6', name: 'Pending', badge: '#581c87' },
  cancelled: { color: '#ef4444', bg: 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)', textColor: '#991b1b', name: 'Cancelled', badge: '#7f1d1d' }
};

let TREATMENTS = [];

function applyCssVars() {
  document.documentElement.style.setProperty('--hour-h', `${PX_PER_HOUR}px`);
}

async function loadTreatments() {
  try {
    const url = `${API}?action=treatments&_=${Date.now()}`;
    const r = await fetchJSON(url);
    if (!r?.ok) {
      console.error('Treatments API Error:', r?.msg || 'Unknown error');
      return;
    }
    TREATMENTS = r.data || [];
    
    // Initialize searchable treatment select
    initTreatmentSearch();
    
    // Also update edit modal select
    const selEdit = q('#modalEdit select[name="treatment_id"]');
    if (selEdit) {
      const options = '<option value="">Сонгоогүй</option>' + 
        TREATMENTS.map(t => `<option value="${t.id}">${esc(t.name)} (${t.sessions} удаа)</option>`).join('');
      selEdit.innerHTML = options;
    }
  } catch (e) {
    console.error('Error loading treatments:', e);
  }
}

function initTreatmentSearch() {
  const searchInput = q('#treatment_search');
  const dropdown = q('#treatment_dropdown');
  const hiddenId = q('#treatment_id');
  const hiddenCustom = q('#custom_treatment');
  
  if (!searchInput || !dropdown) return;
  
  function renderDropdown(searchTerm = '') {
    const term = searchTerm.toLowerCase().trim();
    let html = '';
    
    // Filter treatments
    let filtered = TREATMENTS.filter(t => {
      const name = (t.name || '').toLowerCase();
      const category = (t.category || '').toLowerCase();
      return name.includes(term) || category.includes(term);
    });
    
    // If no search term, show all (limited to 50 for performance)
    if (!term) {
      filtered = filtered.slice(0, 50);
    }
    
    // Show custom option if there's a search term and no exact match
    if (term && !filtered.some(t => t.name.toLowerCase() === term)) {
      html += `<div class="treatment-option custom-option" data-custom="${esc(searchTerm)}">
        <i class="fas fa-plus"></i> "${esc(searchTerm)}" гэж шинээр нэмэх
      </div>`;
    }
    
    // Group by category
    const grouped = {};
    filtered.forEach(t => {
      const cat = t.category || 'Бусад';
      if (!grouped[cat]) grouped[cat] = [];
      grouped[cat].push(t);
    });
    
    // Show filtered treatments grouped by category
    Object.keys(grouped).sort().forEach(cat => {
      if (Object.keys(grouped).length > 1) {
        html += `<div class="treatment-category">${esc(cat)}</div>`;
      }
      grouped[cat].forEach(t => {
        const price = t.price > 0 ? `<span class="treatment-price">${Number(t.price).toLocaleString()}₮</span>` : '';
        html += `<div class="treatment-option" data-id="${t.id}" data-name="${esc(t.name)}" data-price="${t.price || 0}">
          <span class="treatment-name">${esc(t.name)} <small>(${t.sessions} удаа)</small></span>
          ${price}
        </div>`;
      });
    });
    
    if (!html) {
      html = '<div class="treatment-option no-result">Олдсонгүй. Бичээд нэмнэ үү.</div>';
    }
    
    dropdown.innerHTML = html;
  }
  
  // Show dropdown on focus
  searchInput.addEventListener('focus', () => {
    renderDropdown(searchInput.value);
    dropdown.classList.add('show');
  });
  
  // Filter on input
  searchInput.addEventListener('input', () => {
    renderDropdown(searchInput.value);
    dropdown.classList.add('show');
    // Clear hidden fields when typing
    hiddenId.value = '';
    hiddenCustom.value = searchInput.value;
  });
  
  // Handle option click
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.treatment-option');
    if (!opt || opt.style.cursor === 'default') return;
    
    if (opt.dataset.custom) {
      // Custom treatment
      searchInput.value = opt.dataset.custom;
      hiddenId.value = '';
      hiddenCustom.value = opt.dataset.custom;
    } else if (opt.dataset.id) {
      // Existing treatment
      searchInput.value = opt.dataset.name;
      hiddenId.value = opt.dataset.id;
      hiddenCustom.value = '';
    }
    
    dropdown.classList.remove('show');
    searchInput.classList.remove('input-error');
  });
  
  // Hide dropdown on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.treatment-select-wrapper')) {
      dropdown.classList.remove('show');
    }
  });
  
  // Keyboard navigation
  searchInput.addEventListener('keydown', (e) => {
    const options = dropdown.querySelectorAll('.treatment-option:not([style*="cursor:default"])');
    const active = dropdown.querySelector('.treatment-option.active');
    let idx = Array.from(options).indexOf(active);
    
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (idx < options.length - 1) idx++;
      options.forEach(o => o.classList.remove('active'));
      if (options[idx]) options[idx].classList.add('active');
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (idx > 0) idx--;
      options.forEach(o => o.classList.remove('active'));
      if (options[idx]) options[idx].classList.add('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (active) active.click();
      else if (options[0]) options[0].click();
    } else if (e.key === 'Escape') {
      dropdown.classList.remove('show');
    }
  });
}

async function loadDoctors() {
  try {
    const url = `${API}?action=doctors&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`;
    const r = await fetchJSON(url);
    if (!r?.ok) {
      console.error('API Error:', r?.msg || 'Unknown error');
      showNotification(`Алдаа: ${r?.msg || 'Эмчдийг ачаалахад сүтэл гарлаа'}`);
      return;
    }
    DOCTORS = r.data || [];
    console.log(`Loaded ${DOCTORS.length} doctors`);
    const options = (DOCTORS || []).map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    const selAdd = q('#doctor_id');
    const selEdit = q('#modalEdit select[name="doctor_id"]');
    if (selAdd) selAdd.innerHTML = options;
    if (selEdit) selEdit.innerHTML = options;
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
    const r = await fetchJSON(`${API}?action=bookings&date=${date}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
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
    const r = await fetchJSON(`${API}?action=bookings_week&start=${fmtDate(start)}&end=${fmtDate(end)}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
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
    const r = await fetchJSON(`${API}?action=bookings_month&month=${y}-${m}&clinic=${encodeURIComponent(CURRENT_CLINIC)}&_=${Date.now()}`);
    renderMonthView(y, parseInt(m, 10), r?.ok ? r.data || [] : []);
  } catch (e) {
    console.error('Error loading month view:', e);
    showNotification('Алдаа: Сарын хэсгийг ачаалахад сүтэл гарлаа');
  }
}

function renderNoDoctors() {
  q('#dateLabel').textContent = `${fmtDate(CURRENT_DATE)}`;
  q('#timeCol').innerHTML = '';
  q('#calendarRow').innerHTML = '<div class="w-100 text-center text-muted p-5"><i class="fas fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i><p>Энэ клиникт идэвхтэй эмч олдсонгүй</p></div>';
}

function renderDayView(date, events) {
  if (!DOCTORS.length) return renderNoDoctors();
  q('#dateLabel').textContent = `Өдөр: ${date}`;
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
  (DOCTORS || []).filter(d => parseInt(d.show_in_calendar) === 1).forEach(d => {
    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #e2e8f0';
    col.style.backgroundColor = '#f8fafc';
    const docColor = d.color || '#6366f1';
    const dayOfWeek = new Date(date).getDay();
    const todayWorkHours = d.working_hours?.find(wh => parseInt(wh.day_of_week) === dayOfWeek);
    let workLabel = `${String(WORK_START).padStart(2, '0')}:00–${String(WORK_END).padStart(2, '0')}:00`;
    if (todayWorkHours) {
      const st = todayWorkHours.start_time?.slice(0,5) || '09:00';
      const et = todayWorkHours.end_time?.slice(0,5) || '18:00';
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

  // Align time gutter with the header height so 09:00 lines up under doctor name
  try {
    const firstHead = document.querySelector('.calendar-col .head');
    const headerH = firstHead ? firstHead.offsetHeight : 0;
    if (headerH) {
      const tc = q('#timeCol');
      if (tc) tc.style.paddingTop = headerH + 'px';
    }
  } catch (e) { /* ignore */ }
  (events || []).forEach(ev => {
    const idx = (DOCTORS || []).findIndex(x => String(x.id) === String(ev.doctor_id));
    if (idx < 0) return;
    const col = document.querySelectorAll('.calendar-col')[idx];
    const hoursEl = col.querySelector('.calendar-hours');
    const calendarGrid = col.querySelector('.calendar-grid');
    if (calendarGrid) {
      calendarGrid.style.position = 'absolute';
      calendarGrid.style.left = '0';
      calendarGrid.style.right = '0';
      calendarGrid.style.top = '0';
      calendarGrid.style.bottom = '0';
      calendarGrid.style.pointerEvents = 'none';
      // stronger black hour lines, rendered above working blocks
      calendarGrid.style.background = `repeating-linear-gradient(to bottom, rgba(0,0,0,0.25) 0px, rgba(0,0,0,0.25) 1px, transparent 1px, transparent ${PX_PER_HOUR}px)`;
      calendarGrid.style.zIndex = '2';
    }
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
    el.style.zIndex = '3';
    el.style.transition = 'all 0.2s ease';
    el.style.display = 'flex';
    el.style.flexDirection = 'column';
    const dur = Math.max(30, minsBetween(ev.start_time, ev.end_time));
    el.style.top = `${hoursToY(ev.start_time)}px`;
    el.style.height = `${Math.max(64, dur * (PX_PER_HOUR / 60))}px`;
    el.style.overflow = 'hidden';
    el.title = [ev.patient_name, ev.phone || '', ev.service_name || '', `${ev.start_time}–${ev.end_time}`, cfg.name].filter(Boolean).join('\n');
    el.innerHTML = `
      <div style="font-weight:700;color:${cfg.textColor};font-size:.9rem;line-height:1.15;display:flex;align-items:center;gap:8px;">
        <span style="opacity:.85;font-size:.85rem;">${ev.start_time}–${ev.end_time}</span>
        <span style="margin-left:6px;color:#374151;font-weight:600;min-width:0;">${esc(ev.patient_name || '')}</span>
        ${ev.phone ? `<span style="margin-left:8px;background:#0d9488;color:white;padding:0.15rem 0.5rem;border-radius:6px;font-size:0.75rem;font-weight:700;flex-shrink:0;">${esc(ev.phone)}</span>` : ''}
      </div>
      <div style="margin-top:6px;display:flex;align-items:center;gap:8px;color:#64748b;font-weight:500;">
        <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(ev.service_name || '')}</span>
      </div>`;
    el.addEventListener('click', e => { e.stopPropagation(); openEdit(ev); });
    hoursEl.appendChild(el);
  });
  initScrollSync();
}

function renderWeekView(startDate, endDate, events) {
  q('#dateLabel').textContent = `Долоо хоног: ${fmtDate(startDate)} – ${fmtDate(endDate)}`;
  const timeCol = q('#timeCol');
  timeCol.innerHTML = '';
  // 09:00-18:00 = 9 rows - 18:00 is end time
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
    const col = document.createElement('div');
    col.className = 'calendar-col';
    col.style.borderRight = '1px solid #e2e8f0';
    col.style.backgroundColor = '#f8fafc';
    const headerBg = isToday ? 'linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%)' : 'linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%)';
    const headerColor = isToday ? '#7c3aed' : '#1e293b';
    const headerBorder = isToday ? '#8b5cf6' : '#e2e8f0';
    col.innerHTML = `<div class="head text-center" style="background: ${headerBg}; border-bottom: 2px solid ${headerBorder};"><strong style="color: ${headerColor}; display: block; margin-bottom: 0.25rem; font-size: 0.95rem;">${names[i]}</strong><small style="color: ${isToday ? '#7c3aed' : '#64748b'}; font-weight: 500;">${ds}</small></div><div class="calendar-hours position-relative"><div class="calendar-grid"></div></div>`;
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
    // For week view, non-working times show the calendar background; draw each doctor's working hours
    (DOCTORS || []).filter(doc => parseInt(doc.show_in_calendar) === 1).forEach(doc => {
      const whData = (doc.working_hours || []).find(wh => parseInt(wh.day_of_week) === dayOfWeek);
      if (whData && parseInt(whData.is_available) === 1) {
        const startTop = Math.max(0, hoursToY(whData.start_time));
        // Calculate end position based on actual end time
        const endTop = hoursToY(whData.end_time);
        if (endTop > startTop + 2) {
          const workDiv = document.createElement('div');
          workDiv.style.position = 'absolute';
          // inset slightly so stripes are visible and don't touch edges
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
      } else {
        // if doctor is off that day, optionally nothing to draw (calendar background shows off time)
      }
    });
    (events || []).filter(ev => ev.date === ds).forEach(ev => {
      const cfg = statusConfig[ev.status] || statusConfig.online;
      const el = document.createElement('div');
      el.className = 'event';
      el.style.background = cfg.bg;
      el.style.borderLeft = `4px solid ${cfg.color}`;
      el.style.borderRadius = '8px';
      el.style.padding = '0.5rem 0.6rem';
      el.style.fontSize = '0.75rem';
      el.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.08)';
      el.style.cursor = 'pointer';
      el.style.border = `1px solid ${cfg.color}30`;
      el.style.zIndex = '3';
      el.style.transition = 'all 0.2s ease';
      el.style.display = 'flex';
      el.style.flexDirection = 'column';
      const dur = Math.max(15, minsBetween(ev.start_time, ev.end_time));
      el.style.top = `${hoursToY(ev.start_time)}px`;
      el.style.height = `${Math.max(64, dur * (PX_PER_HOUR / 60))}px`;
      el.title = [ev.patient_name, ev.phone || '', ev.service_name || '', `${ev.start_time}–${ev.end_time}`, ev.doctor_name || '', cfg.name].filter(Boolean).join('\n');
      el.innerHTML = `
        <div style="font-weight:700;color:${cfg.textColor};display:flex;align-items:center;gap:6px;">
          <span style="opacity:.85;font-size:.85rem;">${ev.start_time}–${ev.end_time}</span>
          <span style="margin-left:6px;color:#374151;font-weight:600;min-width:0;">${esc(ev.patient_name || '')}</span>
          ${ev.phone ? `<span style="margin-left:8px;background:#0d9488;color:white;padding:0.15rem 0.5rem;border-radius:6px;font-size:0.7rem;font-weight:700;flex-shrink:0;">${esc(ev.phone)}</span>` : ''}
        </div>
        <div style="margin-top:4px;display:flex;align-items:center;gap:6px;color:#64748b;font-size:0.75rem;font-weight:500;">
          <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;flex:1;">${esc(ev.service_name || '')}</span>
        </div>`;
      el.addEventListener('click', e => { e.stopPropagation(); openEdit(ev); });
      hoursEl.appendChild(el);
    });
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

  // Align time gutter with the header height so 09:00 lines up under day headers
  try {
    const firstHead = document.querySelector('.calendar-col .head');
    const headerH = firstHead ? firstHead.offsetHeight : 0;
    if (headerH) {
      const tc = q('#timeCol');
      if (tc) tc.style.paddingTop = headerH + 'px';
    }
  } catch (e) { /* ignore */ }
  initScrollSync();
}

function renderMonthView(y, m, events) {
  q('#dateLabel').textContent = `Сар: ${y} оны ${String(m).padStart(2, '0')}-р сар`;
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
      eventDiv.style.background = '#334155';
      eventDiv.style.padding = '0.25rem 0.5rem';
      eventDiv.style.borderRadius = '3px';
      eventDiv.style.marginBottom = '0.25rem';
      eventDiv.style.color = '#e0e7ff';
      eventDiv.style.display = 'flex';
      eventDiv.style.alignItems = 'center';
      eventDiv.style.justifyContent = 'space-between';
      eventDiv.style.gap = '8px';
      eventDiv.style.cursor = 'pointer';
      eventDiv.innerHTML = `
        <span style="display:inline-block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;min-width:0;">
          ${esc(ev.start_time)} <strong style="margin-left:6px;">${esc(ev.patient_name || '(no name)')}${ev.service_name ? ' — ' + esc(ev.service_name) : ''}</strong>
        </span>
        <span style="color:#93c5fd;font-weight:600;flex-shrink:0;margin-left:8px;">📞 ${esc(ev.phone || '')}</span>
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
      moreDiv.style.fontSize = '0.7rem';
      moreDiv.style.color = '#94a3b8';
      moreDiv.textContent = `+${dayEvents.length - 2} more`;
      eventContainer.appendChild(moreDiv);
    }
    
    cell.appendChild(eventContainer);
    
    // Click to add new booking
    cell.addEventListener('click', (e) => {
      if (e.target !== cell && e.target.closest('[role="button"]')) return;
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
    const r = await fetchJSON(`${API}?action=update`, { method: 'POST', body: JSON.stringify(payload) });
    if (!r?.ok) { showNotification(`Алдаа: ${r?.msg || 'Шинэчлэхэд сүтэл гарлаа'}`); return; }
    hideModal('#modalEdit');
    await loadBookings();
    showNotification('✅ Захиалга амжилттай шинэчлэгдлээ');
  } catch (e) {
    console.error('Error saving:', e);
    showNotification('Алдаа: Захиалгу шинэчлэхэд сүтэл гарлаа');
  }
}

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
      const genderField = form.querySelector('[name="gender"]');
      const noteField = form.querySelector('[name="note"]');
      const visitField = form.querySelector('[name="visit_count"]');
      if (visitField) visitField.value = r.data.visits > 1 ? '2' : '1';
      if (r.data.patient_name && nameField) nameField.value = r.data.patient_name;
      if (serviceField && r.data.service_name) { serviceField.value = r.data.service_name; serviceField.classList.remove('input-error'); }
      if (r.data.gender && genderField) genderField.value = r.data.gender;
      if (r.data.note && noteField) noteField.value = r.data.note;
      if (formId === 'addForm') showNotification('Previous service: ' + (r.data.service_name || 'No info'));
    } else {
      const visitField = form.querySelector('[name="visit_count"]');
      if (visitField) visitField.value = '1';
      if (formId === 'addForm') showNotification('First-time customer');
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
  await loadDoctors();
  await loadTreatments();
  await loadBookings();
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
  clinicSel?.addEventListener('change', async e => { CURRENT_CLINIC = e.target.value; await loadDoctors(); await loadBookings(); });
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
    
    if (typeof f.reportValidity === 'function' && !f.reportValidity()) return;
    
    // Get treatment name for service_name field
    let serviceName = customTreatment;
    if (treatmentId) {
      const selectedTreatment = TREATMENTS.find(t => t.id == treatmentId);
      serviceName = selectedTreatment ? selectedTreatment.name : '';
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
      service_name: serviceName, 
      gender: f.querySelector('#gender')?.value || '', 
      visit_count: +(f.querySelector('#visit_count')?.value || 1), 
      note: (f.querySelector('#note')?.value || '').trim(), 
      treatment_id: treatmentId || null,
      custom_treatment: customTreatment
    };
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
  const editPhoneInput = q('#editForm [name="phone"]');
  editPhoneInput?.addEventListener('blur', e => { const phone = e.target.value.trim(); if (phone.length >= 7) loadPatientInfo(phone, 'editForm'); });
  editPhoneInput?.addEventListener('input', e => { schedulePatientLookup(editPhoneInput, 'editForm'); });
  document.getElementById('modalAdd')?.addEventListener('shown.bs.modal', () => { const phone = q('#addForm #phone'); if (phone) { phone.classList.add('phone-reminder'); phone.focus(); setTimeout(() => phone.classList.remove('phone-reminder'), 1600); } });
  q('#editForm')?.addEventListener('submit', saveEdit);
  q('#btnDelete')?.addEventListener('click', deleteBooking);
});
