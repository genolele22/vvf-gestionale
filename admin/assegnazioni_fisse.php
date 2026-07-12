<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
$pdo     = getDB();
$TURNO   = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)
$errore  = '';
$sucesso = '';

// Tabella regole di assegnazione fissa. Creata al volo se manca.
$pdo->exec("CREATE TABLE IF NOT EXISTS assegnazioni_fisse (
    id           INT UNSIGNED NOT NULL,
    vigile_id    INT UNSIGNED NOT NULL,
    posizione_id SMALLINT UNSIGNED NOT NULL,
    tipo_turno   VARCHAR(2) NOT NULL DEFAULT 'DN',
    PRIMARY KEY (id),
    UNIQUE KEY uq_af (vigile_id, posizione_id, tipo_turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$TURNI = ['DN' => 'Diurno + Notturno', 'D' => 'Solo diurno', 'N' => 'Solo notturno'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vietaSeSolaLetturaTurno($TURNO);   // niente modifiche se il turno di Amministrazione è in sola lettura
    $azione = $_POST['azione'] ?? '';
    if ($azione === 'aggiungi') {
        $vigileId = (int)($_POST['vigile_id'] ?? 0);
        $posId    = (int)($_POST['posizione_id'] ?? 0);
        $turno    = $_POST['tipo_turno'] ?? 'DN';
        if (!isset($TURNI[$turno])) $turno = 'DN';
        if ($vigileId <= 0 || $posId <= 0) {
            $errore = 'Seleziona vigile e posizione.';
        } else {
            try {
                $newId = nextId($pdo, 'assegnazioni_fisse');
                $pdo->prepare("INSERT INTO assegnazioni_fisse (id, vigile_id, posizione_id, tipo_turno) VALUES (?,?,?,?)")
                    ->execute([$newId, $vigileId, $posId, $turno]);
                $sucesso = 'Regola aggiunta.';
            } catch (Throwable $e) {
                $errore = (strpos($e->getMessage(), 'uq_af') !== false)
                    ? 'Questa regola esiste già.'
                    : 'Errore: ' . $e->getMessage();
            }
        }
    } elseif ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM assegnazioni_fisse WHERE id=?")->execute([$id]);
            $sucesso = 'Regola eliminata.';
        }
    }
}

// Liste per le tendine
$vigili = $pdo->query(
    "SELECT id, cognome, nome, disambiguatore FROM vigili WHERE attivo=1 AND turno='$TURNO' ORDER BY cognome, nome"
)->fetchAll();
$posizioni = $pdo->query(
    "SELECT p.id, p.codice, p.nome, s.codice AS sede_codice
     FROM posizioni p JOIN sedi s ON s.id = p.sede_id
     ORDER BY s.ordine, s.codice, p.ordine, p.codice"
)->fetchAll();

// Regole esistenti con join leggibili
$righe = $pdo->query(
    "SELECT af.id, af.tipo_turno,
            v.cognome, v.nome, v.disambiguatore,
            p.codice AS pos_codice, p.nome AS pos_nome, s.codice AS sede_codice
     FROM assegnazioni_fisse af
     JOIN vigili    v ON v.id = af.vigile_id
     JOIN posizioni p ON p.id = af.posizione_id
     JOIN sedi      s ON s.id = p.sede_id
     WHERE v.turno = '$TURNO'
     ORDER BY v.cognome, v.nome"
)->fetchAll();

function nomeVigile(array $r): string {
    $n = ucfirst(strtolower($r['cognome']));
    if (!empty($r['disambiguatore'])) $n .= ' ' . (int)$r['disambiguatore'];
    if (!empty($r['nome'])) $n .= ' ' . $r['nome'];
    return $n;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Assegnazioni fisse</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .af-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
  .af-form .ff { display:flex; flex-direction:column; gap:3px; }
  .af-form label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--grigio-md); }
  .af-form select { padding:7px 9px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; min-width:200px; }
  .badge-turno { background:#eef1f4; border-radius:5px; padding:2px 8px; font-size:.78rem; font-weight:600; }
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
    <a href="../index.php"        class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
    <span style="margin-left:auto"><?= turnoComandoHtml() ?></span>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>📌 Assegnazioni fisse</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <p style="color:var(--grigio-md);margin:0 0 12px;font-size:.85rem">
    Regole di posizione ricorrente per un vigile. Servono a pre-caricare i fogli
    (l'applicazione automatica sul foglio è il passo successivo).
  </p>

  <div class="card">
    <div class="card-head">➕ Nuova regola</div>
    <div style="padding:14px">
      <form method="POST" action="assegnazioni_fisse.php" class="af-form">
        <input type="hidden" name="azione" value="aggiungi">
        <div class="ff">
          <label>Vigile</label>
          <select name="vigile_id">
            <option value="">— seleziona —</option>
            <?php foreach ($vigili as $v): ?>
              <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(nomeVigile($v)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff">
          <label>Posizione</label>
          <select name="posizione_id">
            <option value="">— seleziona —</option>
            <?php foreach ($posizioni as $p): ?>
              <option value="<?= (int)$p['id'] ?>">
                <?= htmlspecialchars($p['sede_codice'] . ' · ' . $p['codice'] . ($p['nome'] ? ' (' . $p['nome'] . ')' : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff">
          <label>Turno</label>
          <select name="tipo_turno">
            <?php foreach ($TURNI as $k => $lbl): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff"><button type="submit" class="btn btn-rosso">Aggiungi</button></div>
      </form>
    </div>
  </div>

  <div class="tabella-wrap" style="margin-top:16px">
    <table>
      <thead><tr><th>Vigile</th><th>Posizione</th><th>Turno</th><th style="text-align:center">Azioni</th></tr></thead>
      <tbody>
        <?php if (empty($righe)): ?>
          <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--grigio-md)">Nessuna regola.</td></tr>
        <?php endif; ?>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars(nomeVigile($r)) ?></strong></td>
            <td><?= htmlspecialchars($r['sede_codice'] . ' · ' . $r['pos_codice']) ?>
                <?php if ($r['pos_nome']): ?><span style="color:var(--grigio-md);font-size:.8rem"> (<?= htmlspecialchars($r['pos_nome']) ?>)</span><?php endif; ?></td>
            <td><span class="badge-turno"><?= htmlspecialchars($TURNI[$r['tipo_turno']] ?? $r['tipo_turno']) ?></span></td>
            <td>
              <div class="azioni" style="justify-content:center">
                <form method="POST" action="assegnazioni_fisse.php"
                      onsubmit="return confermaSubmit(this, 'Eliminare questa regola?', {titolo:'Elimina regola', okLabel:'🗑️ Elimina'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️ Elimina</button>
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
