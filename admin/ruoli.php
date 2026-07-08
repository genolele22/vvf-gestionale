<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediAdmin();
$pdo     = getDB();
$TURNO   = turnoAmministrazione();   // fisso sul turno di casa (admin/user niente switch qui)
$errore  = '';
$sucesso = '';

// Tabelle (create al volo se mancano).
$pdo->exec("CREATE TABLE IF NOT EXISTS capi_pool (
    id        INT UNSIGNED NOT NULL,
    vigile_id INT UNSIGNED NOT NULL,
    ordine    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_capo (vigile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS furieri_fissi (
    id        INT UNSIGNED NOT NULL,
    vigile_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fur (vigile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vietaSeSolaLetturaTurno($TURNO);   // niente modifiche se il turno di Amministrazione è in sola lettura
    $azione = $_POST['azione'] ?? '';
    try {
        if ($azione === 'capo_add') {
            $vid = (int)($_POST['vigile_id'] ?? 0);
            if ($vid <= 0) { $errore = 'Seleziona un vigile.'; }
            else {
                $newId = nextId($pdo, 'capi_pool');
                $ord   = (int)$pdo->query("SELECT COALESCE(MAX(ordine),0)+1 FROM capi_pool")->fetchColumn();
                $pdo->prepare("INSERT INTO capi_pool (id, vigile_id, ordine) VALUES (?,?,?)")->execute([$newId, $vid, $ord]);
                $sucesso = 'Aggiunto al pool capi/vice.';
            }
        } elseif ($azione === 'capo_del') {
            $pdo->prepare("DELETE FROM capi_pool WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Rimosso dal pool.';
        } elseif ($azione === 'capo_su' || $azione === 'capo_giu') {
            // Scambia l'ordine con il vicino (sopra o sotto)
            $id   = (int)($_POST['id'] ?? 0);
            $righe = $pdo->query("SELECT id, ordine FROM capi_pool ORDER BY ordine, id")->fetchAll();
            $pos  = null;
            foreach ($righe as $i => $r) if ((int)$r['id'] === $id) { $pos = $i; break; }
            if ($pos !== null) {
                $j = $azione === 'capo_su' ? $pos - 1 : $pos + 1;
                if ($j >= 0 && $j < count($righe)) {
                    $a = $righe[$pos]; $b = $righe[$j];
                    $up = $pdo->prepare("UPDATE capi_pool SET ordine=? WHERE id=?");
                    $up->execute([(int)$b['ordine'], (int)$a['id']]);
                    $up->execute([(int)$a['ordine'], (int)$b['id']]);
                }
            }
        } elseif ($azione === 'fur_add') {
            $vid = (int)($_POST['vigile_id'] ?? 0);
            if ($vid <= 0) { $errore = 'Seleziona un vigile.'; }
            else {
                $newId = nextId($pdo, 'furieri_fissi');
                $pdo->prepare("INSERT INTO furieri_fissi (id, vigile_id) VALUES (?,?)")->execute([$newId, $vid]);
                $sucesso = 'Aggiunto ai furieri fissi.';
            }
        } elseif ($azione === 'fur_del') {
            $pdo->prepare("DELETE FROM furieri_fissi WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Rimosso dai furieri fissi.';
        }
    } catch (Throwable $e) {
        $errore = (strpos($e->getMessage(), 'uq_') !== false)
            ? 'Questo vigile è già nell\'elenco.'
            : 'Errore: ' . $e->getMessage();
    }
}

// Liste
function nomeVig(array $r): string {
    $n = ucfirst(strtolower($r['cognome']));
    if (!empty($r['disambiguatore'])) $n .= ' ' . (int)$r['disambiguatore'];
    if (!empty($r['nome'])) $n .= ' ' . $r['nome'];
    return $n;
}
// Per il pool capi/vice: solo Capo Reparto e Capo Squadra (Cr, Cs)
$capiSel = $pdo->query(
    "SELECT v.id, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice
     FROM vigili v JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.attivo=1 AND q.codice IN ('Cr','Cs') AND v.turno='$TURNO' ORDER BY v.cognome, v.nome"
)->fetchAll();
$tuttiSel = $pdo->query(
    "SELECT v.id, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice
     FROM vigili v JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.attivo=1 AND v.turno='$TURNO' ORDER BY v.cognome, v.nome"
)->fetchAll();

$poolRows = $pdo->query(
    "SELECT cp.id, cp.ordine, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice
     FROM capi_pool cp JOIN vigili v ON v.id = cp.vigile_id JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.turno='$TURNO' ORDER BY cp.ordine, cp.id"
)->fetchAll();
$furRows = $pdo->query(
    "SELECT ff.id, v.cognome, v.nome, v.disambiguatore, q.codice AS qcodice
     FROM furieri_fissi ff JOIN vigili v ON v.id = ff.vigile_id JOIN qualifiche q ON q.id = v.qualifica_id
     WHERE v.turno='$TURNO' ORDER BY v.cognome, v.nome"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Capi servizio & Furieri</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
<style>
  .due-col { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:16px; }
  .ruolo-add { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
  .ruolo-add select { padding:7px 9px; border:1px solid #d5d8dc; border-radius:6px; font-size:.85rem; min-width:220px; }
  .ord-badge { display:inline-block; min-width:22px; text-align:center; background:var(--rosso); color:#fff;
               border-radius:5px; font-size:.74rem; font-weight:700; padding:2px 6px; }
  .ruolo-note { font-size:.8rem; color:var(--grigio-md); margin:2px 0 10px; }
  .qd { color:var(--grigio-md); font-size:.78rem; font-weight:600; }
</style>
</head>
<body>
<header class="header">
  <div class="header-inner">
    <div class="header-logo">🚒</div>
    <div class="header-testi">
      <h1>Comando Provinciale VVF di Genova</h1>
      <p>Gestionale Foglio di Servizio &mdash; Turno B</p>
    </div>
    <div class="header-badge">TURNO&nbsp;B</div>
  </div>
</header>
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="../vigili/lista.php" class="nav-btn">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="index.php"           class="nav-btn active">⚙️ Amministrazione</a>
  </div>
</nav>
<main class="main">
  <div class="page-title">
    <h2>👷 Capi servizio & Furieri</h2>
    <a href="index.php" class="btn btn-grigio">← Amministrazione</a>
  </div>

  <?php if ($sucesso): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
  <?php if ($errore):  ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <div class="due-col">

    <!-- POOL CAPO / VICE -->
    <div>
      <div class="card">
        <div class="card-head">🎖️ Pool Capo servizio / Vice</div>
        <div style="padding:14px">
          <p class="ruolo-note">In ordine di priorità. Sul foglio: <b>capo</b> = primo presente in servizio, <b>vice</b> = secondo. Solo Cr/Cs.</p>
          <form method="POST" action="ruoli.php" class="ruolo-add">
            <input type="hidden" name="azione" value="capo_add">
            <select name="vigile_id">
              <option value="">— seleziona Cr/Cs —</option>
              <?php foreach ($capiSel as $v): ?>
                <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['qcodice'].' '.nomeVig($v)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-rosso">Aggiungi</button>
          </form>
        </div>
      </div>
      <div class="tabella-wrap" style="margin-top:12px">
        <table>
          <thead><tr><th>#</th><th>Nominativo</th><th style="text-align:center">Ordine / Azioni</th></tr></thead>
          <tbody>
            <?php if (empty($poolRows)): ?>
              <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--grigio-md)">Pool vuoto.</td></tr>
            <?php endif; ?>
            <?php foreach ($poolRows as $i => $r): ?>
              <tr>
                <td><span class="ord-badge"><?= $i + 1 ?></span></td>
                <td><strong><?= htmlspecialchars(nomeVig($r)) ?></strong> <span class="qd"><?= htmlspecialchars($r['qcodice']) ?></span>
                    <?php if ($i === 0): ?> <span class="qd">→ capo</span><?php elseif ($i === 1): ?> <span class="qd">→ vice</span><?php endif; ?></td>
                <td>
                  <div class="azioni" style="justify-content:center">
                    <form method="POST" action="ruoli.php"><input type="hidden" name="azione" value="capo_su"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-grigio btn-sm" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
                    <form method="POST" action="ruoli.php"><input type="hidden" name="azione" value="capo_giu"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-grigio btn-sm" <?= $i === count($poolRows)-1 ? 'disabled' : '' ?>>↓</button></form>
                    <form method="POST" action="ruoli.php" onsubmit="return confermaSubmit(this, 'Rimuovere dal pool?', {titolo:'Rimuovi', okLabel:'🗑️ Rimuovi'})"><input type="hidden" name="azione" value="capo_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️</button></form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- FURIERI FISSI -->
    <div>
      <div class="card">
        <div class="card-head">📒 Furieri fissi</div>
        <div style="padding:14px">
          <p class="ruolo-note">Set fisso: sul foglio compaiono quelli <b>presenti in servizio</b> (non in ferie/salto).</p>
          <form method="POST" action="ruoli.php" class="ruolo-add">
            <input type="hidden" name="azione" value="fur_add">
            <select name="vigile_id">
              <option value="">— seleziona —</option>
              <?php foreach ($tuttiSel as $v): ?>
                <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['qcodice'].' '.nomeVig($v)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-rosso">Aggiungi</button>
          </form>
        </div>
      </div>
      <div class="tabella-wrap" style="margin-top:12px">
        <table>
          <thead><tr><th>Nominativo</th><th style="text-align:center">Azioni</th></tr></thead>
          <tbody>
            <?php if (empty($furRows)): ?>
              <tr><td colspan="2" style="text-align:center;padding:20px;color:var(--grigio-md)">Nessun furiere fisso.</td></tr>
            <?php endif; ?>
            <?php foreach ($furRows as $r): ?>
              <tr>
                <td><strong><?= htmlspecialchars(nomeVig($r)) ?></strong> <span class="qd"><?= htmlspecialchars($r['qcodice']) ?></span></td>
                <td>
                  <div class="azioni" style="justify-content:center">
                    <form method="POST" action="ruoli.php" onsubmit="return confermaSubmit(this, 'Rimuovere dai furieri fissi?', {titolo:'Rimuovi', okLabel:'🗑️ Rimuovi'})"><input type="hidden" name="azione" value="fur_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" style="background:var(--rosso);color:#fff">🗑️ Rimuovi</button></form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>
</body>
</html>
