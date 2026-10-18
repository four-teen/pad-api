import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('legacy_prepare', Path(__file__).parents[1] / 'scripts' / 'prepare-legacy-records.py')
migration = importlib.util.module_from_spec(spec)
spec.loader.exec_module(migration)


class LegacyImportTests(unittest.TestCase):
    def fixtures(self):
        tables = {k: [] for k in ['tbl_office_heads','tbldivisions','tbltypeofdocuments','tblprofiles',
            'tbl_documents_registry','tbl_document_actions','tbl_document_images','tbl_travel_order',
            'tblother_information','tbl_travel_order_faculty']}
        tables['tbl_office_heads'] = [{'office_id': 67, 'office_name': 'CENTRAL RECORDS UNIT'}]
        tables['tbldivisions'] = [{'divisionid': 1, 'division_desc': 'Original source'}]
        tables['tbltypeofdocuments'] = [{'docid': 7, 'doctype_desc': 'TRAVEL ORDER'}]
        tables['tbl_documents_registry'] = [{'doc_id': 42, 'file_code': '26-00042_1', 'particular': 'Original title ' * 25,
            'office_division': 1, 'received_by': 67, 'uni_divisionid': 67, 'type_of_documents': '7',
            'date_received': '2026-10-08 09:00:00', 'created_at': '2026-10-08 01:01:00'}]
        tables['tbl_document_actions'] = [
            {'action_id': 1, 'doc_id': 42, 'from_office_id': None, 'to_office_id': 67, 'action_type': 'Logged', 'action_date': '2026-10-08 01:01:00', 'action_remarks': ''},
            {'action_id': 2, 'doc_id': 42, 'from_office_id': 67, 'to_office_id': 0, 'action_type': 'Delivered', 'action_date': '2026-10-08 02:00:00', 'action_remarks': 'Handed to named person'}]
        baseline = {'schema_hash': 'schema', 'data_hash': 'data', 'rows': {'offices': [
            {'id': 2, 'code': 'PAD', 'name': 'Presidential Action Desk - Records'}],
            'document_sources': [], 'document_types': [], 'document_personnel': [], 'documents': []}}
        return tables, baseline

    def test_sql_literals_are_decoded_without_executing_statements(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'source.sql'
            path.write_text('SET time_zone = "+00:00";\nCREATE TABLE `sample` (`id` int, `name` text);\n'
                "INSERT INTO `sample` (`id`, `name`) VALUES\n(1, 'O\\'Brien, with; punctuation'),\n"
                "(2, 'line\\nnext\\q and \\% and \\_'),\n(3, NULL);\nDROP DATABASE `never_execute`;\n", encoding='utf-8')
            result = migration.parse_dump(path)['sample']
            self.assertEqual(result[0]['name'], "O'Brien, with; punctuation")
            self.assertEqual(result[1]['name'], 'line\nnextq and \\% and \\_')
            self.assertIsNone(result[2]['name'])

    def test_sql_expressions_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'source.sql'
            path.write_text('SET time_zone = "+00:00";\nCREATE TABLE `sample` (`id` int);\nINSERT INTO `sample` (`id`) VALUES (SLEEP(10));', encoding='utf-8')
            with self.assertRaises(ValueError):
                migration.parse_dump(path)

    def test_closed_record_preserves_reference_text_times_and_person_delivery(self):
        tables, baseline = self.fixtures()
        with tempfile.TemporaryDirectory() as directory:
            plan, report, _ = migration.build_plan(tables, baseline, Path(directory), Path(directory))
        self.assertEqual(report['selected_documents'], 1)
        entry = plan['documents'][0]
        self.assertEqual(entry['row']['reference_no'], 'LEGACY-SAM-42')
        self.assertIn('26-00042_1', entry['row']['subject'])
        self.assertLessEqual(len(entry['row']['subject']), 255)
        self.assertIn(tables['tbl_documents_registry'][0]['particular'], entry['row']['description'])
        self.assertEqual(entry['row']['created_at'], '2026-10-08 01:00:00')
        self.assertEqual(entry['routes'][-1]['created_at'], '2026-10-08 02:00:00')
        self.assertIsNone(entry['routes'][-1]['to_office_id'])
        self.assertIsNone(entry['routes'][-1]['received_at'])
        self.assertIsNone(entry['row']['current_office_id'])
        self.assertIsNone(entry['row']['number_sequence'])
        self.assertEqual(plan['migration_identity']['is_active'], 0)

    def test_pending_records_and_chronology_disagreements_are_held(self):
        for mutate in ['pending', 'chronology', 'unknown']:
            with self.subTest(mutate=mutate):
                tables, baseline = self.fixtures()
                if mutate == 'pending': tables['tbl_document_actions'][-1]['action_type'] = 'Received'
                elif mutate == 'chronology': tables['tbl_document_actions'][0]['action_date'] = '2026-10-09 01:00:00'
                else: tables['tbl_document_actions'][0]['action_type'] = ''
                with tempfile.TemporaryDirectory() as directory:
                    plan, report, _ = migration.build_plan(tables, baseline, Path(directory), Path(directory))
                self.assertFalse(plan['documents'])
                self.assertEqual(report['held_documents'], 1)

    def test_partial_attachment_sets_are_never_substituted(self):
        tables, baseline = self.fixtures()
        tables['tbl_document_images'] = [{'img_id': 1, 'doc_id': 42, 'img_filename': 'missing.jpg'}]
        with tempfile.TemporaryDirectory() as directory:
            plan, report, _ = migration.build_plan(tables, baseline, Path(directory), Path(directory))
        self.assertIsNone(plan['documents'][0]['attachment'])
        self.assertIsNone(plan['documents'][0]['row']['stored_file_name'])
        self.assertEqual(len(report['attachment_exceptions']), 1)

    def test_incomplete_travel_values_are_preserved_without_fabricated_enums(self):
        tables, baseline = self.fixtures()
        tables['tbl_travel_order'] = [{'to_id': 3, 'doc_id': 42, 'to_destination': 'Original destination',
            'to_purpose': 'Original purpose', 'to_type': '', 'to_vehicle': '',
            'to_date': '0000-00-00', 'to_departure_date': '2026-10-10', 'to_return_date': '2026-10-09'}]
        with tempfile.TemporaryDirectory() as directory:
            plan, report, _ = migration.build_plan(tables, baseline, Path(directory), Path(directory))
        self.assertIsNone(plan['documents'][0]['travel_details'])
        self.assertIn('Original destination', plan['documents'][0]['row']['description'])
        self.assertEqual(len(report['travel_exceptions']), 1)


if __name__ == '__main__':
    unittest.main()
