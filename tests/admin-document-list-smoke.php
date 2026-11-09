<?php
// Real MySQL queries in a generated empty database; no live records are changed.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/Administration.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';
$checks = 0;
function type_list_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Invalid source database name.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4', env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$fixtureDatabase = 'pad_type_list_test_' . bin2hex(random_bytes(6));
$quote = chr(96);
$management->exec('CREATE DATABASE ' . $quote . $fixtureDatabase . $quote . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    foreach (array('offices', 'users', 'documents', 'document_routes', 'document_types', 'document_travel_details', 'document_people') as $table) {
        $management->exec('CREATE TABLE ' . $quote . $fixtureDatabase . $quote . '.' . $quote . $table . $quote . ' LIKE ' . $quote . $source . $quote . '.' . $quote . $table . $quote);
    }
    putenv('DB_DATABASE=' . $fixtureDatabase);
    $db = database();
    install_pad_reviews($db);
    $db->exec("INSERT INTO offices (id, code, name, created_at, updated_at) VALUES (2,'RECORDS','Records',NOW(),NOW()),(9,'OWNER','Owner',NOW(),NOW()),(10,'OTHER','Other',NOW(),NOW())");
    $db->exec("INSERT INTO users (id,name,email,role,roles,office_id,created_at,updated_at) VALUES (1,'Fixture','type-list@example.test','admin','admin,sksu_officials',9,NOW(),NOW())");
    $db->exec("INSERT INTO document_types (name,is_active) VALUES ('Memorandum',1),('Travel Order',1)");
    $insert=$db->prepare("INSERT INTO documents (reference_no,subject,document_type,origin_type,source_office_id,current_office_id,status,created_by_user_id,created_at,updated_at) VALUES (?,?,?,'office',?,?,?,1,?,?)");
    for($i=1;$i<=26;$i++) {
        $type=$i<=24?'Memorandum':'Memorandum Reply';
        $office=$i%2?9:10;
        $status=$i%3?'received':'completed';
        $date=$i%2?'2025-01-01 00:00:00':'2026-11-06 00:00:00';
        $insert->execute(array('TYPE-'.str_pad($i,4,'0',STR_PAD_LEFT),'Subject '.$i,$type,$office,$office,$status,$date,$date));
    }
    $actor=$db->query('SELECT * FROM users WHERE id=1')->fetch();
    $actor['active_role']='admin';
    $routing=new DocumentRouting($db,$actor);
    $filter=array('queue'=>'all','office_id'=>'all','document_type'=>'Memorandum');
    $first=$routing->workspace($filter);
    $second=$routing->workspace($filter+array('page'=>2));
    type_list_check($first['pagination']['total']===24 && $first['pagination']['pages']===2 && count($first['data'])===20,'The type-filtered first page uses the exact all-time total.');
    type_list_check($second['pagination']['total']===24 && count($second['data'])===4,'The second page contains the remaining exact type matches.');
    $ids=array_merge(array_column($first['data'],'id'),array_column($second['data'],'id'));
    type_list_check(count(array_unique($ids))===24,'Pagination never duplicates documents.');
    foreach(array_merge($first['data'],$second['data']) as $row) type_list_check($row['document_type']==='Memorandum','Similar type names do not leak into the selected type.');
    $counts=array_column((new Administration($db,$actor))->documentTypeCounts(),'documents','name');
    type_list_check($counts['Memorandum']===$first['pagination']['total'],'Sidebar badges and document lists agree.');
    $empty=$routing->workspace(array_merge($filter,array('document_type'=>'Travel Order','page'=>99)));
    type_list_check($empty['data']===array() && $empty['pagination']['total']===0 && $empty['pagination']['page']===1,'An unused type opens a genuine empty page.');
    $searched=$routing->workspace($filter+array('search'=>'TYPE-0001','status'=>'received'));
    type_list_check(count($searched['data'])===1 && $searched['pagination']['total']===1,'Search and status filters compose with the selected type.');
    $injection=$routing->workspace(array_merge($filter,array('document_type'=>"Memorandum' OR 1=1 --")));
    type_list_check($injection['pagination']['total']===0,'Type names are bound values, not SQL.');
    try { $routing->workspace(array_merge($filter,array('document_type'=>array('Memorandum')))); throw new RuntimeException('Array type must fail.'); }
    catch (ApiException $error) { type_list_check($error->getCode()===422,'Non-string type filters are rejected.'); }
    $actor['active_role']='sksu_officials';
    $limited=(new DocumentRouting($db,$actor))->workspace(array('queue'=>'all','document_type'=>'Memorandum'));
    type_list_check($limited['pagination']['total']===12,'Type filtering preserves office visibility.');

    // Travel registry uses the same authorized workspace and inclusive Manila dates.
    $actor['active_role']='admin';
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
    $travelInsert=$db->prepare("INSERT INTO document_travel_details (document_id,destination,purpose,order_date,departure_date,return_date,travel_type,vehicle_requirement) VALUES (?,'Tacurong training center','Regional training',?,?,?,'Official Business','Not Necessary')");
    $peopleInsert=$db->prepare('INSERT INTO document_people (document_id,personnel_id,name_snapshot) VALUES (?,?,?)');
    $ranges=array(array(-1,0),array(0,1),array(1,2),array(2,4),array(-7,-4),null,array(0,1));
    $travelIds=array();
    foreach($ranges as $i=>$range){
        $office=$i===6?10:9;
        $insert->execute(array('TRAVEL-'.($i+1),'Travel fixture '.($i+1),'Travel Order',$office,$office,'received',$today->format('Y-m-d H:i:s'),$today->format('Y-m-d H:i:s')));
        $id=(int)$db->lastInsertId();$travelIds[]=$id;
        if($range)$travelInsert->execute(array($id,$today->format('Y-m-d'),$today->modify(sprintf('%+d days',$range[0]))->format('Y-m-d'),$today->modify(sprintf('%+d days',$range[1]))->format('Y-m-d')));
    }
    $peopleInsert->execute(array($travelIds[0],1001,'Ana Santos'));
    $peopleInsert->execute(array($travelIds[0],1002,'Maya Reyes'));
    $travelFilter=array('queue'=>'all','office_id'=>'all','document_type'=>'Travel Order','travel_window'=>'all');
    $allTravel=$routing->workspace($travelFilter);
    type_list_check($allTravel['travel_summary']['counts']===array('total'=>7,'ongoing'=>1,'today'=>2,'tomorrow'=>1,'incoming'=>1,'completed'=>1,'unscheduled'=>1),'Travel summary separates ongoing, departures, upcoming and completed schedules.');
    foreach(array('all'=>'total','ongoing'=>'ongoing','today'=>'today','tomorrow'=>'tomorrow','incoming'=>'incoming','completed'=>'completed') as $period=>$countKey){
        $periodRows=$routing->workspace(array_merge($travelFilter,array('travel_window'=>$period)));
        type_list_check($periodRows['pagination']['total']===$allTravel['travel_summary']['counts'][$countKey],'Travel card totals match the filtered list: '.$period);
    }
    $byEmployee=$routing->workspace($travelFilter+array('search'=>'Ana Santos'));
    type_list_check($byEmployee['pagination']['total']===1,'Travel registry searches recorded employee names.');
    type_list_check(array_column($byEmployee['data'][0]['personnel'],'name')===array('Ana Santos','Maya Reyes'),'Matching one employee retains everyone on the travel order.');
    type_list_check($byEmployee['data'][0]['travel_details']['departure_date']===$today->modify('-1 day')->format('Y-m-d') && $byEmployee['data'][0]['travel_details']['return_date']===$today->format('Y-m-d'),'Travel list includes departure and return dates.');
    type_list_check($byEmployee['travel_summary']['counts']['total']===7,'Search filters the list without replacing the overall travel card totals.');
    type_list_check($routing->workspace($travelFilter+array('search'=>'Tacurong'))['pagination']['total']===6,'Travel registry also searches destination.');
    $actor['active_role']='sksu_officials';
    $localTravel=(new DocumentRouting($db,$actor))->workspace(array('queue'=>'all','document_type'=>'Travel Order','travel_window'=>'all'));
    type_list_check($localTravel['travel_summary']['counts']['total']===6 && $localTravel['travel_summary']['counts']['today']===1,'Travel summaries preserve office visibility.');
    foreach(array('bad',array('today')) as $invalid){
        try{$routing->workspace(array_merge($travelFilter,array('travel_window'=>$invalid)));throw new RuntimeException('Invalid travel period must fail.');}
        catch(ApiException $error){type_list_check($error->getCode()===422,'Invalid travel periods are rejected.');}
    }

    echo 'Administrator document list checks passed: '.$checks.PHP_EOL;
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (!preg_match('/^pad_type_list_test_[a-f0-9]{12}$/D',$fixtureDatabase)) throw new RuntimeException('Unsafe fixture cleanup.');
    $management->exec('DROP DATABASE ' . $quote . $fixtureDatabase . $quote);
}
