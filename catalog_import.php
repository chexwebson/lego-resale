<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ensure_dirs();
require_login();
$pdo=db();

function csv_handle(string $path){
  if(str_ends_with(strtolower($path),'.gz')){
    $h=gzopen($path,'rb'); if(!$h)throw new RuntimeException('Could not open gzip file.');
    return ['gz',$h];
  }
  $h=fopen($path,'rb'); if(!$h)throw new RuntimeException('Could not open CSV file.');
  return ['plain',$h];
}
function csv_line(array $h){
  if($h[0]==='gz'){
    $line=gzgets($h[1]); if($line===false)return false;
    return str_getcsv($line);
  }
  return fgetcsv($h[1]);
}
function csv_close(array $h):void{ $h[0]==='gz'?gzclose($h[1]):fclose($h[1]); }
function hmap(array $h):array{$m=[];foreach($h as $i=>$v)$m[trim((string)$v)]=$i;return $m;}
function cv(array $r,array $m,string $k){return isset($m[$k])?($r[$m[$k]]??null):null;}
function bool_csv($v):int{return in_array(strtolower(trim((string)$v)),['1','true','t','yes'],true)?1:0;}

function import_catalog_file(PDO $pdo,string $type,string $path):int{
  $fh=csv_handle($path);$head=csv_line($fh);if(!$head)throw new RuntimeException('CSV has no header.');
  $m=hmap($head);$count=0;$pdo->beginTransaction();
  try{
    if($type==='themes'){
      $st=$pdo->prepare("INSERT INTO themes(theme_id,name,parent_id) VALUES(?,?,?) ON CONFLICT(theme_id) DO UPDATE SET name=excluded.name,parent_id=excluded.parent_id");
      while(($r=csv_line($fh))!==false){$parent=cv($r,$m,'parent_id');$st->execute([(int)cv($r,$m,'id'),cv($r,$m,'name'),$parent===''?null:(int)$parent]);$count++;}
    }elseif($type==='sets'){
      $st=$pdo->prepare("INSERT INTO catalog_sets(set_num,base_set_num,variant,name,year,theme_id,num_parts,image_url,source,source_updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(set_num) DO UPDATE SET base_set_num=excluded.base_set_num,variant=excluded.variant,name=excluded.name,year=excluded.year,theme_id=excluded.theme_id,num_parts=excluded.num_parts,image_url=excluded.image_url,source=excluded.source,source_updated_at=excluded.source_updated_at");
      while(($r=csv_line($fh))!==false){$full=clean_set_num((string)cv($r,$m,'set_num'));if(!$full)continue;[$base,$variant]=split_set_num($full);$st->execute([$full,$base,$variant,cv($r,$m,'name'),(int)cv($r,$m,'year'),(int)cv($r,$m,'theme_id'),(int)cv($r,$m,'num_parts'),cv($r,$m,'img_url'),'rebrickable',now_iso()]);$count++;}
    }elseif($type==='colors'){
      $st=$pdo->prepare("INSERT INTO colors(color_id,name,rgb,is_trans) VALUES(?,?,?,?) ON CONFLICT(color_id) DO UPDATE SET name=excluded.name,rgb=excluded.rgb,is_trans=excluded.is_trans");
      while(($r=csv_line($fh))!==false){$st->execute([(int)cv($r,$m,'id'),cv($r,$m,'name'),cv($r,$m,'rgb'),bool_csv(cv($r,$m,'is_trans'))]);$count++;}
    }elseif($type==='part_categories'){
      $st=$pdo->prepare("INSERT INTO part_categories(category_id,name) VALUES(?,?) ON CONFLICT(category_id) DO UPDATE SET name=excluded.name");
      while(($r=csv_line($fh))!==false){$st->execute([(int)cv($r,$m,'id'),cv($r,$m,'name')]);$count++;}
    }elseif($type==='parts'){
      $st=$pdo->prepare("INSERT INTO parts(part_num,name,part_cat_id,part_material) VALUES(?,?,?,?) ON CONFLICT(part_num) DO UPDATE SET name=excluded.name,part_cat_id=excluded.part_cat_id,part_material=excluded.part_material");
      while(($r=csv_line($fh))!==false){$mat=cv($r,$m,'part_material');$st->execute([(string)cv($r,$m,'part_num'),cv($r,$m,'name'),(int)cv($r,$m,'part_cat_id'),$mat]);$count++;}
    }elseif($type==='inventories'){
      $st=$pdo->prepare("INSERT INTO catalog_inventories(inventory_id,version,set_num,ref_type) VALUES(?,?,?,?) ON CONFLICT(inventory_id) DO UPDATE SET version=excluded.version,set_num=excluded.set_num,ref_type=excluded.ref_type");
      while(($r=csv_line($fh))!==false){$set=clean_set_num((string)cv($r,$m,'set_num'));if(!$set)continue;$refType=str_starts_with($set,'fig-')?'minifig':(preg_match('/-\d+$/',$set)?'set':'other');$st->execute([(int)cv($r,$m,'id'),(int)cv($r,$m,'version'),$set,$refType]);$count++;}
    }elseif($type==='minifigs'){
      $st=$pdo->prepare("INSERT INTO catalog_minifigs(fig_num,name,num_parts,image_url) VALUES(?,?,?,?) ON CONFLICT(fig_num) DO UPDATE SET name=excluded.name,num_parts=excluded.num_parts,image_url=excluded.image_url");
      while(($r=csv_line($fh))!==false){$st->execute([(string)cv($r,$m,'fig_num'),cv($r,$m,'name'),(int)cv($r,$m,'num_parts'),cv($r,$m,'img_url')]);$count++;}
    }elseif($type==='inventory_parts'){
      $st=$pdo->prepare("INSERT INTO catalog_inventory_parts(inventory_id,part_num,color_id,quantity,is_spare,image_url) VALUES(?,?,?,?,?,?)
        ON CONFLICT(inventory_id,part_num,color_id,is_spare) DO UPDATE SET quantity=excluded.quantity,image_url=excluded.image_url");
      while(($r=csv_line($fh))!==false){$st->execute([(int)cv($r,$m,'inventory_id'),(string)cv($r,$m,'part_num'),(int)cv($r,$m,'color_id'),(int)cv($r,$m,'quantity'),bool_csv(cv($r,$m,'is_spare')),cv($r,$m,'img_url')]);$count++; if($count%50000===0){$pdo->commit();$pdo->beginTransaction();}}
    }elseif($type==='inventory_minifigs'){
      $st=$pdo->prepare("INSERT INTO catalog_inventory_minifigs(inventory_id,fig_num,quantity) VALUES(?,?,?) ON CONFLICT(inventory_id,fig_num) DO UPDATE SET quantity=excluded.quantity");
      while(($r=csv_line($fh))!==false){$st->execute([(int)cv($r,$m,'inventory_id'),(string)cv($r,$m,'fig_num'),(int)cv($r,$m,'quantity')]);$count++;}
    }elseif($type==='inventory_sets'){
      $st=$pdo->prepare("INSERT INTO catalog_inventory_sets(inventory_id,set_num,quantity) VALUES(?,?,?) ON CONFLICT(inventory_id,set_num) DO UPDATE SET quantity=excluded.quantity");
      while(($r=csv_line($fh))!==false){$st->execute([(int)cv($r,$m,'inventory_id'),(string)cv($r,$m,'set_num'),(int)cv($r,$m,'quantity')]);$count++;}
    }else throw new RuntimeException('Unsupported catalog type: '.$type);
    $pdo->commit();csv_close($fh);return $count;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();csv_close($fh);throw $e;}
}

if(($_GET['mode']??'')==='server_file'){
  $type=$_POST['type']??'';$path=$_POST['path']??'';
  $real=realpath($path);$allowed=realpath(__DIR__.'/data/catalog_seed');
  if(!$real||!$allowed||!str_starts_with($real,$allowed.DIRECTORY_SEPARATOR))json_response(['ok'=>false,'error'=>'Invalid server catalog path'],422);
  try{set_time_limit(0);$n=import_catalog_file($pdo,$type,$real);json_response(['ok'=>true,'type'=>$type,'rows_imported'=>$n]);}catch(Throwable $e){json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
}

$type=$_POST['type']??'';
if(!isset($_FILES['catalog_file'])||($_FILES['catalog_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_response(['ok'=>false,'error'=>'No file uploaded.'],422);
try{set_time_limit(0);$n=import_catalog_file($pdo,$type,$_FILES['catalog_file']['tmp_name']);json_response(['ok'=>true,'type'=>$type,'rows_imported'=>$n]);}
catch(Throwable $e){json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
