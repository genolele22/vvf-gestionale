<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediLogin();
// Gerarchia: comando gestisce tutto (crea/modifica/elimina/ruoli/permessi);
// admin e user possono SOLO reimpostare le password, in autonomia senza il
// Comando — admin sui profili admin e user, user solo sui profili user.
$RUOLI_PASSWORD = isComando() ? ['comando', 'admin', 'user']
                : (isAdmin() ? ['admin', 'user'] : ['user']);
$pdo = getDB();
$errore = '';
$ok     = '';

$RUOLI  = ['comando' => 'Comando', 'admin' => 'Admin di turno', 'user' => 'User (sola lettura)'];
$TURNI  = ['A','B','C','D'];

// Revoca le sessioni attive di un utente (tabella `sessioni`, dati PHP-serializzati:
// la chiave 'id' dentro $_SESSION['utente'] compare come s:2:"id";i:<id>;).
// Senza questa, un utente eliminato o disattivato resterebbe loggato fino a 12h.
function revocaSessioniUtente(PDO $pdo, int $utenteId): void {
    try {
        $pdo->prepare("DELETE FROM sessioni WHERE data LIKE ?")
            ->execute(['%s:2:"id";i:' . $utenteId . ';%']);
    } catch (Throwable $e) { /* tabella assente (sessioni su file): niente da revocare */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    try {
        if (in_array($azione, ['salva', 'elimina'], true) && !isComando()) {
            throw new RuntimeException('Solo il Comando può creare, modificare o eliminare utenti.');
        }
        if ($azione === 'password') {
            $id       = (int)($_POST['id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            $st = $pdo->prepare("SELECT username, ruolo FROM utenti WHERE id=?");
            $st->execute([$id]);
            $target = $st->fetch();
            if (!$target) throw new RuntimeException('Utente non trovato.');
            if (!in_array($target['ruolo'], $RUOLI_PASSWORD, true))
                throw new RuntimeException('Non puoi gestire la password di questo profilo.');
            if (strlen($password) < 6) throw new RuntimeException('La password deve avere almeno 6 caratteri.');
            $pdo->prepare("UPDATE utenti SET password=? WHERE id=?")
                ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
            $ok = 'Password di ' . $target['username'] . ' aggiornata.';
        } elseif ($azione === 'salva') {
            $id       = (int)($_POST['id'] ?? 0);
            $username = strtolower(trim($_POST['username'] ?? ''));
            $nome     = trim($_POST['nome'] ?? '');
            $ruolo    = $_POST['ruolo'] ?? 'user';
            $attivo   = isset($_POST['attivo']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');
            $perm     = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];  // [turno => '' | 'lettura' | 'scrittura']

            if (!isset($RUOLI[$ruolo]))                 throw new RuntimeException('Ruolo non valido.');
            if ($username === '')                       throw new RuntimeException('Username obbligatorio.');
            // comando = super (nessuna matrice). Per admin/user serve almeno un permesso in griglia.
            if ($ruolo !== 'comando') {
                $haPerm = false;
                foreach ($TURNI as $t) if (in_array($perm[$t] ?? '', ['lettura','scrittura'], true)) { $haPerm = true; break; }
                if (!$haPerm) throw new RuntimeException('Assegna almeno un permesso di turno (lettura o scrittura).');
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare("UPDATE utenti SET username=?, nome=?, ruolo=?, attivo=? WHERE id=?")
                    ->execute([$username, $nome, $ruolo, $attivo, $id]);
                if ($password !== '') {
                    if (strlen($password) < 6) throw new RuntimeException('La password deve avere almeno 6 caratteri.');
                    $pdo->prepare("UPDATE utenti SET password=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
                }
                $targetId = $id;
                $ok = 'Utente aggiornato.';
            } else {
                if (strlen($password) < 6) throw new RuntimeException('Password obbligatoria (min 6 caratteri) per un nuovo utente.');
                $targetId = nextId($pdo, 'utenti');
                $pdo->prepare("INSERT INTO utenti (id,username,password,nome,ruolo,turno,attivo) VALUES (?,?,?,?,?,NULL,?)")
                    ->execute([$targetId, $username, password_hash($password, PASSWORD_BCRYPT), $nome, $ruolo, $attivo]);
                $ok = 'Utente creato.';
            }

            // ── Matrice permessi per turno (#90) + turno "di casa" (default al login) ──
            $pdo->prepare("DELETE FROM utenti_turni WHERE utente_id=?")->execute([$targetId]);
            $homeTurno = null;
            if ($ruolo !== 'comando') {
                $insUT = $pdo->prepare("INSERT INTO utenti_turni (utente_id, turno, livello) VALUES (?,?,?)");
                foreach ($TURNI as $t) {
                    $liv = $perm[$t] ?? '';
                    if ($liv === 'lettura' || $liv === 'scrittura') {
                        $insUT->execute([$targetId, $t, $liv]);
                        if ($homeTurno === null && $liv === 'scrittura') $homeTurno = $t;  // 1° scrittura = casa
                    }
                }
                if ($homeTurno === null) foreach ($TURNI as $t) if (($perm[$t] ?? '') === 'lettura') { $homeTurno = $t; break; }
            }
            $pdo->prepare("UPDATE utenti SET turno=? WHERE id=?")->execute([$homeTurno, $targetId]);
            $pdo->commit();
            if (!$attivo && $targetId !== (int)utenteCorrente()['id']) {
                revocaSessioniUtente($pdo, $targetId);   // disattivato → fuori subito
            }
        } elseif ($azione === 'elimina') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)utenteCorrente()['id']) throw new RuntimeException('Non puoi eliminare il tuo stesso account.');
            $pdo->prepare("DELETE FROM utenti_turni WHERE utente_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM utenti WHERE id=?")->execute([$id]);
            revocaSessioniUtente($pdo, $id);
            $ok = 'Utente eliminato.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        if (stripos($msg, 'uq_username') !== false || stripos($msg, 'Duplicate') !== false)
            $msg = 'Esiste già un utente con questo username.';
        $errore = $msg;
    }
}

$rigaEdit = null;
if (isset($_GET['modifica']) && isComando()) {
    $st = $pdo->prepare("SELECT * FROM utenti WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}

// Permessi per turno: dell'utente in modifica (pre-riempie la griglia) e di tutti (tabella).
$editGrants = [];
$grantsById = [];
try {
    if ($rigaEdit) {
        $stg = $pdo->prepare("SELECT turno, livello FROM utenti_turni WHERE utente_id=?");
        $stg->execute([(int)$rigaEdit['id']]);
        foreach ($stg->fetchAll() as $g) $editGrants[$g['turno']] = $g['livello'];
    }
    foreach ($pdo->query("SELECT utente_id, turno, livello FROM utenti_turni ORDER BY turno") as $g)
        $grantsById[(int)$g['utente_id']][$g['turno']] = $g['livello'];
} catch (Throwable $e) { /* tabella assente */ }
$righe = $pdo->query("SELECT * FROM utenti ORDER BY ruolo, turno, username")->fetchAll();
// Non-comando: vede solo i profili di cui può gestire la password.
if (!isComando())
    $righe = array_values(array_filter($righe, fn($r) => in_array($r['ruolo'], $RUOLI_PASSWORD, true)));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Gestione utenti</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .u-form { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
  .u-form .ff { display:flex; flex-direction:column; gap:3px; }
  .u-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); }
  .u-form input, .u-form select { padding:7px 9px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; }
  .badge-ruolo { font-size:.7rem; font-weight:800; padding:2px 7px; border-radius:6px; color:#fff; }
  .r-comando { background:#5b2c83; } .r-admin { background:#1e6091; } .r-user { background:#5b6b7b; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars(turnoCorrente() ?: turnoAttivo()) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars(turnoCorrente() ?: turnoAttivo()) ?></div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"  class="nav-btn">🚒 Home</a>
    <?php if (isAdmin()): ?>
    <a href="index.php"     class="nav-btn">⚙️ Amministrazione</a>
    <?php endif; ?>
    <a href="utenti.php"    class="nav-btn active">👤 Utenti</a>
    <a href="../logout.php" class="nav-btn ml-auto">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>👤 Gestione utenti</h2>
    <?php if (isAdmin()): ?>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
    <?php endif; ?>
  </div>

  <?php if (!isComando()): ?>
  <p style="color:var(--grigio-md);margin:0 0 14px">
    Da qui puoi reimpostare la password dei profili
    <strong><?= implode(' e ', $RUOLI_PASSWORD) ?></strong>.
    Creazione utenti, ruoli e permessi per turno li gestisce il Comando.
  </p>
  <?php endif; ?>

  <?php if (isComando()): ?>
  <div class="card">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica utente' : '➕ Nuovo utente' ?></div>
    <div style="padding:14px">
      <form method="POST" action="utenti.php" class="u-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="id" value="<?= (int)($rigaEdit['id'] ?? 0) ?>">
        <div class="ff">
          <label>Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($rigaEdit['username'] ?? '') ?>" required>
        </div>
        <div class="ff">
          <label>Nome (descrizione)</label>
          <input type="text" name="nome" value="<?= htmlspecialchars($rigaEdit['nome'] ?? '') ?>">
        </div>
        <div class="ff">
          <label>Ruolo</label>
          <select name="ruolo">
            <?php foreach ($RUOLI as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($rigaEdit['ruolo'] ?? 'user') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff" style="flex:1 1 100%">
          <label>Permessi per turno &nbsp;<span style="font-weight:600;text-transform:none;color:var(--grigio-md)">(il ruolo Comando ha comunque tutto)</span></label>
          <div style="display:flex;gap:14px;flex-wrap:wrap">
            <?php foreach ($TURNI as $t): $liv = $editGrants[$t] ?? ''; ?>
              <div style="display:flex;flex-direction:column;gap:3px">
                <span style="font-size:.72rem;font-weight:800;color:var(--grigio-sc);text-align:center">Turno <?= $t ?></span>
                <select name="perm[<?= $t ?>]">
                  <option value=""          <?= $liv===''          ? 'selected':'' ?>>—</option>
                  <option value="lettura"   <?= $liv==='lettura'   ? 'selected':'' ?>>👁 Lettura</option>
                  <option value="scrittura" <?= $liv==='scrittura' ? 'selected':'' ?>>✏️ Scrittura</option>
                </select>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="ff">
          <label><?= $rigaEdit ? 'Nuova password (vuoto = invariata)' : 'Password' ?></label>
          <input type="text" name="password" placeholder="<?= $rigaEdit ? 'lascia vuoto' : 'min 6 caratteri' ?>">
        </div>
        <div class="ff">
          <label>Attivo</label>
          <input type="checkbox" name="attivo" value="1" <?= (!$rigaEdit || $rigaEdit['attivo']) ? 'checked' : '' ?>>
        </div>
        <div class="ff">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva' : 'Crea utente' ?></button>
        </div>
        <?php if ($rigaEdit): ?>
          <div class="ff"><a href="utenti.php" class="btn btn-grigio">Annulla</a></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead>
        <tr><th>Username</th><th>Nome</th><th>Ruolo</th><th>Permessi turni</th><th>Stato</th><th style="text-align:center">Azioni</th></tr>
      </thead>
      <tbody>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
            <td><?= htmlspecialchars($r['nome'] ?? '') ?></td>
            <td><span class="badge-ruolo r-<?= htmlspecialchars($r['ruolo']) ?>"><?= htmlspecialchars(strtoupper($r['ruolo'])) ?></span></td>
            <td style="font-size:.82rem">
              <?php if ($r['ruolo'] === 'comando'): ?>
                <span style="color:#5b2c83;font-weight:700">Tutti (R/W)</span>
              <?php else:
                $gs = $grantsById[(int)$r['id']] ?? [];
                if (!$gs): ?><span style="color:var(--grigio-md)">—</span><?php
                else: foreach ($TURNI as $t): if (isset($gs[$t])): ?><span title="<?= $gs[$t] ?>" style="margin-right:7px;font-weight:700"><?= $t ?><?= $gs[$t]==='scrittura'?'✏️':'👁' ?></span><?php endif; endforeach; endif;
              endif; ?>
            </td>
            <td><?= $r['attivo'] ? '● Attivo' : '○ Disattivato' ?></td>
            <td>
              <div class="azioni">
                <?php if (isComando()): ?>
                <a href="utenti.php?modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <?php if ((int)$r['id'] !== (int)utenteCorrente()['id']): ?>
                <form method="POST" action="utenti.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare l\'utente <?= htmlspecialchars(addslashes($r['username'])) ?>?', {titolo:'Elimina utente', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <form method="POST" action="utenti.php" style="display:flex;gap:6px;justify-content:center"
                      onsubmit="return confermaSubmit(this, 'Reimpostare la password di <?= htmlspecialchars(addslashes($r['username'])) ?>?', {titolo:'Nuova password', okLabel:'🔑 Imposta'})">
                  <input type="hidden" name="azione" value="password">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="text" name="password" placeholder="nuova password" required minlength="6"
                         style="padding:6px 8px;border:1px solid #d5d8dc;border-radius:6px;font-size:.82rem;width:140px">
                  <button type="submit" class="btn btn-grigio btn-sm">🔑 Imposta</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
