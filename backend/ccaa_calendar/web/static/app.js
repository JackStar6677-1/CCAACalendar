const shell = document.querySelector(".shell");
const lookupForm = document.querySelector("#lookup-form");
const loginForm = document.querySelector("#login-form");
const activateForm = document.querySelector("#activate-form");
const resetConfirmForm = document.querySelector("#reset-confirm-form");
const authStepReset = document.querySelector("#auth-step-reset");
const authPanel = document.querySelector("#auth-panel");
const authStepLookup = document.querySelector("#auth-step-lookup");
const authStepLogin = document.querySelector("#auth-step-login");
const authStepActivate = document.querySelector("#auth-step-activate");
const authStepBlocked = document.querySelector("#auth-step-blocked");
const loginRutSummary = document.querySelector("#login-rut-summary");
const activateRutSummary = document.querySelector("#activate-rut-summary");
const blockedMessage = document.querySelector("#blocked-message");
const passwordResetButton = document.querySelector("#password-reset-button");
const bootstrapOfficialEmail = document.querySelector("#bootstrap-official-email");
const bootstrapOfficialDetail = document.querySelector("#bootstrap-official-detail");
const demoButton = document.querySelector("#demo-button");
const loginStatus = document.querySelector("#login-status");
const soundToggle = document.querySelector("#sound-toggle");
const calendarGrid = document.querySelector("#calendar-grid");
const agendaList = document.querySelector("#agenda-list");
const metricEvents = document.querySelector("#metric-events");
const metricHolidays = document.querySelector("#metric-holidays");
const missionStrip = document.querySelector("#mission-strip");
const googleStatusBadge = document.querySelector("#google-status-badge");
const googleStatusDetail = document.querySelector("#google-status-detail");
const dialog = document.querySelector("#event-dialog");
const eventForm = document.querySelector("#event-form");
const eventSaveStatus = document.querySelector("#event-save-status");
const newEventButton = document.querySelector("#new-event-button");
const todayButton = document.querySelector("#today-button");
const prevMonthButton = document.querySelector("#prev-month-button");
const nextMonthButton = document.querySelector("#next-month-button");
const notificationButton = document.querySelector("#notification-button");
const installButton = document.querySelector("#install-button");
const calendarTitle = document.querySelector("#calendar-title");
const agendaTitle = document.querySelector("#agenda-title");
const viewButtons = document.querySelectorAll("[data-view-button]");
const appSections = document.querySelectorAll("[data-view]");
const refreshAdminButton = document.querySelector("#refresh-admin-button");
const adminUserList = document.querySelector("#admin-user-list");
const adminAuditList = document.querySelector("#admin-audit-list");
const refreshSpacesButton = document.querySelector("#refresh-spaces-button");
const spaceForm = document.querySelector("#space-form");
const spaceSaveStatus = document.querySelector("#space-save-status");
const spaceList = document.querySelector("#space-list");
const reservationForm = document.querySelector("#reservation-form");
const reservationSaveStatus = document.querySelector("#reservation-save-status");
const reservationSpaceSelect = document.querySelector("#reservation-space-select");
const reservationList = document.querySelector("#reservation-list");
const profileSummary = document.querySelector("#profile-summary");
const profileEmailNotifications = document.querySelector("#profile-email-notifications");
const profileEmailStatus = document.querySelector("#profile-email-status");
const profileSaveButton = document.querySelector("#profile-save-button");

let soundEnabled = false;
let audioContext;
let organizationId;
let deferredInstallPrompt;
let authSession;
let authLookupContext = null;
let orbitConfig;
let googleConnected = false;
let gmailAuthorized = false;
let calendarCursor = new Date();
calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1);
let selectedDate = dateKey(new Date());
let missionStripKey = "";
let currentView = "calendar";
let spaces = [];
let reservations = [];

const PLACEHOLDER_TITLES = new Set([
  "reunion centro de estudiantes",
  "reunión centro de estudiantes",
  "semana de bienvenida",
  "auditorio reservado",
]);

let events = [];
let holidays = [];
const nativeFetch = window.fetch.bind(window);

const fallbackConfig = {
  modules: [
    {
      id: "auth",
      label: "Acceso interno",
      status: "Operativo",
      detail: "RUT, clave propia y auditoria base.",
    },
    {
      id: "calendar",
      label: "Calendario vivo",
      status: "En marcha",
      detail: "Eventos, feriados y agenda mensual.",
    },
    {
      id: "google",
      label: "Google Calendar",
      status: "Conectar",
      detail: "OAuth del calendario oficial.",
    },
  ],
};

function getAudioContext() {
  audioContext ||= new AudioContext();
  return audioContext;
}

function chirp(type = "soft") {
  if (!soundEnabled) return;

  const context = getAudioContext();
  const oscillator = context.createOscillator();
  const gain = context.createGain();
  const now = context.currentTime;

  oscillator.type = type === "gold" ? "triangle" : "sine";
  oscillator.frequency.setValueAtTime(type === "gold" ? 740 : 520, now);
  oscillator.frequency.exponentialRampToValueAtTime(type === "gold" ? 980 : 660, now + 0.08);
  gain.gain.setValueAtTime(0.0001, now);
  gain.gain.exponentialRampToValueAtTime(0.08, now + 0.012);
  gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);

  oscillator.connect(gain);
  gain.connect(context.destination);
  oscillator.start(now);
  oscillator.stop(now + 0.2);
}

function openApp() {
  shell.dataset.screen = "app";
  switchView(currentView);
  syncNotificationButtonState();
  render();
  scheduleIdle(() => {
    hydrateFromApi();
    hydrateGoogleStatus().then(() => {
      if (googleConnected) scheduleIdle(hydrateGoogleEvents);
    });
  });
}

function switchView(view) {
  currentView = view;
  viewButtons.forEach((button) => {
    button.classList.toggle("active", button.dataset.viewButton === view);
  });
  appSections.forEach((section) => {
    section.hidden = section.dataset.view !== view;
  });
  if (view === "admin") {
    hydrateAdmin();
  }
  if (view === "spaces") {
    if (reservationForm) {
      prepareReservationForm();
    }
    hydrateSpaces();
  }
  if (view === "profile") {
    hydrateProfile();
  }
}

function setEventSaveStatus(message, tone = "neutral") {
  eventSaveStatus.textContent = message;
  eventSaveStatus.dataset.tone = tone;
}

function setLoginStatus(message, tone = "neutral") {
  loginStatus.textContent = message;
  loginStatus.dataset.tone = tone;
}

function setSpaceStatus(message, tone = "neutral") {
  spaceSaveStatus.textContent = message;
  spaceSaveStatus.dataset.tone = tone;
}

function setReservationStatus(message, tone = "neutral") {
  reservationSaveStatus.textContent = message;
  reservationSaveStatus.dataset.tone = tone;
}

function normalizeCategory(category) {
  if (category === "academico" || category === "espacio" || category === "google") return category;
  return "centro";
}

function isPlaceholderEvent(event) {
  return PLACEHOLDER_TITLES.has(String(event.title || "").trim().toLocaleLowerCase("es-CL"));
}

function uniqueEvents(items) {
  return items
    .filter((event) => !isPlaceholderEvent(event))
    .filter((event, index, list) => list.findIndex((item) => item.id === event.id) === index);
}

function isAppOpen() {
  return shell.dataset.screen === "app";
}

function scheduleIdle(callback) {
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(callback, { timeout: 1800 });
    return;
  }
  window.setTimeout(callback, 160);
}

function reportClientIssue(kind, message, metadata = {}, stack = "") {
  const payload = {
    kind,
    message: String(message || "Client issue").slice(0, 800),
    source: "browser",
    path: window.location.pathname,
    stack: String(stack || "").slice(0, 1600),
    metadata,
  };
  const body = JSON.stringify(payload);
  if (navigator.sendBeacon) {
    navigator.sendBeacon("/api/diagnostics/client-error", new Blob([body], { type: "application/json" }));
    return;
  }
  nativeFetch("/api/diagnostics/client-error", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body,
    keepalive: true,
  }).catch(() => {});
}

async function trackedFetch(resource, options) {
  const started = performance.now();
  const response = await nativeFetch(resource, options);
  const durationMs = Math.round(performance.now() - started);
  const url = typeof resource === "string" ? resource : resource.url;
  if (response.status >= 400 || durationMs > 1500) {
    reportClientIssue("client.fetch", `Fetch ${response.status} ${url}`, {
      url,
      status: response.status,
      duration_ms: durationMs,
    });
  }
  return response;
}

function eventDate(event) {
  return new Date(event.starts_at);
}

function formatTime(event) {
  if (event.all_day) {
    return eventDate(event).toLocaleDateString("es-CL", {
      day: "2-digit",
      month: "short",
      weekday: "long",
    });
  }

  const start = eventDate(event);
  const end = new Date(event.ends_at);
  return `${start.toLocaleDateString("es-CL", {
    day: "2-digit",
    month: "short",
  })} · ${start.toLocaleTimeString("es-CL", {
    hour: "2-digit",
    minute: "2-digit",
  })}-${end.toLocaleTimeString("es-CL", {
    hour: "2-digit",
    minute: "2-digit",
  })}`;
}

const REMINDER_STORAGE_KEY = "ccaa-calendar-reminders";
const REMINDER_CHECK_MS = 30_000;
let reminderWatcherId = null;

function canUseNotifications() {
  return "Notification" in window;
}

function hasNotificationPermission() {
  return canUseNotifications() && Notification.permission === "granted";
}

function loadStoredReminders() {
  try {
    const raw = localStorage.getItem(REMINDER_STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

function saveStoredReminders(reminders) {
  localStorage.setItem(REMINDER_STORAGE_KEY, JSON.stringify(reminders));
}

function formatDateTime(raw) {
  if (!raw) return "Sin registro";
  return new Date(raw).toLocaleString("es-CL", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

async function ensureNotificationPermission() {
  if (!canUseNotifications()) return "unsupported";
  if (Notification.permission === "granted") return "granted";
  if (Notification.permission === "denied") return "denied";
  return Notification.requestPermission();
}

async function notifyNow(title, body) {
  if (!hasNotificationPermission()) return false;

  const options = {
    body: String(body || "").slice(0, 240),
    icon: "/assets/orbit-icon.svg",
    badge: "/assets/orbit-icon.svg",
    tag: "ccaa-calendar-reminder",
    renotify: true,
  };

  try {
    if ("serviceWorker" in navigator) {
      const registration = await navigator.serviceWorker.ready;
      if (registration?.showNotification) {
        await registration.showNotification(title, options);
        return true;
      }
    }
    new Notification(title, options);
    return true;
  } catch (error) {
    reportClientIssue("client.notification", error?.message || error, {}, error?.stack || "");
    return false;
  }
}

function syncNotificationButtonState() {
  if (!notificationButton) return;
  if (!canUseNotifications()) {
    notificationButton.textContent = "No soportado";
    notificationButton.title = "Este navegador no expone Notification API.";
    return;
  }
  if (Notification.permission === "granted") {
    notificationButton.textContent = "Alertas ON";
    notificationButton.title = "Recordatorios activos en este dispositivo.";
    startReminderWatcher();
    return;
  }
  if (Notification.permission === "denied") {
    notificationButton.textContent = "Bloqueadas";
    notificationButton.title = "Habilita notificaciones en ajustes del navegador para este sitio.";
    stopReminderWatcher();
    return;
  }
  notificationButton.textContent = "Activar alertas";
  notificationButton.title = "Permite notificaciones para avisos de eventos.";
}

function stopReminderWatcher() {
  if (reminderWatcherId !== null) {
    window.clearInterval(reminderWatcherId);
    reminderWatcherId = null;
  }
}

async function processDueReminders() {
  if (!hasNotificationPermission()) return;

  const now = Date.now();
  const reminders = loadStoredReminders();
  const stillPending = [];

  for (const reminder of reminders) {
    if (reminder.fireAt <= now) {
      if (now - reminder.fireAt > 10 * 60 * 1000) {
        continue;
      }
      const minutes = reminder.minutesBefore || 30;
      await notifyNow(
        `CCAACalendar: ${reminder.title}`,
        `Empieza en ${minutes} minutos. ${reminder.description || ""}`.trim(),
      );
      continue;
    }
    stillPending.push(reminder);
  }

  if (stillPending.length !== reminders.length) {
    saveStoredReminders(stillPending);
  }
}

function startReminderWatcher() {
  if (!hasNotificationPermission()) return;
  if (reminderWatcherId !== null) return;
  processDueReminders();
  reminderWatcherId = window.setInterval(() => {
    processDueReminders();
  }, REMINDER_CHECK_MS);
}

function addBrowserReminder(event, minutesBefore = 30) {
  const startsAt = eventDate(event).getTime();
  if (Number.isNaN(startsAt)) return false;

  const fireAt = startsAt - minutesBefore * 60 * 1000;
  if (fireAt <= Date.now()) {
    return false;
  }

  const eventId = String(event.id || crypto.randomUUID());
  const reminders = loadStoredReminders().filter((item) => item.eventId !== eventId);
  reminders.push({
    id: crypto.randomUUID(),
    eventId,
    title: event.title || "Evento",
    description: event.description || "",
    fireAt,
    minutesBefore,
  });
  saveStoredReminders(reminders);
  startReminderWatcher();
  return true;
}

function scheduleBrowserReminder(event, minutesBefore = 30) {
  return addBrowserReminder(event, minutesBefore);
}

function toDateTimeLocalValue(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function prepareEventFormForSelectedDay() {
  const base = new Date(`${selectedDate}T10:00:00`);
  const end = new Date(base);
  end.setHours(base.getHours() + 1);
  eventForm.elements.starts_at.value = toDateTimeLocalValue(base);
  eventForm.elements.ends_at.value = toDateTimeLocalValue(end);
  eventForm.elements.title.value = "";
  eventForm.elements.description.value = "";
  setEventSaveStatus("El evento puede quedar local o sincronizarse al Google oficial.", "neutral");
}

function buildMonthDays() {
  const month = calendarCursor.getMonth();
  const year = calendarCursor.getFullYear();
  const first = new Date(year, month, 1);
  const startOffset = (first.getDay() + 6) % 7;
  const days = [];

  for (let index = 0; index < 42; index += 1) {
    const date = new Date(year, month, 1 - startOffset + index);
    days.push(date);
  }

  return days;
}

function eventsForDay(day) {
  return events.filter((event) => {
    const date = eventDate(event);
    return (
      date.getFullYear() === day.getFullYear() &&
      date.getMonth() === day.getMonth() &&
      date.getDate() === day.getDate()
    );
  });
}

function selectedDayEvents() {
  return events
    .filter((event) => dateKey(eventDate(event)) === selectedDate)
    .toSorted((a, b) => eventDate(a) - eventDate(b));
}

function dateKey(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function holidayForDay(day) {
  return holidays.find((holiday) => holiday.date === dateKey(day));
}

function renderCalendar() {
  const eventsByDay = new Map();
  events.forEach((event) => {
    const key = dateKey(eventDate(event));
    const bucket = eventsByDay.get(key) || [];
    bucket.push(event);
    eventsByDay.set(key, bucket);
  });
  eventsByDay.forEach((bucket) => bucket.sort((a, b) => eventDate(a) - eventDate(b)));

  const fragment = document.createDocumentFragment();
  const today = new Date();
  calendarTitle.textContent = calendarCursor.toLocaleDateString("es-CL", {
    month: "long",
    year: "numeric",
  });

  buildMonthDays().forEach((day) => {
    const dayCard = document.createElement("article");
    dayCard.className = "calendar-day";
    const holiday = holidayForDay(day);
    const key = dateKey(day);
    const dayEvents = eventsByDay.get(key) || [];
    const visibleEvents = dayEvents.slice(0, 3);
    const hiddenCount = Math.max(0, dayEvents.length - visibleEvents.length);
    if (day.getMonth() !== calendarCursor.getMonth()) dayCard.classList.add("is-muted");
    if (holiday) dayCard.classList.add("is-holiday");
    if (holiday?.is_irrenunciable) dayCard.classList.add("is-irrenunciable");
    if (selectedDate === key) dayCard.classList.add("is-selected");
    if (
      day.getFullYear() === today.getFullYear() &&
      day.getMonth() === today.getMonth() &&
      day.getDate() === today.getDate()
    ) {
      dayCard.classList.add("today");
    }
    dayCard.addEventListener("click", () => {
      selectedDate = key;
      render();
    });

    const dateNumber = document.createElement("div");
    dateNumber.className = "date-number";
    dateNumber.innerHTML = `<span>${day.getDate()}</span><span>${day.toLocaleDateString("es-CL", {
      weekday: "short",
    })}</span>`;
    dayCard.append(dateNumber);

    if (holiday && day.getMonth() === calendarCursor.getMonth()) {
      const ribbon = document.createElement("span");
      ribbon.className = `holiday-ribbon${holiday.is_irrenunciable ? " is-irrenunciable" : ""}`;
      ribbon.title = holiday.label;
      ribbon.textContent = holiday.is_irrenunciable ? "Irrenunciable" : holiday.label;
      dayCard.append(ribbon);
    }

    if (dayEvents.length > 0) {
      const dayBadge = document.createElement("span");
      dayBadge.className = "day-count-badge";
      dayBadge.textContent = String(dayEvents.length);
      dayCard.append(dayBadge);
    }

    visibleEvents.forEach((event) => {
      const pill = document.createElement("button");
      pill.type = "button";
      pill.className = `event-pill ${normalizeCategory(event.category)}`;
      pill.textContent = event.title;
      pill.addEventListener("click", (clickEvent) => {
        clickEvent.stopPropagation();
        selectedDate = key;
        render();
      });
      dayCard.append(pill);
    });

    if (hiddenCount > 0) {
      const more = document.createElement("button");
      more.type = "button";
      more.className = "more-events";
      more.textContent = `+${hiddenCount} mas`;
      more.addEventListener("click", (clickEvent) => {
        clickEvent.stopPropagation();
        selectedDate = key;
        render();
      });
      dayCard.append(more);
    }

    fragment.append(dayCard);
  });
  calendarGrid.replaceChildren(fragment);
}

function renderAgenda() {
  agendaList.replaceChildren();
  const selected = new Date(`${selectedDate}T12:00:00`);
  agendaTitle.textContent = selected.toLocaleDateString("es-CL", {
    day: "numeric",
    month: "long",
    weekday: "long",
  });

  const holidayItems = holidays
    .filter((holiday) => holiday.date === selectedDate)
    .map((holiday) => ({
      id: `holiday-${holiday.date}`,
      title: holiday.label,
      category: "holiday",
      starts_at: `${holiday.date}T00:00:00-04:00`,
      ends_at: `${holiday.date}T23:59:00-04:00`,
      all_day: true,
      description: holiday.is_irrenunciable
        ? "Feriado irrenunciable confirmado en calendario chileno."
        : "Feriado nacional confirmado en calendario chileno.",
    }));

  const dayItems = [...selectedDayEvents(), ...holidayItems].toSorted((a, b) => eventDate(a) - eventDate(b));

  if (dayItems.length === 0) {
    const empty = document.createElement("article");
    empty.className = "agenda-empty";
    empty.innerHTML = `
      <strong>Sin eventos para este dia</strong>
      <p>Selecciona otro dia o crea un evento nuevo para enviarlo al calendario oficial.</p>
    `;
    agendaList.append(empty);
    return;
  }

  dayItems
    .toSorted((a, b) => eventDate(a) - eventDate(b))
    .forEach((event, index) => {
      const item = document.createElement("article");
      item.className = `agenda-item ${event.category === "holiday" ? "holiday" : normalizeCategory(event.category)}`;
      item.innerHTML = `
        <strong>${event.title}</strong>
        <span>${formatTime(event)}</span>
        <p>${event.description || "Sin detalle todavia."}</p>
      `;
      if (event.category !== "holiday") {
        const actions = document.createElement("div");
        actions.className = "agenda-actions";
        if (event.google_event_id || event.source === "google_calendar") {
          actions.innerHTML = `<span class="sync-chip">Sincronizado</span>`;
        } else if (!String(event.id).startsWith("seed-")) {
          const syncButton = document.createElement("button");
          syncButton.type = "button";
          syncButton.className = "mini-action";
          syncButton.textContent = "Sincronizar Google";
          syncButton.addEventListener("click", () => syncEventToGoogle(event.id));
          actions.append(syncButton);
        }

        const reminderButton = document.createElement("button");
        reminderButton.type = "button";
        reminderButton.className = "mini-action ghost";
        reminderButton.textContent = "Probar notificacion";
        reminderButton.addEventListener("click", async () => {
          const permission = await ensureNotificationPermission();
          syncNotificationButtonState();
          if (permission !== "granted") {
            window.alert(
              permission === "denied"
                ? "Las notificaciones estan bloqueadas. En Chrome: candado junto a la URL > Notificaciones > Permitir."
                : "No se otorgo permiso para notificaciones.",
            );
            return;
          }
          const ok = await notifyNow(
            `CCAACalendar: ${event.title}`,
            `Prueba de alerta · ${formatTime(event)}`,
          );
          if (!ok) {
            window.alert("No se pudo mostrar la notificacion en este dispositivo.");
          }
        });
        actions.append(reminderButton);
        item.append(actions);
      }
      agendaList.append(item);
    });
}

function render() {
  const visibleMonth = `${calendarCursor.getFullYear()}-${String(calendarCursor.getMonth() + 1).padStart(2, "0")}`;
  metricEvents.textContent = String(
    events.filter((event) => dateKey(eventDate(event)).startsWith(visibleMonth)).length,
  );
  metricHolidays.textContent = String(holidays.filter((holiday) => holiday.is_irrenunciable).length);
  renderCalendar();
  renderAgenda();
}

function renderMissionStrip() {
  /* Tarjetas de modulo deshabilitadas: estado compacto en .status-bar */
}

async function hydrateOrbitConfig() {
  try {
    const response = await trackedFetch("/assets/ccaa-calendar.config.json");
    orbitConfig = response.ok ? await response.json() : fallbackConfig;
  } catch {
    orbitConfig = fallbackConfig;
  }
}

async function hydrateGoogleStatus() {
  try {
    const response = await trackedFetch("/api/integrations/google/status");
    if (!response.ok) return;
    const status = await response.json();
    googleConnected = Boolean(status.token_present);
    gmailAuthorized = Boolean(status.gmail_authorized);
    googleStatusBadge.textContent = status.token_present
      ? "Conectado"
      : status.ready_to_connect
        ? "Listo"
        : "Pendiente";
    googleStatusDetail.textContent = status.token_present
      ? status.gmail_authorized
        ? "Sincronizado · Gmail activo"
        : "Reconecta para habilitar envio de correos del centro"
      : "Conecta calendario y correo oficial del centro";
    googleStatusBadge.dataset.ready = String(status.ready_to_connect);
  } catch {
    googleStatusBadge.textContent = "Sin conexion API";
    googleStatusDetail.textContent = "La interfaz funciona offline, pero la API local no respondio.";
  }
}

async function hydrateGoogleEvents() {
  try {
    const response = await trackedFetch("/api/integrations/google/events?max_results=20");
    if (!response.ok) return;
    const payload = await response.json();
    if (!payload.connected) return;

    const googleEvents = payload.events || [];
    if (googleEvents.length > 0) {
      events = uniqueEvents([...googleEvents, ...events]);
      googleStatusBadge.textContent = "Google sincronizado";
      googleStatusDetail.textContent = `${googleEvents.length} eventos del calendario oficial cargados en la vista.`;
      if (isAppOpen()) render();
      return;
    }

    googleStatusBadge.textContent = "Google conectado";
    googleStatusDetail.textContent = "La cuenta esta conectada, pero no hay eventos proximos para mostrar.";
  } catch {
    googleStatusDetail.textContent = "Google esta conectado, pero no pudimos leer eventos en este intento.";
  }
}

async function hydrateHolidays() {
  try {
    const response = await trackedFetch("/api/holidays?year=2026");
    if (!response.ok) return;
    holidays = await response.json();
    if (isAppOpen()) render();
  } catch {
    if (isAppOpen()) render();
  }
}

async function ensurePrimaryOrganization() {
  const response = await trackedFetch("/api/organizations");
  const organizations = response.ok ? await response.json() : [];
  const existing = organizations.find((item) => item.slug === "ccaa-psicologia");
  if (existing) return existing.id;

  const created = await trackedFetch("/api/organizations", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      name: "Centro de Estudiantes de Psicología · UDLA Maipú",
      slug: "ccaa-psicologia",
      domain_hint: "udla-maipu-psicologia",
    }),
  });

  if (!created.ok) return undefined;
  return (await created.json()).id;
}

async function hydrateFromApi() {
  try {
    organizationId = await ensurePrimaryOrganization();
    if (!organizationId) return;

    const response = await trackedFetch(`/api/events?organization_id=${organizationId}`);
    if (!response.ok) return;

    const apiEvents = await response.json();
    if (apiEvents.length > 0) {
      events = uniqueEvents([...apiEvents, ...events]);
      if (isAppOpen()) render();
    }
  } catch {
    if (isAppOpen()) render();
  }
}

function authHeaders() {
  if (!authSession?.token) return {};
  return { Authorization: `Bearer ${authSession.token}` };
}

function slugify(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 80);
}

function renderAdminUsers(users) {
  adminUserList.replaceChildren();
  if (!users.length) {
    const empty = document.createElement("div");
    empty.className = "loading-row";
    empty.textContent = "Aun no hay administradoras activadas en esta organizacion.";
    adminUserList.append(empty);
    return;
  }

  users.forEach((user) => {
    const row = document.createElement("article");
    row.className = "admin-row";

    const body = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = user.display_name;
    const meta = document.createElement("span");
    const mailPref = user.email_notifications_enabled ? "Correos ON" : "Correos OFF";
    meta.textContent = `${user.rut_masked || "RUT protegido"} · ${user.role} · ${user.email} · ${mailPref}`;
    body.append(name, meta);

    const status = document.createElement("span");
    status.className = "sync-chip";
    status.textContent = user.is_active ? "Activo" : "Pausado";

    row.append(body, status);
    adminUserList.append(row);
  });
}

function renderAudit(auditEntries) {
  adminAuditList.replaceChildren();
  if (!auditEntries.length) {
    const empty = document.createElement("div");
    empty.className = "loading-row";
    empty.textContent = "Sin movimientos registrados todavia.";
    adminAuditList.append(empty);
    return;
  }

  auditEntries.forEach((entry) => {
    const row = document.createElement("article");
    row.className = "admin-row audit-row";

    const body = document.createElement("div");
    const title = document.createElement("strong");
    title.textContent = `${entry.action} · ${entry.entity_type}`;
    const meta = document.createElement("span");
    meta.textContent = `${formatDateTime(entry.created_at)} · ${entry.entity_id}`;
    body.append(title, meta);

    const actor = document.createElement("span");
    actor.className = "sync-chip";
    actor.textContent = entry.actor_user_id ? "Con autora" : "Sistema";

    row.append(body, actor);
    adminAuditList.append(row);
  });
}

async function hydrateAdmin() {
  if (!adminUserList || !adminAuditList) return;
  if (!authSession?.token) {
    adminUserList.innerHTML = `<div class="loading-row warning">Entra con RUT y clave para ver administradoras.</div>`;
    adminAuditList.innerHTML = `<div class="loading-row warning">La auditoria no se expone sin sesion interna.</div>`;
    return;
  }

  adminUserList.innerHTML = `<div class="loading-row">Cargando administradoras...</div>`;
  adminAuditList.innerHTML = `<div class="loading-row">Cargando auditoria...</div>`;

  try {
    const [usersResponse, auditResponse] = await Promise.all([
      trackedFetch("/api/admin/users", { headers: authHeaders() }),
      trackedFetch("/api/admin/audit?limit=30", { headers: authHeaders() }),
    ]);
    const users = usersResponse.ok ? await usersResponse.json() : [];
    const audit = auditResponse.ok ? await auditResponse.json() : [];
    renderAdminUsers(users);
    renderAudit(audit);
  } catch (error) {
    adminUserList.innerHTML = `<div class="loading-row warning">No pudimos cargar administradoras.</div>`;
    adminAuditList.innerHTML = `<div class="loading-row warning">No pudimos cargar auditoria.</div>`;
    reportClientIssue("client.admin_load", error?.message || error, {}, error?.stack || "");
  }
}

function renderSpaces() {
  spaceList.replaceChildren();
  reservationSpaceSelect.replaceChildren();

  if (!spaces.length) {
    const empty = document.createElement("div");
    empty.className = "loading-row";
    empty.textContent = "Crea el primer espacio, por ejemplo Auditorio principal.";
    spaceList.append(empty);

    const option = document.createElement("option");
    option.value = "";
    option.textContent = "Sin espacios todavia";
    reservationSpaceSelect.append(option);
    return;
  }

  spaces.forEach((space) => {
    const row = document.createElement("article");
    row.className = "admin-row";

    const body = document.createElement("div");
    const title = document.createElement("strong");
    title.textContent = space.name;
    const meta = document.createElement("span");
    const capacity = space.capacity ? `${space.capacity} personas` : "Capacidad sin definir";
    meta.textContent = `${capacity} · ${space.location || "Ubicacion pendiente"}`;
    body.append(title, meta);

    const badge = document.createElement("span");
    badge.className = "sync-chip";
    badge.textContent = "Disponible";
    row.append(body, badge);
    spaceList.append(row);

    const option = document.createElement("option");
    option.value = space.id;
    option.textContent = space.name;
    reservationSpaceSelect.append(option);
  });
}

function renderReservations() {
  reservationList.replaceChildren();
  const nextReservations = reservations
    .filter((item) => new Date(item.ends_at).getTime() >= Date.now() - 60 * 60 * 1000)
    .slice(0, 12);

  if (!nextReservations.length) {
    const empty = document.createElement("div");
    empty.className = "loading-row";
    empty.textContent = "No hay reservas próximas.";
    reservationList.append(empty);
    return;
  }

  nextReservations.forEach((reservation) => {
    const row = document.createElement("article");
    row.className = "admin-row audit-row";

    const body = document.createElement("div");
    const title = document.createElement("strong");
    title.textContent = reservation.title;
    const space = spaces.find((item) => item.id === reservation.space_id);
    const meta = document.createElement("span");
    meta.textContent = `${space?.name || "Espacio"} · ${formatTime(reservation)}`;
    body.append(title, meta);

    const badge = document.createElement("span");
    badge.className = "sync-chip";
    badge.textContent = reservation.google_event_id ? "Google" : "Local";
    row.append(body, badge);
    reservationList.append(row);
  });
}

async function hydrateSpaces() {
  if (!spaceList || !reservationList) return;
  if (!organizationId) {
    organizationId = await ensurePrimaryOrganization();
  }
  if (!organizationId) return;

  try {
    const [spacesResponse, reservationsResponse] = await Promise.all([
      trackedFetch(`/api/spaces?organization_id=${organizationId}`),
      trackedFetch(`/api/spaces/reservations?organization_id=${organizationId}`),
    ]);
    spaces = spacesResponse.ok ? await spacesResponse.json() : [];
    reservations = reservationsResponse.ok ? await reservationsResponse.json() : [];
    events = uniqueEvents([...reservations, ...events]);
    renderSpaces();
    renderReservations();
    if (isAppOpen()) render();
  } catch (error) {
    spaceList.innerHTML = `<div class="loading-row warning">No pudimos cargar espacios.</div>`;
    reservationList.innerHTML = `<div class="loading-row warning">No pudimos cargar reservas.</div>`;
    reportClientIssue("client.spaces_load", error?.message || error, {}, error?.stack || "");
  }
}

function prepareReservationForm() {
  const base = new Date(`${selectedDate}T14:00:00`);
  const end = new Date(base);
  end.setHours(base.getHours() + 2);
  reservationForm.elements.starts_at.value = toDateTimeLocalValue(base);
  reservationForm.elements.ends_at.value = toDateTimeLocalValue(end);
}

async function hydrateProfile() {
  if (!profileSummary || !profileEmailNotifications) return;
  if (!authSession?.token) {
    profileSummary.textContent = "Inicia sesion para gestionar tus avisos por correo.";
    profileEmailNotifications.checked = true;
    profileEmailNotifications.disabled = true;
    profileSaveButton.hidden = true;
    profileEmailStatus.textContent =
      "Los correos se envian desde la cuenta oficial del centro cuando esta conectada en Google.";
    return;
  }

  profileSummary.textContent = `${authSession.display_name} · ${authSession.email}`;
  profileEmailNotifications.checked = Boolean(authSession.email_notifications_enabled);
  profileEmailNotifications.disabled = false;
  profileSaveButton.hidden = false;

  try {
    const response = await trackedFetch("/api/auth/me", { headers: authHeaders() });
    if (!response.ok) return;
    const profile = await response.json();
    authSession = { ...authSession, email_notifications_enabled: profile.email_notifications_enabled };
    profileEmailNotifications.checked = Boolean(profile.email_notifications_enabled);
    profileEmailStatus.textContent = profile.email_notifications_enabled
      ? "Recibiras confirmaciones al agendar y recordatorios antes del evento."
      : "No recibiras correos masivos del calendario. Puedes activarlos cuando quieras.";
  } catch {
    profileEmailStatus.textContent = "No pudimos cargar tu perfil. Intenta de nuevo.";
  }
}

async function saveProfileNotifications() {
  if (!authSession?.token || !profileEmailNotifications) return;
  profileSaveButton.disabled = true;
  const response = await trackedFetch("/api/auth/me/notifications", {
    method: "PATCH",
    headers: { "Content-Type": "application/json", ...authHeaders() },
    body: JSON.stringify({
      email_notifications_enabled: profileEmailNotifications.checked,
    }),
  });
  profileSaveButton.disabled = false;
  if (!response.ok) {
    profileEmailStatus.textContent = "No pudimos guardar la preferencia.";
    return;
  }
  const profile = await response.json();
  authSession = { ...authSession, email_notifications_enabled: profile.email_notifications_enabled };
  profileEmailStatus.textContent = profile.email_notifications_enabled
    ? "Preferencia guardada: recibiras avisos por correo."
    : "Preferencia guardada: no recibiras avisos masivos.";
  chirp("soft");
}

async function createEventFromForm(formData) {
  const localEvent = {
    id: crypto.randomUUID(),
    title: formData.get("title"),
    category: formData.get("category"),
    starts_at: new Date(formData.get("starts_at")).toISOString(),
    ends_at: new Date(formData.get("ends_at")).toISOString(),
    description: formData.get("description"),
  };

  if (organizationId) {
    const response = await trackedFetch("/api/events", {
      method: "POST",
      headers: { "Content-Type": "application/json", ...authHeaders() },
      body: JSON.stringify({
        organization_id: organizationId,
        title: localEvent.title,
        category: localEvent.category,
        visibility: "organization",
        starts_at: localEvent.starts_at,
        ends_at: localEvent.ends_at,
        description: localEvent.description,
        created_by_user_id: authSession?.user_id || null,
        notify_subscribers: Boolean(formData.get("notify_subscribers")),
      }),
    });

    if (response.ok) {
      const createdEvent = await response.json();
      events = [createdEvent, ...events];
      return createdEvent;
    }
  }

  events = [localEvent, ...events];
  return localEvent;
}

async function syncEventToGoogle(eventId) {
  googleStatusBadge.textContent = "Sincronizando...";
  googleStatusDetail.textContent = "Enviando evento local al calendario oficial de Google.";
  const response = await trackedFetch(
    `/api/integrations/google/events/${eventId}/sync?dry_run=false&confirm=sync-google-calendar`,
    { method: "POST" },
  );

  if (!response.ok) {
    googleStatusBadge.textContent = "Sync pendiente";
    googleStatusDetail.textContent = "No pudimos publicar en Google. Revisa que la cuenta siga conectada.";
    return false;
  }

  const payload = await response.json();
  events = events.map((item) =>
    item.id === eventId
      ? {
          ...item,
          google_event_id: payload.google_event_id,
          google_calendar_id: payload.google_calendar_id,
          source: "google_sync",
        }
      : item,
  );
  googleStatusBadge.textContent = "Google sincronizado";
  googleStatusDetail.textContent = "Evento guardado en Google Calendar con recordatorios popup y correo.";
  render();
  return true;
}

function showAuthStep(step) {
  authStepLookup.hidden = step !== "lookup";
  authStepLogin.hidden = step !== "login";
  authStepActivate.hidden = step !== "activate";
  if (authStepReset) authStepReset.hidden = step !== "reset";
  authStepBlocked.hidden = step !== "blocked";
  authPanel.dataset.step = step;
}

function resetAuthFlow() {
  authLookupContext = null;
  showAuthStep("lookup");
  lookupForm?.reset();
  loginForm?.reset();
  activateForm?.reset();
}

function rutSummaryText(rutMasked) {
  return rutMasked ? `RUT ${rutMasked}` : "RUT autorizado";
}

function applyLookupHintsToActivateForm(lookup) {
  if (!activateForm) return;
  const firstName = activateForm.querySelector('[name="first_name"]');
  const lastName = activateForm.querySelector('[name="last_name"]');
  const email = activateForm.querySelector('[name="email"]');
  if (firstName && lookup.first_name_hint && !firstName.value) {
    firstName.value = lookup.first_name_hint;
  }
  if (lastName && lookup.last_name_hint && !lastName.value) {
    lastName.value = lookup.last_name_hint;
  }
  if (email && lookup.email_hint && !email.value) {
    email.value = lookup.email_hint;
  }
}

async function hydrateLoginBootstrap() {
  if (!bootstrapOfficialEmail) return;
  try {
    const response = await trackedFetch("/api/auth/bootstrap");
    if (!response.ok) return;
    const bootstrap = await response.json();
    if (bootstrap.official_email) {
      bootstrapOfficialEmail.textContent = bootstrap.official_email;
      const googleNote = bootstrap.google_token_present
        ? "Google Calendar del centro conectado."
        : "Pendiente conectar Google desde el panel (OAuth del correo oficial).";
      bootstrapOfficialDetail.textContent = `${bootstrap.center_name}. ${googleNote}`;
      return;
    }
    bootstrapOfficialEmail.textContent = "Correo oficial pendiente";
    bootstrapOfficialDetail.textContent =
      "Define GOOGLE_CENTER_ACCOUNT_EMAIL en el servidor para mostrar el calendario institucional.";
  } catch {
    bootstrapOfficialDetail.textContent = "No se pudo cargar la configuracion del centro.";
  }
}

lookupForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(lookupForm);
  const rut = String(formData.get("rut") || "").trim();
  if (!rut) {
    setLoginStatus("Ingresa tu RUT para continuar.", "warning");
    return;
  }

  setLoginStatus("Verificando RUT en el roster del centro...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/lookup", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rut }),
    });
    if (!response.ok) {
      const errorBody = await response.json().catch(() => ({}));
      if (response.status === 404) {
        setLoginStatus(
          "La API no tiene el endpoint de validacion. Reinicia el servidor (uvicorn --reload) y recarga la pagina.",
          "warning",
        );
      } else {
        const detail = errorBody.detail;
        const detailText = typeof detail === "string" ? detail : Array.isArray(detail) ? detail[0]?.msg : "";
        setLoginStatus(detailText || "No pudimos validar el RUT. Reintenta en unos segundos.", "warning");
      }
      return;
    }

    const lookup = await response.json();
    authLookupContext = { rut, ...lookup };

    if (lookup.status === "ready_to_login") {
      loginForm.elements.rut.value = rut;
      loginRutSummary.textContent = rutSummaryText(lookup.rut_masked);
      showAuthStep("login");
      setLoginStatus("Ingresa tu clave para abrir el calendario.", "neutral");
      loginForm.querySelector('[name="password"]')?.focus();
      return;
    }

    if (lookup.status === "needs_activation") {
      activateForm.elements.rut.value = rut;
      activateRutSummary.textContent = `${rutSummaryText(lookup.rut_masked)} · primera activacion`;
      applyLookupHintsToActivateForm(lookup);
      showAuthStep("activate");
      setLoginStatus("Completa tus datos y crea tu clave personal.", "neutral");
      activateForm.querySelector('[name="first_name"]')?.focus();
      return;
    }

    if (lookup.status === "inactive") {
      blockedMessage.textContent =
        "Tu cuenta esta inactiva. Pide a la directiva que reactive tu acceso.";
      showAuthStep("blocked");
      setLoginStatus("Cuenta inactiva.", "warning");
      return;
    }

    blockedMessage.textContent =
      "Tu RUT no figura habilitado en el centro. Contacta a la directiva para que te agreguen al roster.";
    showAuthStep("blocked");
    setLoginStatus("RUT no habilitado.", "warning");
  } catch {
    setLoginStatus("No se pudo contactar la API. Reintenta en unos segundos.", "warning");
  }
});

loginForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(loginForm);
  const rut = String(formData.get("rut") || authLookupContext?.rut || "").trim();
  const password = String(formData.get("password") || "");

  if (!rut || !password) {
    setLoginStatus("Ingresa tu clave para continuar.", "warning");
    return;
  }

  setLoginStatus("Verificando acceso...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rut, password }),
    });

    if (!response.ok) {
      setLoginStatus("Clave incorrecta. Si olvidaste la clave, usa recuperacion.", "warning");
      return;
    }

    authSession = await response.json();
    setLoginStatus(`Sesion iniciada como ${authSession.display_name}.`, "success");
    openApp();
  } catch {
    setLoginStatus("No se pudo contactar la API. Reintenta en unos segundos.", "warning");
  }
});

activateForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(activateForm);
  const rut = String(formData.get("rut") || authLookupContext?.rut || "").trim();
  const password = String(formData.get("password") || "");
  const passwordConfirm = String(formData.get("password_confirm") || "");

  if (password !== passwordConfirm) {
    setLoginStatus("Las claves no coinciden.", "warning");
    return;
  }

  setLoginStatus("Creando tu cuenta interna...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/activate", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        rut,
        password,
        password_confirm: passwordConfirm,
        email: String(formData.get("email") || "").trim(),
        first_name: String(formData.get("first_name") || "").trim(),
        last_name: String(formData.get("last_name") || "").trim(),
      }),
    });

    if (!response.ok) {
      const detail = (await response.json().catch(() => ({}))).detail;
      setLoginStatus(detail || "No pudimos activar tu cuenta.", "warning");
      return;
    }

    authSession = await response.json();
    setLoginStatus(`Cuenta creada. Bienvenida, ${authSession.display_name}.`, "success");
    openApp();
  } catch {
    setLoginStatus("No se pudo contactar la API. Reintenta en unos segundos.", "warning");
  }
});

passwordResetButton?.addEventListener("click", async () => {
  const rut = String(loginForm?.elements.rut?.value || authLookupContext?.rut || "").trim();
  if (!rut) {
    setLoginStatus("No hay RUT en contexto para recuperar clave.", "warning");
    return;
  }
  setLoginStatus("Solicitando recuperacion de clave...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/password-reset/request", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rut }),
    });
    const payload = await response.json().catch(() => ({}));
    setLoginStatus(payload.message || "Si el RUT existe, enviaremos instrucciones al correo asociado.", "neutral");
    if (resetConfirmForm) {
      resetConfirmForm.elements.rut.value = rut;
      showAuthStep("reset");
    }
  } catch {
    setLoginStatus("No se pudo solicitar la recuperacion.", "warning");
  }
});

resetConfirmForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(resetConfirmForm);
  const rut = String(formData.get("rut") || "").trim();
  const token = String(formData.get("token") || "").trim();
  const password = String(formData.get("password") || "");
  const passwordConfirm = String(formData.get("password_confirm") || "");
  if (password !== passwordConfirm) {
    setLoginStatus("Las claves no coinciden.", "warning");
    return;
  }
  setLoginStatus("Guardando nueva clave...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/password-reset/confirm", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rut, token, password, password_confirm: passwordConfirm }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      setLoginStatus(payload.detail || "No pudimos restablecer la clave.", "warning");
      return;
    }
    setLoginStatus(payload.message || "Clave actualizada.", "success");
    authLookupContext = { rut };
    if (loginForm?.elements.rut) loginForm.elements.rut.value = rut;
    showAuthStep("login");
  } catch {
    setLoginStatus("No se pudo contactar la API.", "warning");
  }
});

function maybeOpenPasswordResetFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const token = params.get("reset_token");
  if (!token || !resetConfirmForm) return;
  resetConfirmForm.elements.token.value = token;
  showAuthStep("reset");
  setLoginStatus("Ingresa tu RUT y la nueva clave.", "neutral");
  window.history.replaceState({}, "", window.location.pathname);
}

document.querySelectorAll("[data-auth-back]").forEach((button) => {
  button.addEventListener("click", () => {
    resetAuthFlow();
    setLoginStatus("Ingresa tu RUT para continuar.", "neutral");
  });
});

demoButton?.addEventListener("click", openApp);

viewButtons.forEach((button) => {
  button.addEventListener("click", () => switchView(button.dataset.viewButton));
});

refreshAdminButton?.addEventListener("click", hydrateAdmin);
refreshSpacesButton?.addEventListener("click", hydrateSpaces);

spaceForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!authSession?.token) {
    setSpaceStatus("Entra con RUT y clave antes de crear espacios.", "warning");
    return;
  }
  if (!organizationId) {
    organizationId = await ensurePrimaryOrganization();
  }

  const formData = new FormData(spaceForm);
  const name = String(formData.get("name") || "").trim();
  const capacity = String(formData.get("capacity") || "").trim();
  const location = String(formData.get("location") || "").trim();
  setSpaceStatus("Guardando espacio...", "neutral");

  const response = await trackedFetch("/api/spaces", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...authHeaders() },
    body: JSON.stringify({
      organization_id: organizationId,
      name,
      slug: slugify(name),
      capacity: capacity ? Number(capacity) : null,
      location: location || null,
    }),
  });

  if (!response.ok) {
    setSpaceStatus(response.status === 409 ? "Ese espacio ya existe." : "No pudimos guardar el espacio.", "warning");
    return;
  }

  spaceForm.reset();
  setSpaceStatus("Espacio guardado y listo para reservas.", "success");
  await hydrateSpaces();
});

reservationForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!authSession?.token) {
    setReservationStatus("Entra con RUT y clave antes de reservar.", "warning");
    return;
  }
  if (!organizationId) {
    organizationId = await ensurePrimaryOrganization();
  }

  const formData = new FormData(reservationForm);
  setReservationStatus("Revisando choques de horario...", "neutral");
  const response = await trackedFetch("/api/spaces/reservations", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...authHeaders() },
    body: JSON.stringify({
      organization_id: organizationId,
      space_id: formData.get("space_id"),
      title: formData.get("title"),
      description: formData.get("description"),
      starts_at: new Date(formData.get("starts_at")).toISOString(),
      ends_at: new Date(formData.get("ends_at")).toISOString(),
    }),
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    setReservationStatus(payload.detail || "No pudimos crear la reserva.", "warning");
    return;
  }

  const reservation = await response.json();
  events = uniqueEvents([reservation, ...events]);
  reservationForm.reset();
  prepareReservationForm();
  setReservationStatus("Reserva creada y visible en calendario.", "success");
  await hydrateSpaces();
});

soundToggle.addEventListener("click", async () => {
  soundEnabled = !soundEnabled;
  soundToggle.textContent = soundEnabled ? "Activo" : "Activar";
  soundToggle.setAttribute("aria-pressed", String(soundEnabled));
  if (soundEnabled) {
    await getAudioContext().resume();
    chirp("gold");
  }
});

newEventButton.addEventListener("click", () => {
  chirp("soft");
  prepareEventFormForSelectedDay();
  dialog.showModal();
});

profileSaveButton?.addEventListener("click", () => {
  saveProfileNotifications();
});

todayButton.addEventListener("click", () => {
  const today = new Date();
  calendarCursor = new Date(today.getFullYear(), today.getMonth(), 1);
  selectedDate = dateKey(today);
  chirp("gold");
  render();
  document.querySelector(".calendar-day.today")?.scrollIntoView({ behavior: "smooth", block: "center" });
});

prevMonthButton.addEventListener("click", () => {
  calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth() - 1, 1);
  selectedDate = dateKey(new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1));
  chirp("soft");
  render();
});

nextMonthButton.addEventListener("click", () => {
  calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth() + 1, 1);
  selectedDate = dateKey(new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1));
  chirp("soft");
  render();
});

notificationButton.addEventListener("click", async () => {
  const permission = await ensureNotificationPermission();
  syncNotificationButtonState();
  if (permission === "granted") {
    await notifyNow(
      "CCAACalendar listo",
      "Las alertas quedaron activas. Te avisaremos 30 min antes de cada evento con recordatorio.",
    );
    return;
  }
  if (permission === "denied") {
    window.alert(
      "Notificaciones bloqueadas. Abre ajustes del sitio (candado en la barra de direcciones) y permite notificaciones para ccaa.drakescraft.cl",
    );
  }
});

eventForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(eventForm);
  setEventSaveStatus("Guardando evento local...", "neutral");
  const createdEvent = await createEventFromForm(formData);
  if (!createdEvent) {
    setEventSaveStatus("No pudimos guardar el evento.", "warning");
    return;
  }

  if (formData.get("browser_reminder")) {
    const permission = await ensureNotificationPermission();
    syncNotificationButtonState();
    if (permission === "granted") {
      const scheduled = scheduleBrowserReminder(createdEvent, 30);
      if (scheduled) {
        await notifyNow(
          "Recordatorio programado",
          `${createdEvent.title}: te avisaremos 30 min antes aunque cierres la pestana.`,
        );
      } else {
        setEventSaveStatus(
          "El evento quedo guardado, pero ya paso la ventana de aviso (30 min antes).",
          "warning",
        );
      }
    } else if (permission === "denied") {
      setEventSaveStatus("Activa notificaciones con el boton Alertas del calendario.", "warning");
    }
  }

  const notifyTeam = Boolean(formData.get("notify_subscribers"));
  if (formData.get("sync_google") && !String(createdEvent.id).startsWith("seed-")) {
    const synced = await syncEventToGoogle(createdEvent.id);
    if (synced && notifyTeam) {
      setEventSaveStatus(
        gmailAuthorized
          ? "Evento guardado, sincronizado con Google y avisos por correo en cola."
          : "Evento guardado y sincronizado. Reconecta Google con Gmail para enviar correos.",
        gmailAuthorized ? "success" : "warning",
      );
    } else {
      setEventSaveStatus(
        synced
          ? notifyTeam
            ? "Evento guardado y sincronizado. Avisos por correo en cola si Gmail esta activo."
            : "Evento guardado y sincronizado con Google Calendar."
          : "Evento guardado localmente. La sincronizacion con Google quedo pendiente.",
        synced ? "success" : "warning",
      );
    }
  } else if (notifyTeam) {
    setEventSaveStatus(
      gmailAuthorized
        ? "Evento guardado. Confirmacion y recordatorios en cola para integrantes con avisos ON."
        : "Evento guardado. Conecta Google con Gmail para enviar correos del centro.",
      gmailAuthorized ? "success" : "warning",
    );
  } else {
    setEventSaveStatus("Evento guardado localmente.", "success");
  }
  dialog.close();
  chirp("gold");
  render();
});

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
  installButton.hidden = false;
});

installButton.addEventListener("click", async () => {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  await deferredInstallPrompt.userChoice;
  deferredInstallPrompt = undefined;
  installButton.hidden = true;
  chirp("gold");
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("/sw.js").catch(() => {});
  });
}

window.addEventListener("error", (event) => {
  reportClientIssue(
    "client.error",
    event.message,
    { filename: event.filename, line: event.lineno, column: event.colno },
    event.error?.stack || "",
  );
});

window.addEventListener("unhandledrejection", (event) => {
  reportClientIssue(
    "client.unhandledrejection",
    event.reason?.message || event.reason || "Unhandled promise rejection",
    {},
    event.reason?.stack || "",
  );
});

resetAuthFlow();
maybeOpenPasswordResetFromUrl();
syncNotificationButtonState();
hydrateLoginBootstrap();
hydrateOrbitConfig();
hydrateHolidays();
