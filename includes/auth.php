<?php
/**
 * auth.php — sessione e controllo accessi del gestionale.
 *
 * MODALITÀ SOFT: finché AUTH_ENFORCE === false il login NON è bloccante
 * (le pagine restano aperte). Serve a testare il login sul live senza
 * rischiare di restare chiusi fuori. Quando confermato, AUTH_ENFORCE → true.
 *
 * Ruoli: 'comando' (super-admin, tutti i turni) > 'admin' (un turno) >
 *        'user' (sola lettura del suo turno).
 */

if (session_status() === PHP_SESSION_NONE) session_start();

const AUTH_ENFORCE = true;   // ← false = soft (pagine aperte); true = blocco attivo

function utenteCorrente(): ?array { return $_SESSION['utente'] ?? null; }
function isLoggato(): bool        { return utenteCorrente() !== null; }
function ruoloCorrente(): ?string { return utenteCorrente()['ruolo'] ?? null; }
function turnoCorrente(): ?string { return utenteCorrente()['turno'] ?? null; }
function isComando(): bool        { return ruoloCorrente() === 'comando'; }
function isAdmin(): bool          { return in_array(ruoloCorrente(), ['comando','admin'], true); }
function isSoloLettura(): bool    { return ruoloCorrente() === 'user'; }

/**
 * Permessi per turno dell'utente corrente: [turno => 'lettura'|'scrittura'].
 * Sorgente = tabella `utenti_turni` (matrice gestita da Comando, #90). `comando`
 * è super: tutti i turni in scrittura, matrice ignorata. Cache per-richiesta.
 */
function grantsTurni(): array {
    static $g = null;
    if ($g !== null) return $g;
    $g = [];
    if (isComando()) { foreach (['A','B','C','D'] as $t) $g[$t] = 'scrittura'; return $g; }
    $uid = (int)(utenteCorrente()['id'] ?? 0);
    if ($uid) {
        try {
            $st = getDB()->prepare("SELECT turno, livello FROM utenti_turni WHERE utente_id=?");
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $r) $g[$r['turno']] = $r['livello'];
        } catch (Throwable $e) { /* tabella assente: nessun permesso */ }
    }
    return $g;
}

/** Visibilità per turno: comando tutto, altrimenti serve una riga in utenti_turni. */
function puoVedereTurno(?string $t): bool {
    if (isComando()) return true;
    return $t !== null && isset(grantsTurni()[$t]);
}

/** Modifica per turno: comando ovunque, altrimenti livello='scrittura' sul turno. */
function puoModificareTurno(?string $t): bool {
    if (isComando()) return true;
    return $t !== null && (grantsTurni()[$t] ?? '') === 'scrittura';
}

/**
 * Turno su cui si sta LAVORANDO (A/B/C/D). Da `?turno=`, poi memorizzato in
 * sessione; default = il turno dell'utente (comando non ne ha → 'B'). Se l'utente
 * non può vedere il turno richiesto, torna al proprio. È la dimensione introdotta
 * col multi-turno (vedi docs/multi-turno-e-permessi.md).
 */
function turnoAttivo(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $t = $_GET['turno'] ?? ($_SESSION['turno_attivo'] ?? (turnoCorrente() ?: 'B'));
    $t = strtoupper(substr((string)$t, 0, 1));
    // se non valido o non visibile all'utente: turno di casa (se visibile), altrimenti il primo visibile
    if (!in_array($t, ['A', 'B', 'C', 'D'], true) || !puoVedereTurno($t)) {
        $home = turnoCorrente();
        $vis  = turniVisibili();
        $t = ($home && puoVedereTurno($home)) ? $home : ($vis[0] ?? 'B');
    }
    $_SESSION['turno_attivo'] = $t;
    return $cache = $t;
}

/** Il turno attualmente in vista è modificabile dall'utente corrente? */
function puoModificareTurnoAttivo(): bool { return puoModificareTurno(turnoAttivo()); }

/** Vista corrente in sola lettura (user, oppure admin su un turno non suo). */
function soloLetturaAttivo(): bool { return !puoModificareTurnoAttivo(); }

/** Elenco dei turni che l'utente può VEDERE (per la tendina in navbar). */
function turniVisibili(): array {
    return array_values(array_filter(['A', 'B', 'C', 'D'], 'puoVedereTurno'));
}

/**
 * HTML della tendina "turno attivo" per la navbar. Mostra i turni che l'utente
 * può vedere; selezionandone uno ricarica la pagina corrente con `?turno=X`
 * (preserva gli altri parametri). Vuoto se l'utente vede un solo turno.
 */
function selettoreTurnoHtml(): string {
    $visibili = turniVisibili();
    if (count($visibili) <= 1) return '';
    $att  = turnoAttivo();
    $opts = '';
    foreach ($visibili as $t) {
        $sel = $t === $att ? ' selected' : '';
        $ro  = puoModificareTurno($t) ? '' : ' 👁';
        $opts .= '<option value="' . $t . '"' . $sel . '>Turno ' . $t . $ro . '</option>';
    }
    return '<select class="nav-turno" title="Turno su cui stai lavorando (👁 = sola lettura)" '
         . 'style="padding:5px 8px;border-radius:6px;border:1px solid #cbd2d9;'
         . 'font:inherit;font-size:.82rem;font-weight:700;background:#fff;cursor:pointer" '
         . 'onchange="var p=new URLSearchParams(location.search);p.set(\'turno\',this.value);'
         . 'location.href=location.pathname+\'?\'+p.toString()">'
         . $opts . '</select>';
}

/** Blocca una POST di modifica se la vista corrente è in sola lettura. */
function vietaSeSolaLetturaTurno(): void {
    if (!AUTH_ENFORCE) return;
    if (soloLetturaAttivo()) { http_response_code(403); exit('Turno in sola lettura per il tuo profilo.'); }
}

/** Redirect al login se non autenticato (no-op in soft). */
function richiediLogin(): void {
    if (!AUTH_ENFORCE) return;
    if (!isLoggato()) { header('Location: /login.php'); exit; }
}

/** Solo admin di turno o comando (no-op in soft). */
function richiediAdmin(): void {
    if (!AUTH_ENFORCE) return;
    richiediLogin();
    if (!isAdmin()) { http_response_code(403); exit('Accesso negato: serve un profilo admin.'); }
}

/** Solo comando (no-op in soft). */
function richiediComando(): void {
    if (!AUTH_ENFORCE) return;
    richiediLogin();
    if (!isComando()) { http_response_code(403); exit('Accesso negato: solo Comando.'); }
}

/** Blocca le azioni di modifica per gli user in sola lettura (no-op in soft). */
function vietaSeSolaLettura(): void {
    if (!AUTH_ENFORCE) return;
    if (isSoloLettura()) { http_response_code(403); exit('Profilo in sola lettura.'); }
}
