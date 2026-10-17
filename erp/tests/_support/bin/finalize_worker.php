<?php

/**
 * Concurrency test worker: finalizes one draft as a given user in its own
 * PHP process (own DB connection). Run from the erp/ directory:
 *   php tests/_support/bin/finalize_worker.php <documentId> <userId> <startAtEpochMs>
 */
require __DIR__ . '/../../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

[$docId, $userId, $startAt] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0)];
while ((int) (microtime(true) * 1000) < $startAt) {
    usleep(500); // all workers start together
}
service('authz')->actAs($userId);

try {
    $r = service('billing')->finalize($docId);
    echo json_encode(['ok' => true, 'doc' => $docId, 'number' => $r['document_number']]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'doc' => $docId, 'error' => $e->getMessage()]);
}
