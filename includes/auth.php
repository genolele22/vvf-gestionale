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

/**
 * Turno per le pagine di Amministrazione: fisso sul turno di casa (admin/user
 * non possono cambiarlo, niente tendina). Comando non ha un turno di casa
 * (gestisce tutti i turni): per lui segue il turno attivo di sessione, lo
 * stesso scelto con le tab del cruscotto — nessuna UI separata da costruire.
 */
function turnoAmministrazione(): string { return turnoCorrente() ?: turnoAttivo(); }

/** Vista corrente in sola lettura (user, oppure admin su un turno non suo). */
function soloLetturaAttivo(): bool { return !puoModificareTurnoAttivo(); }

/** Elenco dei turni che l'utente può VEDERE (per la tendina in navbar). */
function turniVisibili(): array {
    return array_values(array_filter(['A', 'B', 'C', 'D'], 'puoVedereTurno'));
}

/**
 * Blocca una POST di modifica se il turno indicato è in sola lettura per l'utente.
 * Senza argomento usa il turno attivo di sessione (comportamento storico, per le
 * pagine che seguono `turnoAttivo()`). Le pagine di Amministrazione, che seguono
 * invece `turnoAmministrazione()`, passano quel turno esplicitamente.
 */
function vietaSeSolaLetturaTurno(?string $turno = null): void {
    if (!AUTH_ENFORCE) return;
    if (!puoModificareTurno($turno ?? turnoAttivo())) {
        http_response_code(403); exit('Turno in sola lettura per il tuo profilo.');
    }
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
