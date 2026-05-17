(function () {
    var boot = window.CASTEL_CALENDAR_BOOT || {};
    var app = document.querySelector('[data-calendar-app]');
    if (!app) {
        return;
    }

    var currentDate = new Date();
    var currentYear = currentDate.getFullYear();
    var defaultSemester = currentDate.getMonth() >= 7 ? 's2' : 's1';

    var ROOM_OPTIONS = [
        { id: 'basica', label: 'Sala Básica', description: 'Laboratorio de enseñanza básica' },
        { id: 'media', label: 'Sala Media', description: 'Laboratorio de enseñanza media' }
    ];

    var SEMESTER_OPTIONS = [
        { id: 's1', label: '1er semestre', description: 'Marzo a julio' },
        { id: 's2', label: '2do semestre', description: 'Agosto a diciembre' }
    ];

    var STATUS_OPTIONS = [
        { value: 'disponible', label: 'Disponible' },
        { value: 'reservada', label: 'Reservada' },
        { value: 'mantenimiento', label: 'Mantención' },
        { value: 'bloqueada', label: 'Bloqueada' }
    ];

    var state = {
        year: currentYear,
        room: 'basica',
        semester: defaultSemester,
        csrfToken: boot.csrfToken || '',
        currentUser: boot.currentUser || { email: '', name: '', role: 'profesor' },
        userPermissions: { can_override: false, can_manage_holidays: false },
        reservations: {},
        customHolidays: {},
        pendingRequests: []
    };

    var refs = {
        yearSelect: app.querySelector('[data-calendar-year]'),
        roomTabs: app.querySelector('[data-calendar-room-tabs]'),
        semesterTabs: app.querySelector('[data-calendar-semester-tabs]'),
        summary: app.querySelector('[data-calendar-summary]'),
        heading: app.querySelector('[data-calendar-heading]'),
        weeks: app.querySelector('[data-calendar-weeks]'),
        requestList: app.querySelector('[data-request-list]'),
        holidayList: app.querySelector('[data-holiday-list]'),
        holidayDate: app.querySelector('[data-holiday-date]'),
        holidayLabel: app.querySelector('[data-holiday-label]'),
        importInput: app.querySelector('[data-import-input]'),
        statusMessage: app.querySelector('[data-status-message]')
    };

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
        });
        try {
            localStorage.setItem('castel-theme', theme);
        } catch (error) {}
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    });
    setTheme(document.documentElement.getAttribute('data-theme') || 'light');

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showStatus(message, type) {
        refs.statusMessage.textContent = message;
        refs.statusMessage.className = 'status-message is-visible ' + (type === 'error' ? 'is-error' : 'is-ok');
    }

    function clearStatus() {
        refs.statusMessage.textContent = '';
        refs.statusMessage.className = 'status-message';
    }

    function formatDateKey(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function parseDateKey(key) {
        var parts = key.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function cloneDate(date) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function addDays(date, days) {
        var next = cloneDate(date);
        next.setDate(next.getDate() + days);
        return next;
    }

    function startOfWeek(date) {
        var result = cloneDate(date);
        var weekday = (result.getDay() + 6) % 7;
        result.setDate(result.getDate() - weekday);
        return result;
    }

    function formatLongDate(date) {
        return new Intl.DateTimeFormat('es-CL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(date);
    }

    function formatShortDate(date) {
        return new Intl.DateTimeFormat('es-CL', {
            day: 'numeric',
            month: 'short'
        }).format(date);
    }

    function formatRange(startDate, endDate) {
        if (!startDate || !endDate) {
            return '';
        }
        return formatShortDate(startDate) + ' al ' + formatLongDate(endDate);
    }

    function calculateEasterSunday(year) {
        var a = year % 19;
        var b = Math.floor(year / 100);
        var c = year % 100;
        var d = Math.floor(b / 4);
        var e = b % 4;
        var f = Math.floor((b + 8) / 25);
        var g = Math.floor((b - f + 1) / 3);
        var h = (19 * a + b - d - g + 15) % 30;
        var i = Math.floor(c / 4);
        var k = c % 4;
        var l = (32 + 2 * e + 2 * i - h - k) % 7;
        var m = Math.floor((a + 11 * h + 22 * l) / 451);
        var month = Math.floor((h + l - 7 * m + 114) / 31) - 1;
        var day = ((h + l - 7 * m + 114) % 31) + 1;
        return new Date(year, month, day);
    }

    function getBuiltInHolidays(year) {
        var easter = calculateEasterSunday(year);
        var builtIn = [
            { date: new Date(year, 0, 1), label: 'Año Nuevo', source: 'nacional' },
            { date: addDays(easter, -2), label: 'Viernes Santo', source: 'nacional' },
            { date: addDays(easter, -1), label: 'Sábado Santo', source: 'nacional' },
            { date: new Date(year, 4, 1), label: 'Día del Trabajador', source: 'nacional' },
            { date: new Date(year, 4, 21), label: 'Glorias Navales', source: 'nacional' },
            { date: new Date(year, 5, 21), label: 'Día Nacional de los Pueblos Indígenas', source: 'nacional' },
            { date: new Date(year, 6, 16), label: 'Virgen del Carmen', source: 'nacional' },
            { date: new Date(year, 7, 15), label: 'Asunción de la Virgen', source: 'nacional' },
            { date: new Date(year, 8, 18), label: 'Independencia Nacional', source: 'nacional' },
            { date: new Date(year, 8, 19), label: 'Glorias del Ejército', source: 'nacional' },
            { date: new Date(year, 9, 12), label: 'Encuentro de Dos Mundos', source: 'nacional' },
            { date: new Date(year, 9, 31), label: 'Día de las Iglesias Evangélicas', source: 'nacional' },
            { date: new Date(year, 10, 1), label: 'Todos los Santos', source: 'nacional' },
            { date: new Date(year, 11, 8), label: 'Inmaculada Concepción', source: 'nacional' },
            { date: new Date(year, 11, 25), label: 'Navidad', source: 'nacional' }
        ];

        return builtIn.map(function (item) {
            return {
                date: formatDateKey(item.date),
                label: item.label,
                source: item.source
            };
        });
    }

    function getSemesterBounds(year, semesterId) {
        if (semesterId === 's1') {
            return { start: new Date(year, 2, 1), end: new Date(year, 6, 31) };
        }
        return { start: new Date(year, 7, 1), end: new Date(year, 11, 31) };
    }

    function buildWeeks(year, semesterId) {
        var bounds = getSemesterBounds(year, semesterId);
        var start = bounds.start;
        var end = bounds.end;
        var map = new Map();
        var cursor = cloneDate(start);

        while (cursor <= end) {
            var day = cursor.getDay();
            if (day >= 1 && day <= 5) {
                var monday = startOfWeek(cursor);
                var mondayKey = formatDateKey(monday);
                if (!map.has(mondayKey)) {
                    map.set(mondayKey, {
                        monday: monday,
                        days: [null, null, null, null, null]
                    });
                }
                map.get(mondayKey).days[day - 1] = cloneDate(cursor);
            }
            cursor = addDays(cursor, 1);
        }

        return Array.from(map.values());
    }

    function getHolidayMap() {
        var map = new Map();
        getBuiltInHolidays(state.year).forEach(function (item) {
            map.set(item.date, item);
        });
        Object.keys(state.customHolidays || {}).forEach(function (date) {
            var item = state.customHolidays[date];
            if (item && item.label) {
                map.set(date, { date: date, label: item.label, source: 'interno' });
            }
        });
        return map;
    }

    function getSelectedRoom() {
        return ROOM_OPTIONS.find(function (option) { return option.id === state.room; }) || ROOM_OPTIONS[0];
    }

    function getSelectedSemester() {
        return SEMESTER_OPTIONS.find(function (option) { return option.id === state.semester; }) || SEMESTER_OPTIONS[0];
    }

    function renderYearOptions() {
        var years = [];
        for (var year = currentYear - 1; year <= currentYear + 3; year += 1) {
            years.push('<option value="' + year + '"' + (year === state.year ? ' selected' : '') + '>' + year + '</option>');
        }
        refs.yearSelect.innerHTML = years.join('');
    }

    function renderChipGroup(container, options, selected, attrName) {
        container.innerHTML = options.map(function (option) {
            var activeClass = option.id === selected ? ' is-active' : '';
            return '<button type="button" class="chip' + activeClass + '" data-' + attrName + '="' + option.id + '">' + escapeHtml(option.label) + '</button>';
        }).join('');
    }

    function renderSummary(weeks, holidayMap) {
        var workingDays = 0;
        var occupiedDays = 0;
        var pendingCount = state.pendingRequests.length;
        var holidayCount = 0;

        weeks.forEach(function (week) {
            week.days.forEach(function (date) {
                if (!date) return;
                var key = formatDateKey(date);
                if (holidayMap.has(key)) {
                    holidayCount += 1;
                    return;
                }
                workingDays += 1;
                if (state.reservations[key]) {
                    occupiedDays += 1;
                }
            });
        });

        refs.summary.innerHTML = [
            {
                label: 'Sala activa',
                value: getSelectedRoom().label,
                hint: getSelectedRoom().description
            },
            {
                label: 'Semanas visibles',
                value: String(weeks.length),
                hint: getSelectedSemester().label + ' ' + state.year
            },
            {
                label: 'Días ocupados',
                value: String(occupiedDays),
                hint: workingDays + ' hábiles · ' + holidayCount + ' feriados'
            },
            {
                label: 'Solicitudes',
                value: String(pendingCount),
                hint: pendingCount ? 'requieren revisión' : 'sin pendientes'
            }
        ].map(function (item) {
            return '<article class="stat">' +
                '<span class="stat__label">' + escapeHtml(item.label) + '</span>' +
                '<strong class="stat__value">' + escapeHtml(item.value) + '</strong>' +
                '<span class="stat__hint">' + escapeHtml(item.hint) + '</span>' +
            '</article>';
        }).join('');
    }

    function statusLabel(status) {
        return (STATUS_OPTIONS.find(function (option) { return option.value === status; }) || STATUS_OPTIONS[0]).label;
    }

    function canEditReservation(reservation) {
        if (!reservation) {
            return true;
        }
        return state.userPermissions.can_override || reservation.owner_email === state.currentUser.email;
    }

    function dayCardMarkup(date, holidayMap) {
        var dateKey = formatDateKey(date);
        var holiday = holidayMap.get(dateKey);
        var reservation = state.reservations[dateKey] || null;

        if (holiday) {
            return '<article class="day-card is-holiday">' +
                '<div class="day-card__meta">' +
                    '<div><span class="day-card__weekday">' + escapeHtml(new Intl.DateTimeFormat('es-CL', { weekday: 'long' }).format(date)) + '</span>' +
                    '<span class="day-card__date">' + escapeHtml(formatLongDate(date)) + '</span></div>' +
                    '<span class="badge">' + escapeHtml(holiday.source === 'interno' ? 'Jornada interna' : 'Feriado') + '</span>' +
                '</div>' +
                '<div class="note">' + escapeHtml(holiday.label) + '</div>' +
            '</article>';
        }

        var editable = canEditReservation(reservation);
        var owner = reservation ? reservation.owner_name || reservation.owner_email : state.currentUser.name || state.currentUser.email;
        var version = reservation ? Number(reservation.version || 1) : 0;
        var selectedStatus = reservation ? reservation.status : 'disponible';
        var responsable = reservation ? reservation.responsable_label || '' : '';
        var notes = reservation ? reservation.notes || '' : '';

        var options = STATUS_OPTIONS.map(function (option) {
            return '<option value="' + option.value + '"' + (option.value === selectedStatus ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
        }).join('');

        var ownerBlock = reservation ? '<span class="day-card__owner">Propietario: ' + escapeHtml(owner) + '</span>' : '<span class="day-card__owner">Quedará a nombre de: ' + escapeHtml(owner) + '</span>';

        if (!editable) {
            return '<article class="day-card" data-day-card data-date="' + escapeHtml(dateKey) + '">' +
                '<div class="day-card__meta">' +
                    '<div><span class="day-card__weekday">' + escapeHtml(new Intl.DateTimeFormat('es-CL', { weekday: 'long' }).format(date)) + '</span>' +
                    '<span class="day-card__date">' + escapeHtml(formatLongDate(date)) + '</span>' + ownerBlock + '</div>' +
                    '<span class="badge day-card__status">' + escapeHtml(statusLabel(selectedStatus)) + '</span>' +
                '</div>' +
                '<div class="note"><strong>Responsable:</strong> ' + escapeHtml(responsable || 'Sin detalle') + '<br><strong>Observaciones:</strong> ' + escapeHtml(notes || 'Sin observaciones.') + '</div>' +
                '<div class="warning-note">Este día pertenece a otro docente. Puedes proponer un cambio, pero no sobrescribirlo directamente.</div>' +
                '<div class="day-card__grid" style="margin-top:12px;">' +
                    '<div class="calendar-field"><label>Estado solicitado</label><select class="calendar-select" data-request-field="status">' + options + '</select></div>' +
                    '<div class="calendar-field"><label>Responsable / curso</label><input class="calendar-input" type="text" data-request-field="responsable_label" value="' + escapeHtml(responsable) + '"></div>' +
                '</div>' +
                '<div class="calendar-field" style="margin-top:12px;"><label>Observaciones propuestas</label><textarea class="calendar-textarea" data-request-field="notes">' + escapeHtml(notes) + '</textarea></div>' +
                '<div class="calendar-field" style="margin-top:12px;"><label>Motivo de la solicitud</label><textarea class="calendar-textarea" data-request-field="reason" placeholder="Explica por qué necesitas este cambio."></textarea></div>' +
                '<div class="inline-actions"><button type="button" class="button button--primary" data-action="request-change">Solicitar cambio</button></div>' +
            '</article>';
        }

        return '<article class="day-card" data-day-card data-date="' + escapeHtml(dateKey) + '" data-version="' + escapeHtml(String(version)) + '">' +
            '<div class="day-card__meta">' +
                '<div><span class="day-card__weekday">' + escapeHtml(new Intl.DateTimeFormat('es-CL', { weekday: 'long' }).format(date)) + '</span>' +
                '<span class="day-card__date">' + escapeHtml(formatLongDate(date)) + '</span>' + ownerBlock + '</div>' +
                '<span class="badge day-card__status">' + escapeHtml(statusLabel(selectedStatus)) + '</span>' +
            '</div>' +
            '<div class="day-card__grid">' +
                '<div class="calendar-field"><label>Estado</label><select class="calendar-select" data-field="status">' + options + '</select></div>' +
                '<div class="calendar-field"><label>Responsable / curso</label><input class="calendar-input" type="text" data-field="responsable_label" value="' + escapeHtml(responsable) + '" placeholder="Ej.: 6°B · Prof. Ana"></div>' +
            '</div>' +
            '<div class="calendar-field" style="margin-top:12px;"><label>Observaciones</label><textarea class="calendar-textarea" data-field="notes" placeholder="Equipos, evaluación, observaciones de uso.">' + escapeHtml(notes) + '</textarea></div>' +
            '<div class="inline-actions">' +
                '<button type="button" class="button button--primary" data-action="save-day">Guardar día</button>' +
                '<button type="button" class="button button--danger" data-action="clear-day">Liberar día</button>' +
            '</div>' +
        '</article>';
    }

    function renderWeeks() {
        var weeks = buildWeeks(state.year, state.semester);
        var holidayMap = getHolidayMap();
        refs.heading.textContent = getSelectedSemester().label + ' ' + state.year + ' · ' + getSelectedRoom().label;
        renderSummary(weeks, holidayMap);

        if (!weeks.length) {
            refs.weeks.innerHTML = '<div class="empty">No se encontraron semanas laborales para este período.</div>';
            return;
        }

        var currentWeekKey = formatDateKey(startOfWeek(currentDate));
        refs.weeks.innerHTML = weeks.map(function (week, index) {
            var validDates = week.days.filter(Boolean);
            var firstDate = validDates[0];
            var lastDate = validDates[validDates.length - 1];
            var reservedCount = 0;
            var holidayCount = 0;

            validDates.forEach(function (date) {
                var dateKey = formatDateKey(date);
                if (holidayMap.has(dateKey)) {
                    holidayCount += 1;
                } else if (state.reservations[dateKey]) {
                    reservedCount += 1;
                }
            });

            return '<details class="week" ' + ((formatDateKey(week.monday) === currentWeekKey || index === 0) ? 'open' : '') + '>' +
                '<summary>' +
                    '<div class="week__title"><strong>Semana del ' + escapeHtml(formatShortDate(week.monday)) + '</strong><span>' + escapeHtml(formatRange(firstDate, lastDate)) + '</span></div>' +
                    '<div class="week__badges"><span class="badge">' + reservedCount + ' ocupados</span><span class="badge">' + holidayCount + ' feriados</span></div>' +
                '</summary>' +
                '<div class="week__body">' + week.days.filter(Boolean).map(function (date) { return dayCardMarkup(date, holidayMap); }).join('') + '</div>' +
            '</details>';
        }).join('');
    }

    function renderHolidayList() {
        var holidays = Object.keys(state.customHolidays || {}).sort().map(function (date) {
            return state.customHolidays[date];
        });

        if (!holidays.length) {
            refs.holidayList.innerHTML = '<div class="empty">Aún no hay días especiales internos para ' + state.year + '.</div>';
            return;
        }

        refs.holidayList.innerHTML = holidays.map(function (holiday) {
            var canRemove = state.userPermissions.can_manage_holidays;
            return '<li>' +
                '<strong>' + escapeHtml(holiday.label) + '</strong>' +
                '<span>' + escapeHtml(formatLongDate(parseDateKey(holiday.date))) + '</span>' +
                (canRemove ? '<div class="inline-actions"><button type="button" class="button button--ghost" data-remove-holiday="' + escapeHtml(holiday.date) + '">Eliminar</button></div>' : '') +
            '</li>';
        }).join('');
    }

    function renderRequestList() {
        if (!state.pendingRequests.length) {
            refs.requestList.innerHTML = '<div class="empty">No hay solicitudes pendientes para esta vista.</div>';
            return;
        }

        refs.requestList.innerHTML = state.pendingRequests.map(function (request) {
            var canDecide = state.userPermissions.can_override || request.owner_email === state.currentUser.email;
            return '<div class="request-card">' +
                '<div class="request-card__title">' + escapeHtml(formatLongDate(parseDateKey(request.date))) + ' · ' + escapeHtml(request.requested_by_name || request.requested_by_email) + '</div>' +
                '<div class="request-card__meta">Sala ' + escapeHtml(request.room) + ' · Propietario actual: ' + escapeHtml(request.owner_name || request.owner_email) + '</div>' +
                '<div class="request-card__body">' +
                    '<div><strong>Estado solicitado:</strong> ' + escapeHtml(statusLabel(request.requested_status)) + '</div>' +
                    '<div><strong>Responsable propuesto:</strong> ' + escapeHtml(request.requested_responsable_label || 'Sin detalle') + '</div>' +
                    '<div><strong>Observaciones propuestas:</strong> ' + escapeHtml(request.requested_notes || 'Sin observaciones') + '</div>' +
                    '<div><strong>Motivo:</strong> ' + escapeHtml(request.reason || 'Sin motivo indicado') + '</div>' +
                '</div>' +
                (canDecide ? '<div class="inline-actions"><button type="button" class="button button--primary" data-request-decision="approve" data-request-id="' + request.id + '">Aprobar</button><button type="button" class="button button--danger" data-request-decision="reject" data-request-id="' + request.id + '">Rechazar</button></div>' : '') +
            '</div>';
        }).join('');
    }

    function renderAll() {
        renderYearOptions();
        renderChipGroup(refs.roomTabs, ROOM_OPTIONS, state.room, 'room');
        renderChipGroup(refs.semesterTabs, SEMESTER_OPTIONS, state.semester, 'semester');
        renderWeeks();
        renderHolidayList();
        renderRequestList();
    }

    function queryString(params) {
        return Object.keys(params).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');
    }

    async function fetchJson(url, options) {
        var response = await fetch(url, options);
        var data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.message || 'No se pudo completar la operación.');
        }
        return data;
    }

    async function loadPeriod(showToast) {
        refs.weeks.innerHTML = '<div class="loading">Cargando semanas del período...</div>';
        refs.requestList.innerHTML = '<div class="loading">Cargando solicitudes...</div>';
        refs.holidayList.innerHTML = '<div class="loading">Cargando días especiales...</div>';

        var data = await fetchJson('/admin/calendar_api.php?' + queryString({
            action: 'load',
            year: state.year,
            room: state.room,
            semester: state.semester
        }));

        state.csrfToken = data.csrf_token || state.csrfToken;
        state.currentUser = data.user || state.currentUser;
        state.userPermissions = data.user || state.userPermissions;
        state.reservations = data.reservations || {};
        state.customHolidays = data.custom_holidays || {};
        state.pendingRequests = data.pending_requests || [];
        clearStatus();
        renderAll();

        if (showToast) {
            showStatus('Vista actualizada correctamente.', 'ok');
        }
    }

    function collectEditablePayload(dayCard) {
        return {
            room: state.room,
            date: dayCard.getAttribute('data-date'),
            status: dayCard.querySelector('[data-field="status"]').value,
            responsable_label: dayCard.querySelector('[data-field="responsable_label"]').value.trim(),
            notes: dayCard.querySelector('[data-field="notes"]').value.trim(),
            version: Number(dayCard.getAttribute('data-version') || 0),
            csrf_token: state.csrfToken
        };
    }

    function collectRequestPayload(dayCard) {
        return {
            room: state.room,
            date: dayCard.getAttribute('data-date'),
            status: dayCard.querySelector('[data-request-field="status"]').value,
            responsable_label: dayCard.querySelector('[data-request-field="responsable_label"]').value.trim(),
            notes: dayCard.querySelector('[data-request-field="notes"]').value.trim(),
            reason: dayCard.querySelector('[data-request-field="reason"]').value.trim(),
            csrf_token: state.csrfToken
        };
    }

    async function postAction(action, payload) {
        return fetchJson('/admin/calendar_api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    async function handleSaveDay(dayCard) {
        var payload = collectEditablePayload(dayCard);
        payload.send_email = true;
        var data = await postAction('save_reservation', payload);
        showStatus((data.message || 'Reserva guardada.') + (data.mail_sent ? ' Se envió aviso por correo.' : ' No se pudo confirmar el envío de correo.'), 'ok');
        await loadPeriod(false);
    }

    async function handleClearDay(dayCard) {
        var payload = collectEditablePayload(dayCard);
        payload.status = 'disponible';
        payload.responsable_label = '';
        payload.notes = '';
        payload.send_email = true;
        var data = await postAction('save_reservation', payload);
        showStatus((data.message || 'Día liberado.') + (data.mail_sent ? ' Se envió aviso por correo.' : ' No se pudo confirmar el envío de correo.'), 'ok');
        await loadPeriod(false);
    }

    async function handleRequestChange(dayCard) {
        var payload = collectRequestPayload(dayCard);
        if (!payload.reason) {
            showStatus('Debes explicar por qué solicitas el cambio.', 'error');
            return;
        }
        var sendEmail = window.confirm('Este día pertenece a otro docente. Pulsa Aceptar para enviar la solicitud y avisar por correo al propietario. Pulsa Cancelar para guardar la solicitud sin correo.');
        payload.send_email = sendEmail;
        var data = await postAction('request_change', payload);
        showStatus((data.message || 'Solicitud enviada.') + (payload.send_email ? (data.mail_sent ? ' Se envió correo al propietario.' : ' La solicitud quedó guardada, pero no se pudo confirmar el correo.') : ' La solicitud quedó guardada sin enviar correo.'), 'ok');
        await loadPeriod(false);
    }

    async function handleRequestDecision(requestId, decision) {
        var sendEmail = window.confirm(
            decision === 'approve'
                ? 'Pulsa Aceptar para aprobar y avisar por correo al solicitante. Pulsa Cancelar para aprobar sin correo.'
                : 'Pulsa Aceptar para rechazar y avisar por correo al solicitante. Pulsa Cancelar para rechazar sin correo.'
        );
        var data = await postAction('respond_request', {
            request_id: requestId,
            decision: decision,
            send_email: sendEmail,
            csrf_token: state.csrfToken
        });
        showStatus((data.message || 'Solicitud actualizada.') + (sendEmail ? (data.mail_sent ? ' Se envió correo al solicitante.' : ' La solicitud se actualizó, pero no se pudo confirmar el correo.') : ' La solicitud se actualizó sin enviar correo.'), 'ok');
        await loadPeriod(false);
    }

    async function handleSaveHoliday() {
        if (!state.userPermissions.can_manage_holidays) {
            showStatus('Tu rol no puede modificar días especiales.', 'error');
            return;
        }
        var date = refs.holidayDate.value;
        var label = refs.holidayLabel.value.trim();
        if (!date || !label) {
            showStatus('Completa la fecha y el motivo del día especial.', 'error');
            return;
        }
        var data = await postAction('save_holiday', {
            date: date,
            label: label,
            csrf_token: state.csrfToken
        });
        state.customHolidays = data.custom_holidays || state.customHolidays;
        refs.holidayDate.value = '';
        refs.holidayLabel.value = '';
        showStatus(data.message || 'Día especial guardado.', 'ok');
        renderAll();
    }

    async function handleRemoveHoliday(date) {
        var data = await postAction('remove_holiday', {
            date: date,
            csrf_token: state.csrfToken
        });
        state.customHolidays = data.custom_holidays || {};
        showStatus(data.message || 'Día especial eliminado.', 'ok');
        renderAll();
    }

    async function exportPeriod() {
        var data = await fetchJson('/admin/calendar_api.php?' + queryString({
            action: 'export',
            year: state.year,
            room: state.room,
            semester: state.semester
        }));
        var blob = new Blob([JSON.stringify(data.payload, null, 2)], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'calendario-' + state.room + '-' + state.semester + '-' + state.year + '.json';
        link.click();
        URL.revokeObjectURL(url);
    }

    async function importPeriod(file) {
        var text = await file.text();
        var payload = JSON.parse(text);
        var data = await postAction('import_period', {
            payload: payload,
            csrf_token: state.csrfToken
        });
        showStatus(data.message || 'Respaldo importado.', 'ok');
        await loadPeriod(false);
    }

    refs.yearSelect.addEventListener('change', function () {
        state.year = Number(refs.yearSelect.value);
        loadPeriod(false).catch(function (error) { showStatus(error.message, 'error'); });
    });

    refs.roomTabs.addEventListener('click', function (event) {
        var button = event.target.closest('[data-room]');
        if (!button) return;
        state.room = button.getAttribute('data-room');
        loadPeriod(false).catch(function (error) { showStatus(error.message, 'error'); });
    });

    refs.semesterTabs.addEventListener('click', function (event) {
        var button = event.target.closest('[data-semester]');
        if (!button) return;
        state.semester = button.getAttribute('data-semester');
        loadPeriod(false).catch(function (error) { showStatus(error.message, 'error'); });
    });

    app.addEventListener('click', function (event) {
        var actionButton = event.target.closest('[data-action]');
        if (actionButton) {
            var action = actionButton.getAttribute('data-action');
            var dayCard = actionButton.closest('[data-day-card]');

            if (action === 'refresh') {
                loadPeriod(true).catch(function (error) { showStatus(error.message, 'error'); });
                return;
            }
            if (action === 'export') {
                exportPeriod().catch(function (error) { showStatus(error.message, 'error'); });
                return;
            }
            if (action === 'import-trigger') {
                refs.importInput.click();
                return;
            }
            if (action === 'print') {
                window.print();
                return;
            }
            if (action === 'save-holiday') {
                handleSaveHoliday().catch(function (error) { showStatus(error.message, 'error'); });
                return;
            }
            if (action === 'save-day' && dayCard) {
                handleSaveDay(dayCard).catch(function (error) { showStatus(error.message, 'error'); });
                return;
            }
            if (action === 'clear-day' && dayCard) {
                handleClearDay(dayCard).catch(function (error) { showStatus(error.message, 'error'); });
                return;
            }
            if (action === 'request-change' && dayCard) {
                handleRequestChange(dayCard).catch(function (error) { showStatus(error.message, 'error'); });
            }
        }

        var removeHolidayButton = event.target.closest('[data-remove-holiday]');
        if (removeHolidayButton) {
            handleRemoveHoliday(removeHolidayButton.getAttribute('data-remove-holiday')).catch(function (error) {
                showStatus(error.message, 'error');
            });
        }

        var requestDecisionButton = event.target.closest('[data-request-decision]');
        if (requestDecisionButton) {
            handleRequestDecision(
                Number(requestDecisionButton.getAttribute('data-request-id')),
                requestDecisionButton.getAttribute('data-request-decision')
            ).catch(function (error) {
                showStatus(error.message, 'error');
            });
        }
    });

    refs.importInput.addEventListener('change', function () {
        if (!refs.importInput.files || !refs.importInput.files[0]) {
            return;
        }
        importPeriod(refs.importInput.files[0]).catch(function (error) {
            showStatus(error.message || 'No se pudo importar el respaldo.', 'error');
        }).finally(function () {
            refs.importInput.value = '';
        });
    });

    loadPeriod(false).catch(function (error) {
        showStatus(error.message || 'No se pudo cargar el calendario.', 'error');
        refs.weeks.innerHTML = '<div class="empty">No fue posible cargar el calendario. Revisa la sesión o la API privada.</div>';
        refs.requestList.innerHTML = '<div class="empty">Sin datos de solicitudes.</div>';
        refs.holidayList.innerHTML = '<div class="empty">Sin datos de días especiales.</div>';
    });
})();
