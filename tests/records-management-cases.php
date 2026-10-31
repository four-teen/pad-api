<?php
// Included by office-routing-smoke.php, entirely inside its disposable database.
$recordsActor = $db->query('SELECT u.*, o.code AS office_code FROM users u JOIN offices o ON o.id = u.office_id WHERE u.id = 4')->fetch();
$recordsActor['active_role'] = 'ovp_central_records';
$catalog = new RecordsManagement($db, $recordsActor);
$records = new DocumentRouting($db, $recordsActor);
$editReference = $db->query("SELECT reference_no FROM documents d WHERE source_office_id = 1 AND status = 'created'
    AND NOT EXISTS (SELECT 1 FROM document_routes r WHERE r.document_id = d.id AND r.dispatched_at IS NOT NULL) ORDER BY id DESC LIMIT 1")->fetchColumn();
routing_check((bool) $editReference, 'An isolated unsent fixture is available for edit authorization checks.');
$old = $one->record($editReference, true);
$ledgerCount = count($old['history']);
routing_check($old['permissions']['edit_details'] && !$old['permissions']['edit_particulars'], 'An originating office can edit details but cannot correct particulars.');
routing_check($records->record($editReference)['permissions']['edit_particulars'] && !$records->record($editReference)['permissions']['edit_details'], 'Central Records can correct particulars but cannot use owner editing.');

foreach (array(1 => 'sksu_officials', 5 => 'admin') as $actorId => $role) {
    routing_check($http('/api/routing/management/personnel', 'GET', '', 'application/json', $actorId, $role)['status'] === 403, 'Non-Records roles cannot read catalog management through the API.');
    routing_check($http('/api/documents/' . $editReference . '/particulars', 'PATCH', json_encode(array('description' => 'Forbidden', 'version' => $old['edit_version'], 'reason' => 'Attempt')), 'application/json', $actorId, $role)['status'] === 403, 'Only Central Records can correct particulars through the API.');
}
$spoofed = $recordsActor; $spoofed['roles'] = 'sksu_officials';
routing_rejects(function () use ($db, $spoofed) { (new RecordsManagement($db, $spoofed))->catalog('sources'); }, 403, 'Selecting an unassigned Records role does not grant management access.');
$correction = array('description' => "Corrected request name\nFull particulars retained.", 'version' => $old['edit_version'], 'reason' => 'Correct the encoded document name.');
$bad = $correction; $bad['source_division_id'] = 1;
routing_check($http('/api/doc_router/documents/' . $editReference . '/particulars', 'PATCH', json_encode($bad), 'application/json', 4, 'ovp_central_records')['status'] === 422, 'A particulars correction rejects extra document fields.');
$saved = $http('/api/doc_router/documents/' . $editReference . '/particulars', 'PATCH', json_encode($correction), 'application/json', 4, 'ovp_central_records');
routing_check($saved['status'] === 200, 'Central Records corrects particulars through the selected-module HTTP route.');
$corrected = $one->record($editReference, true);
routing_check($corrected['description'] === $correction['description'] && $corrected['subject'] === $correction['description'], 'Particulars correction synchronizes the full particulars and searchable heading.');
foreach (array('reference_no', 'source_office_id', 'document_type', 'source_division_id', 'status', 'current_office_id', 'pending_office_id', 'file_name', 'stored_file_name', 'created_at') as $field) {
    routing_check($corrected[$field] === $old[$field], 'Particulars correction preserves ' . $field . '.');
}
routing_check(count($corrected['history']) === $ledgerCount && count($corrected['changes']) === 1
    && $corrected['changes'][0]['before']['description'] === $old['description']
    && $corrected['changes'][0]['after']['description'] === $correction['description']
    && $corrected['changes'][0]['actor_name'] === $recordsActor['name'], 'Correction evidence retains before/after, reason and editor without altering custody history.');
routing_rejects(function () use ($records, $editReference, $correction) { $records->editDocument($editReference, 'particulars', $correction); }, 409, 'A stale correction cannot overwrite a newer document.');
$bad = $correction; $bad['version'] = $corrected['edit_version']; $bad['description'] = str_repeat('x', 10001);
routing_rejects(function () use ($records, $editReference, $bad) { $records->editDocument($editReference, 'particulars', $bad); }, 422, 'Oversized particulars are rejected.');
$bad['description'] = '   ';
routing_rejects(function () use ($records, $editReference, $bad) { $records->editDocument($editReference, 'particulars', $bad); }, 422, 'Blank particulars are rejected.');
$travelType = $db->query("SELECT id FROM document_types WHERE details_kind = 'travel_order' LIMIT 1")->fetchColumn();
$plainType = $db->query("SELECT id FROM document_types WHERE details_kind = 'personnel' LIMIT 1")->fetchColumn();
$personId = $db->query('SELECT id FROM document_personnel WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$sourceId = $db->query('SELECT id FROM document_sources WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$ownerEdit = array('document_type_id' => $travelType, 'source_division_id' => $sourceId, 'personnel_ids' => array($personId),
    'travel_destination' => 'Test destination', 'travel_purpose' => 'Test purpose', 'travel_order_date' => '2026-10-01',
    'travel_departure_date' => '2026-10-02', 'travel_return_date' => '2026-10-03', 'travel_type' => 'Official Business', 'travel_vehicle_requirement' => 'Not Necessary',
    'reason' => 'Complete the travel details.', 'version' => $corrected['edit_version']);
routing_check($http('/api/doc_router/documents/' . $editReference . '/details', 'PATCH', json_encode($ownerEdit), 'application/json', 4, 'ovp_central_records')['status'] === 403, 'Central Records cannot modify other document details through the owner endpoint.');
foreach (array('description' => 'Forbidden', 'reference_no' => 'bad', 'status' => 'completed', 'current_office_id' => 4) as $key => $value) {
    $bad = $ownerEdit; $bad[$key] = $value;
    routing_rejects(function () use ($one, $editReference, $bad) { $one->editDocument($editReference, 'details', $bad); }, 422, 'Owner edits cannot change protected ' . $key . '.');
}
$result = $http('/api/doc_router/documents/' . $editReference . '/details', 'PATCH', json_encode($ownerEdit), 'application/json', 1, 'sksu_officials');
routing_check($result['status'] === 200, 'The originating office can save travel details through the HTTP API.');
$travelDoc = $one->record($editReference, true);
routing_check($travelDoc['travel_details']['destination'] === 'Test destination' && count($travelDoc['personnel']) === 1
    && $travelDoc['description'] === $corrected['description'], 'Owner editing updates linked personnel and travel while retaining particulars.');
$bad = $ownerEdit; $bad['version'] = $travelDoc['edit_version']; $bad['travel_return_date'] = '2026-09-01';
routing_rejects(function () use ($one, $editReference, $bad) { $one->editDocument($editReference, 'details', $bad); }, 422, 'Invalid travel date edits roll back.');
routing_check(count($one->record($editReference, true)['changes']) === 2, 'Failed edits leave no audit or document mutations.');

$people = $catalog->catalog('personnel')['data'];
$person = array_values(array_filter($people, function ($row) use ($personId) { return $row['id'] === (int) $personId; }))[0];
$renamed = $catalog->saveCatalog('personnel', array('name' => 'Corrected faculty catalog name', 'is_active' => false, 'version' => $person['version'], 'reason' => 'Fix catalog spelling and retire entry.'), $personId)['data'];
routing_check(!$renamed['is_active'] && !in_array((int) $personId, array_map('intval', array_column($one->registrationOptions()['personnel'], 'id')), true), 'Deactivated faculty are excluded from new registration selections.');
$one->editDocument($editReference, 'details', array('version' => $travelDoc['edit_version'], 'reason' => 'Update travel purpose.', 'travel_purpose' => 'Revised purpose'));
$afterRename = $one->record($editReference, true);
routing_check($afterRename['personnel'][0]['name'] === $travelDoc['personnel'][0]['name'], 'Existing personnel snapshots survive catalog renaming and unrelated document edits.');
routing_rejects(function () use ($catalog, $person, $personId) { $catalog->saveCatalog('personnel', array('name' => 'Stale edit', 'version' => $person['version'], 'reason' => 'Stale attempt'), $personId); }, 409, 'Catalog saves reject stale versions.');
$newSource = $catalog->saveCatalog('sources', array('name' => 'New isolated document source', 'reason' => 'Add source.'))['data'];
routing_check($newSource['is_active'], 'Central Records can add an active document source.');
routing_rejects(function () use ($catalog) { $catalog->saveCatalog('sources', array('name' => ' new isolated document source ', 'reason' => 'Duplicate')); }, 409, 'Catalogs reject duplicate names ignoring case and outer spaces.');
$sourceRow = array_values(array_filter($catalog->catalog('sources')['data'], function ($row) use ($sourceId) { return $row['id'] === (int) $sourceId; }))[0];
$catalog->saveCatalog('sources', array('name' => 'Corrected source catalog name', 'is_active' => false, 'version' => $sourceRow['version'], 'reason' => 'Correct source spelling.'), $sourceId);
import_document_sources($db, $sampleSources);
import_document_catalog($db, $sampleTypes, $samplePeople);
routing_check($db->query('SELECT name FROM document_personnel WHERE id = ' . (int) $personId)->fetchColumn() === 'Corrected faculty catalog name'
    && $db->query('SELECT name FROM document_sources WHERE id = ' . (int) $sourceId)->fetchColumn() === 'Corrected source catalog name', 'Rerunning legacy imports cannot overwrite catalog names managed by Records.');
$one->editDocument($editReference, 'details', array('version' => $afterRename['edit_version'], 'reason' => 'Update purpose again.', 'travel_purpose' => 'Final purpose'));
$afterSourceRename = $one->record($editReference, true);
routing_check($afterSourceRename['external_sender'] === $travelDoc['external_sender'], 'Source renaming preserves recorded document source names.');
$clearTravel = array('version' => $afterSourceRename['edit_version'], 'reason' => 'Correct document category.', 'document_type_id' => $plainType);
foreach (array('destination', 'purpose', 'order_date', 'departure_date', 'return_date', 'type', 'vehicle_requirement', 'remarks') as $key) $clearTravel['travel_' . $key] = '';
$one->editDocument($editReference, 'details', $clearTravel);
routing_check($one->record($editReference, true)['travel_details'] === null, 'Changing to a non-travel category removes obsolete travel details atomically.');

$one->action($editReference, 'route', array('to_office_id' => 2, 'notes' => 'Review edited fixture.'));
routing_check(!$one->record($editReference)['permissions']['edit_details'], 'Owner detail editing stops as soon as a document is sent.');
$two->action($editReference, 'receive', array('notes' => 'Received edited fixture.'));
routing_check(!$two->record($editReference)['permissions']['edit_details'], 'The receiving holder does not acquire owner editing rights.');
$two->action($editReference, 'return', array('notes' => 'Correct details.'));
$one->action($editReference, 'receive', array('notes' => 'Correction received.'));
routing_check($one->record($editReference)['permissions']['edit_details'], 'The owner regains editing after receiving an explicit return.');
$one->action($editReference, 'route', array('to_office_id' => 2, 'notes' => 'Resubmitted.'));
$two->action($editReference, 'receive', array('notes' => 'Received.'));
$two->action($editReference, 'complete', array('notes' => 'Completed.'));
$finished = $records->record($editReference, true);
routing_check($finished['permissions']['edit_particulars'] && !$finished['permissions']['edit_details'], 'Only Central Records particulars correction remains available on completed records.');
$records->editDocument($editReference, 'particulars', array('description' => str_repeat('é', 260), 'version' => $finished['edit_version'], 'reason' => 'Correct archived title.'));
$archived = $records->record($editReference, true);
routing_check($archived['status'] === 'completed' && preg_match_all('/./us', $archived['subject'], $chars) === 255 && $archived['description'] === str_repeat('é', 260), 'Archived corrections preserve status and generate a UTF-8 safe 255-character heading.');
routing_check(count($catalog->history('personnel', $personId)) === 1 && count($catalog->history('sources', $newSource['id'])) === 1, 'Catalog creation and updates retain their own audit evidence.');

// Personnel profiles contain real contact details, independently of login accounts.
$newProfile = array('name' => 'New Faculty Fixture', 'email' => 'new.faculty@example.test', 'position' => 'Assistant Professor', 'campus' => 'Tacurong', 'is_active' => true, 'reason' => 'Add verified personnel details.');
foreach (array('email', 'position', 'campus') as $field) {
    $invalid = $newProfile; unset($invalid[$field]);
    routing_check($http('/api/doc_router/routing/management/personnel', 'POST', json_encode($invalid), 'application/json', 4, 'ovp_central_records')['status'] === 422, 'Adding personnel requires ' . $field . ' through the HTTP API.');
}
foreach (array('email' => 'invalid address', 'position' => '', 'campus' => 'unknown') as $field => $value) {
    $invalid = $newProfile; $invalid[$field] = $value;
    routing_rejects(function () use ($catalog, $invalid) { $catalog->saveCatalog('personnel', $invalid); }, 422, 'Personnel validation rejects invalid ' . $field . '.');
}
$invalid = $newProfile; $invalid['email'] = str_repeat('a', 190) . '@example.test';
routing_rejects(function () use ($catalog, $invalid) { $catalog->saveCatalog('personnel', $invalid); }, 422, 'Personnel emails respect the database length limit.');
$invalid = $newProfile; $invalid['roles'] = array('admin');
routing_rejects(function () use ($catalog, $invalid) { $catalog->saveCatalog('personnel', $invalid); }, 422, 'Personnel management cannot assign login roles.');
$invalid = $newProfile; $invalid['acc_type'] = 'Administrator';
routing_rejects(function () use ($catalog, $invalid) { $catalog->saveCatalog('personnel', $invalid); }, 422, 'Legacy account type is not a personnel editing field.');
$usersBefore = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$new = $http('/api/doc_router/routing/management/personnel', 'POST', json_encode($newProfile), 'application/json', 4, 'ovp_central_records');
routing_check($new['status'] === 201 && $new['data']['data']['email'] === $newProfile['email'] && $new['data']['data']['position'] === $newProfile['position']
    && $new['data']['data']['campus'] === $newProfile['campus'], 'Adding personnel saves and returns every contact field through the HTTP API.');
$newRow = $new['data']['data'];
routing_check($db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore, 'Adding a personnel email creates no login or account permissions.');
$duplicate = $newProfile; $duplicate['name'] = 'Different Faculty'; $duplicate['email'] = 'NEW.FACULTY@example.test';
routing_rejects(function () use ($catalog, $duplicate) { $catalog->saveCatalog('personnel', $duplicate); }, 409, 'Personnel rejects duplicate emails regardless of letter case.');
$editProfile = $newProfile; $editProfile['version'] = $newRow['version']; $editProfile['email'] = 'updated.faculty@example.test'; $editProfile['position'] = 'Associate Professor'; $editProfile['campus'] = 'Isulan';
$updated = $http('/api/doc_router/routing/management/personnel/' . $newRow['id'], 'PATCH', json_encode($editProfile), 'application/json', 4, 'ovp_central_records');
routing_check($updated['status'] === 200 && $updated['data']['data']['email'] === $editProfile['email'] && $updated['data']['data']['campus'] === 'Isulan', 'Editing personnel persists changed email, designation and campus.');
$evidence = $catalog->history('personnel', $newRow['id']);
routing_check($evidence[0]['before']['email'] === $newProfile['email'] && $evidence[0]['after']['email'] === $editProfile['email']
    && $evidence[0]['after']['position'] === $editProfile['position'], 'Contact changes retain old and new profile details in the audit record.');
routing_rejects(function () use ($catalog, $newRow, $editProfile) { $catalog->saveCatalog('personnel', $editProfile, $newRow['id']); }, 409, 'Stale profile changes cannot overwrite newer contact details.');
$latest = $updated['data']['data'];
$deactivated = $catalog->saveCatalog('personnel', array('name' => $latest['name'], 'is_active' => false, 'version' => $latest['version'], 'reason' => 'Retire fixture.'), $latest['id'])['data'];
routing_check($deactivated['email'] === $editProfile['email'] && $deactivated['position'] === $editProfile['position'], 'Changing active status preserves omitted profile fields.');
$duplicate['email'] = $editProfile['email'];
routing_rejects(function () use ($catalog, $duplicate) { $catalog->saveCatalog('personnel', $duplicate); }, 409, 'Inactive personnel emails remain protected against accidental duplicate entries.');
$sourceContact = array('name' => 'Source with contact', 'email' => 'source@example.test', 'reason' => 'Invalid field attempt.');
routing_rejects(function () use ($catalog, $sourceContact) { $catalog->saveCatalog('sources', $sourceContact); }, 422, 'Personnel fields are not accepted on the document-source catalog.');
$list = $catalog->catalog('personnel')['data'];
$imported = array_values(array_filter($list, function ($row) use ($personId) { return $row['id'] === (int) $personId; }))[0];
routing_check($imported['email'] === 'traveler@example.test' && $imported['position'] === 'Faculty' && $imported['campus'] === 'Isulan', 'Legacy import carries recorded email, position and campus into the managed catalog.');
$otherPersonId = $db->query('SELECT id FROM document_personnel WHERE legacy_profile_id = 102')->fetchColumn();
$backfillProfiles = array(array('acc_id' => 102, 'acc_email' => 'second@example.test', 'acc_position' => 'Instructor', 'campus' => 'Lutayan'),
    array('acc_id' => 101, 'acc_email' => 'overwrite@example.test', 'acc_position' => 'Overwritten', 'campus' => 'Overwritten'));
routing_check(backfill_personnel_details($db, $backfillProfiles) === 1 && backfill_personnel_details($db, $backfillProfiles) === 0,
    'Profile enrichment fills missing values once and preserves existing imported and managed details.');
$backfilled = $db->query('SELECT email, position, campus FROM document_personnel WHERE id = ' . (int) $otherPersonId)->fetch();
routing_check($backfilled['email'] === 'second@example.test' && $backfilled['position'] === 'Instructor', 'Profile enrichment maps by legacy identity without guessing from names.');
$profileEdit = array('name' => $imported['name'], 'email' => 'records.corrected@example.test', 'position' => 'Professor', 'campus' => 'Access', 'version' => $imported['version'], 'reason' => 'Correct original details.');
$catalog->saveCatalog('personnel', $profileEdit, $personId);
import_document_catalog($db, $sampleTypes, $samplePeople);
backfill_personnel_details($db, $backfillProfiles);
$retained = $db->query('SELECT email, position, campus FROM document_personnel WHERE id = ' . (int) $personId)->fetch();
routing_check($retained['email'] === 'records.corrected@example.test' && $retained['position'] === 'Professor' && $retained['campus'] === 'Access', 'Rerunning legacy setup preserves personnel contact details corrected by Records.');
$publicOptions = array_values(array_filter($one->registrationOptions()['personnel'], function ($row) use ($otherPersonId) { return (int) $row['id'] === (int) $otherPersonId; }));
routing_check($publicOptions && !array_intersect(array('email', 'position', 'campus'), array_keys($publicOptions[0])), 'Registration dropdowns do not expose personnel contact data to other roles.');
