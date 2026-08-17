<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
$pdo   = getDB();
$TURNO = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)

// ── Pausa bot (flag condiviso 'bot_pausa' nella tabella parametri) ───────────
// Quando è ON il bot Telegram rifiuta nuove richieste (ferie e scambi salto):
// utile per testare il gestionale senza che le richieste reali "sporchino" i dati.
$pdo->exec("CREATE TABLE IF NOT EXISTS parametri (
    id INT NOT NULL,
    chiave VARCHAR(60) NOT NULL,
    valore TEXT,
    descrizione VARCHAR(200),
    PRIMARY KEY (id),
    UNIQUE KEY uq_chiave (chiave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function botPausaAttiva(PDO $pdo): bool {
    try {
        $v = $pdo->query("SELECT valore FROM parametri WHERE chiave='bot_pausa' LIMIT 1")->fetchColumn();
        return in_array(strtolower(trim((string)$v)), ['1','true','on','si','sì'], true);
    } catch (Throwable $e) { return false; }
}

$msgPausa = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'toggle_pausa') {
    $nuovo = botPausaAttiva($pdo) ? '0' : '1';
    try {
        $st = $pdo->prepare("UPDATE parametri SET valore=? WHERE chiave='bot_pausa'");
        $st->execute([$nuovo]);
        if ($st->rowCount() === 0) {
            $newId = nextId($pdo, 'parametri');
            $pdo->prepare("INSERT INTO parametri (id, chiave, valore, descrizione)
                           VALUES (?, 'bot_pausa', ?, 'Se 1 il bot Telegram rifiuta nuove richieste')")
                ->execute([$newId, $nuovo]);
        }
        $msgPausa = $nuovo === '1' ? 'Bot in pausa: le richieste Telegram sono sospese.'
                                   : 'Bot riattivato: le richieste Telegram sono di nuovo accettate.';
    } catch (Throwable $e) {
        $msgPausa = 'Errore nel cambio stato: ' . $e->getMessage();
    }
}
$botPausa = botPausaAttiva($pdo);

// Conteggi rapidi per le card (best-effort: se una tabella manca, 0)
function contaTab(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
$nSedi    = contaTab($pdo, "SELECT COUNT(*) FROM sedi");
$nPos     = contaTab($pdo, "SELECT COUNT(*) FROM posizioni");
// Le regole di composizione sono per-turno (via il turno del vigile).
$nAssFix  = contaTab($pdo, "SELECT COUNT(*) FROM assegnazioni_fisse af JOIN vigili v ON v.id=af.vigile_id WHERE v.turno='$TURNO'");
$nParam   = contaTab($pdo, "SELECT COUNT(*) FROM parametri");
$nCapi    = contaTab($pdo, "SELECT COUNT(*) FROM capi_pool cp JOIN vigili v ON v.id=cp.vigile_id WHERE v.turno='$TURNO'");
$nFur     = contaTab($pdo, "SELECT COUNT(*) FROM furieri_fissi ff JOIN vigili v ON v.id=ff.vigile_id WHERE v.turno='$TURNO'");
$nRegole  = contaTab($pdo, "SELECT COUNT(*) FROM regole_squadra WHERE attiva=1 AND turno='$TURNO'");
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Amministrazione</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<style>
  .admin-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; margin-top:8px; }
  .admin-card { display:block; background:var(--bianco); border:1px solid #e4e7ea; border-radius:10px;
                padding:18px 20px; text-decoration:none; color:inherit; box-shadow:var(--shadow);
                transition:border-color .15s, box-shadow .15s, transform .1s; }
  .admin-card:hover { border-color:var(--rosso); box-shadow:0 4px 14px rgba(0,0,0,.1); transform:translateY(-1px); }
  .admin-card .ac-ico { font-size:1.8rem; }
  .admin-card h3 { margin:6px 0 4px; font-size:1.05rem; }
  .admin-card p  { margin:0; color:var(--grigio-md); font-size:.82rem; line-height:1.35; }
  .admin-card .ac-meta { margin-top:10px; font-size:.74rem; color:var(--grigio-md); font-weight:600; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno <?= htmlspecialchars($TURNO) ?></p>
    </div>
    <div class="header-badge">TURNO&nbsp;<?= htmlspecialchars($TURNO) ?></div>
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
    <h2>⚙️ Amministrazione</h2>
  </div>
  <p style="color:var(--grigio-md);margin:0 0 14px">
    Dati fissi del gestionale: anagrafiche di sistema, assegnazioni ricorrenti e parametri.
  </p>

  <!-- Interruttore pausa bot Telegram -->
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;
              background:<?= $botPausa ? '#fdecea' : '#eafaf1' ?>;
              border:1px solid <?= $botPausa ? '#e6b0aa' : '#abebc6' ?>;
              border-radius:10px;padding:14px 18px;margin-bottom:18px">
    <div style="font-size:1.6rem"><?= $botPausa ? '⏸️' : '🤖' ?></div>
    <div style="flex:1;min-width:220px">
      <div style="font-weight:700;font-size:1rem">
        Bot Telegram: <?= $botPausa ? '<span style="color:#c0392b">IN PAUSA</span>'
                                    : '<span style="color:#1e8449">ATTIVO</span>' ?>
      </div>
      <div style="color:var(--grigio-md);font-size:.82rem">
        <?= $botPausa
            ? 'I vigili NON possono inviare richieste (ferie e scambi salto). Riattivalo per riaprirle.'
            : 'I vigili possono inviare richieste. Mettilo in pausa per testare senza richieste reali.' ?>
      </div>
    </div>
    <form method="POST" action="index.php" style="margin:0">
      <input type="hidden" name="azione" value="toggle_pausa">
      <button type="submit" class="btn btn-sm"
              style="background:<?= $botPausa ? 'var(--verde)' : '#c0392b' ?>;color:#fff;white-space:nowrap">
        <?= $botPausa ? '▶️ Riattiva bot' : '⏸️ Metti in pausa' ?>
      </button>
    </form>
  </div>

  <div class="admin-grid">

    <a href="utenti.php" class="admin-card">
      <div class="ac-ico">👤</div>
      <h3>Gestione utenti</h3>
      <p><?= isComando()
          ? 'Account di accesso: comando, admin e user per turno. Crea utenti, assegna ruolo/turno, reimposta password.'
          : 'Reimposta in autonomia la password dei profili admin e user, senza passare dal Comando.' ?></p>
      <div class="ac-meta"><?= contaTab($pdo, "SELECT COUNT(*) FROM utenti") ?> utenti</div>
    </a>

    <a href="anagrafiche.php" class="admin-card">
      <div class="ac-ico">🗂️</div>
      <h3>Anagrafiche di sistema</h3>
      <p>Sedi, mezzi/posizioni, qualifiche, salti turno, tipi assenza, patenti, abilitazioni.</p>
      <div class="ac-meta"><?= $nSedi ?> sedi · <?= $nPos ?> posizioni</div>
    </a>

    <a href="assegnazioni_fisse.php" class="admin-card">
      <div class="ac-ico">📌</div>
      <h3>Assegnazioni fisse</h3>
      <p>Personale che va sempre in una certa posizione, da pre-caricare sui fogli.</p>
      <div class="ac-meta"><?= $nAssFix ?> regole</div>
    </a>

    <a href="regole_squadra.php" class="admin-card">
      <div class="ac-ico">🧩</div>
      <h3>Criteri squadra</h3>
      <p>Chi (per grado/patente/abilitazione) va in quale posizione in Centrale al nuovo foglio o dopo reset.</p>
      <div class="ac-meta"><?= $nRegole ?> regole attive</div>
    </a>

    <a href="ruoli.php" class="admin-card">
      <div class="ac-ico">👷</div>
      <h3>Capi servizio &amp; Furieri</h3>
      <p>Pool ordinato per capo/vice (primo presente = capo) e set fisso dei furieri.</p>
      <div class="ac-meta"><?= $nCapi ?> nel pool · <?= $nFur ?> furieri</div>
    </a>

    <a href="stile_patenti.php" class="admin-card">
      <div class="ac-ico">🎨</div>
      <h3>Stile patenti</h3>
      <p>Come indicare la patente sul foglio e sull'ODT del tuo turno: colore, numero accanto al nome, o entrambi.</p>
      <div class="ac-meta">per turno</div>
    </a>

    <?php if (isComando()): ?>
    <a href="parametri.php" class="admin-card">
      <div class="ac-ico">⚙️</div>
      <h3>Parametri di sistema</h3>
      <p>Posta del bot (indirizzi, SMTP/IMAP), formato nomi sul foglio e parametri liberi.</p>
      <div class="ac-meta"><?= $nParam ?> parametri</div>
    </a>
    <?php endif; ?>

    <a href="ferie_simulate.php" class="admin-card">
      <div class="ac-ico">🏖️</div>
      <h3>Caricamento ferie e permessi <span style="background:#b9770e;color:#fff;font-size:.6rem;font-weight:800;padding:1px 6px;border-radius:5px;vertical-align:middle;">BETA</span></h3>
      <p>Carica ferie (estive / agenda cartacea) e permessi (giornalieri o orari) come richieste bot: voce in Agenda + assenza/annotazione sul foglio giusto. Niente mail.</p>
      <div class="ac-meta">strumento interno di test</div>
    </a>

    <a href="assenze_simulate.php" class="admin-card">
      <div class="ac-ico">🚑</div>
      <h3>Caricamento assenze <span style="background:#b9770e;color:#fff;font-size:.6rem;font-weight:800;padding:1px 6px;border-radius:5px;vertical-align:middle;">BETA</span></h3>
      <p>Carica missione/malattia/infortunio come richieste bot: voce in Agenda (già approvata) + assenza sul foglio giusto. Niente mail.</p>
      <div class="ac-meta">strumento interno di test</div>
    </a>

  </div>
</main>
<?php require __DIR__ . '/../includes/logbook_widget.php'; ?>
</body>
</html>
