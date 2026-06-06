
<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$pdo     = getDB();
$errore  = '';
$sucesso = '';

// ── AZIONI POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $note           = trim($_POST['note']               ?? '');

    if ($cognome === '' || $qualifica_id === 0 || $sede_id === 0 || $salto_id === 0) {
        $errore = 'Cognome, qualifica, sede e salto turno sono obbligatori.';
    } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Indirizzo email non valido.';
    } else {

        if ($azione === 'inserisci') {
            $vid = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM vigili")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO vigili
                 (id,cognome,nome,disambiguatore,email,qualifica_id,sede_id,salto_id,attivo,note)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$vid,$cognome,$nome,$disambiguatore,$email,
                        $qualifica_id,$sede_id,$salto_id,$attivo,$note]);

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
            $pdo->prepare(
                "UPDATE vigili
                 SET cognome=?,nome=?,disambiguatore=?,email=?,qualifica_id=?,
                     sede_id=?,salto_id=?,attivo=?,note=?
                 WHERE id=?"
            )->execute([$cognome,$nome,$disambiguatore,$email,
                        $qualifica_id,$sede_id,$salto_id,$attivo,$note,$id]);

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

        } elseif ($azione === 'elimina') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE vigili SET attivo=0 WHERE id=?")->execute([$id]);
            $sucesso = 'Vigile disattivato.';

        } elseif ($azione === 'riattiva') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE vigili SET attivo=1 WHERE id=?")->execute([$id]);
            $sucesso = 'Vigile riattivato.';
        }
    }
}

// ── FILTRI ───────────────────────────────────────────────────
$filtroSede  = (int)($_GET['sede']   ?? 0);
$filtroSalto = (int)($_GET['salto']  ?? 0);
$filtroStato = $_GET['stato']        ?? 'attivi';
$filtroTesto = trim($_GET['cerca']   ?? '');

$where  = [];
$params = [];
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
     ORDER BY q.id DESC, v.cognome ASC, v.disambiguatore ASC"
);
$stmtV->execute($params);
$vigili = $stmtV->fetchAll();

// ── DATI PER I SELECT ────────────────────────────────────────
$qualifiche   = $pdo->query("SELECT * FROM qualifiche ORDER BY id DESC")->fetchAll();
$sedi         = $pdo->query("SELECT * FROM sedi ORDER BY ordine")->fetchAll();
$salti        = $pdo->query("SELECT * FROM salti_turno ORDER BY id")->fetchAll();
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
      <p>Gestionale Foglio di Servizio &mdash; Turno B</p>
    </div>
    <div class="header-badge">TURNO&nbsp;B</div>
  </div>
</header>
<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="../index.php"        class="nav-btn">🏠 Cruscotto</a>
    <a href="../foglio/nuovo.php" class="nav-btn">📋 Nuovo Foglio</a>
    <a href="lista.php"           class="nav-btn active">👥 Personale</a>
    <a href="../ferie/index.php"  class="nav-btn">🏖️ Ferie</a>
    <a href="../report/index.php" class="nav-btn">📊 Reportistica</a>
    <a href="../admin/index.php"  class="nav-btn">⚙️ Amministrazione</a>
    <a href="../logout.php"       class="nav-btn ml-auto">🚪 Esci</a>
  </div>
</nav>
<!-- MAIN -->
<main class="main">
  <div class="page-title">
    <h2>👥 Gestione Personale — Turno B</h2>
    <?php if (!$vigileEdit && !isset($_GET['nuovo'])): ?>
      <a href="lista.php?nuovo=1" class="btn btn-rosso">➕ Nuovo Vigile</a>
    <?php endif; ?>
  </div>
  <?php if ($sucesso): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($sucesso) ?></div>
  <?php endif; ?>
  <?php if ($errore): ?>
    <div class="alert alert-err">⚠️ <?= htmlspecialchars($errore) ?></div>
  <?php endif; ?>
  <!-- ══ FORM INSERIMENTO / MODIFICA ══════════════════════════ -->
  <?php if (isset($_GET['nuovo']) || $vigileEdit): ?>
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
          <div class="form-group">
            <label>Salto turno *</label>
            <select name="salto_id" required>
              <option value="">— seleziona —</option>
              <?php foreach ($salti as $st): ?>
                <option value="<?= $st['id'] ?>"
                  <?= ($vigileEdit && $vigileEdit['salto_id']==$st['id'])
                      ? 'selected' : '' ?>>
                  <?= htmlspecialchars($st['codice']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full">
            <label>Patenti</label>
            <div class="patenti-group">
              <?php foreach ($patentiAll as $p): ?>
                <label class="patente-check">
                  <input type="checkbox" name="patenti[]"
                         value="<?= $p['id'] ?>"
                         <?= in_array($p['id'], $patentiEdit) ? 'checked' : '' ?>>
                  Patente <?= htmlspecialchars($p['tipo']) ?>
                </label>
              <?php endforeach; ?>
            </div>
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
          <?php foreach ($salti as $st): ?>
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
      <span style="font-size:.8rem;font-weight:400;opacity:.8">
        <?= count($vigili) ?> record trovati
      </span>
    </div>

    <table>
      <thead>
  <tr>
    <th>Qualifica</th>
    <th>Cognome / Nome</th>
    <th>Sede</th>
    <th>Salto</th>
    <th>Patenti</th>
    <th>Abilitazioni</th>
    <th>Stato</th>
    <th style="text-align:center">Azioni</th>
  </tr>
</thead>
      <tbody>

        <?php if (empty($vigili)): ?>
          <tr>
            <td colspan="7"
                style="text-align:center;padding:32px;color:var(--grigio-md)">
              Nessun vigile trovato con i filtri selezionati.
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($vigili as $v):
          $patientiV = $patentiPerVigile[$v['id']] ?? [];
        ?>
        <tr class="<?= $v['attivo'] ? '' : 'disattivo' ?>">

          <!-- Qualifica -->
          <td>
            <span class="badge-qual badge-<?= htmlspecialchars($v['qcodice']) ?>">
              <?= htmlspecialchars(ucfirst(strtolower($v['qcodice']))) ?>
            </span>
          </td>

          <!-- Cognome / Nome -->
          <td>
            <strong><?= htmlspecialchars(ucfirst(strtolower($v['cognome']))) ?></strong>
            <?php if ($v['disambiguatore']): ?>
              <span style="color:var(--grigio-md);font-size:.8rem">
                <?= (int)$v['disambiguatore'] ?>
              </span>
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
          <td><?= htmlspecialchars($v['sede_nome']) ?></td>

          <!-- Salto -->
          <td>
            <span class="badge-salto">
              <?= htmlspecialchars($v['salto_codice']) ?>
            </span>
          </td>

          <!-- Patenti -->
          <td>
            <?php if (empty($patientiV)): ?>
              <span style="color:#bbb;font-size:.75rem">—</span>
            <?php else: ?>
              <?php foreach ($patientiV as $pt): ?>
                <span class="badge-pat"><?= htmlspecialchars($pt) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>


          <!-- Abilitazioni -->
          <td>
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

          <!-- Stato -->
          <td>
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

            </div>
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
// Aggiorna la label del toggle attivo/non attivo in tempo reale
const tog = document.getElementById('toggleAttivo');
const lbl = document.getElementById('toggleLabel');
if (tog && lbl) {
    tog.addEventListener('change', () => {
        lbl.textContent = tog.checked ? 'Attivo' : 'Non attivo';
    });
}
</script>

</body>
</html>
