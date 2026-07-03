<?php
/**
 * sessione.php — handler di sessione salvata su DB (TiDB).
 *
 * Caricato come auto_prepend_file (vedi conf PHP nel Dockerfile): gira PRIMA di
 * ogni script, così l'handler è registrato prima del session_start() che i
 * singoli file chiamano già da soli.
 *
 * PERCHÉ: su Fly la macchina si ferma quando non c'è traffico (auto_stop_machines)
 * e il filesystem è effimero. Le sessioni-file di default (/tmp) sparivano a ogni
 * sleep o deploy → tutti sloggati senza preavviso. Nel DB sopravvivono.
 *
 * Timeout: inattività 12h. last_activity si aggiorna a ogni richiesta; oltre le
 * 12h di inattività la sessione non viene più letta (utente sloggato).
 */

require_once __DIR__ . '/../config/db.php';

const SESSIONE_INATTIVITA = 43200; // 12 ore in secondi

final class DbSessionHandler implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string {
        $st = getDB()->prepare(
            "SELECT data FROM sessioni WHERE id = ? AND last_activity >= ?");
        $st->execute([$id, time() - SESSIONE_INATTIVITA]);
        $row = $st->fetch();
        return $row ? (string)$row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $st = getDB()->prepare(
            "REPLACE INTO sessioni (id, data, last_activity) VALUES (?, ?, ?)");
        return $st->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool {
        getDB()->prepare("DELETE FROM sessioni WHERE id = ?")->execute([$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc(int $maxlifetime): int|false {
        $st = getDB()->prepare("DELETE FROM sessioni WHERE last_activity < ?");
        $st->execute([time() - SESSIONE_INATTIVITA]);
        return $st->rowCount();
    }
}

// Cookie persistente (sopravvive alla chiusura del browser): il limite reale è
// l'inattività di 12h gestita lato server in read(). force_https è attivo → secure.
session_set_cookie_params([
    'lifetime' => 2592000,   // 30 giorni
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string) SESSIONE_INATTIVITA);

session_set_save_handler(new DbSessionHandler(), true);
