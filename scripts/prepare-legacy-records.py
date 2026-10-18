"""Read a legacy SQL dump as DATA and prepare a schema-preserving PAD import.

No SQL from the dump is executed. This program never connects to a database.
Only records with an unambiguous final Delivered action are selected. All original
rows and unresolved records are retained in the private migration package.
"""
import argparse
import collections
import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import shutil


def digest(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def save_json(path, value):
    Path(path).write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding='utf-8')


def parse_dump(path):
    text = Path(path).read_text(encoding='utf-8-sig')
    if not re.search(r'^SET time_zone = "\+00:00";', text, re.M):
        raise ValueError('The dump must explicitly export TIMESTAMP values in UTC.')
    tables = {name: [] for name in re.findall(r'^CREATE TABLE `([^`]+)`', text, re.M)}
    escapes = {'0': '\0', 'n': '\n', 'r': '\r', 't': '\t', 'b': '\b', 'Z': '\x1a'}
    for match in re.finditer(r'^INSERT INTO `([^`]+)` \(([^\n]+)\) VALUES\s*', text, re.M):
        table = match.group(1)
        columns = re.findall(r'`([^`]+)`', match.group(2))
        if table not in tables or not columns:
            raise ValueError('Unrecognized INSERT table or columns.')
        i = match.end()
        while True:
            while text[i].isspace() or text[i] == ',':
                i += 1
            if text[i] == ';':
                break
            if text[i] != '(':
                raise ValueError('Unexpected SQL tuple syntax.')
            i += 1
            values = []
            while True:
                while text[i].isspace():
                    i += 1
                if text[i] == "'":
                    i += 1
                    value = []
                    while True:
                        c = text[i]
                        i += 1
                        if c == '\\':
                            escaped = text[i]
                            value.append('\\' + escaped if escaped in '_%' else escapes.get(escaped, escaped))
                            i += 1
                        elif c == "'":
                            if text[i] == "'":
                                value.append("'")
                                i += 1
                            else:
                                break
                        else:
                            value.append(c)
                    value = ''.join(value)
                else:
                    start = i
                    while text[i] not in ',)':
                        i += 1
                    token = text[start:i].strip()
                    if token.upper() == 'NULL':
                        value = None
                    elif re.fullmatch(r'-?\d+', token):
                        value = int(token)
                    else:
                        raise ValueError('Only literal SQL values are supported; expressions are never executed.')
                values.append(value)
                while text[i].isspace():
                    i += 1
                if text[i] == ')':
                    i += 1
                    break
                if text[i] != ',':
                    raise ValueError('Unexpected SQL value separator.')
                i += 1
            if len(values) != len(columns):
                raise ValueError('SQL column count mismatch in ' + table)
            tables[table].append(dict(zip(columns, values)))
    return tables


def date_time(value, manila=False):
    parsed = dt.datetime.strptime(str(value), '%Y-%m-%d %H:%M:%S')
    if parsed.year < 1000:
        raise ValueError('Invalid legacy timestamp.')
    if manila:
        parsed -= dt.timedelta(hours=8)
    return parsed.strftime('%Y-%m-%d %H:%M:%S')


def valid_date(value):
    try:
        return bool(value and dt.date.fromisoformat(value).year >= 1000)
    except ValueError:
        return False


def normalized(value):
    return ' '.join(str(value).casefold().split())


def group(rows, field):
    result = collections.defaultdict(list)
    for row in rows:
        result[row[field]].append(row)
    return result


# Only explicit institutional identities or unique exact names are mapped.
# Ambiguous combined offices and unspecified campus/dean positions remain NULL.
# Their original identities are retained verbatim in route notes and source data.
OFFICE_ALIASES = {
    'University President': 'OP', 'VPAA': 'VPAA', 'Vice President for FARG': 'VPFARG',
    'VP for REI': 'VPREI', 'Chief Administrative Officer - Administration': 'CAO-ADMIN',
    'Chief Administrative Officer - Finance': 'CAO-FINANCE',
    'Director, Isulan Campus': 'ISULAN-DIR', 'Director, Tacurong': 'TACURONG-DIR',
    'Director, Kalamansig': 'KALAMANSIG-DIR', 'Director, Lutayan': 'LUTAYAN-DIR',
    'Director, Palimbang': 'PALIMBANG-DIR', 'Director, Bagumbayan': 'BAGUMBAYAN-DIR',
    'Human Resource Management': 'HRMD', 'Student Affairs and Services': 'STUDENT-AFFAIRS',
    'Sports': 'SPORTS', 'Planning Division': 'PLANNING', 'NSTP': 'NSTP',
    'Gender and Development': 'GAD', 'Library Services': 'LIBRARY',
    'Health Services': 'HEALTH', 'Guidance and Testing Center': 'GUIDANCE',
    'Chief, Presidential Management Staff': 'PMS', 'Information Communications Technology': 'ICT',
    'CENTRAL RECORDS UNIT': 'PAD', 'PAD OFFICE': 'ACCESS-PAD',
    'LEGAL OFFICE': 'LEGAL', 'SOCIO CULTURAL': 'SOCIOCULTURAL',
    'ACCOUNTING OFFICE': 'ACCOUNTING', 'LABORATORY HIGH SCHOOL': 'ACCESS-LHS',
    'ICT OFFICE': 'ICT', 'Board Secretary': 'BOARDSEC',
}


def build_plan(tables, baseline, uploads, root, prepare_files=False):
    baseline_rows = baseline['rows']
    old_offices = {r['office_id']: r['office_name'].strip() for r in tables['tbl_office_heads']}
    current_codes = {r['code']: r for r in baseline_rows['offices']}
    aliases = {normalized(name): code for name, code in OFFICE_ALIASES.items()}
    office_map = {}
    for old_id, name in old_offices.items():
        exact = [r for r in baseline_rows['offices'] if normalized(r['name']) == normalized(name)]
        if len(exact) == 1:
            office_map[old_id] = exact[0]['id']
        elif normalized(name) in aliases and aliases[normalized(name)] in current_codes:
            office_map[old_id] = current_codes[aliases[normalized(name)]]['id']
        else:
            office_map[old_id] = None

    inserts = {}
    catalog_maps = {}
    conflicts = []
    for destination, source, legacy_column, old_id, old_name, limit in [
        ('document_sources', 'tbldivisions', 'legacy_division_id', 'divisionid', 'division_desc', 255),
        ('document_types', 'tbltypeofdocuments', 'legacy_type_id', 'docid', 'doctype_desc', 120),
        ('document_personnel', 'tblprofiles', 'legacy_profile_id', 'acc_id', 'acc_name', 160),
    ]:
        inserts[destination] = []
        mapping = {}
        current = baseline_rows[destination]
        by_legacy = {r[legacy_column]: r for r in current if r[legacy_column] is not None}
        next_id = max((r['id'] for r in current), default=0) + 1
        for record in tables[source]:
            identifier, name = record[old_id], str(record.get(old_name) or '').strip()
            if not name or len(name) > limit:
                conflicts.append({'table': source, 'legacy_id': identifier, 'reason': 'Blank or oversized catalog label; original retained.'})
                continue
            if identifier in by_legacy:
                mapping[identifier] = by_legacy[identifier]['id']
                if normalized(by_legacy[identifier]['name']) != normalized(name):
                    conflicts.append({'table': source, 'legacy_id': identifier, 'reason': 'Existing catalog name differs; destination left unchanged.'})
                continue
            if destination == 'document_types':
                by_name = [r for r in current if normalized(r['name']) == normalized(name)]
                if by_name:
                    mapping[identifier] = by_name[0]['id']
                    continue
            row = {'id': next_id, legacy_column: identifier, 'name': name, 'is_active': 1}
            if destination == 'document_types':
                row['details_kind'] = 'travel_order' if normalized(name) == 'travel order' else 'personnel'
            inserts[destination].append(row)
            mapping[identifier] = next_id
            next_id += 1
        catalog_maps[destination] = mapping

    docs = tables['tbl_documents_registry']
    doc_ids = {r['doc_id'] for r in docs}
    actions = group(tables['tbl_document_actions'], 'doc_id')
    images = group(tables['tbl_document_images'], 'doc_id')
    travels = group(tables['tbl_travel_order'], 'doc_id')
    travel_by_id = {r['to_id']: r for r in tables['tbl_travel_order']}
    profiles = {r['acc_id']: r for r in tables['tblprofiles']}
    people_links = collections.defaultdict(set)
    for row in tables['tblother_information']:
        people_links[row['doc_id']].add(row['acc_id'])
    for row in tables['tbl_travel_order_faculty']:
        travel = travel_by_id.get(row['to_id'])
        if travel:
            people_links[travel['doc_id']].add(row['acc_id'])
    sources = {r['divisionid']: r['division_desc'] for r in tables['tbldivisions']}
    types = {str(r['docid']): r['doctype_desc'].strip() for r in tables['tbltypeofdocuments']}
    existing_refs = {r['reference_no'].casefold() for r in baseline_rows['documents']}
    ref_counts = collections.Counter(str(r['file_code']).strip().casefold() for r in docs)
    mapped_actions = {'Logged': 'created', 'Outgoing': 'routed', 'Forwarded': 'routed',
                      'Redirected': 'routed', 'Transmitted': 'routed', 'Received': 'received',
                      'Returned': 'returned', 'Acted': 'routed', 'Delivered': 'delivered'}
    selected = []
    held = []
    file_issues = []
    travel_issues = []
    attachment_candidates = []
    for doc in docs:
        doc_id = doc['doc_id']
        history = actions.get(doc_id, [])
        original_ref = str(doc['file_code']).strip()
        reasons = []
        if not history:
            reasons.append('No action history')
        else:
            final = max(history, key=lambda r: r['action_id'])
            latest_time = max(history, key=lambda r: (str(r['action_date']), r['action_id']))
            if final['action_id'] != latest_time['action_id']:
                reasons.append('Final action ID and timestamp disagree')
            if final.get('action_type') != 'Delivered':
                reasons.append('Current custody/status requires review: ' + str(final.get('action_type') or 'unclassified'))
            if any(r.get('action_type') not in mapped_actions for r in history):
                reasons.append('Unclassified historical action')
        if not original_ref or ref_counts[original_ref.casefold()] != 1:
            reasons.append('Missing or duplicate legacy reference')
        if not str(doc.get('particular') or '').strip():
            reasons.append('Blank document particulars')
        if str(doc['type_of_documents']) not in types:
            reasons.append('Unknown document type')
        try:
            created_at = date_time(doc['date_received'], manila=True)
            for event in history:
                date_time(event['action_date'])
        except ValueError:
            reasons.append('Invalid timestamp')
        reference = original_ref if re.fullmatch(r'[A-Za-z0-9-]{1,40}', original_ref) else 'LEGACY-SAM-' + str(doc_id)
        if reference.casefold() in existing_refs:
            reasons.append('Reference already exists in destination')
        if reasons:
            held.append({'legacy_id': doc_id, 'reference': original_ref, 'reasons': reasons})
            continue
        existing_refs.add(reference.casefold())
        source_name = sources.get(doc['office_division'])
        notes = [str(doc['particular']), '', '[Legacy migration]',
                 'Original reference: ' + original_ref, 'Original document ID: ' + str(doc_id),
                 'Legacy source: ' + (source_name or 'Unresolved source ID ' + str(doc['office_division'])),
                 'Original registry received time (Asia/Manila): ' + str(doc['date_received']),
                 'Original registry created_at (UTC dump): ' + str(doc['created_at']),
                 'Closed because the final recorded legacy action is Delivered. This does not assert a PAD decision or owner-office acknowledgment.',
                 'Migration identity represents missing historical staff attribution; original offices and remarks are retained in the timeline.']
        subject = str(doc['particular']).strip()
        if reference != original_ref:
            subject = '[' + original_ref + '] ' + subject
        if len(subject) > 255:
            subject = subject[:252] + '...'
        doc_travel = travels.get(doc_id, [])
        travel_details = None
        if doc_travel:
            notes.extend(['', 'Original travel details (not normalized where required values are missing):'])
            for record in doc_travel:
                notes.append(json.dumps(record, ensure_ascii=False))
            valid = len(doc_travel) == 1
            t = doc_travel[0]
            valid = valid and t.get('to_type') in ('Official Time', 'Official Business') and t.get('to_vehicle') in ('Yes', 'Not Necessary')
            valid = valid and bool(str(t.get('to_destination') or '').strip() and str(t.get('to_purpose') or '').strip())
            valid = valid and all(valid_date(t.get(k)) for k in ('to_date', 'to_departure_date', 'to_return_date'))
            valid = valid and t.get('to_return_date', '') >= t.get('to_departure_date', '')
            if valid:
                travel_details = dict(zip(['destination','purpose','order_date','departure_date','return_date','travel_type','vehicle_requirement','remarks'],
                                         [t[k] for k in ['to_destination','to_purpose','to_date','to_departure_date','to_return_date','to_type','to_vehicle','to_remarks']]))
            else:
                travel_issues.append({'legacy_id': doc_id, 'travel_ids': [r['to_id'] for r in doc_travel], 'reason': 'Required values missing, invalid dates, or multiple versions; preserved in description and source archive.'})
        routes = []
        for event in sorted(history, key=lambda r: (str(r['action_date']), r['action_id'])):
            action = event['action_type']
            event_notes = ['Legacy action ' + str(event['action_id']) + ': ' + action,
                           'From: ' + old_offices.get(event['from_office_id'], 'Not recorded' if not event['from_office_id'] else 'Unresolved office ID ' + str(event['from_office_id'])),
                           'To: ' + old_offices.get(event['to_office_id'], 'Person / no office recorded' if not event['to_office_id'] else 'Unresolved office ID ' + str(event['to_office_id']))]
            if action == 'Acted':
                event_notes.append('Legacy dispatch recorded as Acted; no structured approval or new Records release is inferred.')
            if event.get('action_remarks'):
                event_notes.extend(['Original remarks:', event['action_remarks']])
            stamp = date_time(event['action_date'])
            routes.append({'from_office_id': office_map.get(event['from_office_id']), 'to_office_id': office_map.get(event['to_office_id']),
                           'action': mapped_actions[action], 'notes': '\n'.join(event_notes),
                           'dispatched_at': stamp if action in ('Outgoing','Forwarded','Redirected','Transmitted','Returned','Acted') else None,
                           'received_at': stamp if action == 'Received' else None, 'created_at': stamp, 'actor_role': 'legacy'})
        people = []
        for identifier in people_links.get(doc_id, set()):
            person_id = catalog_maps['document_personnel'].get(identifier)
            original_person = profiles.get(identifier)
            if person_id and original_person and len(original_person['acc_name'].strip()) <= 160:
                people.append({'personnel_id': person_id, 'name_snapshot': original_person['acc_name'].strip()})
            else:
                notes.append('Unresolved original personnel ID: ' + str(identifier))
        people.sort(key=lambda r: r['personnel_id'])
        entry = {'legacy_id': doc_id, 'row': {'reference_no': reference, 'subject': subject, 'description': '',
                 'document_type': types[str(doc['type_of_documents'])], 'document_type_id': catalog_maps['document_types'].get(int(doc['type_of_documents'])),
                 'origin_type': 'external', 'external_sender': (source_name or 'Unresolved legacy source ID ' + str(doc['office_division']))[:255],
                 'source_division_id': catalog_maps['document_sources'].get(doc['office_division']),
                 'source_office_id': None, 'recipient_office_id': None, 'current_office_id': None, 'pending_office_id': None,
                 'movement_type': 'forward', 'number_year': None, 'number_sequence': None,
                 'submission_key': 'legacy-sam-document-' + str(doc_id),
                 'submission_hash': hashlib.sha256(json.dumps({'registry': doc, 'history': history, 'images': images.get(doc_id, []), 'travel': doc_travel}, ensure_ascii=False, sort_keys=True).encode()).hexdigest(),
                 'status': 'delivered', 'file_name': None, 'stored_file_name': None, 'file_mime': None, 'file_size': None,
                 'created_at': created_at, 'updated_at': date_time(final['action_date'])},
                 'routes': routes, 'people': people, 'travel_details': travel_details, 'attachment': None}
        records = sorted(images.get(doc_id, []), key=lambda r: r['img_id'])
        paths = []
        from PIL import Image
        for image in records:
            name = image['img_filename']
            path = (uploads / name).resolve()
            problem = None
            if Path(name).name != name or '/' in name or '\\' in name or not path.is_relative_to(uploads):
                problem = 'Unsafe image path'
            elif not path.is_file():
                problem = 'Image is missing from local uploads'
            else:
                try:
                    with Image.open(path) as opened:
                        if opened.format not in ('JPEG', 'PNG', 'GIF'):
                            raise ValueError('Unexpected image content')
                        opened.verify()
                    paths.append(path)
                except Exception:
                    problem = 'Image cannot be validated'
            if problem:
                file_issues.append({'legacy_id': doc_id, 'image_id': image['img_id'], 'filename': name, 'reason': problem})
        if records and len(paths) != len(records):
            notes.append('Attachment unavailable: only ' + str(len(paths)) + ' of ' + str(len(records)) + ' original scans are available locally. No incomplete combined attachment was substituted.')
        elif paths:
            needs_pdf = len(paths) > 1 or paths[0].suffix.lower() == '.gif'
            with Image.open(paths[0]) as first_image:
                extension = '.pdf' if needs_pdf else '.png' if first_image.format == 'PNG' else '.jpg'
            rel = 'attachments/legacy-sam-' + str(doc_id) + extension
            attachment_candidates.append({'legacy_id': doc_id, 'pages': len(paths), 'pdf': needs_pdf, 'package_file': rel})
            if prepare_files:
                destination = root / rel
                destination.parent.mkdir(parents=True, exist_ok=True)
                if needs_pdf:
                    from PIL import ImageOps
                    pages = []
                    try:
                        for path in paths:
                            with Image.open(path) as opened:
                                pages.append(ImageOps.exif_transpose(opened).convert('RGB'))
                        pages[0].save(destination, format='PDF', save_all=True, append_images=pages[1:], resolution=150, quality=95)
                    finally:
                        for page in pages:
                            page.close()
                else:
                    shutil.copyfile(paths[0], destination)
                for path in paths:
                    original = root / 'original-scans' / path.name
                    original.parent.mkdir(parents=True, exist_ok=True)
                    if not original.exists():
                        shutil.copyfile(path, original)
                    if digest(original) != digest(path):
                        raise ValueError('Original scan archive verification failed.')
                if destination.stat().st_size <= 10485760:
                    sha = digest(destination)
                    entry['attachment'] = {'package_file': rel, 'sha256': sha, 'pages': len(paths)}
                    entry['row'].update({'file_name': 'Legacy-' + reference + extension, 'stored_file_name': 'legacy-sam-' + str(doc_id) + '-' + sha[:24] + extension,
                                         'file_mime': 'application/pdf' if needs_pdf else 'image/png' if extension == '.png' else 'image/jpeg', 'file_size': destination.stat().st_size})
                    if needs_pdf:
                        notes.append('Combined scan pages follow original image IDs in ascending order; original images retained in the private migration archive.')
                else:
                    file_issues.append({'legacy_id': doc_id, 'reason': 'Combined attachment exceeds the existing 10 MiB upload limit; original scans retained.'})
                    notes.append('Original scans retained separately: the combined attachment exceeds the existing file-size limit.')
        entry['row']['description'] = '\n'.join(notes)
        if len(entry['row']['description'].encode('utf-8')) > 65535 or any(len(r['notes'].encode('utf-8')) > 65535 for r in routes):
            held.append({'legacy_id': doc_id, 'reference': original_ref, 'reasons': ['Combined preserved text exceeds the existing TEXT capacity']})
            continue
        selected.append(entry)

    orphan_rows = {name: [r for r in tables[name] if r[field] not in doc_ids]
                   for name, field in [('tbl_document_actions','doc_id'),('tbl_document_images','doc_id'),('tbl_travel_order','doc_id'),('tblother_information','doc_id')]}
    plan = {'format_version': 1, 'baseline_schema_hash': baseline['schema_hash'], 'baseline_data_hash': baseline['data_hash'],
            'catalog_inserts': inserts, 'documents': selected,
            'migration_identity': {'office_id': None, 'ovp_pad_office_id': None, 'ovp_central_records_office_id': None,
                'name': 'Legacy import - original staff not recorded', 'email': 'legacy-sam-import@invalid',
                'avatar_url': None, 'google_subject': None, 'role': 'router', 'roles': 'router', 'is_active': 0,
                'last_login_at': None, 'created_at': '2026-10-18 00:00:00', 'updated_at': '2026-10-18 00:00:00'}}
    report = {'source_counts': {k: len(v) for k, v in tables.items()}, 'selected_documents': len(selected),
              'selected_routes': sum(len(r['routes']) for r in selected), 'held_documents': len(held),
              'prepared_attachments': sum(bool(r['attachment']) for r in selected),
              'attachment_candidates': attachment_candidates, 'held': held, 'attachment_exceptions': file_issues,
              'travel_exceptions': travel_issues, 'catalog_exceptions': conflicts,
              'orphan_counts': {k:len(v) for k,v in orphan_rows.items()},
              'office_mapping': [{'legacy_id': k, 'legacy_name': old_offices[k], 'pad_office_id': v} for k,v in office_map.items()]}
    return plan, report, orphan_rows


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sql', required=True, type=Path)
    parser.add_argument('--snapshot', required=True, type=Path)
    parser.add_argument('--uploads', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--prepare-files', action='store_true')
    args = parser.parse_args()
    root = args.output.resolve()
    private = Path(__file__).resolve().parents[1] / 'storage'
    if not root.is_relative_to(private.resolve()):
        raise ValueError('Migration outputs must remain in the private PAD storage directory.')
    if (root / 'applied.json').exists():
        raise ValueError('An applied migration package cannot be regenerated.')
    root.mkdir(parents=True, exist_ok=True)
    tables = parse_dump(args.sql)
    baseline = json.loads(args.snapshot.read_text(encoding='utf-8'))
    if baseline['database'] != 'pad_db' or baseline['host'] not in ('localhost','127.0.0.1','::1'):
        raise ValueError('This package is limited to the agreed local pad_db destination.')
    source_sha = digest(args.sql)
    archived = root / 'source.sql'
    if archived.exists() and digest(archived) != source_sha:
        raise ValueError('Refusing to replace a different archived source dump.')
    if not archived.exists():
        shutil.copyfile(args.sql, archived)
    plan, report, orphans = build_plan(tables, baseline, args.uploads.resolve(), root, args.prepare_files)
    plan['source_sha256'] = source_sha
    report['source_sha256'] = source_sha
    report['files_prepared'] = args.prepare_files
    save_json(root / 'plan.json', plan)
    save_json(root / 'review.json', report)
    save_json(root / 'original-rows.json', tables)
    save_json(root / 'orphan-rows.json', orphans)
    summary = {k: report[k] for k in ['source_counts','selected_documents','selected_routes','held_documents','prepared_attachments','orphan_counts']}
    summary.update({'pdfs_to_prepare': sum(r['pdf'] for r in report['attachment_candidates']),
                    'attachment_exceptions': len(report['attachment_exceptions']), 'travel_exceptions': len(report['travel_exceptions']),
                    'new_catalog_rows': {k:len(v) for k,v in plan['catalog_inserts'].items()},
                    'unmapped_offices': sum(r['pad_office_id'] is None for r in report['office_mapping'])})
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
