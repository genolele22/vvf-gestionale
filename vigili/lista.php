<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
richiediLogin();

$pdo     = getDB();
$errore  = '';
$sucesso = '';

// Turno fisso sul turno di casa (admin/user niente switch qui); Comando segue
// l'attivo di sessione, cambiabile col selettore in navbar (turnoComandoHtml()).
$TURNO = turnoAmministrazione();

// ── AZIONI POST (solo admin/comando: l'anagrafica si modifica da admin) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    richiediAdmin();
    vietaSeSolaLetturaTurno($TURNO);   // anagrafica modificabile solo se hai scrittura sul turno
    $azione         = $_POST['azione']                  ?? '';
    $cognome        = strtoupper(trim($_POST['cognome'] ?? ''));
    $nome           = trim($_POST['nome']               ?? '');
    $disambiguatore = (int)($_POST['disambiguatore']    ?? 0) ?: null;
    $email          = trim($_POST['email']              ?? '') ?: null;
    $qualifica_id   = (int)($_POST['qualifica_id']      ?? 0);
    $sede_id        = (int)($_POST['sede_id']           ?? 0);
    $salto_id       = (int)($_POST['salto_id']          ?? 0);
    $patenti        = $_POST['patenti']                 ?? [];
    $abilitazioni   = $_POST['abilitazioni']            ?? [];
    $attivo         = isset($_POST['attivo'])           ? 1 : 0;
    $specialista    = isset($_POST['specialista'])      ? 1 : 0;
    $note           = trim($_POST['note']               ?? '');
    // Date scadenza patente / ultima visita medica (#120): vuote → NULL
    $valData        = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$k] ?? '') ? $_POST[$k] : null;
    $patScadenza    = $valData('patente_scadenza');
    $visitaUltima   = $valData('visita_ultima');
    // Cambio turno al vigile: solo Comando può farlo (capita spesso nei trasferimenti
    // tra turni). Per admin/user il vigile resta/nasce sempre nel proprio turno di casa.
    $turnoVigile    = isComando() ? strtoupper(substr((string)($_POST['turno'] ?? ''), 0, 1)) : $TURNO;

    // Coerenza patenti: la 4 presuppone la 3. Se arriva la 4 senza la 3
    // (es. JS bypassato), aggiungo la 3 lato server.
    if (!empty($patenti)) {
        $mapTipo = $pdo->query("SELECT tipo, id FROM patenti")->fetchAll(PDO::FETCH_KEY_PAIR);
        $id3 = $mapTipo['3'] ?? null;
        $id4 = $mapTipo['4'] ?? null;
        $sel = array_map('strval', $patenti);
        if ($id3 && $id4 && in_array((string)$id4, $sel, true) && !in_array((string)$id3, $sel, true)) {
            $patenti[] = $id3;
        }
    }

    // Azioni che lavorano solo sull'id (non richiedono i campi anagrafici):
    // disattiva (soft, reversibile), riattiva, elimina definitivo (hard).
    // Vanno gestite PRIMA della validazione, altrimenti il check "campi
    // obbligatori" le bloccherebbe (la form manda solo azione + id).
    if (in_array($azione, ['elimina', 'riattiva', 'elimina_def'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $turnoTarget = false;
        if ($id > 0) {
            $stT = $pdo->prepare("SELECT turno FROM vigili WHERE id=?");
            $stT->execute([$id]);
            $turnoTarget = $stT->fetchColumn();
        }
        if ($id <= 0 || $turnoTarget === false) {
            $errore = 'Vigile non valido.';
        } elseif (!puoModificareTurno($turnoTarget)) {
            $errore = 'Non puoi modificare un vigile di un altro turno.';
        } elseif ($azione === 'elimina') {
            $pdo->prepare("UPDATE vigili SET attivo=0 WHERE id=?")->execute([$id]);
            $sucesso = 'Vigile disattivato.';
        } elseif ($azione === 'riattiva') {
            $pdo->prepare("UPDATE vigili SET attivo=1 WHERE id=?")->execute([$id]);
            $sucesso = 'Vigile riattivato.';
        } else {
            // elimina_def: cancellazione DEFINITIVA dal DB. Prima tutte le righe
            // figlie con FK senza cascade, poi il vigile (patenti/abilitazioni
            // vanno via in cascade). Tutto in transazione: o tutto o niente.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM salto_override  WHERE vigile_out_id=? OR vigile_in_id=?")->execute([$id, $id]);
                $pdo->prepare("DELETE FROM bot_scambi_salto WHERE vigile_a_id=? OR vigile_b_id=?")->execute([$id, $id]);
                $pdo->prepare("DELETE FROM assegnazioni     WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM assenze          WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM salto_servizio   WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM foglio_furieri   WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM bot_requests     WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM bot_salto        WHERE vigile_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM bot_outbox       WHERE vigile_id=?")->execute([$id]);
                // Riferimenti "attore" sui fogli (non FK): azzerati per pulizia.
                $pdo->prepare("UPDATE fogli_servizio SET capo_servizio_id=NULL WHERE capo_servizio_id=?")->execute([$id]);
                $pdo->prepare("UPDATE fogli_servizio SET vice_capo_id=NULL      WHERE vice_capo_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM vigili WHERE id=?")->execute([$id]);
                $pdo->commit();
                $sucesso = 'Vigile eliminato definitivamente.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errore = 'Impossibile eliminare il vigile: ' . $e->getMessage();
            }
        }
    } elseif ($cognome === '' || $qualifica_id === 0 || $sede_id === 0 || $salto_id === 0) {
        $errore = 'Cognome, qualifica, sede e salto turno sono obbligatori.';
    } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Indirizzo email non valido.';
    } elseif (!in_array($turnoVigile, ['A', 'B', 'C', 'D'], true)) {
        $errore = 'Turno non valido.';
    } else {

        if ($azione === 'inserisci') {
            $vid = nextId($pdo, 'vigili');
            $pdo->prepare(
                "INSERT INTO vigili
                 (id,cognome,nome,disambiguatore,email,qualifica_id,sede_id,salto_id,attivo,specialista,note,turno,patente_scadenza,visita_ultima)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$vid,$cognome,$nome,$disambiguatore,$email,
                        $qualifica_id,$sede_id,$salto_id,$attivo,$specialista,$note,$turnoVigile,
                        $patScadenza,$visitaUltima]);

            foreach ($patenti as $pid) {
                $pdo->prepare(
                    "INSERT INTO vigili_patenti (vigile_id,patente_id) VALUES (?,?)"
                )->execute([$vid,(int)$pid]);
            }
            foreach ($abilitazioni as $aid) {
                $pdo->prepare(
                    "INSERT INTO vigili_abilitazioni (vigile_id,abilitazione_id) VALUES (?,?)"
                )->execute([$vid,(int)$aid]);
            }
            $sucesso = 'Vigile inserito correttamente.';

        } elseif ($azione === 'modifica') {
            $id = (int)($_POST['id'] ?? 0);
            $stT = $pdo->prepare("SELECT turno FROM vigili WHERE id=?");
            $stT->execute([$id]);
            $turnoAttuale = $stT->fetchColumn();

            if ($turnoAttuale === false || !puoModificareTurno($turnoAttuale)) {
                $errore = 'Non puoi modificare un vigile di un altro turno.';
            } else {
                // Il turno si tocca SOLO se Comando (per admin/user $turnoVigile === $TURNO,
                // già quello che ha il vigile: la UPDATE lo riscrive senza cambiarlo).
                $pdo->prepare(
                    "UPDATE vigili
                     SET cognome=?,nome=?,disambiguatore=?,email=?,qualifica_id=?,
                         sede_id=?,salto_id=?,attivo=?,specialista=?,note=?,turno=?,
                         patente_scadenza=?,visita_ultima=?
                     WHERE id=?"
                )->execute([$cognome,$nome,$disambiguatore,$email,
                            $qualifica_id,$sede_id,$salto_id,$attivo,$specialista,$note,$turnoVigile,
                            $patScadenza,$visitaUltima,$id]);

                $pdo->prepare("DELETE FROM vigili_patenti WHERE vigile_id=?")->execute([$id]);
                foreach ($patenti as $pid) {
                    $pdo->prepare(
                        "INSERT INTO vigili_patenti (vigile_id,patente_id) VALUES (?,?)"
                    )->execute([$id,(int)$pid]);
                }

                $pdo->prepare("DELETE FROM vigili_abilitazioni WHERE vigile_id=?")->execute([$id]);
                foreach ($abilitazioni as $aid) {
                    $pdo->prepare(
                        "INSERT INTO vigili_abilitazioni (vigile_id,abilitazione_id) VALUES (?,?)"
                    )->execute([$id,(int)$aid]);
                }
                $sucesso = 'Vigile aggiornato correttamente.';
            }
        }
    }
}

// ── FILTRI ───────────────────────────────────────────────────
$filtroSede  = (int)($_GET['sede']   ?? 0);
$filtroSalto = (int)($_GET['salto']  ?? 0);
$filtroStato = $_GET['stato']        ?? 'attivi';
$filtroTesto = trim($_GET['cerca']   ?? '');

$where  = ['v.turno = ?'];                 // multi-turno: solo il turno in vista
$params = [$TURNO];
if ($filtroStato === 'attivi') { $where[] = 'v.attivo = 1'; }
if ($filtroSede  > 0) { $where[] = 'v.sede_id = ?';  $params[] = $filtroSede;  }
if ($filtroSalto > 0) { $where[] = 'v.salto_id = ?'; $params[] = $filtroSalto; }
if ($filtroTesto !== '') {
    $where[]  = '(v.cognome LIKE ? OR v.nome LIKE ?)';
    $params[] = "%$filtroTesto%";
    $params[] = "%$filtroTesto%";
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtV = $pdo->prepare(
    "SELECT v.*, q.codice AS qcodice, q.nome AS qnome,
            s.nome AS sede_nome, st.codice AS salto_codice
     FROM vigili v
     JOIN qualifiche q   ON q.id  = v.qualifica_id
     JOIN sedi s         ON s.id  = v.sede_id
     JOIN salti_turno st ON st.id = v.salto_id
     $whereStr
     ORDER BY v.cognome ASC, v.disambiguatore ASC"
);
$stmtV->execute($params);
$vigili = $stmtV->fetchAll();

// ── DATI PER I SELECT ────────────────────────────────────────
$qualifiche   = $pdo->query("SELECT * FROM qualifiche ORDER BY id DESC")->fetchAll();
$sedi         = $pdo->query("SELECT * FROM sedi ORDER BY ordine")->fetchAll();
$salti        = $pdo->query("SELECT * FROM salti_turno ORDER BY turno, id")->fetchAll();
$patentiAll   = $pdo->query("SELECT * FROM patenti ORDER BY id")->fetchAll();
$abilitazAll  = $pdo->query("SELECT * FROM abilitazioni ORDER BY id")->fetchAll();

// ── VIGILE IN MODIFICA ───────────────────────────────────────
$vigileEdit    = null;
$patentiEdit   = [];
$abilitazEdit  = [];
if (isset($_GET['modifica'])) {
    $idEdit = (int)$_GET['modifica'];

    $se = $pdo->prepare("SELECT * FROM vigili WHERE id=?");
    $se->execute([$idEdit]);
    $vigileEdit = $se->fetch();

    $sp = $pdo->prepare("SELECT patente_id FROM vigili_patenti WHERE vigile_id=?");
    $sp->execute([$idEdit]);
    $patentiEdit = array_column($sp->fetchAll(), 'patente_id');

    $sa = $pdo->prepare("SELECT abilitazione_id FROM vigili_abilitazioni WHERE vigile_id=?");
    $sa->execute([$idEdit]);
    $abilitazEdit = array_column($sa->fetchAll(), 'abilitazione_id');
}

// ── PREFETCH PATENTI E ABILITAZIONI (una query ciascuna) ─────
$patentiPerVigile   = [];
$abilitazPerVigile  = [];
if (!empty($vigili)) {
    $ids = array_column($vigili, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $sp2 = $pdo->prepare(
        "SELECT vp.vigile_id, p.tipo
         FROM vigili_patenti vp
         JOIN patenti p ON p.id = vp.patente_id
         WHERE vp.vigile_id IN ($in) ORDER BY p.tipo"
    );
    $sp2->execute($ids);
    foreach ($sp2->fetchAll() as $r) {
        $patentiPerVigile[$r['vigile_id']][] = $r['tipo'];
    }

    $sa2 = $pdo->prepare(
        "SELECT va.vigile_id, a.codice
         FROM vigili_abilitazioni va
         JOIN abilitazioni a ON a.id = va.abilitazione_id
         WHERE va.vigile_id IN ($in) ORDER BY a.codice"
    );
    $sa2->execute($ids);
    foreach ($sa2->fetchAll() as $r) {
        $abilitazPerVigile[$r['vigile_id']][] = $r['codice'];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VVF – Gestione Personale</title>
<link rel="stylesheet" href="../assets/css/stile.css?v=<?= @filemtime(__DIR__.'/../assets/css/stile.css') ?>">
<link rel="stylesheet" href="../assets/css/vigili.css?v=<?= @filemtime(__DIR__.'/../assets/css/vigili.css') ?>">
<script defer src="../assets/js/conferma.js?v=<?= @filemtime(__DIR__.'/../assets/js/conferma.js') ?>"></script>
</head>
<body>
<!-- HEADER -->
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
<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🚒 Home</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="lista.php"           class="nav-btn active">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🗓️ Agenda</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <span style="margin-left:auto"><?= turnoComandoHtml() ?></span>
    <a href="../logout.php"       class="nav-btn">🚪 Esci</a>
  </div>
</nav>
<!-- MAIN -->
<main class="main">
  <div class="page-title">
    <h2>👥 Gestione Personale — Turno <?= htmlspecialchars($TURNO) ?></h2>
    <?php if (isAdmin() && !$vigileEdit && !isset($_GET['nuovo'])): ?>
      <a href="lista.php?nuovo=1" class="btn btn-rosso">➕ Nuovo Vigile</a>
    <?php endif; ?>
  </div>
  <!-- ══ FORM INSERIMENTO / MODIFICA (solo admin) ═════════════ -->
  <?php if (isAdmin() && (isset($_GET['nuovo']) || $vigileEdit)): ?>
  <div class="card">
    <div class="card-head">
      <?= $vigileEdit ? '✏️ Modifica Vigile' : '➕ Inserisci Nuovo Vigile' ?>
    </div>
    <div class="card-body">
      <form method="POST" action="lista.php">
        <input type="hidden" name="azione"
               value="<?= $vigileEdit ? 'modifica' : 'inserisci' ?>">
        <?php if ($vigileEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$vigileEdit['id'] ?>">
        <?php endif; ?>
        <div class="form-grid">
          <div class="form-group">
            <label>Qualifica *</label>
            <select name="qualifica_id" required>
              <option value="">— seleziona —</option>
              <?php foreach ($qualifiche as $q): ?>
                <option value="<?= $q['id'] ?>"
                  <?= ($vigileEdit && $vigileEdit['qualifica_id']==$q['id'])
                      ? 'selected' : '' ?>>
                  <?= htmlspecialchars($q['codice'].' — '.$q['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Cognome *</label>
            <input type="text" name="cognome" required
                   placeholder="es. ROSSI"
                   value="<?= htmlspecialchars($vigileEdit['cognome'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Nome</label>
            <input type="text" name="nome"
                   placeholder="es. Mario"
                   value="<?= htmlspecialchars($vigileEdit['nome'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email"
                   placeholder="es. mario.rossi@vigilfuoco.it"
                   value="<?= htmlspecialchars($vigileEdit['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>N° Disambiguatore</label>
            <input type="number" name="disambiguatore" min="1" max="99"
                   placeholder="es. 4 → Romano 4"
                   value="<?= htmlspecialchars(
                       (string)($vigileEdit['disambiguatore'] ?? '')) ?>">
          </div>
          <div class="form-group">
            <label>Sede di assegnazione *</label>
            <select name="sede_id" required>
              <option value="">— seleziona —</option>
              <?php foreach ($sedi as $s): ?>
                <option value="<?= $s['id'] ?>"
                  <?= ($vigileEdit && $vigileEdit['sede_id']==$s['id'])
                      ? 'selected' : '' ?>>
                  <?= htmlspecialchars($s['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (isComando()): ?>
          <div class="form-group">
            <label>Turno *</label>
            <select name="turno" id="turnoSelect" required>
              <?php foreach (['A', 'B', 'C', 'D'] as $t): ?>
                <option value="<?= $t ?>"
                  <?= ($vigileEdit ? $vigileEdit['turno'] : $TURNO) === $t ? 'selected' : '' ?>>
                  Turno <?= $t ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="form-group">
            <label>Salto turno *</label>
            <select name="salto_id" id="saltoSelect" required>
              <option value="">— seleziona —</option>
              <?php foreach ($salti as $st):
                  if (!isComando() && $st['turno'] !== $TURNO) continue;   // admin/user: solo il proprio turno
              ?>
                <option value="<?= $st['id'] ?>" data-turno="<?= htmlspecialchars($st['turno']) ?>"
                  <?= ($vigileEdit && $vigileEdit['salto_id']==$st['id'])
                      ? 'selected' : '' ?>>
                  <?= htmlspecialchars($st['codice']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full">
            <label>Patenti</label>
            <div class="patenti-group" id="patentiGroup">
              <?php foreach ($patentiAll as $p): ?>
                <label class="patente-check">
                  <input type="checkbox" name="patenti[]"
                         value="<?= $p['id'] ?>"
                         data-tipo="<?= htmlspecialchars($p['tipo']) ?>"
                         <?= in_array($p['id'], $patentiEdit) ? 'checked' : '' ?>>
                  Patente <?= htmlspecialchars($p['tipo']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <script>
            // La patente 4 presuppone la 3: spuntando la 4 si spunta la 3;
            // togliendo la 3 si toglie anche la 4.
            (function () {
              var g = document.getElementById('patentiGroup');
              if (!g) return;
              var c3 = g.querySelector('input[data-tipo="3"]');
              var c4 = g.querySelector('input[data-tipo="4"]');
              if (!c3 || !c4) return;
              c4.addEventListener('change', function () { if (c4.checked) c3.checked = true; });
              c3.addEventListener('change', function () { if (!c3.checked) c4.checked = false; });
            })();
            </script>
          </div>
          <div class="form-group">
            <label>Scadenza patente</label>
            <input type="date" name="patente_scadenza"
                   value="<?= htmlspecialchars($vigileEdit['patente_scadenza'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Ultima visita medica</label>
            <input type="date" name="visita_ultima"
                   value="<?= htmlspecialchars($vigileEdit['visita_ultima'] ?? '') ?>">
          </div>
		  <div class="form-group full">
            <label>Abilitazioni speciali</label>
            <div class="abil-group">
              <?php foreach ($abilitazAll as $a): ?>
                <label class="abil-check">
                  <input type="checkbox" name="abilitazioni[]"
                         value="<?= $a['id'] ?>"
                         <?= in_array($a['id'], $abilitazEdit) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($a['codice']) ?>
                  <span style="font-size:.7rem;font-weight:400;color:#999">
                    — <?= htmlspecialchars($a['nome']) ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label>Stato</label>
            <div class="toggle-wrap">
              <label class="toggle">
                <input type="checkbox" name="attivo"
                       id="toggleAttivo" value="1"
                       <?= (!$vigileEdit || $vigileEdit['attivo']) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span id="toggleLabel" style="font-size:.875rem;font-weight:600">
                <?= (!$vigileEdit || $vigileEdit['attivo']) ? 'Attivo' : 'Non attivo' ?>
              </span>
            </div>
          </div>
          <div class="form-group">
            <label>Tipo</label>
            <div class="toggle-wrap">
              <label class="toggle">
                <input type="checkbox" name="specialista" value="1"
                       <?= (!empty($vigileEdit['specialista'])) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span style="font-size:.875rem;font-weight:600">
                Specialista <span style="color:var(--grigio-md);font-weight:400;font-size:.78rem">(conteggiato a parte dagli operativi)</span>
              </span>
            </div>
          </div>
          <div class="form-group full">
            <label>Note / Mansioni speciali</label>
            <textarea name="note"
                      placeholder="Annotazioni, idoneità particolari, ecc."
              ><?= htmlspecialchars($vigileEdit['note'] ?? '') ?></textarea>
          </div>
        </div><!-- /.form-grid -->
        <div style="display:flex;gap:10px;margin-top:24px;flex-wrap:wrap">
          <button type="submit" class="btn btn-rosso">
            <?= $vigileEdit ? '💾 Salva modifiche' : '➕ Inserisci vigile' ?>
          </button>
          <a href="lista.php" class="btn btn-grigio">✖ Annulla</a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <!-- ══ FILTRI ════════════════════════════════════════════════ -->
  <form method="GET" action="lista.php">
    <div class="filtri">
      <div class="form-group">
        <label>🔍 Cerca</label>
        <input type="text" name="cerca"
               placeholder="Cognome o nome…"
               value="<?= htmlspecialchars($filtroTesto) ?>">
      </div>
      <div class="form-group">
        <label>Sede</label>
        <select name="sede">
          <option value="0">Tutte le sedi</option>
          <?php foreach ($sedi as $s): ?>
            <option value="<?= $s['id'] ?>"
              <?= $filtroSede==$s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Salto turno</label>
        <select name="salto">
          <option value="0">Tutti i salti</option>
          <?php foreach ($salti as $st):
              if ($st['turno'] !== $TURNO) continue;   // solo i salti del turno in vista
          ?>
            <option value="<?= $st['id'] ?>"
              <?= $filtroSalto==$st['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($st['codice']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Stato</label>
        <select name="stato">
          <option value="attivi"
            <?= $filtroStato==='attivi' ? 'selected' : '' ?>>
            Solo attivi
          </option>
          <option value="tutti"
            <?= $filtroStato==='tutti' ? 'selected' : '' ?>>
            Tutti (inclusi disattivati)
          </option>
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end">
        <button type="submit" class="btn btn-rosso btn-sm">🔍 Filtra</button>
        <a href="lista.php" class="btn btn-grigio btn-sm">↺ Reset</a>
      </div>
    </div>
  </form>
    <!-- ══ TABELLA ═══════════════════════════════════════════════ -->
  <div class="tabella-wrap">

    <div class="tabella-head">
      <span>👥 Elenco Personale</span>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <select id="filtroTipo" class="th-filtro" title="Operativi / Specialisti">
          <option value="">Tutti i tipi</option>
          <option value="op">Solo operativi</option>
          <option value="spec">Solo specialisti</option>
        </select>
        <details id="filtroComp" class="th-filtro th-filtro-multi" title="Patente / abilitazione (più scelte = chi le ha TUTTE)">
          <summary>Competenze: <span id="compSel">tutte</span></summary>
          <div class="comp-menu">
            <div class="comp-group">Patenti</div>
            <?php foreach (array_reverse($patentiAll) as $p): ?>
              <label><input type="checkbox" class="fcomp" value="P<?= htmlspecialchars($p['tipo']) ?>"> Patente <?= htmlspecialchars($p['tipo']) ?></label>
            <?php endforeach; ?>
            <div class="comp-group">Abilitazioni</div>
            <?php foreach ($abilitazAll as $a): ?>
              <label><input type="checkbox" class="fcomp" value="<?= htmlspecialchars($a['codice']) ?>"> <?= htmlspecialchars($a['codice'] . ' — ' . $a['nome']) ?></label>
            <?php endforeach; ?>
            <button type="button" id="compReset" class="comp-reset">Azzera</button>
          </div>
        </details>
        <span id="contaRecord" style="font-size:.8rem;font-weight:400;opacity:.8">
          <?= count($vigili) ?> record
        </span>
      </div>
    </div>

    <table id="tabellaVigili">
      <thead>
  <tr>
    <th class="sortable" data-col="0">Qualifica</th>
    <th class="sortable" data-col="1" aria-sort="ascending">Cognome / Nome</th>
    <th class="sortable" data-col="2">Sede</th>
    <th class="sortable" data-col="3">Salto</th>
    <th class="sortable" data-col="4">Patenti</th>
    <th class="sortable" data-col="5">Abilitazioni</th>
    <th class="sortable" data-col="6">Scadenze</th>
    <th class="sortable" data-col="7">Stato</th>
    <th style="text-align:center">Azioni</th>
  </tr>
</thead>
      <tbody>

        <?php if (empty($vigili)): ?>
          <tr>
            <td colspan="9"
                style="text-align:center;padding:32px;color:var(--grigio-md)">
              Nessun vigile trovato con i filtri selezionati.
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($vigili as $v):
          $patientiV = $patentiPerVigile[$v['id']] ?? [];
          $abilVsort = $abilitazPerVigile[$v['id']] ?? [];
          $saltoNum  = (int)preg_replace('/\D/', '', $v['salto_codice']);
          $cognKey   = strtolower($v['cognome']) . sprintf('%03d', (int)$v['disambiguatore']);
          // Competenze della riga per il filtro: patenti come "P4 P3", abilitazioni col codice.
          $compRiga  = htmlspecialchars(implode(' ', array_merge(
                          array_map(fn($p) => 'P' . $p, $patientiV),
                          $abilVsort)));
        ?>
        <tr class="<?= $v['attivo'] ? '' : 'disattivo' ?>"
            data-spec="<?= (int)($v['specialista'] ?? 0) ?>"
            data-comp="<?= $compRiga ?>">

          <!-- Qualifica -->
          <td data-sort="<?= (int)$v['qualifica_id'] ?>">
            <span class="badge-qual badge-<?= htmlspecialchars($v['qcodice']) ?>">
              <?= htmlspecialchars(ucfirst(strtolower($v['qcodice']))) ?>
            </span>
          </td>

          <!-- Cognome / Nome -->
          <td data-sort="<?= htmlspecialchars($cognKey) ?>">
            <strong><?= htmlspecialchars(ucfirst(strtolower($v['cognome']))) ?></strong>
            <?php if ($v['disambiguatore']): ?>
              <span style="color:var(--grigio-md);font-size:.8rem">
                <?= (int)$v['disambiguatore'] ?>
              </span>
            <?php endif; ?>
            <?php if (!empty($v['specialista'])): ?>
              <span title="Specialista — conteggiato a parte dagli operativi"
                    style="background:#5b2c83;color:#fff;font-size:.6rem;font-weight:800;
                           padding:1px 5px;border-radius:5px;vertical-align:middle;margin-left:4px">SPEC</span>
            <?php endif; ?>
            <?php if ($v['nome']): ?>
              <br>
              <span style="font-size:.78rem;color:var(--grigio-md)">
                <?= htmlspecialchars($v['nome']) ?>
              </span>
            <?php endif; ?>
            <?php if ($v['note']): ?>
              &nbsp;<span title="<?= htmlspecialchars($v['note']) ?>"
                          style="cursor:help">📝</span>
            <?php endif; ?>
          </td>

          <!-- Sede -->
          <td data-sort="<?= htmlspecialchars(strtolower($v['sede_nome'])) ?>"><?= htmlspecialchars($v['sede_nome']) ?></td>

          <!-- Salto -->
          <td data-sort="<?= $saltoNum ?>">
            <span class="badge-salto">
              <?= htmlspecialchars($v['salto_codice']) ?>
            </span>
          </td>

          <!-- Patenti -->
          <td data-sort="<?= count($patientiV) ?>">
            <?php if (empty($patientiV)): ?>
              <span style="color:#bbb;font-size:.75rem">—</span>
            <?php else: ?>
              <?php foreach ($patientiV as $pt): ?>
                <span class="badge-pat"><?= htmlspecialchars($pt) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>


          <!-- Abilitazioni -->
          <td data-sort="<?= count($abilVsort) ?>">
            <?php
            $abilV = $abilitazPerVigile[$v['id']] ?? [];
            if (empty($abilV)): ?>
              <span style="color:#bbb;font-size:.75rem">—</span>
            <?php else: ?>
              <?php foreach ($abilV as $ab): ?>
                <span class="badge-abil"><?= htmlspecialchars($ab) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>

          <!-- Scadenze (#120): patente con alert 90gg, ultima visita solo informativa -->
          <td data-sort="<?= !empty($v['patente_scadenza']) ? date('Ymd', strtotime($v['patente_scadenza'])) : '99999999' ?>">
            <?php if (!empty($v['patente_scadenza'])):
                $ggMancanti = (int)((strtotime($v['patente_scadenza']) - strtotime(date('Y-m-d'))) / 86400);
                if     ($ggMancanti < 0)   { $scadCol = '#c0392b'; $scadBg = '#fdecea'; $scadTitle = 'Patente SCADUTA'; }
                elseif ($ggMancanti <= 90) { $scadCol = '#b9770e'; $scadBg = '#fef5e7'; $scadTitle = "Patente in scadenza tra $ggMancanti giorni"; }
                else                       { $scadCol = 'var(--grigio-sc)'; $scadBg = 'transparent'; $scadTitle = 'Scadenza patente'; }
            ?>
              <span title="<?= htmlspecialchars($scadTitle) ?>"
                    style="font-size:.75rem;font-weight:700;color:<?= $scadCol ?>;background:<?= $scadBg ?>;
                           border-radius:4px;padding:1px 6px;white-space:nowrap">
                🪪 <?= date('d/m/Y', strtotime($v['patente_scadenza'])) ?><?= $ggMancanti < 0 ? ' ⚠️' : ($ggMancanti <= 90 ? ' ⏳' : '') ?>
              </span><br>
            <?php endif; ?>
            <?php if (!empty($v['visita_ultima'])): ?>
              <span title="Ultima visita medica"
                    style="font-size:.73rem;color:var(--grigio-md);white-space:nowrap">
                🩺 <?= date('d/m/Y', strtotime($v['visita_ultima'])) ?>
              </span>
            <?php endif; ?>
            <?php if (empty($v['patente_scadenza']) && empty($v['visita_ultima'])): ?>
              <span style="color:#bbb;font-size:.75rem">—</span>
            <?php endif; ?>
          </td>

          <!-- Stato -->
          <td data-sort="<?= (int)$v['attivo'] ?>">
            <?php if ($v['attivo']): ?>
              <span style="color:var(--verde);font-size:.78rem;font-weight:600">
                ● Attivo
              </span>
            <?php else: ?>
              <span style="color:#aaa;font-size:.78rem;font-weight:600">
                ○ Disattivato
              </span>
            <?php endif; ?>
          </td>

          <!-- Azioni -->
          <td>
            <?php if (!isAdmin()): ?>
              <span style="color:#bbb;font-size:.75rem">—</span>
            <?php else: ?>
            <div class="azioni">

              <!-- Modifica -->
              <a href="lista.php?modifica=<?= $v['id'] ?>"
                 class="btn btn-grigio btn-sm">
                ✏️ Modifica
              </a>

              <!-- Disattiva / Riattiva -->
              <?php if ($v['attivo']): ?>
                <form method="POST" action="lista.php"
                      onsubmit="return confermaSubmit(this, 'Disattivare questo vigile?', {titolo:'Disattiva vigile', okLabel:'🚫 Disattiva'})">
                  <input type="hidden" name="azione" value="elimina">
                  <input type="hidden" name="id" value="<?= $v['id'] ?>">
                  <button type="submit" class="btn btn-arancio btn-sm">
                    🚫 Disattiva
                  </button>
                </form>
              <?php else: ?>
                <form method="POST" action="lista.php">
                  <input type="hidden" name="azione" value="riattiva">
                  <input type="hidden" name="id" value="<?= $v['id'] ?>">
                  <button type="submit" class="btn btn-verde btn-sm">
                    ✅ Riattiva
                  </button>
                </form>
              <?php endif; ?>

              <!-- Elimina DEFINITIVO (hard): rimuove dal DB con tutto lo storico.
                   Per trasferimenti/pensionamenti. Irreversibile → doppia conferma. -->
              <form method="POST" action="lista.php"
                    onsubmit="return confermaSubmit(this, 'Eliminare DEFINITIVAMENTE <?= htmlspecialchars(addslashes(ucfirst(strtolower($v['cognome'])).' '.$v['nome'])) ?>? Sparisce dal database con tutto il suo storico (fogli, ferie, salti). Operazione irreversibile — per disattivare temporaneamente usa Disattiva.', {titolo:'Elimina definitivamente', okLabel:'🗑️ Elimina dal DB', okStyle:'background:var(--rosso);color:#fff'})">
                <input type="hidden" name="azione" value="elimina_def">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-sm"
                        style="background:var(--rosso);color:#fff">
                  🗑️ Elimina
                </button>
              </form>

            </div>
            <?php endif; ?>
          </td>

        </tr>
        <?php endforeach; ?>

      </tbody>
    </table>

    <div class="pag-info">
      Totale: <strong><?= count($vigili) ?></strong> vigili
      <?php if ($filtroStato === 'attivi'): ?>
        attivi
      <?php else: ?>
        (attivi + disattivati)
      <?php endif; ?>
      <?php if ($filtroSede || $filtroSalto || $filtroTesto): ?>
        — filtro attivo
      <?php endif; ?>
    </div>

  </div><!-- /.tabella-wrap -->

</main>

<script>
// ── Salto turno filtrato dal Turno scelto (solo Comando: admin/user hanno
// già il select "Salto turno" pre-filtrato lato server sul proprio turno) ──
(function () {
    const selTurno = document.getElementById('turnoSelect');
    const selSalto = document.getElementById('saltoSelect');
    if (!selTurno || !selSalto) return;
    function filtra() {
        const t = selTurno.value;
        let primaValida = null;
        Array.from(selSalto.options).forEach(opt => {
            if (!opt.value) return;   // placeholder "— seleziona —"
            const visibile = opt.dataset.turno === t;
            opt.hidden = !visibile;
            if (visibile && primaValida === null) primaValida = opt;
        });
        const sel = selSalto.selectedOptions[0];
        if (sel && sel.hidden) selSalto.value = primaValida ? primaValida.value : '';
    }
    selTurno.addEventListener('change', filtra);
    filtra();
})();

// Aggiorna la label del toggle attivo/non attivo in tempo reale
const tog = document.getElementById('toggleAttivo');
const lbl = document.getElementById('toggleLabel');
if (tog && lbl) {
    tog.addEventListener('change', () => {
        lbl.textContent = tog.checked ? 'Attivo' : 'Non attivo';
    });
}

// ── Ordinamento cliccando le intestazioni di colonna ─────────────────────────
// Chiave d'ordine = attributo data-sort della cella (qualifica per grado, salto
// per numero, ecc.); fallback al testo. Numerico se entrambi i valori sono numeri.
(function () {
    const tab = document.getElementById('tabellaVigili');
    if (!tab) return;
    const tbody = tab.tBodies[0];
    const headers = tab.querySelectorAll('thead th.sortable');

    function chiave(tr, col) {
        const td = tr.children[col];
        if (!td) return '';
        return td.dataset.sort != null ? td.dataset.sort : td.textContent.trim();
    }

    headers.forEach(th => {
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col, 10);
            const asc = th.getAttribute('aria-sort') !== 'ascending';
            headers.forEach(h => h.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');

            const righe = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > 1);
            righe.sort((a, b) => {
                const va = chiave(a, col), vb = chiave(b, col);
                const na = parseFloat(va), nb = parseFloat(vb);
                let cmp;
                if (!isNaN(na) && !isNaN(nb)) cmp = na - nb;
                else cmp = va.localeCompare(vb, 'it', { sensitivity: 'base' });
                return asc ? cmp : -cmp;
            });
            righe.forEach(r => tbody.appendChild(r));
        });
    });
})();

// ── Filtri intestazione: Tipo (operativo/specialista) + Competenza ───────────
// Client-side, combinabili. Nasconde le righe non corrispondenti e aggiorna il
// conteggio. Convive con l'ordinamento (riordina solo le righe visibili/nascoste).
(function () {
    const tab  = document.getElementById('tabellaVigili');
    const fT   = document.getElementById('filtroTipo');
    const fC   = document.getElementById('filtroComp');
    const cnt  = document.getElementById('contaRecord');
    if (!tab || !fT || !fC) return;
    const tbody = tab.tBodies[0];
    const checks   = Array.from(fC.querySelectorAll('input.fcomp'));
    const lblSel   = document.getElementById('compSel');
    const btnReset = document.getElementById('compReset');

    function applica() {
        const tipo = fT.value;            // '', 'op', 'spec'
        const comps = checks.filter(c => c.checked).map(c => c.value);  // AND: chi le ha TUTTE
        if (lblSel) lblSel.textContent = comps.length ? comps.join(' + ') : 'tutte';
        let visibili = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            if (tr.children.length <= 1) return;   // riga "nessun risultato"
            let ok = true;
            if (tipo === 'op')   ok = ok && tr.dataset.spec === '0';
            if (tipo === 'spec') ok = ok && tr.dataset.spec === '1';
            if (comps.length) {
                const tokens = (tr.dataset.comp || '').split(' ');
                ok = ok && comps.every(c => tokens.includes(c));
            }
            tr.style.display = ok ? '' : 'none';
            if (ok) visibili++;
        });
        if (cnt) cnt.textContent = visibili + ' record';
    }
    fT.addEventListener('change', applica);
    checks.forEach(c => c.addEventListener('change', applica));
    if (btnReset) btnReset.addEventListener('click', () => {
        checks.forEach(c => c.checked = false);
        applica();
        fC.removeAttribute('open');
    });
})();
</script>
<style>
  #tabellaVigili thead th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
  #tabellaVigili thead th.sortable:hover { background: rgba(0,0,0,.04); }
  #tabellaVigili thead th.sortable::after { content: ' ⇅'; opacity: .35; font-size: .8em; }
  #tabellaVigili thead th.sortable[aria-sort="ascending"]::after  { content: ' ▲'; opacity: .9; }
  #tabellaVigili thead th.sortable[aria-sort="descending"]::after { content: ' ▼'; opacity: .9; }
  .th-filtro { font-size:.78rem; padding:3px 6px; border-radius:6px; border:1px solid rgba(255,255,255,.35);
               background:#fff; color:#333; max-width:200px; }
  .th-filtro-multi { position:relative; padding:0; max-width:none; }
  .th-filtro-multi > summary { list-style:none; cursor:pointer; padding:4px 8px; white-space:nowrap; }
  .th-filtro-multi > summary::-webkit-details-marker { display:none; }
  .th-filtro-multi > summary::after { content:' ▾'; opacity:.6; }
  .th-filtro-multi #compSel { font-weight:700; }
  .comp-menu { position:absolute; z-index:50; top:calc(100% + 4px); right:0; min-width:210px;
               background:#fff; color:#333; border:1px solid #cfd3d8; border-radius:8px;
               box-shadow:0 6px 18px rgba(0,0,0,.18); padding:6px; max-height:340px; overflow:auto; }
  .comp-menu .comp-group { font-size:.66rem; font-weight:800; text-transform:uppercase;
               color:#8a8f96; margin:6px 4px 2px; }
  .comp-menu label { display:flex; align-items:center; gap:6px; padding:4px 6px; border-radius:5px;
               font-size:.8rem; cursor:pointer; }
  .comp-menu label:hover { background:#eef1f4; }
  .comp-reset { margin-top:6px; width:100%; padding:4px; font-size:.72rem; cursor:pointer;
               border:1px solid #cfd3d8; border-radius:5px; background:#f7f8f9; }
  /* Colonna Scadenze (#120): recupero spazio per tenere i 3 tasti Azioni su una riga —
     abilitazioni contenute in larghezza e tasti più compatti, senza wrap. */
  #tabellaVigili th:nth-child(6), #tabellaVigili td:nth-child(6) { width: 150px; max-width: 150px; }
  #tabellaVigili .azioni { flex-wrap: nowrap; }
  #tabellaVigili .azioni .btn-sm { padding: 4px 7px; font-size: .72rem; white-space: nowrap; }
</style>

</body>
</html>
