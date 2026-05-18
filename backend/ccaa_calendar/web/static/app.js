const shell = document.querySelector(".shell");
const loginForm = document.querySelector("#login-form");
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

let soundEnabled = false;
let audioContext;
let organizationId;
let deferredInstallPrompt;
let authSession;
let orbitConfig;
let googleConnected = false;
let gmailAuthorized = false;
let calendarCursor = new Date();
calendarCursor = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1);
let selectedDate = dateKey(new Date());
let missionStripKey = "";

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
  chirp("gold");
  renderMissionStrip();
  render();
  scheduleIdle(() => {
    hydrateFromApi();
    hydrateGoogleStatus().then(() => scheduleIdle(hydrateGoogleEvents));
  });
}

function setEventSaveStatus(message, tone = "neutral") {
  eventSaveStatus.textContent = message;
  eventSaveStatus.dataset.tone = tone;
}

function setLoginStatus(message, tone = "neutral") {
  loginStatus.textContent = message;
  loginStatus.dataset.tone = tone;
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

function canUseNotifications() {
  return "Notification" in window;
}

async function ensureNotificationPermission() {
  if (!canUseNotifications()) return "unsupported";
  if (Notification.permission === "granted") return "granted";
  if (Notification.permission === "denied") return "denied";
  return Notification.requestPermission();
}

function notifyNow(title, body) {
  if (!canUseNotifications() || Notification.permission !== "granted") return;
  new Notification(title, {
    body,
    icon: "/assets/orbit-icon.svg",
    tag: "ccaa-calendar-reminder",
  });
}

function scheduleBrowserReminder(event) {
  const startsAt = eventDate(event).getTime();
  const reminderAt = startsAt - 30 * 60 * 1000;
  const delay = reminderAt - Date.now();
  if (delay <= 0 || delay > 24 * 60 * 60 * 1000) return;
  window.setTimeout(() => {
    notifyNow(`CCAACalendar: ${event.title}`, `Empieza en 30 minutos. ${event.description || ""}`);
  }, delay);
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

  calendarGrid.replaceChildren();
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
      chirp(dayEvents.length > 0 ? "gold" : "soft");
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
        chirp(event.category === "espacio" ? "gold" : "soft");
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
        chirp("gold");
        render();
      });
      dayCard.append(more);
    }

    calendarGrid.append(dayCard);
  });
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
      item.style.animationDelay = `${index * 45}ms`;
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
          if (permission === "granted") {
            notifyNow(`CCAACalendar: ${event.title}`, `Recordatorio de prueba para ${formatTime(event)}.`);
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
  const modules = orbitConfig?.modules || fallbackConfig.modules;
  const nextKey = JSON.stringify(modules);
  if (nextKey === missionStripKey) return;
  missionStripKey = nextKey;
  const syncCard = missionStrip.querySelector(".sync-card");
  missionStrip.replaceChildren(syncCard);

  modules
    .filter((module) => module.id !== "google")
    .forEach((module, index) => {
      const card = document.createElement("article");
      card.className = `mission-card module-${module.id}`;
      card.style.animationDelay = `${index * 70}ms`;
      card.innerHTML = `
        <span class="eyebrow">${module.label}</span>
        <strong>${module.status}</strong>
        <p>${module.detail}</p>
      `;
      missionStrip.append(card);
    });
}

async function hydrateOrbitConfig() {
  try {
    const response = await trackedFetch("/assets/ccaa-calendar.config.json");
    orbitConfig = response.ok ? await response.json() : fallbackConfig;
  } catch {
    orbitConfig = fallbackConfig;
  }
  renderMissionStrip();
}

async function hydrateGoogleStatus() {
  try {
    const response = await trackedFetch("/api/integrations/google/status");
    if (!response.ok) return;
    const status = await response.json();
    googleConnected = Boolean(status.token_present);
    gmailAuthorized = Boolean(status.gmail_authorized);
    googleStatusBadge.textContent = status.ready_to_connect
      ? "Listo para conectar"
      : "Configuracion pendiente";
    googleStatusDetail.textContent = status.token_present
      ? `Google conectado. ${status.gmail_authorized ? "Correos Gmail autorizados." : "Correos Gmail pendientes de permiso."}`
      : "OAuth configurado sin exponer la cuenta en el repo publico. Falta completar el consentimiento si aun no hay token.";
    googleStatusBadge.dataset.ready = String(status.ready_to_connect);
  } catch {
    googleStatusBadge.textContent = "Sin conexion API";
    googleStatusDetail.textContent = "La interfaz funciona offline, pero la API local no respondio.";
  }
}

async function hydrateGoogleEvents() {
  try {
    const response = await trackedFetch("/api/integrations/google/events?max_results=50");
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
      name: "Centro de Estudiantes de Psicologia",
      slug: "ccaa-psicologia",
      domain_hint: "psicologia",
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
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        organization_id: organizationId,
        title: localEvent.title,
        category: localEvent.category,
        visibility: "organization",
        starts_at: localEvent.starts_at,
        ends_at: localEvent.ends_at,
        description: localEvent.description,
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

async function sendReminderEmail(eventId, formData) {
  const recipientEmail = String(formData.get("reminder_email") || "").trim();
  if (!recipientEmail) return;

  const response = await trackedFetch(`/api/integrations/google/events/${eventId}/reminder-email`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      recipient_email: recipientEmail,
      minutes_before: 60,
      note: "Recordatorio creado desde la vista piloto de CCAACalendar.",
    }),
  });

  if (!response.ok) {
    setEventSaveStatus(
      gmailAuthorized
        ? "El evento se guardo, pero Gmail no pudo enviar el correo."
        : "Evento guardado. Para correos directos, activa Gmail con el boton del panel.",
      "warning",
    );
    return;
  }
  setEventSaveStatus("Evento guardado y correo de recordatorio enviado.", "success");
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(loginForm);
  const rut = String(formData.get("rut") || "").trim();
  const password = String(formData.get("password") || "");

  if (!rut || !password) {
    setLoginStatus("Ingresa RUT y clave para abrir el calendario operativo.", "warning");
    return;
  }

  setLoginStatus("Verificando acceso orbital...", "neutral");
  try {
    const response = await trackedFetch("/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rut, password }),
    });

    if (!response.ok) {
      setLoginStatus("No pudimos validar esas credenciales. Si aun no activaste acceso, queda pendiente con el roster local.", "warning");
      return;
    }

    authSession = await response.json();
    setLoginStatus(`Sesion iniciada como ${authSession.display_name}.`, "success");
    openApp();
  } catch {
    setLoginStatus("No se pudo contactar la API. Reintenta en unos segundos o avisa a soporte.", "warning");
  }
});

demoButton?.addEventListener("click", openApp);

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
  if (permission === "granted") {
    notificationButton.textContent = "Notificaciones ON";
    notifyNow("CCAACalendar listo", "Los recordatorios del navegador quedaron habilitados en este dispositivo.");
    return;
  }
  notificationButton.textContent = permission === "denied" ? "Bloqueadas" : "No disponible";
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
    if (permission === "granted") {
      scheduleBrowserReminder(createdEvent);
      notifyNow("Recordatorio activado", `Seguiremos ${createdEvent.title} en este navegador.`);
    }
  }

  if (formData.get("sync_google") && !String(createdEvent.id).startsWith("seed-")) {
    const synced = await syncEventToGoogle(createdEvent.id);
    setEventSaveStatus(
      synced
        ? "Evento guardado y sincronizado con Google Calendar."
        : "Evento guardado localmente. La sincronizacion con Google quedo pendiente.",
      synced ? "success" : "warning",
    );
  } else {
    setEventSaveStatus("Evento guardado localmente.", "success");
  }

  await sendReminderEmail(createdEvent.id, formData);
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

hydrateOrbitConfig();
hydrateHolidays();
hydrateGoogleStatus();
