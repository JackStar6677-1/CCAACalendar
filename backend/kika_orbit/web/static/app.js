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
const newEventButton = document.querySelector("#new-event-button");
const todayButton = document.querySelector("#today-button");
const installButton = document.querySelector("#install-button");

let soundEnabled = false;
let audioContext;
let organizationId;
let deferredInstallPrompt;
let authSession;
let orbitConfig;

const fallbackEvents = [
  {
    id: "seed-1",
    title: "Reunion centro de estudiantes",
    category: "centro",
    starts_at: "2026-05-18T11:00:00-04:00",
    ends_at: "2026-05-18T12:30:00-04:00",
    description: "Planificacion de hitos del mes y responsables por comision.",
  },
  {
    id: "seed-2",
    title: "Semana de bienvenida",
    category: "academico",
    starts_at: "2026-05-21T09:00:00-04:00",
    ends_at: "2026-05-21T13:00:00-04:00",
    description: "Actividad academica importable desde calendario oficial.",
  },
  {
    id: "seed-3",
    title: "Auditorio reservado",
    category: "espacio",
    starts_at: "2026-05-25T15:00:00-04:00",
    ends_at: "2026-05-25T17:00:00-04:00",
    description: "Bloque protegido para evitar choques de salas.",
  },
];

let events = [...fallbackEvents];
let holidays = [];

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
  render();
}

function setLoginStatus(message, tone = "neutral") {
  loginStatus.textContent = message;
  loginStatus.dataset.tone = tone;
}

function normalizeCategory(category) {
  if (category === "academico" || category === "espacio") return category;
  return "centro";
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

function buildMonthDays() {
  const month = 4;
  const year = 2026;
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
  calendarGrid.replaceChildren();
  const today = new Date();

  buildMonthDays().forEach((day) => {
    const dayCard = document.createElement("article");
    dayCard.className = "calendar-day";
    const holiday = holidayForDay(day);
    if (day.getMonth() !== 4) dayCard.classList.add("is-muted");
    if (holiday) dayCard.classList.add("is-holiday");
    if (holiday?.is_irrenunciable) dayCard.classList.add("is-irrenunciable");
    if (
      day.getFullYear() === today.getFullYear() &&
      day.getMonth() === today.getMonth() &&
      day.getDate() === today.getDate()
    ) {
      dayCard.classList.add("today");
    }

    const dateNumber = document.createElement("div");
    dateNumber.className = "date-number";
    dateNumber.innerHTML = `<span>${day.getDate()}</span><span>${day.toLocaleDateString("es-CL", {
      weekday: "short",
    })}</span>`;
    dayCard.append(dateNumber);

    if (holiday && day.getMonth() === 4) {
      const ribbon = document.createElement("span");
      ribbon.className = `holiday-ribbon${holiday.is_irrenunciable ? " is-irrenunciable" : ""}`;
      ribbon.title = holiday.label;
      ribbon.textContent = holiday.is_irrenunciable ? "Irrenunciable" : holiday.label;
      dayCard.append(ribbon);
    }

    eventsForDay(day).forEach((event) => {
      const pill = document.createElement("button");
      pill.type = "button";
      pill.className = `event-pill ${normalizeCategory(event.category)}`;
      pill.textContent = event.title;
      pill.addEventListener("click", () => chirp(event.category === "espacio" ? "gold" : "soft"));
      dayCard.append(pill);
    });

    calendarGrid.append(dayCard);
  });
}

function renderAgenda() {
  agendaList.replaceChildren();

  const holidayItems = holidays
    .filter((holiday) => holiday.date.startsWith("2026-05"))
    .map((holiday) => ({
      id: `holiday-${holiday.date}`,
      title: holiday.label,
      category: "holiday",
      starts_at: `${holiday.date}T00:00:00-04:00`,
      ends_at: `${holiday.date}T23:59:00-04:00`,
      all_day: true,
      description: holiday.is_irrenunciable
        ? "Feriado irrenunciable adoptado desde la base Castel."
        : "Feriado nacional adoptado desde la base Castel.",
    }));

  [...events, ...holidayItems]
    .toSorted((a, b) => eventDate(a) - eventDate(b))
    .slice(0, 6)
    .forEach((event, index) => {
      const item = document.createElement("article");
      item.className = `agenda-item ${event.category === "holiday" ? "holiday" : normalizeCategory(event.category)}`;
      item.style.animationDelay = `${index * 45}ms`;
      item.innerHTML = `
        <strong>${event.title}</strong>
        <span>${formatTime(event)}</span>
        <p>${event.description || "Sin detalle todavia."}</p>
      `;
      agendaList.append(item);
    });
}

function render() {
  metricEvents.textContent = String(events.length);
  metricHolidays.textContent = String(holidays.filter((holiday) => holiday.is_irrenunciable).length);
  renderMissionStrip();
  renderCalendar();
  renderAgenda();
}

function renderMissionStrip() {
  const modules = orbitConfig?.modules || fallbackConfig.modules;
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
    const response = await fetch("/assets/kika-orbit.config.json");
    orbitConfig = response.ok ? await response.json() : fallbackConfig;
  } catch {
    orbitConfig = fallbackConfig;
  }
  render();
}

async function hydrateGoogleStatus() {
  try {
    const response = await fetch("/api/integrations/google/status");
    if (!response.ok) return;
    const status = await response.json();
    googleStatusBadge.textContent = status.ready_to_connect
      ? "Listo para conectar"
      : "Configuracion pendiente";
    googleStatusDetail.textContent = status.token_present
      ? "Token OAuth local detectado. La sincronizacion ya puede usar el calendario oficial."
      : "OAuth configurado sin exponer la cuenta en el repo publico. Falta completar el consentimiento si aun no hay token.";
    googleStatusBadge.dataset.ready = String(status.ready_to_connect);
  } catch {
    googleStatusBadge.textContent = "Sin conexion API";
    googleStatusDetail.textContent = "La interfaz funciona offline, pero la API local no respondio.";
  }
}

async function hydrateHolidays() {
  try {
    const response = await fetch("/api/holidays?year=2026");
    if (!response.ok) return;
    holidays = await response.json();
    render();
  } catch {
    render();
  }
}

async function ensureDemoOrganization() {
  const response = await fetch("/api/organizations");
  const organizations = response.ok ? await response.json() : [];
  const existing = organizations.find((item) => item.slug === "universidad-demo");
  if (existing) return existing.id;

  const created = await fetch("/api/organizations", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      name: "Universidad Demo",
      slug: "universidad-demo",
      domain_hint: "demo.edu",
    }),
  });

  if (!created.ok) return undefined;
  return (await created.json()).id;
}

async function hydrateFromApi() {
  try {
    organizationId = await ensureDemoOrganization();
    if (!organizationId) return;

    const response = await fetch(`/api/events?organization_id=${organizationId}`);
    if (!response.ok) return;

    const apiEvents = await response.json();
    if (apiEvents.length > 0) {
      events = [...apiEvents, ...fallbackEvents].filter(
        (event, index, list) => list.findIndex((item) => item.id === event.id) === index,
      );
      render();
    }
  } catch {
    render();
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
    const response = await fetch("/api/events", {
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
      events = [await response.json(), ...events];
      return;
    }
  }

  events = [localEvent, ...events];
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(loginForm);
  const rut = String(formData.get("rut") || "").trim();
  const password = String(formData.get("password") || "");

  if (!rut || !password) {
    setLoginStatus("Ingresa RUT y clave, o usa modo invitado para revisar la maqueta.", "warning");
    return;
  }

  setLoginStatus("Verificando acceso orbital...", "neutral");
  try {
    const response = await fetch("/api/auth/login", {
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
    setLoginStatus("No se pudo contactar la API local. Puedes usar modo invitado mientras revisamos.", "warning");
  }
});

demoButton.addEventListener("click", openApp);

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
  dialog.showModal();
});

todayButton.addEventListener("click", () => {
  document.querySelector(".calendar-day.today")?.scrollIntoView({ behavior: "smooth", block: "center" });
  chirp("gold");
});

eventForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  await createEventFromForm(new FormData(eventForm));
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

render();
hydrateOrbitConfig();
hydrateHolidays();
hydrateFromApi();
hydrateGoogleStatus();
