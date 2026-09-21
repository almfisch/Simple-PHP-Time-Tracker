<?php
declare(strict_types=1);

session_start();

define('TIME_TRACKER', true);
const DATA_FILE = __DIR__ . '/data.php';
const LEGACY_DATA_FILE = __DIR__ . '/data.json';
$languages = is_file(__DIR__ . '/lang.php') ? include __DIR__ . '/lang.php' : ['de' => ['name' => 'Deutsch', 'strings' => []]];
$languageCode = 'de';

function t(string $text): string
{
    global $languages, $languageCode;
    return (string)($languages[$languageCode]['strings'][$text] ?? $text);
}

function emptyData(): array
{
    return ['settings' => ['language' => null], 'users' => [], 'customers' => [], 'projects' => [], 'timer' => null, 'entries' => []];
}

function normalizeData($data): array
{
    $data = is_array($data) ? $data : [];
    return [
        'settings'  => is_array($data['settings'] ?? null) ? $data['settings'] : ['language' => null],
        'users'     => is_array($data['users'] ?? null) ? $data['users'] : [],
        'customers' => is_array($data['customers'] ?? null) ? $data['customers'] : [],
        'projects'  => is_array($data['projects'] ?? null) ? $data['projects'] : [],
        'timer'     => is_array($data['timer'] ?? null) ? $data['timer'] : null,
        'entries'   => is_array($data['entries'] ?? null) ? $data['entries'] : [],
    ];
}

function readData(): array
{
    if (!is_file(DATA_FILE)) {
        if (!is_file(LEGACY_DATA_FILE)) return emptyData();
        changeData(function (array &$data): void {});
    }
    $handle = fopen(DATA_FILE, 'rb');
    if (!$handle) {
        throw new RuntimeException('Die Datendatei konnte nicht gelesen werden.');
    }
    flock($handle, LOCK_SH);
    $data = include DATA_FILE;
    flock($handle, LOCK_UN);
    fclose($handle);
    return normalizeData($data);
}

function changeData(callable $change): void
{
    $handle = fopen(DATA_FILE, 'c+');
    if (!$handle) {
        throw new RuntimeException('data.php konnte nicht angelegt werden. Bitte Schreibrechte prüfen.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Die Datendatei ist gerade gesperrt.');
    }
    clearstatcache(true, DATA_FILE);
    if (filesize(DATA_FILE) > 0) {
        $data = normalizeData(include DATA_FILE);
    } elseif (is_file(LEGACY_DATA_FILE)) {
        $legacyJson = file_get_contents(LEGACY_DATA_FILE);
        $data = normalizeData(json_decode($legacyJson ?: '', true));
    } else {
        $data = emptyData();
    }
    $change($data);
    $php = "<?php\nif (!defined('TIME_TRACKER')) { http_response_code(403); exit; }\nreturn " . var_export($data, true) . ";\n";
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $php);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(DATA_FILE, true);
}

function id(): string { return bin2hex(random_bytes(8)); }
function clean(string $value): string { return trim(preg_replace('/\s+/u', ' ', $value) ?? $value); }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function nowIso(): string { return date(DATE_ATOM); }
function secondsBetween(string $from, ?string $to = null): int
{
    return max(0, strtotime($to ?? nowIso()) - strtotime($from));
}
function timerSeconds(array $timer): int
{
    $seconds = (int)($timer['elapsed_seconds'] ?? 0);
    if (($timer['status'] ?? '') === 'running') {
        $seconds += secondsBetween((string)$timer['segment_started_at']);
    }
    return max(0, $seconds);
}
function formatDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
function findById(array $items, string $id): ?array
{
    foreach ($items as $item) if (($item['id'] ?? '') === $id) return $item;
    return null;
}
function entrySessions(array $entry): array
{
    if (!empty($entry['sessions']) && is_array($entry['sessions'])) return $entry['sessions'];
    if (empty($entry['started_at']) || empty($entry['ended_at'])) return [];
    return [[
        'started_at' => $entry['started_at'], 'ended_at' => $entry['ended_at'],
        'duration_seconds' => (int)($entry['duration_seconds'] ?? secondsBetween((string)$entry['started_at'], (string)$entry['ended_at'])),
    ]];
}
function entryDateLabel(array $entry): string
{
    $sessions = entrySessions($entry);
    if (!$sessions) return '–';
    $first = strtotime((string)$sessions[0]['started_at']);
    $lastSession = $sessions[count($sessions) - 1];
    $last = strtotime((string)($lastSession['ended_at'] ?? $lastSession['started_at']));
    if (date('Y-m-d', $first) === date('Y-m-d', $last)) return date('d.m.Y', $first);
    if (date('Y', $first) === date('Y', $last)) return date('d.m.', $first) . '–' . date('d.m.Y', $last);
    return date('d.m.Y', $first) . '–' . date('d.m.Y', $last);
}
function addFinishedSession(array &$timer, string $endedAt): void
{
    if (($timer['status'] ?? '') !== 'running' || empty($timer['segment_started_at'])) return;
    $duration = secondsBetween((string)$timer['segment_started_at'], $endedAt);
    if ($duration > 0) {
        if (!isset($timer['sessions']) || !is_array($timer['sessions'])) $timer['sessions'] = [];
        $timer['sessions'][] = ['started_at' => $timer['segment_started_at'], 'ended_at' => $endedAt, 'duration_seconds' => $duration];
    }
}
function redirect(string $message = '', string $type = 'ok'): void
{
    if ($message !== '') $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
try { $requestData = readData(); } catch (Throwable $e) { $requestData = emptyData(); $loadError = $e->getMessage(); }
$storedLanguage = (string)($requestData['settings']['language'] ?? '');
if (isset($languages[$storedLanguage])) $languageCode = $storedLanguage;
$hasUsers = count($requestData['users']) > 0;
$currentUser = $hasUsers ? findById($requestData['users'], (string)($_SESSION['user_id'] ?? '')) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException(t('error_request_expired'));
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'set_language') {
            $selectedLanguage = (string)($_POST['language'] ?? '');
            if (!isset($languages[$selectedLanguage])) throw new RuntimeException('Unknown language.');
            if ($storedLanguage !== '' && $hasUsers && !$currentUser) throw new RuntimeException(t('error_login_required'));
            changeData(function (array &$data) use ($selectedLanguage): void { $data['settings']['language'] = $selectedLanguage; });
            redirect(t('language_saved'));
        }

        if ($action === 'logout') {
            unset($_SESSION['user_id']);
            session_regenerate_id(true);
            redirect(t('logout_success'));
        }
        if ($action === 'login') {
            $username = clean((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $user = null;
            foreach ($requestData['users'] as $candidate) {
                if (hash_equals(strtolower((string)$candidate['username']), strtolower($username))) { $user = $candidate; break; }
            }
            if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
                throw new RuntimeException(t('error_invalid_credentials'));
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            redirect(t('login_success'));
        }
        if ($action === 'create_login') {
            if ($hasUsers) throw new RuntimeException(t('error_login_already_configured'));
            $username = clean((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirmation = (string)($_POST['password_confirmation'] ?? '');
            if ($username === '' || strlen($username) < 3) throw new RuntimeException(t('error_username_minlength'));
            if (strlen($password) < 3) throw new RuntimeException(t('error_password_minlength'));
            if (!hash_equals($password, $confirmation)) throw new RuntimeException(t('error_password_mismatch'));
            $newUserId = id();
            changeData(function (array &$data) use ($username, $password, $newUserId): void {
                if (!empty($data['users'])) throw new RuntimeException(t('error_login_already_configured'));
                $data['users'][] = ['id' => $newUserId, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => nowIso()];
            });
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            redirect(t('login_enabled'));
        }
        if ($hasUsers && !$currentUser) throw new RuntimeException(t('error_login_required'));

        changeData(function (array &$data) use ($action): void {
            if ($action === 'add_customer') {
                $name = clean((string)($_POST['name'] ?? ''));
                if ($name === '') throw new RuntimeException(t('error_enter_customer_name'));
                $data['customers'][] = ['id' => id(), 'name' => $name, 'created_at' => nowIso()];
                return;
            }
            if ($action === 'edit_customer') {
                $customerId = (string)($_POST['customer_id'] ?? '');
                $name = clean((string)($_POST['name'] ?? ''));
                if ($name === '') throw new RuntimeException(t('error_enter_customer_name'));
                $found = false;
                foreach ($data['customers'] as &$customer) {
                    if (($customer['id'] ?? '') === $customerId) { $customer['name'] = $name; $found = true; break; }
                }
                unset($customer);
                if (!$found) throw new RuntimeException(t('error_customer_not_found'));
                return;
            }
            if ($action === 'add_project') {
                $name = clean((string)($_POST['name'] ?? ''));
                $customerId = (string)($_POST['customer_id'] ?? '');
                if ($name === '') throw new RuntimeException(t('error_enter_project_name'));
                if (!findById($data['customers'], $customerId)) throw new RuntimeException(t('error_select_valid_customer'));
                $data['projects'][] = ['id' => id(), 'customer_id' => $customerId, 'name' => $name, 'created_at' => nowIso()];
                return;
            }
            if ($action === 'edit_project') {
                $projectId = (string)($_POST['project_id'] ?? '');
                $name = clean((string)($_POST['name'] ?? ''));
                $customerId = (string)($_POST['customer_id'] ?? '');
                if ($name === '') throw new RuntimeException(t('error_enter_project_name'));
                if (!findById($data['customers'], $customerId)) throw new RuntimeException(t('error_select_valid_customer'));
                $found = false;
                foreach ($data['projects'] as &$project) {
                    if (($project['id'] ?? '') === $projectId) { $project['name'] = $name; $project['customer_id'] = $customerId; $found = true; break; }
                }
                unset($project);
                if (!$found) throw new RuntimeException(t('error_project_not_found'));
                return;
            }
            if ($action === 'start') {
                if ($data['timer']) throw new RuntimeException(t('error_timer_active'));
                $projectId = (string)($_POST['project_id'] ?? '');
                $description = clean((string)($_POST['description'] ?? ''));
                if (!findById($data['projects'], $projectId)) throw new RuntimeException(t('error_select_project'));
                if ($description === '') throw new RuntimeException(t('error_enter_description'));
                $now = nowIso();
                $data['timer'] = [
                    'project_id' => $projectId, 'description' => $description, 'status' => 'running',
                    'started_at' => $now, 'segment_started_at' => $now, 'elapsed_seconds' => 0, 'sessions' => [],
                ];
                return;
            }
            if ($action === 'pause') {
                if (!$data['timer'] || ($data['timer']['status'] ?? '') !== 'running') throw new RuntimeException(t('error_timer_not_running'));
                $pausedAt = nowIso();
                $data['timer']['elapsed_seconds'] = (int)($data['timer']['elapsed_seconds'] ?? 0) + secondsBetween((string)$data['timer']['segment_started_at'], $pausedAt);
                addFinishedSession($data['timer'], $pausedAt);
                $data['timer']['status'] = 'paused';
                $data['timer']['segment_started_at'] = null;
                return;
            }
            if ($action === 'resume') {
                if (!$data['timer'] || ($data['timer']['status'] ?? '') !== 'paused') throw new RuntimeException(t('error_timer_not_paused'));
                $data['timer']['status'] = 'running';
                $data['timer']['segment_started_at'] = nowIso();
                return;
            }
            if ($action === 'edit_timer') {
                if (!$data['timer']) throw new RuntimeException(t('error_no_active_timer'));
                $description = clean((string)($_POST['description'] ?? ''));
                $projectId = (string)($_POST['project_id'] ?? '');
                if ($description === '') throw new RuntimeException(t('error_enter_task'));
                if (!findById($data['projects'], $projectId)) throw new RuntimeException(t('error_select_valid_project'));
                $data['timer']['description'] = $description;
                $data['timer']['project_id'] = $projectId;
                return;
            }
            if ($action === 'finish') {
                if (!$data['timer']) throw new RuntimeException(t('error_no_active_timer'));
                $timer = $data['timer'];
                $finishedAt = nowIso();
                $duration = (int)($timer['elapsed_seconds'] ?? 0);
                if (($timer['status'] ?? '') === 'running') $duration += secondsBetween((string)$timer['segment_started_at'], $finishedAt);
                addFinishedSession($timer, $finishedAt);
                $existingEntryId = (string)($timer['existing_entry_id'] ?? '');
                $updated = false;
                if ($existingEntryId !== '') {
                    foreach ($data['entries'] as &$entry) {
                        if (($entry['id'] ?? '') === $existingEntryId) {
                            $entry['project_id'] = $timer['project_id'];
                            $entry['description'] = $timer['description'];
                            $entry['ended_at'] = $finishedAt;
                            $entry['duration_seconds'] = $duration;
                            $entry['sessions'] = $timer['sessions'] ?? entrySessions($entry);
                            $updated = true;
                            break;
                        }
                    }
                    unset($entry);
                }
                if (!$updated) {
                    $data['entries'][] = [
                        'id' => id(), 'project_id' => $timer['project_id'], 'description' => $timer['description'],
                        'started_at' => $timer['started_at'], 'ended_at' => $finishedAt, 'duration_seconds' => $duration,
                        'sessions' => $timer['sessions'] ?? [],
                    ];
                }
                $data['timer'] = null;
                return;
            }
            if ($action === 'discard') {
                if (!$data['timer']) throw new RuntimeException(t('error_no_active_timer'));
                $data['timer'] = null;
                return;
            }
            if ($action === 'delete_entry') {
                $entryId = (string)($_POST['entry_id'] ?? '');
                if (($data['timer']['existing_entry_id'] ?? '') === $entryId) throw new RuntimeException(t('error_entry_in_timer'));
                $data['entries'] = array_values(array_filter($data['entries'], function (array $e) use ($entryId): bool { return ($e['id'] ?? '') !== $entryId; }));
                return;
            }
            if ($action === 'delete_session') {
                $entryId = (string)($_POST['entry_id'] ?? '');
                $sessionIndexRaw = (string)($_POST['session_index'] ?? '');
                if (($data['timer']['existing_entry_id'] ?? '') === $entryId) throw new RuntimeException(t('error_entry_in_timer'));
                if ($sessionIndexRaw === '' || !ctype_digit($sessionIndexRaw)) throw new RuntimeException(t('error_session_not_found'));
                $sessionIndex = (int)$sessionIndexRaw;
                $found = false;
                foreach ($data['entries'] as $entryIndex => &$entry) {
                    if (($entry['id'] ?? '') !== $entryId) continue;
                    $sessions = entrySessions($entry);
                    if (!isset($sessions[$sessionIndex])) throw new RuntimeException(t('error_session_not_found'));
                    array_splice($sessions, $sessionIndex, 1);
                    if (!$sessions) {
                        unset($entry);
                        array_splice($data['entries'], $entryIndex, 1);
                    } else {
                        $entry['sessions'] = array_values($sessions);
                        $entry['duration_seconds'] = array_sum(array_map(function (array $session): int { return (int)($session['duration_seconds'] ?? 0); }, $sessions));
                        $entry['started_at'] = $sessions[0]['started_at'];
                        $lastSession = $sessions[count($sessions) - 1];
                        $entry['ended_at'] = $lastSession['ended_at'];
                        unset($entry);
                    }
                    $found = true;
                    break;
                }
                unset($entry);
                if (!$found) throw new RuntimeException(t('error_entry_not_found'));
                return;
            }
            if ($action === 'edit_entry') {
                $entryId = (string)($_POST['entry_id'] ?? '');
                $description = clean((string)($_POST['description'] ?? ''));
                if ($description === '') throw new RuntimeException(t('error_enter_task'));
                $found = false;
                foreach ($data['entries'] as &$entry) {
                    if (($entry['id'] ?? '') === $entryId) { $entry['description'] = $description; $found = true; break; }
                }
                unset($entry);
                if (!$found) throw new RuntimeException(t('error_entry_not_found'));
                return;
            }
            if ($action === 'continue_entry') {
                if ($data['timer']) throw new RuntimeException(t('error_timer_active'));
                $entry = findById($data['entries'], (string)($_POST['entry_id'] ?? ''));
                if (!$entry) throw new RuntimeException(t('error_entry_not_found'));
                if (!findById($data['projects'], (string)$entry['project_id'])) throw new RuntimeException(t('error_project_missing'));
                $now = nowIso();
                $data['timer'] = [
                    'project_id' => $entry['project_id'], 'description' => $entry['description'], 'status' => 'running',
                    'started_at' => $entry['started_at'], 'segment_started_at' => $now,
                    'elapsed_seconds' => (int)($entry['duration_seconds'] ?? 0), 'existing_entry_id' => $entry['id'],
                    'sessions' => entrySessions($entry),
                ];
                return;
            }
            if ($action === 'set_billed') {
                $entryId = (string)($_POST['entry_id'] ?? '');
                $billed = (string)($_POST['billed'] ?? '') === '1';
                $found = false;
                foreach ($data['entries'] as &$entry) {
                    if (($entry['id'] ?? '') === $entryId) { $entry['billed'] = $billed; $entry['billed_at'] = $billed ? nowIso() : null; $found = true; break; }
                }
                unset($entry);
                if (!$found) throw new RuntimeException(t('error_entry_not_found'));
                return;
            }
            if ($action === 'delete_project') {
                $projectId = (string)($_POST['project_id'] ?? '');
                if (($data['timer']['project_id'] ?? '') === $projectId) throw new RuntimeException(t('error_project_in_timer'));
                foreach ($data['entries'] as $entry) if (($entry['project_id'] ?? '') === $projectId) throw new RuntimeException(t('error_project_has_entries'));
                $data['projects'] = array_values(array_filter($data['projects'], function (array $p) use ($projectId): bool { return ($p['id'] ?? '') !== $projectId; }));
                return;
            }
            if ($action === 'delete_customer') {
                $customerId = (string)($_POST['customer_id'] ?? '');
                foreach ($data['projects'] as $project) if (($project['customer_id'] ?? '') === $customerId) throw new RuntimeException(t('error_customer_has_projects'));
                $data['customers'] = array_values(array_filter($data['customers'], function (array $c) use ($customerId): bool { return ($c['id'] ?? '') !== $customerId; }));
                return;
            }
            throw new RuntimeException(t('error_unknown_action'));
        });
        $messages = [
            'add_customer' => t('customer_created'), 'edit_customer' => t('customer_updated'), 'add_project' => t('project_created'), 'edit_project' => t('project_updated'),
            'start' => t('timer_started'), 'pause' => t('timer_paused'), 'resume' => t('timer_resumed'),
            'edit_timer' => t('timer_updated'), 'finish' => t('time_saved'), 'discard' => t('timer_discarded'),
            'delete_entry' => t('entry_deleted'), 'delete_session' => t('session_deleted'), 'edit_entry' => t('entry_updated'), 'continue_entry' => t('timer_reopened'), 'set_billed' => t('billing_status_updated'), 'delete_project' => t('project_deleted'),
            'delete_customer' => t('customer_deleted'),
        ];
        redirect($messages[$action] ?? t('saved'));
    } catch (Throwable $e) {
        redirect($e->getMessage(), 'error');
    }
}

try { $data = readData(); } catch (Throwable $e) { $data = emptyData(); $loadError = $e->getMessage(); }
$storedLanguage = (string)($data['settings']['language'] ?? '');
if (isset($languages[$storedLanguage])) $languageCode = $storedLanguage;
$hasUsers = count($data['users']) > 0;
$currentUser = $hasUsers ? findById($data['users'], (string)($_SESSION['user_id'] ?? '')) : null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if (isset($loadError)) $flash = ['message' => $loadError, 'type' => 'error'];

$customerNames = [];
foreach ($data['customers'] as $customer) $customerNames[$customer['id']] = $customer['name'];
$projectNames = [];
foreach ($data['projects'] as $project) $projectNames[$project['id']] = $project['name'];
$activeEntryId = (string)($data['timer']['existing_entry_id'] ?? '');
$entries = array_reverse(array_values(array_filter($data['entries'], function (array $e) use ($activeEntryId): bool { return empty($e['billed']) && ($e['id'] ?? '') !== $activeEntryId; })));
$billedEntries = array_reverse(array_values(array_filter($data['entries'], function (array $e): bool { return !empty($e['billed']); })));
$openByProject = [];
foreach ($entries as $entry) {
    $projectId = (string)($entry['project_id'] ?? '');
    $openByProject[$projectId] = ($openByProject[$projectId] ?? 0) + (int)($entry['duration_seconds'] ?? 0);
}
if ($data['timer']) {
    $timerProjectId = (string)($data['timer']['project_id'] ?? '');
    $openByProject[$timerProjectId] = ($openByProject[$timerProjectId] ?? 0) + timerSeconds($data['timer']);
}
$openByCustomer = [];
foreach ($data['projects'] as $project) {
    $customerId = (string)($project['customer_id'] ?? '');
    $openByCustomer[$customerId] = ($openByCustomer[$customerId] ?? 0) + ($openByProject[$project['id']] ?? 0);
}
$totalSeconds = array_sum($openByProject);
?>
<!doctype html>
<html lang="<?= h($storedLanguage !== '' ? $storedLanguage : 'de') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('title')) ?></title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#17202a;--muted:#68717c;--line:#dfe3e8;--primary:#146c5a;--primary2:#0d5143;--danger:#b42318;--warn:#9a6700;--shadow:0 8px 24px rgba(16,24,40,.07)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{width:min(1100px,calc(100% - 28px));margin:36px auto 60px}header{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:22px}h1{font-size:30px;line-height:1.1;margin:0}h2{font-size:19px;margin:0 0 18px}h3{font-size:14px;margin:22px 0 8px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.sub,.muted{color:var(--muted)}.sub{margin:5px 0 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;box-shadow:var(--shadow);margin-bottom:18px}.field{margin-bottom:14px}label{display:block;font-weight:650;margin-bottom:6px}input,select{width:100%;padding:11px 12px;border:1px solid #c9d0d7;border-radius:8px;background:#fff;color:var(--text);font:inherit}input:focus,select:focus{outline:3px solid rgba(20,108,90,.14);border-color:var(--primary)}button{border:0;border-radius:8px;padding:10px 15px;background:var(--primary);color:#fff;font:650 14px inherit;cursor:pointer}button:hover{background:var(--primary2)}button.secondary{background:#e9eef0;color:#24313a}button.secondary:hover{background:#dce3e6}button.danger{background:#fff0ee;color:var(--danger)}button.danger:hover{background:#ffe3df}.inline{display:flex;gap:9px;align-items:end}.inline .grow{flex:1}.list{list-style:none;padding:0;margin:12px 0 0}.list li{display:flex;justify-content:space-between;align-items:center;gap:10px;border-top:1px solid var(--line);padding:9px 0}.list form{margin:0}.icon-btn{padding:5px 9px;background:transparent;color:var(--muted);font-size:18px}.icon-btn:hover{background:#fff0ee;color:var(--danger)}.edit-form{display:flex;gap:7px;align-items:center;flex:1}.edit-form input,.edit-form select{padding:7px 9px}.edit-form select{max-width:150px}.edit-form button{padding:7px 10px}.row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}.row-actions form{margin:0}.small-btn{padding:6px 9px;font-size:12px;white-space:nowrap}.timer{text-align:center;padding:8px 0 3px}.clock{font-variant-numeric:tabular-nums;font-size:clamp(44px,8vw,72px);font-weight:750;letter-spacing:-.04em;line-height:1.1}.timer-title{font-size:18px;font-weight:700;margin-top:12px}.timer-actions{display:flex;justify-content:center;gap:9px;flex-wrap:wrap;margin-top:20px}.timer-edit{max-width:680px;margin:22px auto 0;text-align:left}.flash{padding:12px 15px;border-radius:9px;margin-bottom:18px;background:#e7f6f1;color:#0b5d4b;border:1px solid #b8e3d6}.flash.error{background:#fff0ee;color:#8f1d14;border-color:#fac5be}.empty{padding:22px;text-align:center;color:var(--muted);border:1px dashed #cbd2d8;border-radius:10px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}td.duration{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}.summary{font-weight:700;font-variant-numeric:tabular-nums}.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2f4;color:#4d5963;font-size:12px}.badge.running{background:#e7f6f1;color:#0b5d4b}.manage-toggle{margin-top:10px}details summary{cursor:pointer;color:var(--primary);font-weight:650}.sessions{margin-top:5px;min-width:220px}.sessions summary{font-size:12px;font-weight:600}.session-list{margin-top:6px;padding:7px 9px;background:#f5f7f8;border-radius:7px}.session-list>div{display:flex;justify-content:space-between;gap:12px;font-size:12px;white-space:nowrap}.session-list strong{font-variant-numeric:tabular-nums}.session-meta{display:flex;align-items:center;gap:5px}.session-meta form{margin:0}.session-delete{padding:0 5px;border-radius:5px;background:transparent;color:var(--muted);font-size:16px;line-height:18px}.session-delete:hover{background:#ffe3df;color:var(--danger)}@media(max-width:760px){.grid{grid-template-columns:1fr}.inline{align-items:stretch;flex-direction:column}header{align-items:start;flex-direction:column}.wrap{margin-top:22px}.card{padding:17px}.edit-form{align-items:stretch;flex-direction:column}.edit-form select{max-width:none}.list li{align-items:flex-start}}
.time-pill{flex:0 0 auto;padding:5px 8px;border-radius:999px;background:#e7f6f1;color:#0b5d4b;font-size:12px;font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}
</style>
</head>
<body>
<main class="wrap">
<header><div><h1><?= h(t('title')) ?></h1><p class="sub"><?= h(t('subtitle')) ?></p></div><?php if (!$hasUsers || $currentUser): ?><div style="text-align:right"><div class="summary"><?= h(t('not_billed')) ?> <?= h(formatDuration($totalSeconds)) ?></div><?php if ($currentUser): ?><div style="margin-top:7px"><span class="muted"><?= h($currentUser['username']) ?></span><form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="logout"><button class="small-btn secondary"><?= h(t('logout')) ?></button></form></div><?php endif; ?></div><?php endif; ?></header>

<?php if ($flash): ?><div class="flash <?= ($flash['type'] ?? '') === 'error' ? 'error' : '' ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<?php if ($storedLanguage === ''): ?>
<section class="card" style="max-width:440px;margin:70px auto"><h2>Sprache wählen / Choose language</h2><p class="muted">Bitte wähle die Sprache für diese Installation.<br>Please choose the language for this installation.</p><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="set_language"><div class="field"><label for="first-language">Sprache / Language</label><select id="first-language" name="language" required autofocus><option value="">Bitte wählen / Please select</option><?php foreach ($languages as $code => $language): ?><option value="<?= h($code) ?>"><?= h($language['name'] ?? strtoupper($code)) ?></option><?php endforeach; ?></select></div><button>Speichern / Save</button></form></section></main></body></html><?php exit; ?>
<?php endif; ?>

<?php if ($hasUsers && !$currentUser): ?>
<section class="card" style="max-width:440px;margin:70px auto">
<h2><?= h(t('login')) ?></h2><p class="muted"><?= h(t('login_intro')) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="login"><div class="field"><label for="login-user"><?= h(t('username')) ?></label><input id="login-user" name="username" autocomplete="username" required autofocus></div><div class="field"><label for="login-password"><?= h(t('password')) ?></label><input id="login-password" type="password" name="password" autocomplete="current-password" required></div><button><?= h(t('login')) ?></button></form>
</section></main></body></html><?php exit; ?>
<?php endif; ?>

<section class="card"><details><summary><?= h(t('settings')) ?></summary><form method="post" class="inline" style="margin-top:14px"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="set_language"><div class="grow"><label for="settings-language"><?= h(t('language')) ?></label><select id="settings-language" name="language"><?php foreach ($languages as $code => $language): ?><option value="<?= h($code) ?>" <?= $code === $languageCode ? 'selected' : '' ?>><?= h($language['name'] ?? strtoupper($code)) ?></option><?php endforeach; ?></select></div><button><?= h(t('save')) ?></button></form></details></section>

<?php if (!$hasUsers): ?>
<section class="card"><details><summary><?= h(t('setup_optional_login')) ?></summary><p class="muted"><?= h(t('login_setup_info')) ?></p><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="create_login"><div class="grid"><div class="field"><label for="new-username"><?= h(t('username')) ?></label><input id="new-username" name="username" minlength="3" maxlength="80" autocomplete="username" required></div><div><div class="field"><label for="new-password"><?= h(t('password')) ?></label><input id="new-password" type="password" name="password" minlength="3" autocomplete="new-password" required></div><div class="field"><label for="new-password-confirmation"><?= h(t('repeat_password')) ?></label><input id="new-password-confirmation" type="password" name="password_confirmation" minlength="3" autocomplete="new-password" required></div></div></div><button><?= h(t('enable_login')) ?></button></form></details></section>
<?php endif; ?>

<section class="grid">
<div class="card">
<h2><?= h(t('customers')) ?></h2>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="add_customer"><div class="grow"><label for="customer-name"><?= h(t('new_customer')) ?></label><input id="customer-name" name="name" maxlength="120" required placeholder="<?= h(t('customer_placeholder')) ?>"></div><button type="submit"><?= h(t('create')) ?></button></form>
<?php if ($data['customers']): ?><details class="manage-toggle"><summary><?= count($data['customers']) ?> <?= h(t('manage_customers')) ?></summary><ul class="list"><?php foreach ($data['customers'] as $customer): ?><li><form method="post" class="edit-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="edit_customer"><input type="hidden" name="customer_id" value="<?= h($customer['id']) ?>"><input name="name" value="<?= h($customer['name']) ?>" maxlength="120" required aria-label="<?= h(t('customer_name')) ?>"><button class="secondary"><?= h(t('save')) ?></button></form><span class="time-pill" title="<?= h(t('unbilled_time')) ?>"><?= h(formatDuration((int)($openByCustomer[$customer['id']] ?? 0))) ?></span><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_customer'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_customer"><input type="hidden" name="customer_id" value="<?= h($customer['id']) ?>"><button class="icon-btn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete_customer')) ?>">×</button></form></li><?php endforeach; ?></ul></details><?php endif; ?>
</div>

<div class="card">
<h2><?= h(t('projects')) ?></h2>
<?php if (!$data['customers']): ?><div class="empty"><?= h(t('create_customer_first')) ?></div><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="add_project"><div class="inline"><div class="grow"><label for="project-customer"><?= h(t('customer')) ?></label><select id="project-customer" name="customer_id" required><option value=""><?= h(t('please_select')) ?></option><?php foreach ($data['customers'] as $customer): ?><option value="<?= h($customer['id']) ?>"><?= h($customer['name']) ?></option><?php endforeach; ?></select></div><div class="grow"><label for="project-name"><?= h(t('project')) ?></label><input id="project-name" name="name" maxlength="120" required placeholder="<?= h(t('project_placeholder')) ?>"></div><button type="submit"><?= h(t('create')) ?></button></div></form><?php endif; ?>
<?php if ($data['projects']): ?><details class="manage-toggle"><summary><?= count($data['projects']) ?> <?= h(t('manage_projects')) ?></summary><ul class="list"><?php foreach ($data['projects'] as $project): ?><li><form method="post" class="edit-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="edit_project"><input type="hidden" name="project_id" value="<?= h($project['id']) ?>"><select name="customer_id" required aria-label="<?= h(t('customer')) ?>"><?php foreach ($data['customers'] as $customer): ?><option value="<?= h($customer['id']) ?>" <?= $customer['id'] === $project['customer_id'] ? 'selected' : '' ?>><?= h($customer['name']) ?></option><?php endforeach; ?></select><input name="name" value="<?= h($project['name']) ?>" maxlength="120" required aria-label="<?= h(t('project_name')) ?>"><button class="secondary"><?= h(t('save')) ?></button></form><span class="time-pill" title="<?= h(t('unbilled_time')) ?>"><?= h(formatDuration((int)($openByProject[$project['id']] ?? 0))) ?></span><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_project'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= h($project['id']) ?>"><button class="icon-btn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete_project')) ?>">×</button></form></li><?php endforeach; ?></ul></details><?php endif; ?>
</div>
</section>

<section class="card">
<h2><?= h(t('timer')) ?></h2>
<?php if ($data['timer']): $timer = $data['timer']; $timerProject = findById($data['projects'], (string)$timer['project_id']); ?>
<div class="timer" data-status="<?= h($timer['status']) ?>" data-seconds="<?= timerSeconds($timer) ?>">
<span class="badge <?= $timer['status'] === 'running' ? 'running' : '' ?>"><?= h($timer['status'] === 'running' ? t('running') : t('paused')) ?></span>
<div class="clock" id="clock"><?= h(formatDuration(timerSeconds($timer))) ?></div>
<div class="timer-title"><?= h($timerProject['name'] ?? 'Unbekanntes Projekt') ?></div>
<div class="muted"><?= h($customerNames[$timerProject['customer_id'] ?? ''] ?? '') ?> · <?= h($timer['description']) ?></div>
<div class="timer-actions">
<?php if ($timer['status'] === 'running'): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="pause"><button type="submit" class="secondary"><?= h(t('pause')) ?></button></form><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="resume"><button type="submit"><?= h(t('resume')) ?></button></form><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="finish"><button type="submit"><?= h(t('stop_save')) ?></button></form>
<form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_discard_timer'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="discard"><button type="submit" class="danger"><?= h(t('discard')) ?></button></form>
</div>
<details class="timer-edit"><summary><?= h(t('edit_timer')) ?></summary><form method="post" class="inline" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="edit_timer"><div class="grow"><label for="edit-timer-project"><?= h(t('project')) ?></label><select id="edit-timer-project" name="project_id" required><?php foreach ($data['projects'] as $project): ?><option value="<?= h($project['id']) ?>" <?= $project['id'] === $timer['project_id'] ? 'selected' : '' ?>><?= h($customerNames[$project['customer_id']] ?? t('unknown')) ?> — <?= h($project['name']) ?></option><?php endforeach; ?></select></div><div class="grow"><label for="edit-timer-description"><?= h(t('task')) ?></label><input id="edit-timer-description" name="description" value="<?= h($timer['description']) ?>" maxlength="300" required></div><button><?= h(t('save')) ?></button></form></details>
</div>
<?php elseif (!$data['projects']): ?><div class="empty"><?= h(t('create_customer_project_first')) ?></div>
<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="start"><div class="grid"><div class="field"><label for="timer-project"><?= h(t('project')) ?></label><select id="timer-project" name="project_id" required><option value=""><?= h(t('please_select')) ?></option><?php foreach ($data['projects'] as $project): ?><option value="<?= h($project['id']) ?>"><?= h($customerNames[$project['customer_id']] ?? t('unknown')) ?> — <?= h($project['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="description"><?= h(t('what_are_you_doing')) ?></label><input id="description" name="description" maxlength="300" required placeholder="<?= h(t('task_placeholder')) ?>"></div></div><button type="submit"><?= h(t('start_timer')) ?></button></form><?php endif; ?>
</section>

<section class="card">
<h2><?= h(t('open_time_entries')) ?></h2>
<?php if (!$entries): ?><div class="empty"><?= h(t('no_open_entries')) ?></div><?php else: ?><div class="table-wrap"><table><thead><tr><th><?= h(t('date')) ?></th><th><?= h(t('customer_project')) ?></th><th><?= h(t('task')) ?></th><th><?= h(t('duration')) ?></th><th><?= h(t('actions')) ?></th></tr></thead><tbody>
<?php foreach ($entries as $entry): $project = findById($data['projects'], (string)$entry['project_id']); $sessions = entrySessions($entry); ?><tr><td><strong><?= h(entryDateLabel($entry)) ?></strong><details class="sessions"><summary><?= count($sessions) ?> <?= h(count($sessions) === 1 ? t('session') : t('sessions')) ?></summary><div class="session-list"><?php foreach ($sessions as $sessionIndex => $session): ?><div class="session-row"><span><?= h(date('d.m.Y', strtotime($session['started_at']))) ?> · <?= h(date('H:i', strtotime($session['started_at']))) ?>–<?= h(date('H:i', strtotime($session['ended_at']))) ?></span><span class="session-meta"><strong><?= h(formatDuration((int)($session['duration_seconds'] ?? 0))) ?></strong><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_session'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><input type="hidden" name="session_index" value="<?= $sessionIndex ?>"><button class="session-delete" title="<?= h(t('delete_session')) ?>" aria-label="<?= h(t('delete_session')) ?>">×</button></form></span></div><?php endforeach; ?></div></details></td><td><?= h($customerNames[$project['customer_id'] ?? ''] ?? t('unknown')) ?><br><small class="muted"><?= h($project['name'] ?? t('deleted_project')) ?></small></td><td><form method="post" class="edit-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="edit_entry"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><input name="description" value="<?= h($entry['description']) ?>" maxlength="300" required aria-label="<?= h(t('task')) ?>"><button class="secondary"><?= h(t('save')) ?></button></form></td><td class="duration"><?= h(formatDuration((int)$entry['duration_seconds'])) ?></td><td><div class="row-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="continue_entry"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><button class="small-btn" <?= $data['timer'] ? 'disabled title="' . h(t('timer_already_running')) . '"' : '' ?>><?= h(t('continue_working')) ?></button></form><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="set_billed"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><input type="hidden" name="billed" value="1"><button class="small-btn secondary"><?= h(t('billed')) ?></button></form><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_entry'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><button class="icon-btn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete_entry')) ?>">×</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>

<section class="card">
<details><summary><?= h(t('billed')) ?> (<?= count($billedEntries) ?>)</summary>
<?php if (!$billedEntries): ?><div class="empty" style="margin-top:16px"><?= h(t('no_billed_entries')) ?></div><?php else: ?><div class="table-wrap" style="margin-top:12px"><table><thead><tr><th><?= h(t('date')) ?></th><th><?= h(t('customer_project')) ?></th><th><?= h(t('task')) ?></th><th><?= h(t('duration')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($billedEntries as $entry): $project = findById($data['projects'], (string)$entry['project_id']); $sessions = entrySessions($entry); ?><tr><td><strong><?= h(entryDateLabel($entry)) ?></strong><details class="sessions"><summary><?= count($sessions) ?> <?= h(count($sessions) === 1 ? t('session') : t('sessions')) ?></summary><div class="session-list"><?php foreach ($sessions as $sessionIndex => $session): ?><div class="session-row"><span><?= h(date('d.m.Y', strtotime($session['started_at']))) ?> · <?= h(date('H:i', strtotime($session['started_at']))) ?>–<?= h(date('H:i', strtotime($session['ended_at']))) ?></span><span class="session-meta"><strong><?= h(formatDuration((int)($session['duration_seconds'] ?? 0))) ?></strong><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_session'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><input type="hidden" name="session_index" value="<?= $sessionIndex ?>"><button class="session-delete" title="<?= h(t('delete_session')) ?>" aria-label="<?= h(t('delete_session')) ?>">×</button></form></span></div><?php endforeach; ?></div></details></td><td><?= h($customerNames[$project['customer_id'] ?? ''] ?? t('unknown')) ?><br><small class="muted"><?= h($project['name'] ?? t('deleted_project')) ?></small></td><td><?= h($entry['description']) ?></td><td class="duration"><?= h(formatDuration((int)$entry['duration_seconds'])) ?></td><td><div class="row-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="set_billed"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><input type="hidden" name="billed" value="0"><button class="small-btn secondary"><?= h(t('reopen')) ?></button></form><form method="post" onsubmit="return confirm(<?= h(json_encode(t('confirm_delete_entry'))) ?>)"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="entry_id" value="<?= h($entry['id']) ?>"><button class="icon-btn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete_entry')) ?>">×</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></details>
</section>
</main>
<script>
const timer=document.querySelector('.timer[data-seconds]');
if(timer){let seconds=Number(timer.dataset.seconds);const clock=document.getElementById('clock');const draw=()=>{const h=Math.floor(seconds/3600),m=Math.floor(seconds%3600/60),s=seconds%60;clock.textContent=[h,m,s].map(n=>String(n).padStart(2,'0')).join(':')};if(timer.dataset.status==='running')setInterval(()=>{seconds++;draw()},1000)}
</script>
</body>
</html>
