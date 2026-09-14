<?php

/*******************************************************
 * index.php – Mobile-first UI (Tailwind)
 * - Immer Wochenansicht (Mo–So)
 * - Runde Tages-Buttons (Farbe/Indikator bei vorhandenen Buchungen)
 * - Klick auf Tag zeigt darunter die Tagesagenda
 * - Wochenauswahl über Kalender-Icon (ohne sichtbares Datum)
 *******************************************************/

declare(strict_types=1);

require_once __DIR__ . '/config.php';

start_secure_session();

/* =======================
   Helpers / Bootstrap
   ======================= */
/* pdo() liegt in config.php - gemeinsam mit admin.php genutzt. */
function csrf_token(): string
{
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
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
function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_logged_in(): bool
{
  return !empty($_SESSION['user']);
}
function current_user()
{
  return $_SESSION['user'] ?? null;
}
function is_admin(): bool
{
  return (current_user()['role'] ?? '') === 'admin';
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
function anonymize_name(string $n): string
{
  $parts = preg_split('/\s+/', trim($n));
  $ini = array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)), $parts);
  return implode('', $ini);
}
function format_duration_short(int $minutes): string
{
  if ($minutes <= 0) return '0 Minuten';
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  $parts = [];
  if ($h > 0) $parts[] = $h . ' Stunden';
  if ($m > 0) $parts[] = $m . ' Minuten';
  return implode(' ', $parts);
}
function redirect_to_day(string $iso): string
{
  try {
    $d = (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
  } catch (Throwable $e) {
    $d = date('Y-m-d');
  }
  return 'index.php?view=week&date=' . $d . '&selected=' . $d;
}
/* ==== UI Helper: Zeit-Selects im 30-Minuten-Raster ==== */
function render_time_select(string $name, string $selected = '', bool $isEnd = false, string $id = ''): string
{
  $idAttr = $id ? ' id="' . h($id) . '"' : '';
  $opts = [];

  // Start-Liste: 00:00 bis 23:30
  if (!$isEnd) {
    for ($h = 0; $h < 24; $h++) {
      foreach ([0, 30] as $m) {
        $val = sprintf('%02d:%02d', $h, $m);
        $sel = ($selected === $val) ? ' selected' : '';
        $opts[] = '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
      }
    }
  } else {
    // End-Liste: 00:30 bis 23:30
    for ($h = 0; $h < 24; $h++) {
      foreach ([0, 30] as $m) {
        if ($h === 0 && $m === 0) continue;         // 00:00 nicht hier
        if ($h === 23 && $m > 30) continue;         // bis 23:30
        $val = sprintf('%02d:%02d', $h, $m);
        $sel = ($selected === $val) ? ' selected' : '';
        $opts[] = '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
      }
    }
    // Zusätzlich 00:00 als letzter Eintrag (gewünscht)
    $sel = ($selected === '00:00') ? ' selected' : '';
    $opts[] = '<option value="00:00"' . $sel . '>00:00</option>';
  }

  // Button-Style Select (vollbreit, mobilfreundlich)
  return '
  <div class="relative w-full">
    <select name="' . h($name) . '"' . $idAttr . ' class="w-full appearance-none rounded-xl border px-4 py-3 pr-10 bg-white text-gray-900">
      ' . implode("\n", $opts) . '
    </select>
    <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-70" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.146l3.71-3.916a.75.75 0 111.08 1.04l-4.24 4.48a.75.75 0 01-1.08 0l-4.24-4.48a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
      </svg>
    </span>
  </div>';
}

/* ==== UI Helper: Dauer-Eingabe im 30-Minuten-Raster ==== */
function render_duration_input(string $name, int $selectedMin, bool $isAdmin, string $id = ''): string
{
  $idAttr = $id ? ' id="' . h($id) . '"' : '';
  if (!$isAdmin) {
    // Zwei exklusive Buttons: 30 / 60
    $opts = [30 => '30&nbsp;Min', 60 => '60&nbsp;Min'];
    $html = '<div class="flex gap-2" role="radiogroup">';
    foreach ($opts as $min => $label) {
      $cid = $id ? $id . '_' . $min : ('dur_' . $min);
      $checked = ($selectedMin === $min) ? ' checked' : '';
      $html .= '
        <label class="cursor-pointer select-none">
          <input class="peer sr-only" type="radio" name="' . h($name) . '" value="' . $min . '" id="' . h($cid) . '" required' . $checked . '>
          <span class="px-4 py-2 rounded-xl border text-sm bg-white border-gray-300 text-gray-800
                       peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 inline-block">'
        . $label .
        '</span>
        </label>';
    }
    $html .= '</div>';
    return $html;
  }

  // Admin: Dropdown 30–720 Minuten
  $opts = [];
  for ($m = 30; $m <= 12 * 60; $m += 30) {
    $label = ($m < 60) ? ($m . ' Min') : (floor($m / 60) . ' Std' . (($m % 60) ? ' ' . ($m % 60) . ' Min' : ''));
    $sel = ($selectedMin === $m) ? ' selected' : '';
    $opts[] = '<option value="' . $m . '"' . $sel . '>' . $label . '</option>';
  }
  return '
  <div class="relative w-full">
    <select name="' . h($name) . '"' . $idAttr . ' class="w-full appearance-none rounded-xl border px-4 py-3 pr-10 bg-white text-gray-900">
      ' . implode("\n", $opts) . '
    </select>
    <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-70" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.146l3.71-3.916a.75.75 0 111.08 1.04l-4.24 4.48a.75.75 0 01-1.08 0l-4.24-4.48a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
      </svg>
    </span>
  </div>';
}

/* =======================
   Auth (simple)
   ======================= */
$action = $_GET['action'] ?? 'home';
if ($action === 'login') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (login_is_blocked($email)) {
      flash('Zu viele Fehlversuche. Bitte in ' . LOGIN_WINDOW_MINUTES . ' Minuten erneut versuchen.', 'error');
    } else {
      $sql = "SELECT id, email, password_hash, full_name, role, is_active
              FROM app_user
              WHERE lower(email)=lower(:e) LIMIT 1";
      $st = pdo()->prepare($sql);
      $st->execute([':e' => $email]);
      $u = $st->fetch();

      // Einheitliche Meldung fuer jeden Fehlerfall: unterschiedliche Texte wuerden
      // verraten, welche E-Mail-Adressen im Verein registriert sind.
      if (!$u) {
        dummy_password_verify($pass);
        login_record_failure($email);
        flash('E-Mail oder Passwort falsch.', 'error');
      } else {
        // Passwort immer pruefen, auch bei deaktiviertem Konto - sonst verraet
        // die Antwortzeit den Unterschied.
        $pass_ok = password_verify($pass, $u['password_hash']);
        if (!$pass_ok || !$u['is_active']) {
          login_record_failure($email);
          flash('E-Mail oder Passwort falsch.', 'error');
        } else {
          login_clear_failures($email);
          // Session-Fixation verhindern: nach erfolgreichem Login neue Session-ID vergeben
          session_regenerate_id(true);
          $_SESSION['user'] = ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['full_name'], 'role' => $u['role']];
          header('Location: index.php');
          exit;
        }
      }
    }
  }
  echo html_head('Login');
  echo '<main class="max-w-sm mx-auto p-4 space-y-4">';
  echo '<h1 class="text-2xl font-semibold">Login</h1>';
  render_flash();
  echo '<form method="post" class="space-y-3">';
  echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
  echo '<label class="block text-sm">E-Mail<input class="mt-1 w-full rounded-xl border px-4 py-3" type="email" name="email" required></label>';
  echo '<label class="block text-sm">Passwort<input class="mt-1 w-full rounded-xl border px-4 py-3" type="password" name="password" required></label>';
  echo '<button class="w-full rounded-xl bg-gray-900 text-white py-3">Einloggen</button>';
  echo '<div class="text-center"><a class="text-sm text-gray-600 underline" href="admin.php?action=login">Zum Admin-Login</a></div>';
  echo '</form></main>';
  echo html_tail();
  exit;
}
if ($action === 'logout') {
  destroy_session();
  header('Location: index.php');
  exit;
}

/* =======================
   Mini-API: freie Plätze (AJAX)
   ======================= */
if (($_GET['action'] ?? '') === 'free_courts') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $tz = new DateTimeZone('Europe/Berlin');
    $date     = $_GET['date']     ?? '';
    $start    = $_GET['start']    ?? '';
    $duration = isset($_GET['duration']) ? (int)$_GET['duration'] : 0;

    if (!$date || !$start || $duration <= 0) {
      echo json_encode(['ok' => false, 'error' => 'missing_params']);
      exit;
    }
    $s = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $start, $tz);
    if (!$s) {
      echo json_encode(['ok' => false, 'error' => 'invalid_times']);
      exit;
    }
    $e = (clone $s)->modify('+' . $duration . ' minutes');
    if ($e <= $s) {
      echo json_encode(['ok' => false, 'error' => 'invalid_times']);
      exit;
    }

    $st = pdo()->prepare("SELECT id, name FROM free_courts(:s::timestamptz,:e::timestamptz)");
    $st->execute([':s' => $s->format('c'), ':e' => $e->format('c')]);
    echo json_encode(['ok' => true, 'items' => $st->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
  } catch (Throwable $ex) {
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
  }
}

/* =======================
   Mini-API: Serien-Preview (frei je Platz über gesamten Zeitraum)
   ======================= */
if (($_GET['action'] ?? '') === 'series_preview') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $tz = new DateTimeZone('Europe/Berlin');
    $weekday     = isset($_GET['weekday']) ? (int)$_GET['weekday'] : null; // DB: 0=So…6=Sa
    $start_time  = $_GET['start_time'] ?? '';
    $durationMin = isset($_GET['duration']) ? (int)$_GET['duration'] : 0;
    $start_date  = $_GET['start_date'] ?? '';
    $end_date    = $_GET['end_date']   ?? '';

    if ($weekday === null || $start_time === '' || $durationMin <= 0 || $start_date === '' || $end_date === '') {
      echo json_encode(['ok' => false, 'error' => 'missing_params']);
      exit;
    }

    // Zeiten prüfen: wir bilden eine Probe mit start_time + duration
    $tmp  = DateTime::createFromFormat('Y-m-d H:i', $start_date . ' ' . $start_time, $tz);
    if (!$tmp) {
      echo json_encode(['ok' => false, 'error' => 'invalid_times']);
      exit;
    }
    $tmp2 = (clone $tmp)->modify('+' . $durationMin . ' minutes');
    if ($tmp2 <= $tmp) {
      echo json_encode(['ok' => false, 'error' => 'invalid_times']);
      exit;
    }

    $sql = <<<SQL
WITH params AS (
  SELECT :weekday::int  AS wd,
         :start_date::date AS sd,
         :end_date::date   AS ed,
         :start_time::time AS st,
         :duration_min::int AS dur
),
first_day AS (
  SELECT CASE
           WHEN EXTRACT(dow FROM sd)::int = wd THEN sd
           ELSE sd + (((wd - EXTRACT(dow FROM sd)::int + 7) % 7)) * INTERVAL '1 day'
         END AS first
  FROM params
),
occ AS (
  -- alle Vorkommen als tstzrange [start,end) in Europe/Berlin
  SELECT
    g::date AS occur_date,
    tstzrange(
      ((g::date + (SELECT st FROM params))::timestamp AT TIME ZONE 'Europe/Berlin'),
      (((g::date + (SELECT st FROM params))::timestamp AT TIME ZONE 'Europe/Berlin') + make_interval(mins := (SELECT dur FROM params))) ,
      '[)'
    ) AS rng
  FROM first_day f
  JOIN params p ON true
  JOIN LATERAL generate_series(f.first::timestamp, p.ed::timestamp, INTERVAL '7 day') AS g ON g::date <= p.ed
),
tot AS (SELECT COUNT(*) AS total FROM occ)
SELECT
  c.id,
  c.name,
  (SELECT total FROM tot) AS total,
  COALESCE(COUNT(b.id) FILTER (WHERE b.id IS NOT NULL),0) AS conflicts,
  COALESCE(
    json_agg(
      to_char((lower(o.rng) AT TIME ZONE 'Europe/Berlin'),'YYYY-MM-DD"T"HH24:MI')
    ) FILTER (WHERE b.id IS NOT NULL),
    '[]'::json
  ) AS conflict_starts
FROM court c
LEFT JOIN occ o ON TRUE
LEFT JOIN booking b
  ON b.court_id = c.id
 AND b.status   = 'active'
 AND o.rng IS NOT NULL
 AND b.time_span && o.rng
WHERE c.is_active
GROUP BY c.id, c.name
ORDER BY conflicts ASC, c.name ASC;
SQL;

    $st = pdo()->prepare($sql);
    $st->execute([
      ':weekday'     => $weekday,
      ':start_date'  => $start_date,
      ':end_date'    => $end_date,
      ':start_time'  => $start_time,
      ':duration_min' => $durationMin,
    ]);

    $rows = $st->fetchAll();
    echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
  }
}

/* =======================
   View params
   ======================= */
$tz     = new DateTimeZone('Europe/Berlin');
$today  = (new DateTime('today', $tz))->format('Y-m-d');
$view   = 'week';
$date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
$selected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['selected'] ?? '') ? $_GET['selected'] : $today;

/* =======================
   POST Actions
   ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (!is_logged_in()) {
    http_response_code(403);
    exit('Bitte einloggen.');
  }

  $op = $_POST['op'] ?? '';
  try {
    if ($op === 'create_booking') {
      prune_old_if_due(14);

      $court_id   = $_POST['court_id'] ?? '';
      $title      = trim($_POST['title'] ?? '');
      $bookDate   = $_POST['book_date'] ?? '';
      $start_time = $_POST['start_time'] ?? '';
      $duration   = isset($_POST['duration_min']) ? (int)$_POST['duration_min'] : 0;

      if (!$bookDate || !$start_time || $duration <= 0) throw new Exception('Bitte Datum, Startzeit und Dauer angeben.');

      $tzObj   = new DateTimeZone('Europe/Berlin');
      $startDt = DateTime::createFromFormat('Y-m-d H:i', $bookDate . ' ' . $start_time, $tzObj);
      if (!$startDt) throw new Exception('Ungültige Zeitangaben.');

      // 30-Minuten-Raster erzwingen
      if (((int)$startDt->format('i')) % 30 !== 0) {
        throw new Exception('Startzeit muss auf :00 oder :30 liegen.');
      }
      if ($duration <= 0 || $duration % 30 !== 0) {
        throw new Exception('Dauer muss Vielfaches von 30 Minuten sein.');
      }

      // Rollen-Restriktion
      if (!is_admin() && !in_array($duration, [30, 60], true)) {
        throw new Exception('Nur 30 oder 60 Minuten erlaubt.');
      }

      // Buchungen in der Vergangenheit sperren. Admins duerfen nachtragen -
      // fuer sie ist der Kalender auch ein Verwaltungswerkzeug.
      if (!is_admin() && $startDt < new DateTime('now', $tzObj)) {
        throw new Exception('Buchungen in der Vergangenheit sind nicht moeglich.');
      }

      $endDt = (clone $startDt)->modify('+' . $duration . ' minutes');

      $p_user = current_user()['id'];
      $sql = "SELECT * FROM create_booking(:u,:c,:s::timestamptz,:e::timestamptz,'single',NULL,:t)";
      pdo()->prepare($sql)->execute([
        ':u' => $p_user,
        ':c' => $court_id,
        ':s' => $startDt->format('c'),
        ':e' => $endDt->format('c'),
        ':t' => $title
      ]);
      flash('Buchung erstellt.');
      header('Location: ' . redirect_to_day($startDt->format('c')));
      exit;
    }

    if ($op === 'cancel_booking') {
      $booking_id = $_POST['id'] ?? '';
      $q = "SELECT b.id,b.user_id FROM booking b WHERE b.id::text=:id";
      $s = pdo()->prepare($q);
      $s->execute([':id' => $booking_id]);
      $b = $s->fetch();
      if (!$b) throw new Exception('Buchung nicht gefunden.');
      if (!is_admin() && $b['user_id'] !== current_user()['id']) throw new Exception('Keine Berechtigung.');
      pdo()->prepare("UPDATE booking SET status='canceled' WHERE id::text=:id")->execute([':id' => $booking_id]);
      flash('Buchung storniert.');
      header('Location: index.php?view=week&date=' . $date . ($selected ? '&selected=' . $selected : ''));
      exit;
    }

    if ($op === 'cancel_series') {
      $series_id = $_POST['series_id'] ?? '';
      $q = "SELECT user_id FROM recurring_series WHERE id::text=:id";
      $s = pdo()->prepare($q);
      $s->execute([':id' => $series_id]);
      $row = $s->fetch();
      if (!$row) throw new Exception('Serie nicht gefunden.');
      if (!is_admin() && $row['user_id'] !== current_user()['id']) throw new Exception('Keine Berechtigung.');
      pdo()->prepare("UPDATE recurring_series SET is_active=FALSE WHERE id::text=:id")->execute([':id' => $series_id]);
      pdo()->prepare("UPDATE booking SET status='canceled' WHERE series_id::text=:id")->execute([':id' => $series_id]);
      flash('Serie und zugehörige Termine wurden storniert.');
      header('Location: index.php?view=week&date=' . $date . '&selected=' . $selected);
      exit;
    }

    if ($op === 'create_series') {
      // Serien sind Admins vorbehalten. Das Formular wird zwar nur Admins angezeigt,
      // der POST-Handler muss die Rolle aber selbst pruefen - sonst kann jedes
      // eingeloggte Mitglied per Handrequest einen Platz dauerhaft blockieren.
      if (!is_admin()) throw new Exception('Nur Administratoren duerfen Serien anlegen.');

      prune_old_if_due(14);

      $court_id   = $_POST['court_id'] ?? '';
      $title      = trim($_POST['title'] ?? '');
      $weekday    = (int)($_POST['weekday'] ?? 1); // DB: 0=So … 6=Sa
      $start_time = $_POST['start_time'] ?? '';
      $duration   = isset($_POST['duration_min']) ? (int)$_POST['duration_min'] : 0;
      $start_date = $_POST['start_date'] ?? $today;
      $end_date   = $_POST['end_date']   ?? '';
      $tzname     = 'Europe/Berlin';

      if (!$court_id) throw new Exception('Bitte einen Platz wählen.');
      if (!$start_time) throw new Exception('Bitte eine Startzeit angeben.');
      if ($duration <= 0) throw new Exception('Bitte eine Dauer angeben.');

      $tz = new DateTimeZone($tzname);
      $tmpDate = $start_date ?: $today;
      $s = DateTime::createFromFormat('Y-m-d H:i', $tmpDate . ' ' . $start_time, $tz);
      if (!$s) throw new Exception('Ungültige Zeitangaben.');
      if (((int)$s->format('i')) % 30 !== 0) throw new Exception('Startzeit muss auf :00 oder :30 liegen.');
      if ($duration <= 0 || $duration % 30 !== 0) throw new Exception('Dauer muss Vielfaches von 30 Minuten sein.');
      if (!is_admin() && !in_array($duration, [30, 60], true)) throw new Exception('Nur 30 oder 60 Minuten erlaubt.');
      $e = (clone $s)->modify('+' . $duration . ' minutes');
      if (!$s || !$e) throw new Exception('Ungültige Zeitangaben.');
      if ($e <= $s)   throw new Exception('Ende muss nach Start liegen.');
      $duration = (int) round(($e->getTimestamp() - $s->getTimestamp()) / 60);

      if ($duration <= 0 || $duration % 30 !== 0) throw new Exception('Dauer muss Vielfaches von 30 Minuten sein.');
      if (((int)$s->format('i')) % 30 !== 0 || ((int)$e->format('i')) % 30 !== 0) {
        throw new Exception('Start/Ende müssen auf :00 oder :30 liegen.');
      }

      // --- STRIKTE KONFLIKTPRÜFUNG VOR dem Anlegen der Serie ---
      $checkSql = "
    WITH params AS (
      SELECT :weekday::int  AS wd,
             :start_date::date AS sd,
             :end_date::date   AS ed,
             :start_time::time AS st,
             :duration_min::int AS dur
    ),
    first_day AS (
      SELECT CASE
               WHEN EXTRACT(dow FROM sd)::int = wd THEN sd
               ELSE sd + (((wd - EXTRACT(dow FROM sd)::int + 7) % 7)) * INTERVAL '1 day'
             END AS first
      FROM params
    ),
    occ AS (
      SELECT
        g::date AS occur_date,
        tstzrange(
          ((g::date + (SELECT st FROM params))::timestamp AT TIME ZONE 'Europe/Berlin'),
          (((g::date + (SELECT st FROM params))::timestamp AT TIME ZONE 'Europe/Berlin') + make_interval(mins := (SELECT dur FROM params))),
          '[)'
        ) AS rng
      FROM first_day f
      JOIN params p ON true
      JOIN LATERAL generate_series(f.first::timestamp, p.ed::timestamp, INTERVAL '7 day') AS g ON g::date <= p.ed
    )
    SELECT COALESCE(COUNT(b.id),0) AS conflicts
    FROM occ o
    JOIN booking b
      ON b.court_id = :court_id
     AND b.status   = 'active'
     AND b.time_span && o.rng
  ";
      $chk = pdo()->prepare($checkSql);
      $chk->execute([
        ':weekday'     => $weekday,
        ':start_date'  => $start_date,
        ':end_date'    => $end_date,
        ':start_time'  => $start_time,
        ':duration_min' => $duration,
        ':court_id'    => $court_id,
      ]);
      $conflicts = (int)$chk->fetchColumn();
      if ($conflicts > 0) {
        throw new Exception('Der gewählte Platz ist im Zeitraum nicht durchgängig frei. Bitte wähle einen anderen Platz oder Zeitraum.');
      }

      // --- Serie anlegen (jetzt konfliktfrei) ---
      $sql = "INSERT INTO recurring_series
          (user_id,court_id,title,weekday,start_time,duration_min,timezone,start_date,end_date,is_active)
          VALUES (:u,:c,:title,:w,:t,:d,:tz,:sd,:ed,TRUE)
          RETURNING id";
      $st = pdo()->prepare($sql);
      $st->execute([
        ':u' => current_user()['id'],
        ':c' => $court_id,
        ':title' => $title,
        ':w' => $weekday,
        ':t' => $start_time,
        ':d' => $duration,
        ':tz' => $tzname,
        ':sd' => $start_date,
        ':ed' => ($end_date ?: null)
      ]);
      $series_id = $st->fetchColumn();

      // Termine materialisieren
      $mat = materialize_series($series_id, $start_date, $end_date ?: (new DateTime($start_date))->modify('+6 months')->format('Y-m-d'));

      if ($mat['skipped'] > 0) {
        flash("Serie angelegt, aber unvollstaendig: {$mat['created']} Termine erstellt, {$mat['skipped']} konnten nicht angelegt werden. Bitte die betroffenen Tage pruefen.", 'error');
      } else {
        flash("Serie angelegt: {$mat['created']} Termine erstellt.");
      }
      header('Location: index.php?view=week&date=' . $start_date . '&selected=' . $start_date);
      exit;
    }
  } catch (PDOException $e) {
    $msg = $e->getMessage();

    // Versuche: "Konflikt: Zeitraum <start> – <end> auf Platz <uuid> belegt"
    if (preg_match('/Konflikt:\s*Zeitraum\s+(.+?)\s+–\s+(.+?)\s+auf\s+Platz\s+([0-9a-f\-]{36})/i', $msg, $m)) {
      $startIso  = trim($m[1]);
      $endIso    = trim($m[2]);
      $courtId   = trim($m[3]);

      // Platznamen auflösen (falls möglich)
      $courtName = $courtId;
      try {
        $st = pdo()->prepare("SELECT name FROM court WHERE id::text = :id LIMIT 1");
        $st->execute([':id' => $courtId]);
        $name = $st->fetchColumn();
        if ($name) $courtName = $name;
      } catch (Throwable $ignore) {
      }

      // Datum/Zeit benutzerfreundlich formatieren
      try {
        $tzLocal = new DateTimeZone('Europe/Berlin');
        $stDt = (new DateTime($startIso))->setTimezone($tzLocal);
        $enDt = (new DateTime($endIso))->setTimezone($tzLocal);
        $dateLabel = $stDt->format('d.m.Y');
        $timeLabel = $stDt->format('H:i') . '–' . $enDt->format('H:i');

        flash("Konflikt: Auf Platz {$courtName} ist am {$dateLabel} von {$timeLabel} bereits eine Buchung vorhanden.", 'error');
      } catch (Throwable $ignore) {
        // Fallback, falls das Format mal anders ist
        flash("Konflikt: Der gewünschte Zeitraum ist auf Platz {$courtName} bereits belegt.", 'error');
      }
    } else {
      // Generische (kurze) DB-Fehlermeldung
      flash('Datenbankfehler bei der Buchung. Bitte erneut versuchen oder später noch einmal probieren.', 'error');
    }
  } catch (Exception $e) {
    flash($e->getMessage(), 'error');
  }
}

/* =======================
   Daten laden
   ======================= */
$courts = pdo()->query("SELECT id, name, is_active FROM court ORDER BY name")->fetchAll();

/* Woche (Mo–So) relativ zum gewählten Datum */
$weekStart = (new DateTime($date, $tz))->modify('monday this week')->setTime(0, 0, 0);
$weekEnd   = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

/* Sicherstellen: selected liegt in der aktuellen Woche; sonst Montag der Woche wählen */
if ($selected) {
  $sel = DateTime::createFromFormat('Y-m-d', $selected, $tz);
  if (!$sel) {
    $selected = $weekStart->format('Y-m-d');
  } else {
    // Grenzen inkl./exkl. passend zu [Mo 00:00:00, So 23:59:59]
    if ($sel < $weekStart || $sel > $weekEnd) {
      $selected = $weekStart->format('Y-m-d'); // Montag dieser Woche
    }
  }
}

$wParams = [':s' => $weekStart->format('c'), ':e' => $weekEnd->format('c')];
$wSql = "SELECT b.id,b.user_id,b.court_id,b.status,b.source,b.series_id,b.title,
                lower(b.time_span) AS start_at, upper(b.time_span) AS end_at,
                u.full_name, c.name AS court_name
         FROM booking b
         JOIN app_user u ON u.id=b.user_id
         JOIN court c ON c.id=b.court_id
         WHERE b.status='active' AND b.time_span && tstzrange(:s::timestamptz,:e::timestamptz,'[)')";
$wSql .= " ORDER BY start_at";
$wBookings = pdo()->prepare($wSql);
$wBookings->execute($wParams);
$wBookings = $wBookings->fetchAll();

/* Map Buchungen nach Tag */
$weekMap = [];
foreach ($wBookings as $b) {
  $dkey = (new DateTime($b['start_at']))->setTimezone($tz)->format('Y-m-d');
  $weekMap[$dkey][] = $b;
}

/* Tagesdaten laden (nur wenn ausgewählt) */
$dayBookings = [];
if ($selected) {
  $dayStart = new DateTime($selected . ' 00:00:00', $tz);
  $dayEnd   = (clone $dayStart)->modify('+1 day');
  $params = [':s' => $dayStart->format('c'), ':e' => $dayEnd->format('c')];
  $sqlBookings = "SELECT b.id,b.user_id,b.court_id,b.status,b.source,b.series_id,b.title,
                         lower(b.time_span) AS start_at, upper(b.time_span) AS end_at,
                         u.full_name, u.email, c.name AS court_name
                  FROM booking b
                  JOIN app_user u ON u.id=b.user_id
                  JOIN court c ON c.id=b.court_id
                  WHERE b.status='active' AND b.time_span && tstzrange(:s::timestamptz,:e::timestamptz,'[)')";
  $sqlBookings .= " ORDER BY c.name, start_at";
  $stDay = pdo()->prepare($sqlBookings);
  $stDay->execute($params);
  $dayBookings = $stDay->fetchAll();
}

/* =======================
   Render
   ======================= */
echo html_head('Tennisbuchung – Woche');
echo topbar();
echo '<main class="max-w-lg mx-auto p-4 space-y-4 pt-3">';

render_flash();

/* =======================
   Wochenansicht
   ======================= */
$weekLabel = $weekStart->format('d.m.') . ' – ' . $weekEnd->format('d.m.Y');

echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
echo '<header class="flex items-center justify-between">';
echo '<h2 class="text-lg font-semibold">' . h($weekLabel) . '</h2>';
$prevW = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
$nextW = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
echo '<div class="flex items-center gap-2">';
echo '<a class="px-3 py-2 rounded-xl bg-gray-100" href="?view=week&date=' . $prevW . '"><i data-lucide="arrow-big-left" class="w-5 h-5"></i></a>';
// Kalender-Button mit nativem Datepicker (sichtbar genug für Safari + showPicker)
echo '
<div class="relative">
  <input type="date"
         id="weekDateInput"
         value="' . h($date) . '"
         onchange="goWeek(this.value)"
         tabindex="-1" aria-hidden="true"
         class="absolute inset-0 w-full h-full cursor-pointer opacity-[0.001] z-20"
         style="-webkit-appearance:none;appearance:none;">
  <button type="button"
          class="relative z-10 p-2 rounded-xl bg-indigo-600 text-white flex items-center justify-center w-10 h-10"
          aria-label="Woche wählen"
          onclick="openWeekPicker()">
    <i data-lucide="calendar" class="w-5 h-5"></i>
  </button>
</div>';
echo '<a class="px-3 py-2 rounded-xl bg-gray-100" href="?view=week&date=' . $nextW . '"><i data-lucide="arrow-big-right" class="w-5 h-5"></i></a>';
echo '</div></header>';

/* Wochentags-Köpfe */
$wdNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
echo '<div class="grid grid-cols-7 gap-3 text-center">';
foreach ($wdNames as $w) echo '<div class="text-xs text-gray-500">' . $w . '</div>';
echo '</div>';

/* Tage */
echo '<div class="grid grid-cols-7 gap-2 text-center">';
for ($d = clone $weekStart; $d <= $weekEnd; $d->modify('+1 day')) {
  $dStr = $d->format('Y-m-d');
  $isToday = $d->format('Y-m-d') === (new DateTime('today', $tz))->format('Y-m-d');
  $hasBooking = !empty($weekMap[$dStr]);
  $base  = 'h-10 w-10 mx-auto rounded-full flex items-center justify-center text-sm font-semibold transition';
  $color = $hasBooking ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700';
  $ring  = ($selected === $dStr) ? ' ring-2 ring-indigo-600' : ($isToday ? ' ring-2 ring-indigo-400' : '');
  echo '<div class="flex flex-col items-center gap-1">';
  echo '<button type="button" onclick="selectDay(\'' . $dStr . '\')" class="' . $base . ' ' . $color . $ring . '">' . h($d->format('j')) . '</button>';
  echo $hasBooking ? '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>' : '<span class="w-1.5 h-1.5 rounded-full bg-transparent"></span>';
  echo '</div>';
}
echo '</div>';
echo '</section>';

/* =======================
   Tagesansicht
   ======================= */
if ($selected) {
  echo '<section class="bg-white rounded-2xl shadow p-3 space-y-3">';
  echo '<header class="flex items-center justify-between">';
  $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  $dt = new DateTime($selected, $tz);
  $dayName = $days[(int)$dt->format('w')];
  echo '<h3 class="text-lg font-semibold">' . h($dayName . ', ' . $dt->format('d.m.Y')) . '</h3>';
  echo '<div class="flex gap-2">';
  $prev = (new DateTime($selected, $tz))->modify('-1 day')->format('Y-m-d');
  $next = (new DateTime($selected, $tz))->modify('+1 day')->format('Y-m-d');
  echo '<a class="px-3 py-2 rounded-xl bg-gray-100" href="?view=week&date=' . $prev . '&selected=' . $prev . '"><i data-lucide="arrow-left" class="w-5 h-5"></i></a>';
  $tiso = (new DateTime('today', $tz))->format('Y-m-d');
  echo '<a class="px-3 py-2 rounded-xl bg-gray-100" href="?view=week&date=' . $tiso . '&selected=' . $tiso . '" aria-label="Heute" title="Heute"><i data-lucide="pin" class="w-5 h-5"></i></a>';
  echo '<a class="px-3 py-2 rounded-xl bg-gray-100" href="?view=week&date=' . $next . '&selected=' . $next . '"><i data-lucide="arrow-right" class="w-5 h-5"></i></a>';
  echo '</div></header>';

  if (!$dayBookings) {
    echo '<p class="text-gray-500">Keine Buchungen.</p>';
  } else {
    // Nach Platz gruppieren (sortiert per SQL: ORDER BY c.name, start_at)
    $byCourt = [];
    foreach ($dayBookings as $r) {
      $byCourt[$r['court_name']][] = $r;
    }

    foreach ($byCourt as $courtName => $items) {
      // Platz-Badge (volle Breite, niedrig)
      echo '<div class="w-full rounded-md bg-indigo-100 text-indigo-800 text-sm font-semibold py-1 px-3 mb-1 text-center">'
        . h($courtName)
        . '</div>';

      $prevEndDt = null; // vorheriges Ende je Court (Europe/Berlin)
      foreach ($items as $r) {
        $owner   = ($r['user_id'] === (current_user()['id'] ?? null));
        $canEdit = is_admin() || $owner;

        $startDt = (new DateTime($r['start_at']))->setTimezone($tz);
        $endDt   = (new DateTime($r['end_at']))->setTimezone($tz);

        // --- dezente Lücke zwischen Terminen desselben Courts ---
        if ($prevEndDt !== null) {
          $gapMin = (int) round(($startDt->getTimestamp() - $prevEndDt->getTimestamp()) / 60);
          if ($gapMin > 0) {
            $gapLabel = h(format_duration_short($gapMin)) . ' frei';
            echo '
      <div class="my-1 flex items-center justify-center text-[11px] text-gray-400 select-none" aria-hidden="true">
        <span class="h-px w-8 bg-gray-200"></span>
        <span class="mx-2">' . $gapLabel . '</span>
        <span class="h-px w-8 bg-gray-200"></span>
      </div>';
          }
        }
        $prevEndDt = $endDt;

        // --- bisherige Karten-Ausgabe ---
        // Der Kalender ist oeffentlich einsehbar - ohne Login nur Initialen zeigen.
        $who   = is_logged_in() ? $r['full_name'] : anonymize_name($r['full_name']);
        $sTime = $startDt->format('H:i');
        $eTime = $endDt->format('H:i');

        echo '<article class="rounded-xl border border-gray-200 p-3 flex items-start gap-3 mb-2">';
        echo '  <div class="flex-1">';
        echo '    <div class="text-sm text-gray-500">' . (!empty($r['series_id']) ? h($r['source']) : '') . '</div>';
        echo '    <div class="text-base font-semibold">' . h($r['title'] ?: '—') . '</div>';
        echo '    <div class="text-sm">' . h($sTime) . '–' . h($eTime) . ' • ' . h($who) . '</div>';
        echo '  </div>';
        echo '  <div class="pt-1">';
        if ($canEdit) {
          echo '    <form method="post" class="inline">';
          echo '      <input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
          echo '      <input type="hidden" name="op" value="cancel_booking">';
          echo '      <input type="hidden" name="id" value="' . h($r['id']) . '">';
          if (!empty($r['series_id'])) echo '      <input type="hidden" name="series_id" value="' . h($r['series_id']) . '">';
          echo '      <button class="text-red-600 text-sm" type="button" onclick="openCancelDialog(this.form)">Stornieren</button>';
          echo '    </form>';
        }
        echo '  </div>';
        echo '</article>';
      }
    }
  }

  echo '</section>';
}

/* =======================
   Formulare (nur eingeloggt)
   ======================= */
if (is_logged_in()) {
  // Neue Buchung
  echo '<details class="bg-white rounded-2xl shadow p-3"><summary class="text-base font-semibold py-1 cursor-pointer">Neue Buchung</summary>';
  echo '<form method="post" class="mt-2 space-y-4">';
  echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
  echo '<input type="hidden" name="op" value="create_booking">';

  echo '<label class="block text-sm">Titel';
  echo '  <input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="title" placeholder="z. B. Training">';
  echo '</label>';

  // Datum
  $prefDate = $selected ?: $today;
  // Ohne Adminrechte nicht in die Vergangenheit: Vorauswahl anheben und Feld begrenzen.
  // ISO-Datumsstrings lassen sich direkt lexikografisch vergleichen.
  $minAttr = '';
  if (!is_admin()) {
    if ($prefDate < $today) $prefDate = $today;
    $minAttr = ' min="' . h($today) . '"';
  }
  echo '<label class="block text-sm">Datum';
  echo '  <input class="mt-1 dt-compact rounded-xl border w-full max-w-[300px] mx-auto block" type="date" name="book_date" value="' . h($prefDate) . '"' . $minAttr . ' appearance-none required>';
  echo '</label>';

  // Start/Ende (30 min Raster)
  $now = new DateTime('now', $tz);
  $m = (int)$now->format('i');
  $add = ($m % 30 === 0 ? 0 : (30 - $m % 30));
  $now->modify("+{$add} minutes");
  $defStart = $now->format('H:i');

  echo '<div class="grid grid-cols-2 gap-3 items-center">';
  echo '  <label class="block text-sm">Start';
  echo        render_time_select('start_time', $defStart, false, 'start_time_single');
  echo '  </label>';
  echo '  <div class="block text-sm">';
  echo '    <div class="mb-1">Dauer</div>';
  $defaultDur = 60;
  echo        render_duration_input('duration_min', $defaultDur, is_admin(), 'duration_min_single');
  echo '  </div>';
  echo '</div>';

  // Platz – wird dynamisch aus der API befüllt
  $activeCourts = array_values(array_filter($courts, fn($c) => $c['is_active']));
  echo '<div class="space-y-2">';
  echo '  <div class="text-sm">Platz</div>';
  echo '  <div id="freeCourtsWrap" class="space-y-2">';
  echo '    <p id="freeCourtsStatus" class="text-sm text-gray-500">Wähle Datum & Zeit, um freie Plätze zu laden.</p>';
  echo '    <div id="freeCourts" class="flex justify-center flex-wrap gap-2" role="radiogroup"></div>';
  echo '  </div>';
  echo '</div>';

  echo '<button id="bookBtn" type="submit" class="w-full rounded-xl bg-indigo-600 text-white py-3 mt-1 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Buchen</button>';
  echo '</form></details>';

  // Neue Serie
  if (is_admin()) {
    $seriesStartDefault = $today;
    $seriesEndDefault   = (new DateTime($seriesStartDefault))->format('Y') . '-12-31';

    echo '<details class="bg-white rounded-2xl shadow p-3"><summary class="text-base font-semibold py-1 cursor-pointer">Neue Serie</summary>';
    echo '<form method="post" class="mt-2 space-y-4">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<input type="hidden" name="op" value="create_series">';

    echo '<label class="block text-sm">Titel';
    echo '  <input class="mt-1 w-full rounded-xl border px-4 py-3" type="text" name="title" placeholder="z. B. Mannschaftstraining">';
    echo '</label>';

    // Wochentag (Anzeige ab Mo; Werte DB: 1,2,3,4,5,6,0)
    $weekdays = [['Mo', 1], ['Di', 2], ['Mi', 3], ['Do', 4], ['Fr', 5], ['Sa', 6], ['So', 0]];
    echo '<label class="block text-sm">Wochentag';
    echo '  <select class="mt-1 w-full max-w-[300px] mx-auto block rounded-xl border px-4 py-3" name="weekday">';
    foreach ($weekdays as [$label, $val]) echo '<option value="' . $val . '">' . $label . '</option>';
    echo '  </select>';
    echo '</label>';

    // Start/Ende (30 min Raster)
    $seriesStartVal = '17:00';
    echo '<div class="grid grid-cols-2 gap-3 items-center">';
    echo '  <label class="block text-sm">Startzeit';
    echo        render_time_select('start_time', $seriesStartVal, false, 'start_time_series');
    echo '  </label>';
    echo '  <div class="block text-sm">';
    echo '    <div class="mb-1">Dauer</div>';
    $seriesDurDefault = 120;
    echo        render_duration_input('duration_min', $seriesDurDefault, is_admin(), 'duration_min_series');
    echo '  </div>';
    echo '</div>';

    // Beginn/Ende
    echo '<label class="block text-sm">Beginn';
    echo '  <input id="seriesStart" class="mt-1 dt-compact rounded-xl border w-full max-w-[300px] mx-auto block" type="date" name="start_date" value="' . h($seriesStartDefault) . '" appearance-none required>';
    echo '</label>';
    echo '<label class="block text-sm">Ende';
    echo '  <input id="seriesEnd" class="mt-1 dt-compact rounded-xl border w-full max-w-[300px] mx-auto block" type="date" name="end_date" value="' . h($seriesEndDefault) . '" appearance-none required>';
    echo '</label>';

    // Platz – wird durch Serien-Preview befüllt
    echo '<div class="space-y-2">';
    echo '  <div class="text-sm">Platz</div>';
    echo '  <div id="seriesCourtsWrap" class="space-y-2">';
    echo '    <p id="seriesCourtsStatus" class="text-sm text-gray-500">Wähle Wochentag, Zeit & Zeitraum, um freie Plätze zu prüfen.</p>';
    echo '    <div id="seriesCourts" class="flex justify-center flex-wrap gap-2" role="radiogroup"></div>';
    echo '  </div>';
    echo '</div>';

    echo '<button class="w-full rounded-2xl bg-indigo-600 text-white py-3">Serie anlegen</button>';
    echo '</form></details>';
  }
}

echo '</main>';
echo page_footer();
echo cancel_dialog_markup();
echo scripts_block();
echo html_tail();

/* =======================
   UI Chrome (mobile)
   ======================= */
function topbar(): string
{
  // dezenter Login/Logout rechts – stört die Zentrierung nicht (Grid-Layout)
  $auth = is_logged_in()
    ? '<a href="?action=logout" class="btn-login h-9 px-3 rounded-lg inline-flex items-center justify-center text-sm"
         title="Logout" aria-label="Logout">Logout</a>'
    : '<a href="?action=login"  class="btn-login h-9 px-3 rounded-lg inline-flex items-center justify-center text-sm"
         title="Login"  aria-label="Login">Login</a>';

  return '
<header class="sticky top-0 z-40 bg-gray-50/95 backdrop-blur border-b border-black/5">
  <div class="max-w-lg mx-auto px-4 py-3">
    <div class="grid grid-cols-3 items-center">
      <div><!-- leer, um Mitte wirklich mittig zu halten --></div>
      <div class="text-center leading-tight select-none whitespace-nowrap">
        <div class="text-gradient text-2xl font-bold whitespace-nowrap">FCF Tennis</div>
        <div class="text-xs text-gray-500 whitespace-nowrap">Court Booking</div>
      </div>
      <div class="flex justify-end">' . $auth . '</div>
    </div>
  </div>
</header>';
}

function page_footer(): string
{
  return '
<footer class="max-w-lg mx-auto px-4 py-6 text-center text-xs text-gray-500">
  <a href="admin.php" class="inline-flex items-center gap-1 underline">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M4 6a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z"/></svg>
    Admin-Panel
  </a>
</footer>';
}

function cancel_dialog_markup(): string
{
  return '
<!-- Cancel Dialog -->
<div id="cancelDialog" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" onclick="closeCancelDialog()"></div>
  <div class="absolute inset-x-4 bottom-6 bg-white rounded-2xl shadow-xl p-4 space-y-3 max-w-lg mx-auto">
    <div class="text-base font-semibold">Was möchtest du stornieren?</div>
    <div class="text-sm text-gray-600">Wähle, ob nur dieser Termin oder die gesamte Serie gelöscht werden soll.</div>
    <div class="grid gap-2">
      <button type="button" class="w-full rounded-xl bg-red-600 text-white py-3" onclick="cancelDialogDeleteSingle()">Termin löschen</button>
      <button type="button" id="btnDeleteSeries" class="w-full rounded-xl bg-red-700 text-white py-3" onclick="cancelDialogDeleteSeries()">Serie löschen</button>
      <button type="button" class="w-full rounded-xl bg-gray-100 text-gray-800 py-3" onclick="closeCancelDialog()">Abbrechen</button>
    </div>
  </div>
</div>';
}

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
  <script src="assets/tailwind-3.4.16.js"></script>
  <!-- Lucide Icons -->
  <script src="assets/lucide-0.469.0.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    });
  </script>
  <style>
    /* kompakte mobile Date-/Time-Inputs */
    .dt-compact{height:42px;padding:0 10px;font-size:14px;line-height:1.2}
    .dt-compact::-webkit-datetime-edit,.dt-compact::-webkit-date-and-time-value{padding:0}
    .dt-compact::-webkit-calendar-picker-indicator{margin:0 2px}
    /* Gradient-Schrift für die Wortmarke */
    .text-gradient{
      background-image: linear-gradient(90deg,#6366f1 0%, #22c55e 40%, #06b6d4 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    /* Dezenter Login-Button */
    .btn-login{
      backdrop-filter: blur(6px);
      background: rgba(255,255,255,.6);
      border: 1px solid rgba(17,24,39,.06); /* gray-900/6 */
      color: rgba(17,24,39,.7);
      transition: background .15s ease, border-color .15s ease, color .15s ease;
    }
    .btn-login:hover{
      background: rgba(255,255,255,.9);
      border-color: rgba(17,24,39,.12);
      color: rgba(17,24,39,.85);
    }
  </style>
</head>
<body class="bg-gray-50 text-gray-900">
HTML;
}

function html_tail(): string
{
  return "</body></html>";
}

/** Materialisierung Serie (robust bzgl. TIME-Format) */
/**
 * Legt die Einzeltermine einer Serie an.
 *
 * @return array{created:int,skipped:int} Anzahl angelegter und uebersprungener Termine
 */
function materialize_series(string $series_id, string $start_date, string $end_date): array
{
  $created = 0;
  $skipped = 0;

  $sql = "SELECT user_id,court_id,title,weekday,start_time,duration_min,timezone
          FROM recurring_series WHERE id::text=:id";
  $s = pdo()->prepare($sql);
  $s->execute([':id' => $series_id]);
  $sconf = $s->fetch();
  if (!$sconf) return ['created' => 0, 'skipped' => 0];

  $user_id = $sconf['user_id'];
  $court_id = $sconf['court_id'];
  $series_title = $sconf['title'] ?? '';
  $weekday = (int)$sconf['weekday'];
  $start_time = (string)$sconf['start_time'];
  $duration = (int)$sconf['duration_min'];
  $tzname = $sconf['timezone'] ?: 'Europe/Berlin';
  $tz = new DateTimeZone($tzname);

  $from = new DateTime($start_date . ' 00:00:00', $tz);
  $to   = new DateTime($end_date . ' 23:59:59', $tz);
  while ((int)$from->format('w') !== $weekday) $from->modify('+1 day');

  $buildStart = function (string $date, string $time, DateTimeZone $tz): DateTime {
    if (preg_match('/^\d{2}:\d{2}$/', $time)) $time .= ':00';
    return new DateTime($date . ' ' . $time, $tz);
  };

  for ($d = clone $from; $d <= $to; $d->modify('+7 day')) {
    $occDate = $d->format('Y-m-d');
    $ex = pdo()->prepare("SELECT canceled FROM recurring_exception WHERE series_id::text=:sid AND occur_date=:d");
    $ex->execute([':sid' => $series_id, ':d' => $occDate]);
    $row = $ex->fetch();
    if ($row && $row['canceled']) continue;
    try {
      $start = $buildStart($occDate, $start_time, $tz);
      $end   = (clone $start)->modify('+' . $duration . ' minutes');
      $sql = "SELECT * FROM create_booking(:u,:c,:s::timestamptz,:e::timestamptz,'series',:sid,:title)";
      pdo()->prepare($sql)->execute([':u' => $user_id, ':c' => $court_id, ':s' => $start->format('c'), ':e' => $end->format('c'), ':sid' => $series_id, ':title' => $series_title]);
      $created++;
    } catch (Throwable $e) {
      // Einzelne Termine koennen scheitern (etwa durch einen zwischenzeitlich
      // entstandenen Konflikt). Nicht still verschlucken - sonst haelt der Admin
      // eine lueckenhafte Serie fuer vollstaendig.
      error_log('book_courts materialize_series: ' . $e->getMessage());
      $skipped++;
    }
  }

  return ['created' => $created, 'skipped' => $skipped];
}

function scripts_block(): string
{
  return <<<'HTML'
<script>
"use strict";
let _pendingCancelForm = null;

// ======== Serien-Preview – frei je Platz über gesamten Zeitraum ========
const SeriesPreview = (() => {
  let controller = null, debounceTimer = null;

  const qs = (id) => document.getElementById(id);
  function setStatus(type,msg){
    const s = qs('seriesCourtsStatus'); if(!s) return;
    s.className = 'text-sm ' + (type==='error'?'text-red-600':(type==='loading'?'text-gray-500':'text-gray-500'));
    s.textContent = msg || '';
  }
  function setSubmitEnabled(on){
    const btn = document.querySelector('button[type="submit"]'); if(!btn) return;
    btn.disabled = !on;
    btn.classList.toggle('opacity-50', !on);
    btn.classList.toggle('cursor-not-allowed', !on);
  }
  function render(items){
  const host = qs('seriesCourts'); if(!host) return;
  host.innerHTML = '';

  if (!items || !items.length){
    setStatus('info','Keine aktiven Plätze (oder keine Daten).');
    setSubmitEnabled(false);
    return;
  }

  const total = items[0]?.total ?? 0;
  if (total === 0) {
    setStatus('info','Der gewählte Zeitraum enthält keinen passenden Wochentag. Bitte Start/Dauer/Tag prüfen.');
    setSubmitEnabled(false);
    return;
  }

  // sortiere: erst konfliktfrei (0), dann aufsteigend Konflikte, dann Name
  items.sort((a,b) => (a.conflicts - b.conflicts) || a.name.localeCompare(b.name, 'de'));

  setStatus('info', null);
  setSubmitEnabled(false);

  const frag = document.createDocumentFragment();
  items.forEach(row => {
    const id = 'series_court_' + row.id;
    const ok = Number(row.conflicts) === 0;
    const badge = ok
      ? `<span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">${row.total}/${row.total} frei</span>`
      : `<span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">${row.total - row.conflicts}/${row.total} frei</span>`;

    const label = document.createElement('label');
    label.className = 'cursor-pointer select-none';
    label.innerHTML = `
      <input class="peer sr-only" type="radio" name="court_id" value="${row.id}" id="${id}" required>
      <span class="px-4 py-2 rounded-xl border text-sm bg-white border-gray-300 text-gray-800
                   peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 inline-flex items-center">
        ${row.name} ${badge}
      </span>`;

    if (ok && !frag._picked) { label.dataset.autopick = '1'; frag._picked = true; }

    if (!ok && Array.isArray(row.conflict_starts) && row.conflict_starts.length){
      label.title = 'Kollisionen:\n' + row.conflict_starts.map(s => {
        const d = s.split('T'); return `${d[0]} ${d[1]}`;
      }).join('\n');
    }
    frag.appendChild(label);
  });

  host.appendChild(frag);

  const firstAuto = host.querySelector('label[data-autopick="1"] input[type="radio"]');
  if (firstAuto) firstAuto.checked = true;

  host.addEventListener('change', (e) => {
    if (e.target && e.target.name === 'court_id') setSubmitEnabled(true);
  }, { once: true });

  setSubmitEnabled(!!firstAuto);
}

    function buildURL(){
    const wd = document.querySelector('select[name="weekday"]')?.value ?? '';
    const st = document.getElementById('start_time_series')?.value ?? '';
    const sd = document.getElementById('seriesStart')?.value ?? '';
    const ed = document.getElementById('seriesEnd')?.value ?? '';

    let dur = 0;
    const radio = document.querySelector('#duration_min_series input[type="radio"]:checked') 
               || document.querySelector('input[name="duration_min"]:checked');
    const select = document.querySelector('#duration_min_series select') 
                || document.querySelector('select[name="duration_min"]');

    if (radio && radio.value) dur = parseInt(radio.value, 10);
    else if (select && select.value) dur = parseInt(select.value, 10);

    if (!wd || !st || !sd || !ed || !dur) return null;
    const p = new URLSearchParams({ action:'series_preview', weekday:wd, start_time:st, duration:String(dur), start_date:sd, end_date:ed });
    return 'index.php?' + p.toString();
  }

  function loadNow(){
    const url = buildURL();
    if (!url) { setStatus('info','Wähle Wochentag, Zeit & Zeitraum.'); render([]); return; }
    setStatus('loading','Prüfe Plätze …'); render([]); setSubmitEnabled(false);
    if (controller) controller.abort();
    controller = new AbortController();
    fetch(url, {signal:controller.signal, headers:{'Accept':'application/json'}})
      .then(r => r.json())
      .then(j => {
        if (!j || !j.ok){ setStatus('error','Fehler bei der Abfrage.'); render([]); return; }
        render(j.items||[]);
      })
      .catch(err => { if (err.name!=='AbortError'){ setStatus('error','Netzwerkfehler.'); render([]);} });
  }

  function schedule(){ clearTimeout(debounceTimer); debounceTimer = setTimeout(loadNow, 250); }

  function init(){
  const deps = [
    document.querySelector('select[name="weekday"]'),
    document.getElementById('start_time_series'),
    document.getElementById('seriesStart'),
    document.getElementById('seriesEnd'),
    document.querySelector('#duration_min_series'),
  ];
  deps.forEach(el => { if(el){ el.addEventListener('change', schedule); el.addEventListener('input', schedule); }});
  // zusätzlich, falls Radios in #duration_min_series sind:
  const radios = document.querySelectorAll('#duration_min_series input[type="radio"]');
  radios.forEach(r => r.addEventListener('change', schedule));
  schedule();
}
  return { init };
})();

document.addEventListener('DOMContentLoaded', () => {
  FreeCourts.init();
  SeriesPreview.init();
});

// ======== freie Plätze – Loader ========
const FreeCourts = (() => {
  let controller = null;
  let debounceTimer = null;

  function qs(id){ return document.getElementById(id); }
  function setStatus(type,msg){
    const s = qs('freeCourtsStatus'); if (!s) return;
    s.className = 'text-sm ' + (
      type==='error'   ? 'text-red-600' :
      type==='loading' ? 'text-gray-500' :
      'text-gray-500'
    );
    s.textContent = msg;
  }
  function setDisabledBooking(disabled){
    const btns = document.querySelectorAll('button[type="submit"]');
    btns.forEach(b => b.disabled = !!disabled);
    btns.forEach(b => b.classList.toggle('opacity-50', !!disabled));
  }
  function render(items){
  const host = qs('freeCourts'); if(!host) return;
  host.innerHTML = '';

  if (!items || !items.length) {
    setStatus('info','Kein Platz im gewählten Zeitraum frei.');
    setDisabledBooking(true);
    return;
  }

  setStatus('info', null);
  setDisabledBooking(true);

  const frag = document.createDocumentFragment();
  items.forEach(c => {
    const id = 'court_' + c.id;
    const label = document.createElement('label');
    label.className = 'cursor-pointer select-none';
    label.innerHTML = `
      <input class="peer sr-only" type="radio" name="court_id" value="${c.id}" id="${id}" required>
      <span class="px-4 py-2 rounded-xl border text-sm bg-white border-gray-300 text-gray-800
                   peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 inline-block">
        ${c.name}
      </span>`;
    frag.appendChild(label);
  });
  host.appendChild(frag);

  // Ersten Platz automatisch auswählen (falls gewünscht)
  const first = host.querySelector('input[type="radio"]');
  if (first) first.checked = true;

  // Aktivieren, sobald Auswahl besteht
  host.addEventListener('change', (e) => {
    if (e.target && e.target.name === 'court_id') setDisabledBooking(false);
  }, { once:true });

  setDisabledBooking(!first);
}

    function buildURL(){
    const dateEl = document.querySelector('input[name="book_date"]');
    const sEl    = document.getElementById('start_time_single');
    const date = dateEl?.value || '';
    const start= sEl?.value || '';

    // Dauer je nach Rolle (Radio für Nicht-Admin, Select für Admin)
    let dur = 0;
    const radio = document.querySelector('#duration_min_single input[type="radio"]:checked') 
               || document.querySelector('input[name="duration_min"]:checked');
    const select = document.querySelector('#duration_min_single select') 
                || document.querySelector('select[name="duration_min"]');

    if (radio && radio.value) dur = parseInt(radio.value, 10);
    else if (select && select.value) dur = parseInt(select.value, 10);

    if (!date || !start || !dur) return null;
    const p = new URLSearchParams({ action:'free_courts', date, start, duration: String(dur) });
    return 'index.php?' + p.toString();
  }

  function loadNow(){
    const url = buildURL();
    if (!url) { setStatus('info','Wähle Datum & Zeit, um freie Plätze zu laden.'); render([]); return; }

    // Status zurücksetzen
    setStatus('loading','Lade freie Plätze …');
    render([]); // UI leeren
    setDisabledBooking(true);

    // alte Requests abbrechen
    if (controller) controller.abort();
    controller = new AbortController();

    fetch(url, {signal: controller.signal, headers:{'Accept':'application/json'}})
      .then(r => r.json())
      .then(j => {
        if (!j || !j.ok) {
          const code = j?.error || 'unknown';
          const msg =
            code==='missing_params' ? 'Bitte Datum, Start & Dauer wählen.' :
            code==='invalid_times'  ? 'Ungültige Zeit/Dauer.' :
            code==='server_error'   ? 'Serverfehler bei der Abfrage.' :
                                      'Fehler bei der Abfrage.';
          setStatus('error', msg);
          render([]);
          return;
        }
        render(j.items || []);
      })
      .catch(err => {
        if (err.name === 'AbortError') return;
        setStatus('error','Netzwerkfehler bei der Abfrage.');
        render([]);
      });
  }

  function schedule(){
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadNow, 250);
  }

  function init(){
    const dateEl = document.querySelector('input[name="book_date"]');
    const sEl    = document.getElementById('start_time_single');
    const durEl  = document.querySelector('#duration_min_single'); // ▼ Neu

    [dateEl, sEl, durEl].forEach(el => {
      if (!el) return;
      el.addEventListener('change', schedule);
      el.addEventListener('input',  schedule);
    });
    // falls Radio-Buttons:
    const radios = document.querySelectorAll('#duration_min_single input[type="radio"]');
    radios.forEach(r => r.addEventListener('change', schedule));

    schedule();
  }
  return { init };
})();

// --- 30-Minuten-Snapping für Time-Inputs ---
function snapTo30min(el) {
  if (!el || !el.value) return;
  const m = el.value.match(/^([01]\d|2[0-3]):([0-5]\d)$/);
  if (!m) return;
  let h = parseInt(m[1], 10), min = parseInt(m[2], 10);
  const snapped = (min < 15) ? 0 : (min < 45 ? 30 : 60);
  if (snapped === 60) {
    h = (h + 1) % 24;
    min = 0;
  } else {
    min = snapped;
  }
  const toStr = (n) => String(n).padStart(2, "0");
  let val = `${toStr(h)}:${toStr(min)}`;
  const minAttr = el.getAttribute("min");
  const maxAttr = el.getAttribute("max");
  if (minAttr && val < minAttr) val = minAttr;
  if (maxAttr && val > maxAttr) val = maxAttr;
  el.value = val;
}

// beim Laden aktivieren
document.addEventListener("DOMContentLoaded", () => {
  const timeInputs = document.querySelectorAll('input[type="time"][step="1800"]');
  timeInputs.forEach(el => {
    el.addEventListener("change", () => snapTo30min(el));
    el.addEventListener("blur",   () => snapTo30min(el));
    // optional sofort einrasten
    if (el.value) snapTo30min(el);
  });
});

function openWeekPicker(){
  const i = document.getElementById('weekDateInput');
  if (!i) return;
  try {
    i.showPicker();        // Moderne Browser inkl. aktuelles Safari/Chromium
  } catch (e) {
    i.focus();             // Fallback, falls showPicker fehlt oder abgelehnt wird
    i.click();
  }
}
// Das unsichtbare Datumsfeld liegt ueber dem Button. Solange es Klicks abfaengt, laeuft
// openWeekPicker() nie - ob der Picker aufgeht, haengt dann davon ab, welche Stelle im
// nativen Feld man trifft. Wo showPicker() existiert, darf der Klick daher zum Button
// durch. Aeltere Browser ohne showPicker behalten das Overlay als Tipp-Flaeche.
(function(){
  const i = document.getElementById('weekDateInput');
  if (i && typeof i.showPicker === 'function') i.style.pointerEvents = 'none';
})();

function openCancelDialog(form){
  _pendingCancelForm = form || null;
  const hasSeries = !!(_pendingCancelForm && _pendingCancelForm.querySelector('input[name="series_id"]'));
  const btnSeries = document.getElementById('btnDeleteSeries');
  if (btnSeries) btnSeries.classList.toggle('hidden', !hasSeries);
  document.getElementById('cancelDialog')?.classList.remove('hidden');
  return false;
}
function closeCancelDialog(){ document.getElementById('cancelDialog')?.classList.add('hidden'); }
function cancelDialogDeleteSingle(){
  if(!_pendingCancelForm){ closeCancelDialog(); return; }
  const op = _pendingCancelForm.querySelector('input[name="op"]');
  if (op) op.value = 'cancel_booking';
  closeCancelDialog(); _pendingCancelForm.submit(); _pendingCancelForm = null;
}
function cancelDialogDeleteSeries(){
  if(!_pendingCancelForm){ closeCancelDialog(); return; }
  const hasSeries = !!_pendingCancelForm.querySelector('input[name="series_id"]');
  if(!hasSeries){ closeCancelDialog(); return; }
  const op = _pendingCancelForm.querySelector('input[name="op"]');
  if (op) op.value = 'cancel_series';
  closeCancelDialog(); _pendingCancelForm.submit(); _pendingCancelForm = null;
}
function goWeek(v){ if(!v) return; window.location='?view=week&date='+encodeURIComponent(v); }
function selectDay(iso){
  const url = new URL(window.location.href);
  url.searchParams.set('view','week'); url.searchParams.set('date',iso); url.searchParams.set('selected',iso);
  window.location = url.toString();
}
// Serien-Enddatum automatisch auf Jahresende (solange Nutzer es nicht ändert)
(function(){
  const s = document.getElementById('seriesStart');
  const e = document.getElementById('seriesEnd');
  if(!s || !e) return;
  let userSetEnd = false; e.addEventListener('input', ()=>{ userSetEnd = true; });
  function syncEndToYear(){
    if(userSetEnd) return;
    const val = s.value || new Date().toISOString().slice(0,10);
    const y = new Date(val).getFullYear();
    e.value = y + '-12-31';
  }
  syncEndToYear(); s.addEventListener('change', syncEndToYear);
})();
</script>
HTML;
}
