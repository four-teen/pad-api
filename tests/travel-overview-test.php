<?php
// Pure read-only query tests on an in-memory SQLite fixture.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/TravelOrderOverview.php';
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$db->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, document_type TEXT, office_id INTEGER)');
$db->exec('CREATE TABLE document_people (document_id INTEGER, personnel_id INTEGER, name_snapshot TEXT)');
$db->exec('CREATE TABLE document_travel_details (document_id INTEGER PRIMARY KEY, destination TEXT, purpose TEXT, order_date TEXT, departure_date TEXT, return_date TEXT, travel_type TEXT, vehicle_requirement TEXT, remarks TEXT)');
$dates = array(1=>array('2026-11-05','2026-11-06'),2=>array('2026-11-06','2026-11-06'),3=>array('2026-11-05','2026-11-08'),4=>array('2026-11-07','2026-11-09'),5=>array('2026-11-08','2026-11-10'),6=>array('2026-11-01','2026-11-04'));
$insert=$db->prepare("INSERT INTO document_travel_details VALUES (?,'Tacurong','Training','2026-11-01',?,?,'Official Business','Not Necessary',NULL)");
foreach($dates as $id=>$range){$db->exec("INSERT INTO documents VALUES ($id,'Travel Order',9)");$insert->execute(array($id,$range[0],$range[1]));}
$db->exec("INSERT INTO documents VALUES (7,'Travel Order',9),(8,'Memorandum',9),(9,'Travel Order',10)");
$db->exec("INSERT INTO document_people VALUES (1,1,'Maya Reyes'),(1,2,'Ana Santos'),(2,3,'Juan Dela Cruz')");
$travel=new TravelOrderOverview($db,new DateTimeImmutable('2026-11-05T16:30:00Z'));
$report=$travel->summary('d.document_type = ? AND d.office_id = ?',array('Travel Order',9));
if($report['today']!=='2026-11-06' || $report['tomorrow']!=='2026-11-07')throw new RuntimeException('Day boundaries must use Asia/Manila.');
if($report['counts']!==array('total'=>7,'ongoing'=>2,'today'=>1,'tomorrow'=>1,'incoming'=>1,'completed'=>1,'unscheduled'=>1))throw new RuntimeException('Travel categories must partition valid schedules into ongoing, departure dates, future starts and completed trips.');
$expected=array('ongoing'=>array(1,3),'today'=>array(2),'tomorrow'=>array(4),'incoming'=>array(5),'completed'=>array(6));
foreach($expected as $window=>$ids){$parameters=array();$condition=$travel->condition($window,$parameters);$query=$db->prepare('SELECT document_id FROM document_travel_details t WHERE '.$condition.' ORDER BY document_id');$query->execute($parameters);if(array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN))!==$ids)throw new RuntimeException('Date filter does not match its count: '.$window);}
$rows=$travel->details(array(array('id'=>1),array('id'=>7)));
if(array_column($rows[0]['personnel'],'name')!==array('Ana Santos','Maya Reyes'))throw new RuntimeException('Every recorded employee must be included.');
if($rows[1]['personnel']!==array() || $rows[1]['travel_details']!==null)throw new RuntimeException('Missing legacy details must stay explicit.');
if($rows[0]['travel_details']['departure_date']!=='2026-11-05'||$rows[0]['travel_details']['return_date']!=='2026-11-06')throw new RuntimeException('Departure and return dates must both be returned.');
foreach(array('bad',array('today')) as $invalid){try{$args=array();$travel->condition($invalid,$args);throw new RuntimeException('Invalid period accepted.');}catch(ApiException $error){if($error->getCode()!==422)throw $error;}}
$yearEnd=new TravelOrderOverview($db,new DateTimeImmutable('2026-12-31T12:00:00Z'));
if($yearEnd->summary('1 = 0',array())['tomorrow']!=='2027-01-01')throw new RuntimeException('Tomorrow must cross year boundaries.');
echo "PASS travel periods: Manila dates, exclusive travel categories, inclusive ongoing return day, completed trips, missing schedules, all employees, and validation.\n";
