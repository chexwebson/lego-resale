<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ensure_dirs();
require_login();
$pdo=db();
$action=$_GET['action'] ?? '';


if ($action === 'delete') {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') json_response(['ok'=>false,'error'=>'POST required'],405);
    $raw=file_get_contents('php://input');
    $d=json_decode($raw?:'{}',true);
    if(!is_array($d)) json_response(['ok'=>false,'error'=>'Invalid JSON request'],400);
    $photoId=$d['photo_id']??'';
    if($photoId==='') json_response(['ok'=>false,'error'=>'Photo ID required'],400);
    $q=$pdo->prepare("SELECT * FROM unit_photos WHERE photo_id=?");$q->execute([$photoId]);$ph=$q->fetch();
    if(!$ph) json_response(['ok'=>false,'error'=>'Photo not found'],404);
    $unitId=$ph['unit_id']; $path=PHOTO_ROOT.'/'.$unitId.'/'.$ph['stored_filename'];
    $pdo->beginTransaction();
    try{
      $pdo->prepare("DELETE FROM unit_photos WHERE photo_id=?")->execute([$photoId]);
      $q=$pdo->prepare("SELECT photo_id FROM unit_photos WHERE unit_id=? ORDER BY sort_order,created_at,photo_id");$q->execute([$unitId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
      if($ids){
        $pdo->prepare("UPDATE unit_photos SET is_primary=0 WHERE unit_id=?")->execute([$unitId]);
        $pdo->prepare("UPDATE unit_photos SET is_primary=1 WHERE photo_id=?")->execute([$ids[0]]);
        foreach($ids as $i=>$pid)$pdo->prepare("UPDATE unit_photos SET sort_order=? WHERE photo_id=?")->execute([$i,$pid]);
      }
      $pdo->commit();
      if(is_file($path) && !@unlink($path)) json_response(['ok'=>true,'warning'=>'Photo record deleted, but stored file could not be removed.']);
      json_response(['ok'=>true]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
}
if ($action === 'download') {
    $photoId=$_GET['id']??'';
    $q=$pdo->prepare("SELECT p.*,u.display_code FROM unit_photos p JOIN inventory_units u ON u.unit_id=p.unit_id WHERE p.photo_id=?");
    $q->execute([$photoId]);$ph=$q->fetch(); if(!$ph){http_response_code(404);exit;}
    $path=PHOTO_ROOT.'/'.$ph['unit_id'].'/'.$ph['stored_filename']; if(!is_file($path)){http_response_code(404);exit;}
    $real=realpath($path);$root=realpath(PHOTO_ROOT); if(!$real||!$root||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)){http_response_code(403);exit;}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($real);
    $ext=strtolower(pathinfo($ph['filename']?:$ph['stored_filename'],PATHINFO_EXTENSION));
    if(!in_array($ext,['jpg','jpeg','png','webp'],true))$ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
    $role=preg_replace('/[^a-z0-9_-]+/i','-',strtolower((string)($ph['role']?:'photo')));
    $display=preg_replace('/[^a-z0-9_-]+/i','-',(string)$ph['display_code']);
    $name=$display.'-'.$role.'.'.$ext;
    header('Content-Type: '.$mime);header('Content-Length: '.filesize($real));
    header('Content-Disposition: inline; filename="'.$name.'"');
    header('Cache-Control: private,max-age=300');header('X-Content-Type-Options: nosniff');readfile($real);exit;
}
if($action==='upload'){
  $unit=$_POST['unit_id'] ?? '';
  $role=$_POST['role'] ?? 'unknown';
  $allowedRoles=['front','back','side','top','bottom','seal','damage','contents','instructions','label','other','unknown'];
  if(!in_array($role,$allowedRoles,true))$role='unknown';
  $aiReview=!empty($_POST['ai_review_requested'])?1:0;
  $s=$pdo->prepare("SELECT unit_id FROM inventory_units WHERE unit_id=?");$s->execute([$unit]);
  if(!$s->fetchColumn()) json_response(['ok'=>false,'error'=>'Unit not found'],404);
  if(!isset($_FILES['photo']) || $_FILES['photo']['error']!==UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'Photo upload failed'],422);
  $f=$_FILES['photo'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if(!isset($allowed[$mime])) json_response(['ok'=>false,'error'=>'Unsupported image type'],422);
  $dir=PHOTO_ROOT.'/'.$unit; if(!is_dir($dir))mkdir($dir,0775,true);
  $photoId=uuidv4();$stored=$photoId.'.'.$allowed[$mime];
  if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$stored))json_response(['ok'=>false,'error'=>'Could not store photo'],500);
  $q=$pdo->prepare("SELECT COUNT(*) photo_count, COALESCE(MAX(sort_order),-1) max_sort FROM unit_photos WHERE unit_id=?");$q->execute([$unit]);$st=$q->fetch();
  $isPrimary=((int)($st['photo_count']??0)===0)?1:0;$sortOrder=(int)($st['max_sort']??-1)+1;
  $pdo->prepare("INSERT INTO unit_photos(photo_id,unit_id,filename,stored_filename,role,is_primary,sort_order,created_at,ai_review_requested) VALUES(?,?,?,?,?,?,?,?,?)")
      ->execute([$photoId,$unit,$f['name'],$stored,$role,$isPrimary,$sortOrder,now_iso(),$aiReview]);
  json_response(['ok'=>true,'photo_id'=>$photoId]);
}
if($action==='view'){
  $id=$_GET['id'] ?? '';
  $s=$pdo->prepare("SELECT p.*,u.unit_id FROM unit_photos p JOIN inventory_units u ON u.unit_id=p.unit_id WHERE p.photo_id=?");$s->execute([$id]);$p=$s->fetch();
  if(!$p) {http_response_code(404);exit;}
  $path=PHOTO_ROOT.'/'.$p['unit_id'].'/'.$p['stored_filename']; if(!is_file($path)){http_response_code(404);exit;}
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);header('Content-Type: '.$mime);header('Cache-Control: private,max-age=3600');readfile($path);exit;
}
json_response(['ok'=>false,'error'=>'Unknown action'],404);
