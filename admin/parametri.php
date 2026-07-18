<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediComando();   // config di sistema: mail del bot + formato foglio (tutti i turni)
$pdo     = getDB();
$errore  = '';
$sucesso = '';

// Tabella parametri (chiave/valore). Creata al volo se manca (no AUTO_INCREMENT su TiDB).
$pdo->exec("CREATE TABLE IF NOT EXISTS parametri (
    id          INT UNSIGNED NOT NULL,
    chiave      VARCHAR(60)  NOT NULL,
    valore      TEXT,
    descrizione VARCHAR(160) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chiave (chiave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/** Upsert di un parametro per chiave (crea l'id se nuovo — no AUTO_INCREMENT su TiDB). */
function setParam(PDO $pdo, string $chiave, string $valore, ?string $descr = null): void {
    $st = $pdo->prepare("SELECT id FROM parametri WHERE chiave=?");
    $st->execute([$chiave]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE parametri SET valore=?, descrizione=COALESCE(?, descrizione) WHERE id=?")
            ->execute([$valore, $descr, $id]);
    } else {
        $newId = nextId($pdo, 'parametri');
        $pdo->prepare("INSERT INTO parametri (id, chiave, valore, descrizione) VALUES (?,?,?,?)")
            ->execute([$newId, $chiave, $valore, $descr]);
    }
}
function getParam(PDO $pdo, string $chiave, string $default = ''): string {
    $st = $pdo->prepare("SELECT valore FROM parametri WHERE chiave=?");
    $st->execute([$chiave]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'salva_mail') {
        // Indirizzi e server di posta usati dal bot Telegram (fallback .env se vuoto).
        try {
            foreach (['A','B','C','D'] as $t) {
                setParam($pdo, "mail_furiera_richieste_$t", trim($_POST["mail_furiera_richieste_$t"] ?? ''),
                         "Destinatario richieste turno $t (To)");
                setParam($pdo, "mail_furiera_risposte_$t", trim($_POST["mail_furiera_risposte_$t"] ?? ''),
                         "Mittente risposte (conferme/dinieghi) turno $t");
            }
            setParam($pdo, 'smtp_host', trim($_POST['smtp_host'] ?? ''), 'Server SMTP in uscita');
            setParam($pdo, 'smtp_port', trim($_POST['smtp_port'] ?? ''), 'Porta SMTP (es. 587 o 465)');
            setParam($pdo, 'imap_host', trim($_POST['imap_host'] ?? ''), 'Server IMAP (copia in Inviati)');
            setParam($pdo, 'imap_port', trim($_POST['imap_port'] ?? ''), 'Porta IMAP (es. 993)');
            $sucesso = 'Parametri posta salvati. Il bot li userà alla prossima mail (nessun redeploy).';
        } catch (Throwable $e) {
            $errore = 'Errore salvataggio posta: ' . $e->getMessage();
        }

    } elseif ($azione === 'salva_nome') {
        $fmt = $_POST['foglio_formato_nome'] ?? 'standard';
        if (!in_array($fmt, ['standard', 'cognome_maiusc', 'tutto_maiusc'], true)) $fmt = 'standard';
        try {
            setParam($pdo, 'foglio_formato_nome', $fmt, 'Formato dei nomi sul Foglio/ODT');
            $sucesso = 'Formato nome salvato. Vale dal prossimo foglio generato.';
        } catch (Throwable $e) {
            $errore = 'Errore salvataggio formato nome: ' . $e->getMessage();
        }

    } elseif ($azione === 'salva') {   // editor libero (parametri extra)
        $id     = (int)($_POST['id'] ?? 0);
        $chiave = trim($_POST['chiave'] ?? '');
        $valore = trim($_POST['valore'] ?? '');
        $descr  = trim($_POST['descrizione'] ?? '') ?: null;
        if ($chiave === '') {
            $errore = 'La chiave è obbligatoria.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE parametri SET chiave=?, valore=?, descrizione=? WHERE id=?")
                        ->execute([$chiave, $valore, $descr, $id]);
                    $sucesso = 'Parametro aggiornato.';
                } else {
                    $newId = nextId($pdo, 'parametri');
                    $pdo->prepare("INSERT INTO parametri (id, chiave, valore, descrizione) VALUES (?,?,?,?)")
                        ->execute([$newId, $chiave, $valore, $descr]);
                    $sucesso = 'Parametro aggiunto.';
                }
            } catch (Throwable $e) {
                $errore = (strpos($e->getMessage(), 'uq_chiave') !== false)
                    ? 'Esiste già un parametro con questa chiave.'
                    : 'Errore salvataggio: ' . $e->getMessage();
            }
        }
    } elseif ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM parametri WHERE id=?")->execute([$id]);
            $sucesso = 'Parametro eliminato.';
        }
    }
}

// Valori correnti sezioni strutturate
$mailRichTurni = $mailRispTurni = [];
foreach (['A','B','C','D'] as $t) {
    $mailRichTurni[$t] = getParam($pdo, "mail_furiera_richieste_$t");
    $mailRispTurni[$t] = getParam($pdo, "mail_furiera_risposte_$t");
}
$mail = [
    'smtp_host'              => getParam($pdo, 'smtp_host'),
    'smtp_port'              => getParam($pdo, 'smtp_port'),
    'imap_host'              => getParam($pdo, 'imap_host'),
    'imap_port'              => getParam($pdo, 'imap_port'),
];
$formatoNome = getParam($pdo, 'foglio_formato_nome', 'standard');

// Chiavi gestite dalle sezioni strutturate: nascoste dall'editor libero
$chiaviStrutturate = ['mail_furiera_richieste','mail_furiera_risposte',
                      'mail_furiera_richieste_A','mail_furiera_richieste_B','mail_furiera_richieste_C','mail_furiera_richieste_D',
                      'mail_furiera_risposte_A','mail_furiera_risposte_B','mail_furiera_risposte_C','mail_furiera_risposte_D',
                      'smtp_host','smtp_port','imap_host','imap_port','foglio_formato_nome'];

$rigaEdit = null;
if (isset($_GET['modifica'])) {
    $st = $pdo->prepare("SELECT * FROM parametri WHERE id=?");
    $st->execute([(int)$_GET['modifica']]);
    $rigaEdit = $st->fetch() ?: null;
}
$righeTutte = $pdo->query("SELECT * FROM parametri ORDER BY chiave")->fetchAll();
$righe = array_filter($righeTutte, fn($r) => !in_array($r['chiave'], $chiaviStrutturate, true));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Parametri di sistema</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .par-form { display:flex; flex-direction:column; gap:12px; max-width:680px; }
  .par-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .par-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); display:block; margin-bottom:3px; }
  .par-form input, .par-form textarea { width:100%; padding:8px 10px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; font-family:inherit; }
  .par-form textarea { min-height:64px; resize:vertical; }
  .hint { font-size:.75rem; color:var(--grigio-md); margin-top:2px; }
  code.kk { background:#f1f3f5; padding:1px 6px; border-radius:4px; font-size:.82rem; }
  .fmt-opt { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e3e6e9; border-radius:8px; margin-bottom:8px; cursor:pointer; }
  .fmt-opt input { width:auto; }
  .fmt-opt .ex { font-weight:700; color:var(--rosso); margin-left:auto; }
  @media(max-width:640px){ .par-grid{ grid-template-columns:1fr; } }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio</p>
    </div>
    <div class="header-badge">SISTEMA</div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
    <a href="../logbook/index.php" class="nav-btn">📓 Logbook</a>
    <a href="../cambia_password.php" class="nav-btn ml-auto">🔑 Password</a>
    <?= turnoComandoHtml() ?>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>⚙️ Parametri di sistema</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <!-- ── POSTA (bot Telegram) ─────────────────────────────────────────── -->
  <div class="card">
    <div class="card-head">📧 Posta del bot</div>
    <div style="padding:14px">
      <p class="hint" style="margin:0 0 12px">
        Indirizzi e server usati dal bot per le mail di ferie/scambio. Ogni turno gestisce
        in autonomia: le richieste di un vigile <b>arrivano</b> alla casella del suo turno e
        conferme/dinieghi <b>partono</b> dalla stessa. Se un campo è vuoto, il bot usa il
        valore configurato nel sistema (.env). La <b>password</b> di ogni casella resta
        cifrata sull'anagrafica del vigile-fureria, non si tocca qui.
      </p>
      <form method="POST" action="parametri.php" class="par-form">
        <input type="hidden" name="azione" value="salva_mail">
        <label>📥 Destinatario richieste — una casella per turno (To)</label>
        <div class="par-grid">
          <?php foreach (['A','B','C','D'] as $t): ?>
          <div>
            <label>Turno <?= $t ?></label>
            <input type="email" name="mail_furiera_richieste_<?= $t ?>" value="<?= htmlspecialchars($mailRichTurni[$t]) ?>" placeholder="fureria.turno<?= strtolower($t) ?>@vigilfuoco.it">
          </div>
          <?php endforeach; ?>
        </div>
        <label style="margin-top:6px">📤 Mittente risposte (conferme/dinieghi) — una per turno</label>
        <div class="par-grid">
          <?php foreach (['A','B','C','D'] as $t): ?>
          <div>
            <label>Turno <?= $t ?></label>
            <input type="email" name="mail_furiera_risposte_<?= $t ?>" value="<?= htmlspecialchars($mailRispTurni[$t]) ?>" placeholder="fureria.turno<?= strtolower($t) ?>@vigilfuoco.it">
          </div>
          <?php endforeach; ?>
        </div>
        <div class="par-grid">
          <div>
            <label>Server SMTP (uscita)</label>
            <input type="text" name="smtp_host" value="<?= htmlspecialchars($mail['smtp_host']) ?>" placeholder="smtp-s.vigilfuoco.it">
          </div>
          <div>
            <label>Porta SMTP</label>
            <input type="number" name="smtp_port" value="<?= htmlspecialchars($mail['smtp_port']) ?>" placeholder="587">
            <div class="hint">587 = STARTTLS · 465 = SSL diretto.</div>
          </div>
          <div>
            <label>Server IMAP (copia in Inviati)</label>
            <input type="text" name="imap_host" value="<?= htmlspecialchars($mail['imap_host']) ?>" placeholder="imap-s.vigilfuoco.it">
          </div>
          <div>
            <label>Porta IMAP</label>
            <input type="number" name="imap_port" value="<?= htmlspecialchars($mail['imap_port']) ?>" placeholder="993">
          </div>
        </div>
        <div><button type="submit" class="btn btn-rosso">Salva posta</button></div>
      </form>
    </div>
  </div>

  <!-- ── FORMATO NOME sul foglio ──────────────────────────────────────── -->
  <div class="card" style="margin-top:16px">
    <div class="card-head">🔤 Formato nomi sul Foglio</div>
    <div style="padding:14px">
      <p class="hint" style="margin:0 0 12px">
        Come vengono scritti i nomi sul foglio di servizio e nell'ODT. Vale dal
        prossimo foglio generato.
      </p>
      <form method="POST" action="parametri.php" class="par-form">
        <input type="hidden" name="azione" value="salva_nome">
        <?php
          $opts = [
            'standard'       => ['Standard',          'Cs Rossi'],
            'cognome_maiusc' => ['Cognome maiuscolo', 'Cs ROSSI'],
            'tutto_maiusc'   => ['Tutto maiuscolo',   'CS ROSSI'],
          ];
          foreach ($opts as $val => [$lbl, $ex]): ?>
          <label class="fmt-opt">
            <input type="radio" name="foglio_formato_nome" value="<?= $val ?>" <?= $formatoNome === $val ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($lbl) ?></span>
            <span class="ex"><?= htmlspecialchars($ex) ?></span>
          </label>
        <?php endforeach; ?>
        <div><button type="submit" class="btn btn-rosso">Salva formato</button></div>
      </form>
    </div>
  </div>

  <!-- ── EDITOR LIBERO (parametri extra) ──────────────────────────────── -->
  <div class="card" style="margin-top:16px">
    <div class="card-head"><?= $rigaEdit ? '✏️ Modifica parametro libero' : '➕ Altri parametri (avanzato)' ?></div>
    <div style="padding:14px">
      <form method="POST" action="parametri.php" class="par-form">
        <input type="hidden" name="azione" value="salva">
        <input type="hidden" name="id" value="<?= $rigaEdit['id'] ?? 0 ?>">
        <div>
          <label>Chiave (identificativo univoco)</label>
          <input type="text" name="chiave" value="<?= htmlspecialchars($rigaEdit['chiave'] ?? '') ?>"
                 placeholder="es. nome_comando, nota_standard">
        </div>
        <div>
          <label>Valore</label>
          <textarea name="valore" placeholder="Contenuto del parametro…"><?= htmlspecialchars($rigaEdit['valore'] ?? '') ?></textarea>
        </div>
        <div>
          <label>Descrizione (facoltativa)</label>
          <input type="text" name="descrizione" value="<?= htmlspecialchars($rigaEdit['descrizione'] ?? '') ?>"
                 placeholder="A cosa serve questo parametro">
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-rosso"><?= $rigaEdit ? 'Salva modifiche' : 'Aggiungi' ?></button>
          <?php if ($rigaEdit): ?><a href="parametri.php" class="btn btn-grigio">Annulla</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead><tr><th>Chiave</th><th>Valore</th><th>Descrizione</th><th style="text-align:center">Azioni</th></tr></thead>
      <tbody>
        <?php if (empty($righe)): ?>
          <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--grigio-md)">Nessun parametro libero.</td></tr>
        <?php endif; ?>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><code class="kk"><?= htmlspecialchars($r['chiave']) ?></code></td>
            <td style="white-space:pre-wrap"><?= htmlspecialchars($r['valore'] ?? '') ?></td>
            <td style="color:var(--grigio-md);font-size:.82rem"><?= htmlspecialchars($r['descrizione'] ?? '') ?></td>
            <td>
              <div class="azioni">
                <a href="parametri.php?modifica=<?= (int)$r['id'] ?>" class="btn btn-grigio btn-sm">✏️ Modifica</a>
                <form method="POST" action="parametri.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare questo parametro?', {titolo:'Elimina parametro', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️</button>
                </form>
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
