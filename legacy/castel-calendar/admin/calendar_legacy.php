<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_email = $current_user ? $current_user['email'] : '';
$current_name = $current_user ? admin_user_display_name($current_user) : '';
$current_role = $current_user ? admin_user_role($current_user) : 'profesor';
$csrf_token = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Calendario Privado | RoomKeeper</title>
    <meta name="theme-color" content="#163966">
    <script>
        (function () {
            try {
                var storedTheme = localStorage.getItem('castel-theme');
                document.documentElement.setAttribute('data-theme', storedTheme || 'light');
            } catch (error) {}
        })();
        window.CASTEL_CALENDAR_BOOT = {
            csrfToken: <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            currentUser: {
                email: <?php echo json_encode($current_email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                name: <?php echo json_encode($current_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                role: <?php echo json_encode($current_role, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #1b8252;
            --forest-deep: #11583b;
            --navy: #0f264f;
            --navy-soft: #193868;
            --teal: #1c9a8a;
            --gold: #d6aa43;
            --paper: #f3f7f2;
            --paper-strong: rgba(255, 255, 255, 0.9);
            --line: rgba(15, 38, 79, 0.12);
            --ink: #17304b;
            --muted: rgba(23, 48, 75, 0.72);
            --danger: #c44f4f;
            --warning: #c38c22;
            --ok: #0f7b58;
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --shadow-lg: 0 24px 48px rgba(15, 38, 79, 0.14);
            --shadow-md: 0 16px 28px rgba(15, 38, 79, 0.08);
            --site-width: 1200px;
        }

        :root[data-theme="dark"] {
            --paper: #081625;
            --paper-strong: rgba(12, 27, 46, 0.92);
            --ink: #ecf5ff;
            --muted: rgba(236, 245, 255, 0.74);
            --line: rgba(148, 196, 255, 0.14);
            --shadow-lg: 0 24px 54px rgba(2, 7, 18, 0.42);
            --shadow-md: 0 16px 34px rgba(2, 7, 18, 0.26);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; overflow-x: hidden; }
        body {
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 10%, rgba(123, 196, 255, 0.2), transparent 18%),
                radial-gradient(circle at 85% 8%, rgba(214, 170, 67, 0.14), transparent 18%),
                radial-gradient(circle at 78% 52%, rgba(28, 154, 138, 0.12), transparent 22%),
                linear-gradient(180deg, #f8fbff 0%, #eef5ef 42%, #edf1eb 100%);
            min-height: 100vh;
        }
        :root[data-theme="dark"] body {
            background:
                radial-gradient(circle at 12% 10%, rgba(123, 196, 255, 0.12), transparent 18%),
                radial-gradient(circle at 85% 8%, rgba(214, 170, 67, 0.08), transparent 18%),
                radial-gradient(circle at 78% 52%, rgba(28, 154, 138, 0.1), transparent 22%),
                linear-gradient(180deg, #08111c 0%, #0b1a28 42%, #0c1824 100%);
        }

        .container {
            width: min(var(--site-width), calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 14px 0;
        }

        .site-header__bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 18px;
            min-height: 84px;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(255,255,255,0.88), rgba(235,244,238,0.7));
            border: 1px solid rgba(255,255,255,0.8);
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(18px);
        }
        :root[data-theme="dark"] .site-header__bar {
            background: linear-gradient(135deg, rgba(11, 29, 50, 0.92), rgba(15, 49, 69, 0.78));
            border-color: rgba(148,196,255,0.12);
        }

        .site-logo {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            text-decoration: none;
            color: var(--ink);
        }
        .site-logo img {
            height: 58px;
            width: auto;
            display: block;
        }
        .site-logo__meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .site-logo__eyebrow {
            font-size: 0.76rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .site-logo__name {
            font-size: 1.06rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .site-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .theme-toggle,
        .nav-link {
            border: 0;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 999px;
            font: inherit;
            font-weight: 700;
            color: var(--ink);
            background: rgba(15, 38, 79, 0.06);
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .theme-toggle:hover,
        .nav-link:hover { transform: translateY(-1px); background: rgba(27, 130, 82, 0.12); }
        .nav-link--primary { background: linear-gradient(135deg, var(--forest), var(--teal)); color: #fff; }
        .theme-fab {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 80;
            border: 0;
            border-radius: 999px;
            padding: 13px 18px;
            font: inherit;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, rgba(15, 38, 79, 0.95), rgba(28, 154, 138, 0.88));
            box-shadow: var(--shadow-md);
            cursor: pointer;
        }

        main { padding: 18px 0 64px; }

        .page-hero {
            position: relative;
            overflow: hidden;
            padding: clamp(34px, 5vw, 56px) clamp(24px, 4vw, 50px);
            border-radius: var(--radius-xl);
            color: #fff;
            background:
                linear-gradient(135deg, rgba(15, 38, 79, 0.9), rgba(15, 38, 79, 0.68)),
                url('/wp-content/uploads/2024/10/Colegio-logo-1.jpg') center 28%/cover no-repeat;
            box-shadow: var(--shadow-lg);
        }
        .page-hero__kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 0.76rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 700;
            color: rgba(255,255,255,0.72);
        }
        .page-hero__kicker::before {
            content: "";
            width: 40px;
            height: 1px;
            background: rgba(214, 170, 67, 0.92);
        }
        .page-hero h1 {
            margin: 0;
            max-width: 12ch;
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 1.02;
            letter-spacing: -0.05em;
        }
        .page-hero p {
            max-width: 72ch;
            margin: 14px 0 0;
            font-size: 1rem;
            color: rgba(255,255,255,0.86);
        }

        .surface {
            margin-top: 16px;
            padding: clamp(18px, 3vw, 30px);
            background: var(--paper-strong);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(18px);
        }
        :root[data-theme="dark"] .surface { border-color: var(--line); }

        .calendar-intro h2,
        .calendar-panel h3,
        .calendar-section__header h3 {
            margin: 10px 0 6px;
            font-size: clamp(1.2rem, 2vw, 1.5rem);
            line-height: 1.14;
            letter-spacing: -0.03em;
        }
        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.78rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--muted);
        }
        .kicker::after {
            content: "";
            width: 34px;
            height: 1px;
            background: linear-gradient(90deg, rgba(214,170,67,0.85), rgba(15,38,79,0.18));
        }

        .calendar-shell { display: grid; gap: 22px; }
        .calendar-toolbar {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 18px;
        }
        .calendar-panel {
            padding: 22px;
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.74);
            border: 1px solid var(--line);
            box-shadow: 0 14px 24px rgba(15,38,79,0.05);
        }
        :root[data-theme="dark"] .calendar-panel { background: rgba(10, 25, 41, 0.74); }
        .calendar-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .calendar-field {
            display: grid;
            gap: 8px;
        }
        .calendar-field label {
            font-size: 0.87rem;
            font-weight: 700;
            color: var(--muted);
        }
        .calendar-select,
        .calendar-input,
        .calendar-textarea {
            width: 100%;
            min-width: 0;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(15,38,79,0.14);
            background: rgba(255,255,255,0.84);
            color: var(--ink);
            font: inherit;
        }
        :root[data-theme="dark"] .calendar-select,
        :root[data-theme="dark"] .calendar-input,
        :root[data-theme="dark"] .calendar-textarea {
            background: rgba(255,255,255,0.05);
            border-color: rgba(148,196,255,0.18);
            color: var(--ink);
        }
        .calendar-textarea { min-height: 94px; resize: vertical; }

        .chip-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            border: 1px solid var(--line);
            background: rgba(15,38,79,0.04);
            color: var(--ink);
            border-radius: 999px;
            padding: 10px 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .chip.is-active {
            background: linear-gradient(135deg, rgba(27,130,82,0.14), rgba(28,154,138,0.12));
            border-color: rgba(27,130,82,0.3);
            color: var(--forest-deep);
        }
        :root[data-theme="dark"] .chip.is-active { color: #dffceb; }

        .action-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            color: var(--navy);
            background: rgba(15,38,79,0.08);
        }
        .button--primary { color: #fff; background: linear-gradient(135deg, var(--forest), var(--teal)); }
        .button--ghost { background: rgba(255,255,255,0.56); border: 1px solid var(--line); }
        .button--danger { color: #fff; background: linear-gradient(135deg, #aa4343, #cf6262); }
        .button:disabled { opacity: 0.55; cursor: not-allowed; }

        .calendar-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .stat {
            padding: 18px;
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--line);
        }
        :root[data-theme="dark"] .stat { background: rgba(255,255,255,0.04); }
        .stat__label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
        .stat__value { display: block; font-size: 1.8rem; font-weight: 800; margin-top: 6px; }
        .stat__hint { display: block; margin-top: 6px; color: var(--muted); font-size: 0.9rem; }

        .calendar-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(290px, 0.95fr);
            gap: 18px;
        }
        .calendar-section,
        .calendar-aside { display: grid; gap: 18px; align-content: start; }
        .calendar-section__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; }

        .legend { display: flex; flex-wrap: wrap; gap: 10px; color: var(--muted); font-size: 0.84rem; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 10px; height: 10px; border-radius: 999px; display: inline-block; }
        .legend .free { background: rgba(15,38,79,0.15); }
        .legend .reserved { background: linear-gradient(135deg, rgba(27,130,82,0.86), rgba(28,154,138,0.86)); }
        .legend .service { background: linear-gradient(135deg, rgba(195,140,34,0.9), rgba(214,170,67,0.88)); }
        .legend .blocked { background: linear-gradient(135deg, rgba(170,67,67,0.92), rgba(204,96,96,0.82)); }
        .legend .holiday { background: linear-gradient(135deg, rgba(15,38,79,0.62), rgba(214,170,67,0.86)); }

        .week-list { display: grid; gap: 14px; }
        .week {
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.64);
            overflow: hidden;
        }
        :root[data-theme="dark"] .week { background: rgba(255,255,255,0.03); }
        .week summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            cursor: pointer;
        }
        .week summary::-webkit-details-marker { display: none; }
        .week__title strong { display: block; font-size: 1rem; }
        .week__title span { display: block; margin-top: 3px; color: var(--muted); font-size: 0.92rem; }
        .week__badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            background: rgba(15,38,79,0.08);
        }
        .week__body {
            display: grid;
            gap: 12px;
            padding: 0 18px 18px;
        }
        .day-card {
            border-radius: 18px;
            border: 1px solid var(--line);
            padding: 16px;
            background: rgba(255,255,255,0.72);
        }
        :root[data-theme="dark"] .day-card { background: rgba(255,255,255,0.04); }
        .day-card.is-holiday { background: linear-gradient(135deg, rgba(15,38,79,0.82), rgba(29,59,112,0.78)); color: #fff; }
        .day-card__meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .day-card__weekday { display: block; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-weight: 700; }
        .day-card.is-holiday .day-card__weekday,
        .day-card.is-holiday .day-card__date { color: rgba(255,255,255,0.88); }
        .day-card__date { display: block; margin-top: 4px; font-size: 1rem; font-weight: 800; }
        .day-card__owner { display: block; margin-top: 6px; color: var(--muted); font-size: 0.88rem; }
        .day-card__status { white-space: nowrap; }
        .day-card__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .note {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(15,38,79,0.05);
            color: var(--muted);
        }
        .warning-note {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(195,140,34,0.12);
            color: #7a5712;
            font-weight: 600;
        }
        .inline-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

        .list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 10px;
        }
        .list li {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.58);
        }
        :root[data-theme="dark"] .list li { background: rgba(255,255,255,0.04); }
        .list strong { display: block; }
        .list span { display: block; margin-top: 4px; color: var(--muted); }
        .request-card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid rgba(27,130,82,0.22);
            background: linear-gradient(135deg, rgba(27,130,82,0.07), rgba(28,154,138,0.05));
        }
        .request-card__title { font-weight: 800; }
        .request-card__meta { margin-top: 4px; color: var(--muted); font-size: 0.9rem; }
        .request-card__body { margin-top: 10px; display: grid; gap: 6px; font-size: 0.95rem; }

        .status-message {
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 700;
            margin-bottom: 14px;
            display: none;
        }
        .status-message.is-visible { display: block; }
        .status-message.is-ok { background: rgba(15,123,88,0.12); color: var(--ok); }
        .status-message.is-error { background: rgba(196,79,79,0.12); color: var(--danger); }
        .empty {
            padding: 16px;
            border-radius: 16px;
            border: 1px dashed var(--line);
            color: var(--muted);
        }
        .loading {
            padding: 22px;
            color: var(--muted);
        }

        .site-footer { padding-bottom: 34px; }
        .site-footer__panel {
            padding: 24px;
            border-radius: var(--radius-xl);
            background: rgba(15,38,79,0.92);
            color: rgba(255,255,255,0.84);
        }
        .site-footer__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .site-footer h3 { margin: 0 0 10px; color: #fff; }
        .site-footer a { color: rgba(255,255,255,0.84); text-decoration: none; display: block; margin-top: 6px; }
        .site-footer__bottom {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: rgba(255,255,255,0.6);
            font-size: 0.9rem;
        }

        @media (max-width: 1080px) {
            .calendar-toolbar,
            .calendar-layout,
            .site-footer__grid,
            .calendar-summary { grid-template-columns: 1fr; }
        }

        @media (max-width: 820px) {
            .site-header__bar { border-radius: 28px; align-items: flex-start; }
            .site-actions { width: 100%; justify-content: flex-start; }
            .calendar-field-grid,
            .day-card__grid { grid-template-columns: 1fr; }
            .theme-toggle { display: none; }
        }

        @media (max-width: 640px) {
            .container { width: min(var(--site-width), calc(100% - 18px)); }
            .site-header { padding: 10px 0; }
            .site-header__bar {
                min-height: auto;
                padding: 12px 14px;
                border-radius: 24px;
                flex-direction: column;
                align-items: stretch;
            }
            .site-logo img { height: 48px; }
            .site-actions { gap: 8px; }
            .nav-link,
            .button { width: 100%; justify-content: center; }
            .page-hero { padding: 28px 18px; border-radius: 24px; }
            .surface { padding: 18px 14px; border-radius: 24px; }
            .week summary,
            .week__body { padding-left: 14px; padding-right: 14px; }
            .day-card__meta { flex-direction: column; }
            .inline-actions { flex-direction: column; }
            .theme-fab { bottom: 14px; right: 14px; }
            .site-footer__bottom { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <div class="container">
                <div class="site-header__bar">
                    <a class="site-logo" href="/admin/editor.php">
                        <img src="/admin/calendar-icon.svg" alt="RoomKeeper">
                        <span class="site-logo__meta">
                            <span class="site-logo__eyebrow">RoomKeeper</span>
                            <span class="site-logo__name">Calendario privado</span>
                        </span>
                    </a>

                    <div class="site-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle>Oscuro</button>
                        <a class="nav-link" href="/admin/editor.php">Panel</a>
                        <a class="nav-link nav-link--primary" href="/admin/calendar.php">Calendario</a>
                        <a class="nav-link" href="/admin/sql.php">SQL / prueba</a>
                        <a class="nav-link" href="/admin/index.php">Inicio</a>
                        <a class="nav-link" href="/admin/index.php?logout=1">Cerrar sesión</a>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <div class="container">
                <section class="page-hero">
                    <div class="page-hero__kicker">Herramienta privada</div>
                    <h1>Calendario Sala de Computación</h1>
                    <p>Agenda interna para Sala Básica y Sala Media. Cada reserva tiene propietario, los cambios quedan registrados y las modificaciones sobre reservas ajenas requieren solicitud y aprobación.</p>
                </section>

                <section class="surface">
                    <div class="calendar-shell" data-calendar-app>
                        <div class="status-message" data-status-message></div>

                        <section class="calendar-intro">
                            <span class="kicker">Gestión interna</span>
                            <h2>Planificación protegida y compartida</h2>
                            <p>Esta versión ya no guarda solo en un navegador: usa almacenamiento central del panel administrativo. Cada día reservado queda asociado a un docente o responsable, y cualquier cambio sobre una reserva ajena debe pasar por una solicitud y aprobación.</p>
                        </section>

                        <section class="calendar-toolbar">
                            <article class="calendar-panel">
                                <span class="kicker">Vista actual</span>
                                <h3>Configura el período de trabajo</h3>
                                <div class="calendar-field-grid">
                                    <div class="calendar-field">
                                        <label for="calendar-year">Año académico</label>
                                        <select id="calendar-year" class="calendar-select" data-calendar-year></select>
                                    </div>
                                    <div class="calendar-field">
                                        <label>Tu rol actual</label>
                                        <input class="calendar-input" type="text" value="<?php echo htmlspecialchars($current_role); ?>" readonly>
                                    </div>
                                </div>
                                <div class="calendar-field">
                                    <label>Salas</label>
                                    <div class="chip-group" data-calendar-room-tabs></div>
                                </div>
                                <div class="calendar-field">
                                    <label>Semestre</label>
                                    <div class="chip-group" data-calendar-semester-tabs></div>
                                </div>
                            </article>

                            <article class="calendar-panel">
                                <span class="kicker">Respaldo y uso</span>
                                <h3>Herramienta compartida</h3>
                                <p>Las reservas se guardan en el panel del colegio. Puedes exportar el período actual como respaldo, importar un JSON si tienes permiso y usar impresión para colgar una vista semanal física cuando haga falta.</p>
                                <div class="action-row">
                                    <button type="button" class="button button--primary" data-action="refresh">Actualizar</button>
                                    <button type="button" class="button" data-action="export">Exportar período</button>
                                    <button type="button" class="button" data-action="import-trigger">Importar respaldo</button>
                                    <button type="button" class="button button--ghost" data-action="print">Imprimir</button>
                                </div>
                                <input type="file" accept="application/json" hidden data-import-input>
                            </article>
                        </section>

                        <section class="calendar-summary" data-calendar-summary>
                            <div class="loading">Cargando resumen del período...</div>
                        </section>

                        <section class="calendar-layout">
                            <section class="calendar-section">
                                <div class="calendar-section__header">
                                    <div>
                                        <span class="kicker">Agenda semanal</span>
                                        <h3 data-calendar-heading>Semanas del período</h3>
                                    </div>
                                    <div class="legend">
                                        <span><i class="free"></i>Disponible</span>
                                        <span><i class="reserved"></i>Reservada</span>
                                        <span><i class="service"></i>Mantención</span>
                                        <span><i class="blocked"></i>Bloqueada</span>
                                        <span><i class="holiday"></i>Feriado</span>
                                    </div>
                                </div>
                                <div class="week-list" data-calendar-weeks>
                                    <div class="loading">Cargando semanas...</div>
                                </div>
                            </section>

                            <aside class="calendar-aside">
                                <article class="calendar-panel">
                                    <span class="kicker">Solicitudes</span>
                                    <h3>Cambios pendientes</h3>
                                    <div data-request-list class="list">
                                        <div class="loading">Buscando solicitudes...</div>
                                    </div>
                                </article>

                                <article class="calendar-panel">
                                    <span class="kicker">Feriados y bloqueos</span>
                                    <h3>Días especiales del colegio</h3>
                                    <p>Los feriados nacionales se aplican automáticamente. Si tu rol lo permite, también puedes marcar jornadas internas sin clases o bloqueos especiales.</p>
                                    <div class="calendar-field">
                                        <label for="holiday-date">Fecha</label>
                                        <input id="holiday-date" class="calendar-input" type="date" data-holiday-date>
                                    </div>
                                    <div class="calendar-field">
                                        <label for="holiday-label">Motivo</label>
                                        <input id="holiday-label" class="calendar-input" type="text" maxlength="90" placeholder="Ej.: Consejo de profesores" data-holiday-label>
                                    </div>
                                    <div class="action-row">
                                        <button type="button" class="button button--primary" data-action="save-holiday">Guardar día especial</button>
                                    </div>
                                    <ul class="list" data-holiday-list>
                                        <div class="loading">Cargando días especiales...</div>
                                    </ul>
                                </article>

                                <article class="calendar-panel">
                                    <span class="kicker">Visibilidad</span>
                                    <h3>Cómo funciona el bloqueo</h3>
                                    <div class="note">
                                        Si un docente ya reservó un día, otro usuario no puede sobrescribirlo directamente. Debe enviar una solicitud de cambio. El propietario de la reserva, coordinación, directivos o administración pueden aprobar o rechazar.
                                    </div>
                                </article>
                            </aside>
                        </section>
                    </div>
                </section>
            </div>
        </main>

        <footer class="site-footer">
            <div class="container">
                <div class="site-footer__panel">
                    <div class="site-footer__grid">
                        <section>
                            <h3>RoomKeeper</h3>
                            <p>Herramienta privada del panel administrativo para ordenar la ocupación de las salas.</p>
                        </section>
                        <section>
                            <h3>Accesos</h3>
                            <a href="/admin/editor.php">Panel principal</a>
                            <a href="/admin/index.php?logout=1">Cerrar sesión</a>
                            <a href="/admin/sql.php">SQL / prueba</a>
                            <a href="/admin/index.php">Inicio</a>
                        </section>
                        <section>
                            <h3>Seguridad</h3>
                            <p>Las reservas quedan con propietario, se registra auditoría y los cambios sensibles no dependen solo del navegador.</p>
                        </section>
                    </div>
                    <div class="site-footer__bottom">
                        <span>RoomKeeper</span>
                        <span>Calendario privado · Admin</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <button type="button" class="theme-fab" data-theme-toggle>Oscuro</button>
    <script src="/admin/calendar_app.js"></script>
</body>
</html>
