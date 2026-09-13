<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ensure_dirs();
require_login();
$pdo=db();
$action=$_GET['action'] ?? '';

function normalize_manifest(array $m): array {
    if (($m['schema'] ?? '') !== 'lego-resale-exchange-v1') {
        throw new RuntimeException('Unsupported manifest schema. Expected lego-resale-exchange-v1.');
    }
    return $m;
}
function safe_basename(string $name): string {
    $name = str_replace('\\','/',$name);
    return basename($name);
}
function import_stage_dir(string $importId): string {
    $dir = IMPORT_ROOT . '/' . $importId;
    if (!is_dir($dir)) mkdir($dir,0775,true);
    return $dir;
}
function find_manifest_in_zip(ZipArchive $zip): array {
    $candidates=[];
    for($i=0;$i<$zip->numFiles;$i++){
        $name=$zip->getNameIndex($i);
        if(!$name || str_ends_with($name,'/')) continue;
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='json'){
            $raw=$zip->getFromIndex($i);
            $j=json_decode($raw,true);
            if(is_array($j) && ($j['schema']??'')==='lego-resale-exchange-v1'){
                $candidates[]=['name'=>$name,'manifest'=>$j];
            }
        }
    }
    if(!$candidates) throw new RuntimeException('No LRX v1 JSON manifest found inside ZIP.');
    return $candidates[0];
}
function next_display_code_local(PDO $pdo,string $setNum): string {
    $stmt=$pdo->prepare("SELECT display_code FROM inventory_units WHERE catalog_set_num=?");
    $stmt->execute([$setNum]);$used=[];
    foreach($stmt->fetchAll() as $r){if(preg_match('/-([A-Z]+)$/',$r['display_code'],$m))$used[$m[1]]=true;}
    for($i=0;$i<1000;$i++){
        $n=$i;$suffix='';
        do{$suffix=chr(65+($n%26)).$suffix;$n=intdiv($n,26)-1;}while($n>=0);
        if(!isset($used[$suffix])) return $setNum.'-'.$suffix;
    }
    return $setNum.'-'.strtoupper(substr(uuidv4(),0,4));
}

function validate_physical_unit_analysis(array $proposal): array {
    $errors = [];
    $warnings = [];

    $analysis = $proposal['physical_unit_analysis'] ?? null;
    $units = $proposal['units'] ?? null;
    $physicalUnits = (int)($proposal['physical_units'] ?? 0);

    if (!is_array($analysis)) {
        $errors[] = 'Missing physical_unit_analysis block.';
        return ['errors'=>$errors,'warnings'=>$warnings];
    }

    $photosExamined = (int)($analysis['photos_examined'] ?? -1);
    $unitsDetected = (int)($analysis['units_detected'] ?? -1);
    $confidence = $analysis['confidence'] ?? null;

    if (!is_array($units) || count($units) < 1) {
        $errors[] = 'Missing explicit units array.';
        return ['errors'=>$errors,'warnings'=>$warnings];
    }

    if ($physicalUnits < 1) {
        $errors[] = 'physical_units must be at least 1.';
    }

    if ($unitsDetected !== count($units)) {
        $errors[] = 'physical_unit_analysis.units_detected does not equal the number of unit groups.';
    }

    if ($physicalUnits !== count($units)) {
        $errors[] = 'physical_units does not equal the number of unit groups.';
    }

    if ($confidence === null || !is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
        $errors[] = 'physical_unit_analysis.confidence must be between 0 and 1.';
    }

    $all = [];
    foreach ($units as $idx => $unit) {
        $photos = $unit['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 1) {
            $errors[] = 'Unit '.($idx+1).' has no photos.';
            continue;
        }
        foreach ($photos as $ph) {
            $fn = is_array($ph) ? ($ph['filename'] ?? '') : (string)$ph;
            $fn = safe_basename($fn);
            if ($fn === '') {
                $errors[] = 'Unit '.($idx+1).' contains a photo with no filename.';
                continue;
            }
            if (isset($all[$fn])) {
                $errors[] = "Photo $fn is assigned to more than one physical unit.";
            }
            $all[$fn] = true;
        }
    }

    if ($photosExamined !== count($all)) {
        $errors[] = 'physical_unit_analysis.photos_examined does not equal the number of uniquely assigned photos.';
    }

    $basis = $analysis['grouping_basis'] ?? [];
    if (!is_array($basis) || count($basis) < 1) {
        $warnings[] = 'No grouping_basis supplied.';
    }

    if (isset($analysis['catalog_set'])) {
        $setNum = clean_set_num($proposal['catalog']['set_num'] ?? null);
        if ($setNum && clean_set_num((string)$analysis['catalog_set']) !== $setNum) {
            $errors[] = 'physical_unit_analysis.catalog_set does not match catalog.set_num.';
        }
    }

    return ['errors'=>$errors,'warnings'=>$warnings,'assigned_photos'=>array_keys($all)];
}

function photo_role_allowed(string $role): string {
    $allowed=['front','back','side','seal','damage','contents','instructions','unknown'];
    return in_array($role,$allowed,true)?$role:'unknown';
}

if($action==='upload_package'){
    if(!isset($_FILES['package']) || ($_FILES['package']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
        json_response(['ok'=>false,'error'=>'LRX ZIP upload failed.'],422);
    }
    $name=$_FILES['package']['name']??'package.zip';
    if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip') json_response(['ok'=>false,'error'=>'Upload a .zip LRX package.'],422);
    $zip=new ZipArchive();
    if($zip->open($_FILES['package']['tmp_name'])!==true) json_response(['ok'=>false,'error'=>'Could not open ZIP package.'],422);
    try{
        $found=find_manifest_in_zip($zip);
        $m=normalize_manifest($found['manifest']);
        $importId=uuidv4();$stage=import_stage_dir($importId);$batch=$m['batch']??[];
        $manifestStored=$stage.'/manifest.json';
        file_put_contents($manifestStored,json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO import_batches(import_id,external_batch_id,schema_version,generator,source_type,filename,status,created_at)
                       VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$importId,$batch['batch_id']??null,$m['schema_version']??'1.0',$batch['generator']??null,'model_assisted',$name,'preview',now_iso()]);
        $propStmt=$pdo->prepare("INSERT INTO import_proposals(proposal_id,import_id,item_type,proposed_set_num,proposed_name,confidence,review_required,payload_json,decision)
                                VALUES(?,?,?,?,?,?,?,?,?)");
        foreach(($m['proposals']??[]) as $p){
            $validation = validate_physical_unit_analysis($p);
            if ($validation['errors']) {
                throw new RuntimeException('Physical-unit validation failed for '.(($p['catalog']['set_num']??$p['catalog']['name']??'proposal')).': '.implode(' | ',$validation['errors']));
            }
            $p['_physical_unit_validation'] = $validation;
            $propStmt->execute([uuidv4(),$importId,$p['item_type']??'set',clean_set_num($p['catalog']['set_num']??null),$p['catalog']['name']??null,
                $p['identification']['confidence']??null,!empty($p['identification']['review_required'])?1:0,json_encode($p,JSON_UNESCAPED_SLASHES),'pending']);
        }

        // Stage only files actually referenced by the manifest.
        $referenced=[];
        foreach(($m['proposals']??[]) as $p){
            foreach(($p['units']??[]) as $u){
                foreach(($u['photos']??[]) as $ph){
                    $fn=is_array($ph)?($ph['filename']??''):(string)$ph;
                    if($fn!=='') $referenced[safe_basename($fn)]=true;
                }
            }
        }
        $matched=0;$missing=array_keys($referenced);
        for($i=0;$i<$zip->numFiles;$i++){
            $entry=$zip->getNameIndex($i); if(!$entry || str_ends_with($entry,'/'))continue;
            $base=safe_basename($entry);
            if(!isset($referenced[$base])) continue;
            $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));
            if(!in_array($ext,['jpg','jpeg','png','webp'],true)) continue;
            $bytes=$zip->getFromIndex($i); if($bytes===false)continue;
            $stored=uuidv4().'.'.$ext;
            $path=$stage.'/'.$stored;
            file_put_contents($path,$bytes);
            $pdo->prepare("INSERT INTO import_files(import_file_id,import_id,original_filename,staged_path,file_type,created_at) VALUES(?,?,?,?,?,?)")
                ->execute([uuidv4(),$importId,$base,$path,'photo',now_iso()]);
            $matched++;
        }
        $pdo->commit();$zip->close();

        $s=$pdo->prepare("SELECT original_filename FROM import_files WHERE import_id=?");$s->execute([$importId]);
        $present=array_flip(array_column($s->fetchAll(),'original_filename'));
        $missing=[];foreach(array_keys($referenced) as $fn)if(!isset($present[$fn]))$missing[]=$fn;

        json_response(['ok'=>true,'import_id'=>$importId,'proposal_count'=>count($m['proposals']??[]),'referenced_photos'=>count($referenced),'matched_photos'=>$matched,'missing_photos'=>$missing]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $zip->close();
        json_response(['ok'=>false,'error'=>$e->getMessage()],422);
    }
}


if($action==='view_staged_photo'){
    $importId=$_GET['import_id']??'';
    $filename=safe_basename($_GET['filename']??'');
    if($importId===''||$filename===''){http_response_code(400);exit;}
    $s=$pdo->prepare("SELECT staged_path FROM import_files WHERE import_id=? AND original_filename=? LIMIT 1");
    $s->execute([$importId,$filename]);$path=$s->fetchColumn();
    if(!$path||!is_file($path)){http_response_code(404);exit;}
    $real=realpath($path);$root=realpath(IMPORT_ROOT);
    if(!$real||!$root||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)){http_response_code(403);exit;}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($real);
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(415);exit;}
    header('Content-Type: '.$mime);
    header('Cache-Control: private,max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

if($action==='list'){
    $rows=$pdo->query("SELECT b.*,
      (SELECT COUNT(*) FROM import_proposals p WHERE p.import_id=b.import_id) proposal_count,
      (SELECT COUNT(*) FROM import_files f WHERE f.import_id=b.import_id) staged_file_count
      FROM import_batches b ORDER BY created_at DESC")->fetchAll();
    json_response(['ok'=>true,'items'=>$rows]);
}

if($action==='proposals'){
    $id=$_GET['import_id']??'';
    $s=$pdo->prepare("SELECT * FROM import_proposals WHERE import_id=? ORDER BY rowid");$s->execute([$id]);
    $rows=$s->fetchAll();
    foreach($rows as &$r){
        $r['payload']=json_decode($r['payload_json'],true);
        $r['photo_status']=[];
        foreach(($r['payload']['units']??[]) as $unitIndex=>$u){
            foreach(($u['photos']??[]) as $ph){
                $fn=is_array($ph)?($ph['filename']??''):(string)$ph;
                if($fn==='')continue;
                $q=$pdo->prepare("SELECT COUNT(*) FROM import_files WHERE import_id=? AND original_filename=?");
                $q->execute([$id,safe_basename($fn)]);
                $r['photo_status'][]=[
                    'filename'=>safe_basename($fn),
                    'matched'=>(int)$q->fetchColumn()>0,
                    'role'=>is_array($ph)?($ph['role']??'unknown'):'unknown',
                    'unit_index'=>$unitIndex
                ];
            }
        }
        $r['physical_unit_validation']=validate_physical_unit_analysis($r['payload']);
    }
    json_response(['ok'=>true,'items'=>$rows]);
}

if($action==='approve'){
    $d=body_json();$proposalId=$d['proposal_id']??'';
    $s=$pdo->prepare("SELECT * FROM import_proposals WHERE proposal_id=?");$s->execute([$proposalId]);$r=$s->fetch();
    if(!$r)json_response(['ok'=>false,'error'=>'Proposal not found'],404);
    if($r['decision']==='approved')json_response(['ok'=>false,'error'=>'Proposal already approved'],409);

    $p=json_decode($r['payload_json'],true);$setNum=clean_set_num($p['catalog']['set_num']??null);
    if(($r['item_type']??'set')!=='set') json_response(['ok'=>false,'error'=>'Alpha.3 package commit currently supports set proposals only.'],422);
    if(!$setNum) json_response(['ok'=>false,'error'=>'Proposal has no exact set number'],422);
    $c=$pdo->prepare("SELECT set_num FROM catalog_sets WHERE set_num=?");$c->execute([$setNum]);
    if(!$c->fetchColumn()) json_response(['ok'=>false,'error'=>"Catalog does not contain $setNum."],422);

    $validation = validate_physical_unit_analysis($p);
    if($validation['errors']) {
        json_response(['ok'=>false,'error'=>'Physical-unit validation failed: '.implode(' | ',$validation['errors'])],422);
    }
    $qty=(int)$p['physical_units'];

    $pdo->beginTransaction();
    try{
        $created=[];$unitsPayload=$p['units'];
        foreach($unitsPayload as $idx=>$unitPayload){
            $unitId=uuidv4();$display=next_display_code_local($pdo,$setNum);$now=now_iso();
            $cond=$unitPayload['condition']??($p['condition']??[]);
            if(($cond['completeness']??null)==='sealed_unverified') $cond['completeness']='unknown';
            $notes=$unitPayload['notes']??($p['notes']??null);
            $pdo->prepare("INSERT INTO inventory_units(unit_id,item_type,catalog_set_num,display_code,status,condition_state,sealed_status,box_condition,instructions_status,completeness,notes,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$unitId,'set',$setNum,$display,'available',$cond['state']??'unknown',$cond['sealed_status']??'unknown',$cond['box_condition']??'unknown',
                          $cond['instructions_status']??'unknown',$cond['completeness']??'unknown',$notes,$now,$now]);

            // Photos
            $photos=$unitPayload['photos']??($p['photos']??[]);
            $photoDir=PHOTO_ROOT.'/'.$unitId;if(!is_dir($photoDir))mkdir($photoDir,0775,true);
            $sort=0;
            foreach($photos as $ph){
                $fn=is_array($ph)?($ph['filename']??''):(string)$ph;
                if($fn==='')continue;$base=safe_basename($fn);
                $q=$pdo->prepare("SELECT * FROM import_files WHERE import_id=? AND original_filename=? LIMIT 1");$q->execute([$r['import_id'],$base]);$f=$q->fetch();
                if(!$f) throw new RuntimeException("Referenced photo missing from staged package: $base");
                $ext=strtolower(pathinfo($f['staged_path'],PATHINFO_EXTENSION));
                $photoId=uuidv4();$stored=$photoId.'.'.$ext;$dest=$photoDir.'/'.$stored;
                if(!copy($f['staged_path'],$dest)) throw new RuntimeException("Could not copy photo: $base");
                $role=photo_role_allowed(is_array($ph)?($ph['role']??'unknown'):'unknown');
                $pdo->prepare("INSERT INTO unit_photos(photo_id,unit_id,filename,stored_filename,role,is_primary,sort_order,created_at) VALUES(?,?,?,?,?,?,?,?)")
                    ->execute([$photoId,$unitId,$base,$stored,$role,$sort===0?1:0,$sort,now_iso()]);
                $sort++;
            }

            // Valuation observation from proposal
            $val=$unitPayload['valuation']??($p['valuation']??null);
            if(is_array($val)){
                $vid=uuidv4();
                $pdo->prepare("INSERT INTO valuations(valuation_id,unit_id,observed_at,currency,source_label,msrp,whole_new,whole_used,part_out_sold,part_out_for_sale,notes)
                               VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$vid,$unitId,now_iso(),$val['currency']??'USD',$val['source_label']??'model-assisted import',
                               $val['msrp']??null,$val['whole_new']??null,$val['whole_used']??null,$val['part_out_sold']??null,$val['part_out_for_sale']??null,
                               $val['notes']??null]);
            }

            $created[]=['unit_id'=>$unitId,'display_code'=>$display,'photo_count'=>count($photos)];
        }
        queue_pov_for_set($pdo,$setNum,false);
        $pdo->prepare("UPDATE import_proposals SET decision='approved',committed_unit_id=? WHERE proposal_id=?")
            ->execute([$created[0]['unit_id'],$proposalId]);

        $pending=$pdo->prepare("SELECT COUNT(*) FROM import_proposals WHERE import_id=? AND decision='pending'");$pending->execute([$r['import_id']]);
        if((int)$pending->fetchColumn()===0){
            $pdo->prepare("UPDATE import_batches SET status='committed',committed_at=? WHERE import_id=?")->execute([now_iso(),$r['import_id']]);
        }
        $pdo->commit();
        json_response(['ok'=>true,'created_units'=>$created]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        json_response(['ok'=>false,'error'=>$e->getMessage()],500);
    }
}

if($action==='reject'){
    $d=body_json();$id=$d['proposal_id']??'';
    $pdo->prepare("UPDATE import_proposals SET decision='rejected' WHERE proposal_id=?")->execute([$id]);
    json_response(['ok'=>true]);
}

json_response(['ok'=>false,'error'=>'Unknown action'],404);
