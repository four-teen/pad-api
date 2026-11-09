<?php
// In-memory query checks: no application database is opened or modified.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Administration.php';
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$db->exec('CREATE TABLE document_types (id INTEGER PRIMARY KEY, name TEXT UNIQUE, is_active INTEGER)');
$db->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, document_type TEXT, created_at TEXT, status TEXT)');
$db->exec("INSERT INTO document_types VALUES (1, 'Memorandum', 1), (2, 'Travel Order', 1), (3, 'Archived Type', 0)");
$db->exec("INSERT INTO documents VALUES (1, 'Memorandum', '2025-01-01', 'created'), (2, 'Memorandum', '2026-11-06', 'delivered'), (3, 'Historical Type', '2024-01-01', 'completed')");
$admin = new Administration($db, array('role' => 'admin', 'roles' => array('admin'), 'active_role' => 'admin'));
$rows = $admin->documentTypeCounts();
$actual = array_column($rows, 'documents', 'name');
$expected = array('Archived Type' => 0, 'Historical Type' => 1, 'Memorandum' => 2, 'Travel Order' => 0);
if ($actual !== $expected) throw new RuntimeException('Document type totals must include all configured and historical types across years and statuses.');
if (array_sum($actual) !== 3) throw new RuntimeException('Each document must count exactly once.');
$db->exec('DELETE FROM documents');
$empty = array_column($admin->documentTypeCounts(), 'documents', 'name');
if ($empty !== array('Archived Type' => 0, 'Memorandum' => 0, 'Travel Order' => 0)) throw new RuntimeException('Configured types must remain visible when the registry is empty.');
echo "PASS document types: all-time counts, unique registry rows, historical names, zero-count types, and empty registry.\n";
