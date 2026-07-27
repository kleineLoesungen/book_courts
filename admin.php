<?php

/*******************************************************
 * admin.php – Mobile-first Admin (Tailwind)
 * - Login für Admins
 * - Übersicht: Alle aktiven Termine & Serien als Kartenlisten
 * - Admin kann stornieren (Termin oder ganze Serie)
 * - Nutzer & Plätze verwalten (kompakt)
 *******************************************************/

declare(strict_types=1);
session_start();

require_once __DIR__ . '/config.php';

/* =======================
   Helpers / Bootstrap
   ======================= */
function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $dsn, $DB_USER, $DB_PASS;
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) {
            http_response_code(400);
            exit('CSRF-Token ungültig.');
        }
    }
}
function is_logged_in(): bool
{
    return !empty($_SESSION['admin_user']);
}
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ?action=login');
        exit;
    }
}
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}
function render_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            $cls = $f['t'] === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200';
            echo '<div class="rounded-xl border px-4 py-2 text-sm ' . $cls . '">' . h($f['m']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
}

/* =======================
   Bootstrap: existiert ein User?
   ======================= */
function any_user_exists(): bool
{
    $stmt = pdo()->query("SELECT EXISTS(SELECT 1 FROM book_courts.app_user) AS e");
    return (bool)$stmt->fetchColumn();
}

/* =======================
   HTML Chrome (mobile-first)
   ======================= */
function html_head(string $title): string
{
    $t = h($title);
    return <<<HTML
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>{$t}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<header class="bg-gray-900 text-white">
  <div class="max-w-lg mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500 font-bold"><i data-lucide="loader-pinwheel" class="w-5 h-5"></i></span>
      <strong class="text-base">Admin</strong>
    </div>
    <nav class="text-sm opacity-90">
      <a class="underline" href="?action=home">Übersicht</a>
    </nav>
  </div>
</header>
HTML;
}
function bottom_nav(string $active = 'home'): string
{
    $item = function (string $href, string $label, bool $isActive): string {
        $cls = $isActive ? 'text-indigo-600 font-semibold' : '';
        return '<a href="' . $href . '" class="py-3 text-center ' . $cls . '">' . $label . '</a>';
    };
    return <<<HTML
<nav class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200">
  <div class="max-w-lg mx-auto grid grid-cols-3 text-sm text-gray-700">
    {$item('?action=home', 'Übersicht',$active === 'home')}
    {$item('?action=users', 'Nutzer',$active === 'users')}
    {$item('?action=courts', 'Plätze',$active === 'courts')}
  </div>
</nav>
<div class="pb-24"></div>
HTML;
}
function html_tail(): string
{
    return "</body></html>";
}

/* =======================
   Routing
   ======================= */
$action = $_GET['action'] ?? 'home';

/* =======================
   Erstadmin anlegen (wenn keine Nutzer existieren)
   ======================= */
if (!any_user_exists()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['full_name'] ?? '');
        $pass1 = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        if ($email === '' || $name === '' || $pass1 === '') {
            flash('Bitte alle Felder ausfüllen.', 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Ungültige E-Mail-Adresse.', 'error');
        } elseif ($pass1 !== $pass2) {
            flash('Passwörter stimmen nicht überein.', 'error');
        } else {
            try {
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                $sql = "INSERT INTO book_courts.app_user (email, password_hash, full_name, role, is_active)
                        VALUES (:email,:hash,:name,'admin',TRUE)";
                pdo()->prepare($sql)->execute([':email' => $email, ':hash' => $hash, ':name' => $name]);
                flash('Erster Admin angelegt. Bitte einloggen.');
                header('Location: ?action=login');
                exit;
            } catch (PDOException $e) {
                flash('Fehler: ' . $e->getMessage(), 'error');
            }
        }
    }
    echo html_head('Erst-Admin anlegen');
    echo '<main class="max-w-lg mx-auto p-4 space-y-4">';
    echo '<h1 class="text-2xl font-semibold">Erst-Admin anlegen</h1>';
    render_flash();
    echo '<form method="post" class="space-y-3 bg-white rounded-2xl shadow p-4">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<label class="block text-sm">E-Mail<input class="mt-1 w-full rounded-xl border px-4 py-3" type="email" name="email" required></label>';
    echo '<label class="block text-sm">Voller Name<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="full_name" required></label>';
    echo '<label class="block text-sm">Passwort<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password" required></label>';
    echo '<label class="block text-sm">Passwort (Wiederholung)<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password2" required></label>';
    echo '<button class="w-full rounded-xl bg-gray-900 text-white py-3">Admin anlegen</button>';
    echo '</form></main>';
    echo html_tail();
    exit;
}

/* =======================
   Login/Logout
   ======================= */
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $sql = "SELECT id, email, password_hash, full_name, role, is_active
                FROM book_courts.app_user
                WHERE lower(email)=lower(:email) LIMIT 1";
        $st = pdo()->prepare($sql);
        $st->execute([':email' => $email]);
        $u = $st->fetch();
        if (!$u || $u['role'] !== 'admin') {
            flash('Kein Admin mit dieser E-Mail.', 'error');
        } elseif (!$u['is_active']) {
            flash('Admin ist deaktiviert.', 'error');
        } elseif (!password_verify($pass, $u['password_hash'])) {
            flash('Falsches Passwort.', 'error');
        } else {
            $_SESSION['admin_user'] = ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['full_name'], 'role' => $u['role']];
            header('Location: ?action=home');
            exit;
        }
    }
    echo html_head('Admin Login');
    echo '<main class="max-w-lg mx-auto p-4 space-y-4">';
    echo '<h1 class="text-2xl font-semibold">Admin Login</h1>';
    render_flash();
    echo '<form method="post" class="space-y-3 bg-white rounded-2xl shadow p-4">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<label class="block text-sm">E-Mail<input class="mt-1 w-full rounded-xl border px-4 py-3" type="email" name="email" required></label>';
    echo '<label class="block text-sm">Passwort<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password" required></label>';
    echo '<button class="w-full rounded-xl bg-gray-900 text-white py-3">Einloggen</button>';
    echo '</form></main>';
    echo html_tail();
    exit;
}
if ($action === 'logout') {
    session_destroy();
    header('Location: ?action=login');
    exit;
}

/* =======================
   Actions (POST)
   ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_login();
    $op = $_POST['op'] ?? '';

    try {
        // Termine stornieren
        if ($op === 'cancel_booking') {
            $id = $_POST['id'] ?? '';
            pdo()->prepare("UPDATE book_courts.booking SET status='canceled' WHERE id::text=:id")
                ->execute([':id' => $id]);
            flash('Termin storniert.');
            header('Location: ?action=home');
            exit;
        }
        // Serie stornieren (deaktivieren + Buchungen canceln)
        if ($op === 'cancel_series') {
            $series_id = $_POST['series_id'] ?? '';
            pdo()->prepare("UPDATE book_courts.recurring_series SET is_active=FALSE WHERE id::text=:id")
                ->execute([':id' => $series_id]);
            pdo()->prepare("UPDATE book_courts.booking SET status='canceled' WHERE series_id::text=:id")
                ->execute([':id' => $series_id]);
            flash('Serie und zugehörige Termine storniert.');
            header('Location: ?action=home');
            exit;
        }

        // Nutzer-CRUD
        if ($op === 'user_create') {
            $email = trim($_POST['email'] ?? '');
            $name = trim($_POST['full_name'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $pass = $_POST['password'] ?? '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Ungültige E-Mail.');
            if ($name === '') throw new Exception('Name fehlt.');
            if ($pass === '') throw new Exception('Passwort fehlt.');
            if (!in_array($role, ['user', 'admin'], true)) $role = 'user';
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $sql = "INSERT INTO book_courts.app_user (email,password_hash,full_name,role,is_active)
                    VALUES (:e,:h,:n,:r,TRUE)";
            pdo()->prepare($sql)->execute([':e' => $email, ':h' => $hash, ':n' => $name, ':r' => $role]);
            flash('Nutzer angelegt.');
            header('Location: ?action=users');
            exit;
        }
        if ($op === 'user_update') {
            $id = $_POST['id'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $name = trim($_POST['full_name'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $active = isset($_POST['is_active']) ? 'TRUE' : 'FALSE';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Ungültige E-Mail.');
            $sql = "UPDATE book_courts.app_user SET email=:e, full_name=:n, role=:r, is_active={$active} WHERE id::text=:id";
            pdo()->prepare($sql)->execute([':e' => $email, ':n' => $name, ':r' => $role, ':id' => $id]);
            flash('Nutzer aktualisiert.');
            header('Location: ?action=users');
            exit;
        }
        if ($op === 'user_resetpw') {
            $id = $_POST['id'] ?? '';
            $pass = $_POST['password'] ?? '';
            if ($pass === '') throw new Exception('Neues Passwort fehlt.');
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            pdo()->prepare("UPDATE book_courts.app_user SET password_hash=:h WHERE id::text=:id")
                ->execute([':h' => $hash, ':id' => $id]);
            flash('Passwort gesetzt.');
            header('Location: ?action=users');
            exit;
        }
        if ($op === 'user_delete') {
            $id = $_POST['id'] ?? '';
            pdo()->prepare("DELETE FROM book_courts.app_user WHERE id::text=:id")->execute([':id' => $id]);
            flash('Nutzer gelöscht.');
            header('Location: ?action=users');
            exit;
        }

        // Plätze-CRUD
        if ($op === 'court_create') {
            $name = trim($_POST['name'] ?? '');
            $loc = trim($_POST['location'] ?? '');
            if ($name === '') throw new Exception('Name fehlt.');
            pdo()->prepare("INSERT INTO book_courts.court (name,location,is_active) VALUES (:n,:l,TRUE)")
                ->execute([':n' => $name, ':l' => $loc]);
            flash('Platz angelegt.');
            header('Location: ?action=courts');
            exit;
        }
        if ($op === 'court_update') {
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $loc = trim($_POST['location'] ?? '');
            $active = isset($_POST['is_active']) ? 'TRUE' : 'FALSE';
            if ($name === '') throw new Exception('Name fehlt.');
            pdo()->prepare("UPDATE book_courts.court SET name=:n, location=:l, is_active={$active} WHERE id::text=:id")
                ->execute([':n' => $name, ':l' => $loc, ':id' => $id]);
            flash('Platz aktualisiert.');
            header('Location: ?action=courts');
            exit;
        }
        if ($op === 'court_delete') {
            $id = $_POST['id'] ?? '';
            pdo()->prepare("DELETE FROM book_courts.court WHERE id::text=:id")->execute([':id' => $id]);
            flash('Platz gelöscht.');
            header('Location: ?action=courts');
            exit;
        }
    } catch (Exception $e) {
        flash('Fehler: ' . $e->getMessage(), 'error');
        header('Location: ?action=' . $action);
        exit;
    }
}

/* =======================
   PAGES
   ======================= */
$tz = new DateTimeZone('Europe/Berlin');

switch ($action) {
    case 'home':
        require_login();
        echo html_head('Admin – Übersicht');
        echo '<main class="max-w-lg mx-auto p-4 space-y-4">';
        render_flash();

        // Daten: aktive Buchungen (Zukunft + heute) und aktive Serien
        $nowISO = (new DateTime('now', $tz))->format('c');

        // Buchungen
        $sqlB = "SELECT b.id, b.user_id, b.court_id, b.status, b.source, b.series_id, b.title,
                        lower(b.time_span) AS start_at, upper(b.time_span) AS end_at,
                        u.full_name, u.email, c.name AS court_name
                 FROM book_courts.booking b
                 JOIN book_courts.app_user u ON u.id=b.user_id
                 JOIN book_courts.court c ON c.id=b.court_id
                 WHERE b.status='active' AND upper(b.time_span) >= :now
                 ORDER BY start_at ASC";
        $stB = pdo()->prepare($sqlB);
        $stB->execute([':now' => $nowISO]);
        $bookings = $stB->fetchAll();

        // Serien
        $sqlS = "SELECT id, user_id, court_id, title, weekday, start_time, duration_min, timezone, start_date, end_date, is_active
                 FROM book_courts.recurring_series
                 WHERE is_active=TRUE
                 ORDER BY start_date DESC";
        $series = pdo()->query($sqlS)->fetchAll();

        // Überschrift
        echo '<section class="space-y-3">';
        echo '<div class="flex items-center justify-between">';
        echo '<h1 class="text-2xl font-semibold">Übersicht</h1>';
        echo '<a class="text-sm text-white bg-gray-900 px-3 py-2 rounded-xl" href="?action=logout">Logout</a>';
        echo '</div>';
        echo '</section>';

        // LISTE: Buchungen (nach Jahr/Monat gruppiert, nur aktueller Monat geöffnet)
        echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
        echo '<header class="flex items-center justify-between">';
        echo '<h2 class="text-lg font-semibold">Termine</h2>';
        echo '</header>';

        if (!$bookings) {
            echo '<p class="text-gray-500">Keine aktiven Termine.</p>';
        } else {
            // Gruppieren: $groups[YYYY][MM] = []
            $groups = [];
            foreach ($bookings as $r) {
                $st = (new DateTime($r['start_at']))->setTimezone($tz);
                $y  = (int)$st->format('Y');
                $m  = (int)$st->format('n'); // 1..12
                $groups[$y][$m][] = $r;
            }

            // aktuelle Year/Month
            $now = new DateTime('now', $tz);
            $curY = (int)$now->format('Y');
            $curM = (int)$now->format('n');

            // Deutsche Monatsnamen
            $mon = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

            // Jahre absteigend, Monate absteigend innerhalb des Jahres
            krsort($groups, SORT_NUMERIC);
            foreach ($groups as $year => $months) {
                krsort($months, SORT_NUMERIC);

                // Year-Accordion
                $yearOpen = ($year === $curY) ? ' open' : '';
                echo '<details class="rounded-xl border p-3"' . $yearOpen . '>';
                echo '  <summary class="font-semibold cursor-pointer flex items-center justify-between">';
                echo '    <span>Jahr ' . h((string)$year) . '</span>';
                echo '  </summary>';
                echo '  <div class="mt-2 space-y-3">';

                foreach ($months as $mNum => $items) {
                    // Month-Accordion (nur aktueller Monat offen)
                    $monthOpen = ($year === $curY && $mNum === $curM) ? ' open' : '';
                    $label = $mon[$mNum] ?? ('Monat ' . $mNum);

                    echo '    <details class="rounded-lg border p-2"' . $monthOpen . '>';
                    echo '      <summary class="text-sm font-semibold cursor-pointer flex items-center justify-between">';
                    echo '        <span>' . h($label) . ' ' . h((string)$year) . '</span>';
                    echo '      </summary>';
                    echo '      <div class="mt-2 space-y-2">';

                    // Einträge innerhalb des Monats (Eingang bereits ORDER BY start_at)
                    foreach ($items as $r) {
                        $sTime = (new DateTime($r['start_at']))->setTimezone($tz)->format('d.m. H:i');
                        $eTime = (new DateTime($r['end_at']))->setTimezone($tz)->format('H:i');
                        $isSeries = !empty($r['series_id']) || $r['source'] === 'series';

                        echo '        <article class="rounded-xl border border-gray-200 p-3 flex items-start gap-3">';
                        echo '          <div class="h-10 w-10 shrink-0 rounded-xl bg-indigo-100 text-indigo-700 grid place-items-center font-semibold">' . h(mb_substr($r['court_name'], 0, 2)) . '</div>';
                        echo '          <div class="flex-1">';
                        echo '            <div class="text-sm text-gray-500">' . h($r['court_name']) . ($isSeries ? ' • Serie' : '') . '</div>';
                        echo '            <div class="text-base font-semibold">' . h($r['title'] ?: '—') . '</div>';
                        echo '            <div class="text-sm">' . h($sTime) . '–' . h($eTime) . ' • ' . h($r['full_name']) . '</div>';
                        echo '          </div>';
                        echo '          <div class="pt-1">';
                        // Stornieren (einzelner Termin oder Serie via Dialog)
                        echo '            <form method="post" class="inline">';
                        echo '              <input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                        echo '              <input type="hidden" name="op" value="cancel_booking">';
                        echo '              <input type="hidden" name="id" value="' . h($r['id']) . '">';
                        if ($isSeries) {
                            echo '              <input type="hidden" name="series_id" value="' . h($r['series_id']) . '">';
                            echo '              <button type="button" class="text-red-600 text-sm" onclick="openCancelDialog(this.form)">Stornieren</button>';
                        } else {
                            echo '              <button type="submit" class="text-red-600 text-sm" onclick="return confirm(\'Diesen Termin stornieren?\')">Stornieren</button>';
                        }
                        echo '            </form>';
                        echo '          </div>';
                        echo '        </article>';
                    }

                    echo '      </div>';
                    echo '    </details>';
                }

                echo '  </div>';
                echo '</details>';
            }
        }
        echo '</section>';

        // LISTE: Serien
        echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
        echo '<header class="flex items-center justify-between">';
        echo '<h2 class="text-lg font-semibold">Serien</h2>';
        echo '</header>';

        if (!$series) {
            echo '<p class="text-gray-500">Keine aktiven Serien.</p>';
        } else {
            // Court-Namen map für Anzeige
            $courts = pdo()->query("SELECT id, name FROM book_courts.court")->fetchAll();
            $cmap = [];
            foreach ($courts as $c) $cmap[$c['id']] = $c['name'];

            // User-Namen map
            $users = pdo()->query("SELECT id, full_name FROM book_courts.app_user")->fetchAll();
            $umap = [];
            foreach ($users as $u) $umap[$u['id']] = $u['full_name'];

            $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            foreach ($series as $s) {
                $courtName = $cmap[$s['court_id']] ?? 'Platz';
                $userName  = $umap[$s['user_id']] ?? 'Nutzer';
                $weekday   = $wd[(int)$s['weekday']] ?? '';
                $time      = substr((string)$s['start_time'], 0, 5);
                $dur       = (int)$s['duration_min'];
                $startD    = $s['start_date'];
                $endD      = $s['end_date'] ?: '—';

                echo '<article class="rounded-xl border border-gray-200 p-3">';
                echo '<div class="flex items-start gap-3">';
                echo '<div class="h-10 w-10 shrink-0 rounded-xl bg-purple-100 text-purple-700 grid place-items-center font-semibold">SR</div>';
                echo '<div class="flex-1">';
                echo '<div class="text-sm text-gray-500">' . h($courtName) . ' • ' . h($weekday) . ' • ' . h($time) . ' (' . h((string)$dur) . 'm)</div>';
                echo '<div class="text-base font-semibold">' . h($s['title'] ?: '—') . '</div>';
                echo '<div class="text-sm">von ' . h($startD) . ' bis ' . h($endD) . ' • ' . h($userName) . '</div>';
                echo '</div>';
                echo '</div>';
                echo '<div class="mt-2">';
                echo '<form method="post" class="inline">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="cancel_series">';
                echo '<input type="hidden" name="series_id" value="' . h($s['id']) . '">';
                echo '<button type="submit" class="text-red-600 text-sm" onclick="return confirm(\'Gesamte Serie wirklich stornieren?\')">Serie stornieren</button>';
                echo '</form>';
                echo '</div>';
                echo '</article>';
            }
        }
        echo '</section>';

        echo '</main>';
        echo bottom_nav('home');

        // 3-Optionen-Dialog (Termin/Serie/Abbrechen) – nur einmal global
?>
        <div id="cancelDialog" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40" onclick="closeCancelDialog()"></div>
            <div class="absolute inset-x-4 bottom-6 bg-white rounded-2xl shadow-xl p-4 space-y-3 max-w-lg mx-auto">
                <div class="text-base font-semibold">Was möchtest du stornieren?</div>
                <div class="text-sm text-gray-600">Nur diesen Termin oder die gesamte Serie?</div>
                <div class="grid gap-2">
                    <button type="button" class="w-full rounded-xl bg-red-600 text-white py-3" onclick="cancelDialogDeleteSingle()">Termin löschen</button>
                    <button type="button" id="btnDeleteSeries" class="w-full rounded-xl bg-red-700 text-white py-3" onclick="cancelDialogDeleteSeries()">Serie löschen</button>
                    <button type="button" class="w-full rounded-xl bg-gray-100 text-gray-800 py-3" onclick="closeCancelDialog()">Abbrechen</button>
                </div>
            </div>
        </div>
        <script>
            "use strict";
            let _pendingCancelForm = null;

            function openCancelDialog(form) {
                _pendingCancelForm = form || null;
                const hasSeries = !!(_pendingCancelForm && _pendingCancelForm.querySelector('input[name="series_id"]'));
                const btnSeries = document.getElementById('btnDeleteSeries');
                if (btnSeries) btnSeries.classList.toggle('hidden', !hasSeries);
                const dlg = document.getElementById('cancelDialog');
                if (dlg) dlg.classList.remove('hidden');
                return false;
            }

            function closeCancelDialog() {
                const dlg = document.getElementById('cancelDialog');
                if (dlg) dlg.classList.add('hidden');
            }

            function cancelDialogDeleteSingle() {
                if (!_pendingCancelForm) {
                    closeCancelDialog();
                    return;
                }
                const op = _pendingCancelForm.querySelector('input[name="op"]');
                if (op) op.value = 'cancel_booking';
                closeCancelDialog();
                _pendingCancelForm.submit();
                _pendingCancelForm = null;
            }

            function cancelDialogDeleteSeries() {
                if (!_pendingCancelForm) {
                    closeCancelDialog();
                    return;
                }
                const hasSeries = !!_pendingCancelForm.querySelector('input[name="series_id"]');
                if (!hasSeries) {
                    closeCancelDialog();
                    return;
                }
                const op = _pendingCancelForm.querySelector('input[name="op"]');
                if (op) op.value = 'cancel_series';
                closeCancelDialog();
                _pendingCancelForm.submit();
                _pendingCancelForm = null;
            }
        </script>
<?php
        echo html_tail();
        break;

    case 'users':
        require_login();
        echo html_head('Nutzer verwalten');
        echo '<main class="max-w-lg mx-auto p-4 space-y-4">';
        render_flash();

        // Create/Update/Reset/Delete werden im POST-Handler oben abgewickelt
        $users = pdo()->query("SELECT id, email, full_name, role, is_active, created_at
                               FROM book_courts.app_user
                               ORDER BY created_at DESC")->fetchAll();

        echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
        echo '<h1 class="text-lg font-semibold">Nutzer</h1>';

        echo '<details class="rounded-xl border p-3"><summary class="font-semibold cursor-pointer">Neuen Nutzer anlegen</summary>';
        echo '<form method="post" class="mt-2 space-y-3">';
        echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        echo '<input type="hidden" name="op" value="user_create">';
        echo '<label class="block text-sm">E-Mail<input class="mt-1 w-full rounded-xl border px-4 py-3" type="email" name="email" required></label>';
        echo '<label class="block text-sm">Name<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="full_name" required></label>';
        echo '<label class="block text-sm">Rolle<select class="mt-1 w-full rounded-xl border px-4 py-3" name="role"><option value="user">user</option><option value="admin">admin</option></select></label>';
        echo '<label class="block text-sm">Passwort<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password" required></label>';
        echo '<button class="w-full rounded-xl bg-indigo-600 text-white py-3">Anlegen</button>';
        echo '</form></details>';

        if (!$users) {
            echo '<p class="text-gray-500">Keine Nutzer.</p>';
        } else {
            foreach ($users as $u) {
                echo '<article class="rounded-xl border border-gray-200 p-3 space-y-2">';
                echo '<div class="text-base font-semibold">' . h($u['full_name']) . ' <span class="text-xs text-gray-500">(' . h($u['email']) . ')</span></div>';
                echo '<div class="text-sm text-gray-600">Rolle: ' . h($u['role']) . ' • ' . ($u['is_active'] ? 'aktiv' : 'inaktiv') . '</div>';
                echo '<details class="rounded-lg border p-2"><summary class="text-sm font-semibold cursor-pointer">Bearbeiten</summary>';
                // Update
                echo '<form method="post" class="mt-2 space-y-2">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="user_update">';
                echo '<input type="hidden" name="id" value="' . h($u['id']) . '">';
                echo '<label class="block text-sm">E-Mail<input class="mt-1 w-full rounded-xl border px-4 py-3" type="email" name="email" value="' . h($u['email']) . '" required></label>';
                echo '<label class="block text-sm">Name<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="full_name" value="' . h($u['full_name']) . '" required></label>';
                echo '<label class="block text-sm">Rolle<select class="mt-1 w-full rounded-xl border px-4 py-3" name="role"><option value="user" ' . ($u['role'] === 'user' ? 'selected' : '') . '>user</option><option value="admin" ' . ($u['role'] === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
                echo '<label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" ' . ($u['is_active'] ? 'checked' : '') . '> aktiv</label>';
                echo '<button class="w-full rounded-xl bg-gray-900 text-white py-2">Speichern</button>';
                echo '</form>';
                // Reset PW
                echo '<form method="post" class="mt-2 space-y-2">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="user_resetpw">';
                echo '<input type="hidden" name="id" value="' . h($u['id']) . '">';
                echo '<label class="block text-sm">Neues Passwort<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password" required></label>';
                echo '<button class="w-full rounded-xl bg-indigo-600 text-white py-2">Passwort setzen</button>';
                echo '</form>';
                // Delete
                echo '<form method="post" class="mt-2" onsubmit="return confirm(\'Nutzer wirklich löschen?\')">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="user_delete">';
                echo '<input type="hidden" name="id" value="' . h($u['id']) . '">';
                echo '<button class="w-full rounded-xl bg-red-600 text-white py-2">Löschen</button>';
                echo '</form>';
                echo '</details>';
                echo '</article>';
            }
        }
        echo '</section>';

        echo '</main>';
        echo bottom_nav('users');
        echo html_tail();
        break;

    case 'courts':
        require_login();
        echo html_head('Plätze verwalten');
        echo '<main class="max-w-lg mx-auto p-4 space-y-4">';
        render_flash();

        // CRUD über POST oben
        $courts = pdo()->query("SELECT id, name, location, is_active, created_at
                                FROM book_courts.court
                                ORDER BY created_at DESC")->fetchAll();

        echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
        echo '<h1 class="text-lg font-semibold">Plätze</h1>';

        echo '<details class="rounded-xl border p-3"><summary class="font-semibold cursor-pointer">Neuen Platz anlegen</summary>';
        echo '<form method="post" class="mt-2 space-y-3">';
        echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        echo '<input type="hidden" name="op" value="court_create">';
        echo '<label class="block text-sm">Name<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="name" required></label>';
        echo '<label class="block text-sm">Ort/Notiz<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="location"></label>';
        echo '<button class="w-full rounded-xl bg-indigo-600 text-white py-3">Anlegen</button>';
        echo '</form></details>';

        if (!$courts) {
            echo '<p class="text-gray-500">Keine Plätze.</p>';
        } else {
            foreach ($courts as $c) {
                echo '<article class="rounded-xl border border-gray-200 p-3 space-y-2">';
                echo '<div class="text-base font-semibold">' . h($c['name']) . '</div>';
                echo '<div class="text-sm text-gray-600">' . h($c['location'] ?? '') . ' • ' . ($c['is_active'] ? 'aktiv' : 'inaktiv') . '</div>';
                echo '<details class="rounded-lg border p-2"><summary class="text-sm font-semibold cursor-pointer">Bearbeiten</summary>';
                echo '<form method="post" class="mt-2 space-y-2">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="court_update">';
                echo '<input type="hidden" name="id" value="' . h($c['id']) . '">';
                echo '<label class="block text-sm">Name<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="name" value="' . h($c['name']) . '" required></label>';
                echo '<label class="block text-sm">Ort/Notiz<input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="location" value="' . h($c['location'] ?? '') . '"></label>';
                echo '<label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" ' . ($c['is_active'] ? 'checked' : '') . '> aktiv</label>';
                echo '<button class="w-full rounded-xl bg-gray-900 text-white py-2">Speichern</button>';
                echo '</form>';
                echo '<form method="post" class="mt-2" onsubmit="return confirm(\'Platz wirklich löschen?\')">';
                echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
                echo '<input type="hidden" name="op" value="court_delete">';
                echo '<input type="hidden" name="id" value="' . h($c['id']) . '">';
                echo '<button class="w-full rounded-xl bg-red-600 text-white py-2">Löschen</button>';
                echo '</form>';
                echo '</details>';
                echo '</article>';
            }
        }
        echo '</section>';

        echo '</main>';
        echo bottom_nav('courts');
        echo html_tail();
        break;

    default:
        header('Location: ?action=home');
        exit;
}
