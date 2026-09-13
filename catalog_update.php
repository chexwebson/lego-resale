<?php
declare(strict_types=1);
require __DIR__.'/config.php';
ensure_dirs();
require_login();

$datasets=[
 'themes'=>'themes.csv.gz','sets'=>'sets.csv.gz','colors'=>'colors.csv.gz',
 'part_categories'=>'part_categories.csv.gz','parts'=>'parts.csv.gz',
 'inventories'=>'inventories.csv.gz','minifigs'=>'minifigs.csv.gz',
 'inventory_parts'=>'inventory_parts.csv.gz','inventory_minifigs'=>'inventory_minifigs.csv.gz',
 'inventory_sets'=>'inventory_sets.csv.gz'
];

$action=$_GET['action']??'';
$type=$_GET['type']??'';
if(!isset($datasets[$type]))json_response(['ok'=>false,'error'=>'Unknown dataset'],422);

if($action==='download'){
  if(!function_exists('curl_init'))json_response(['ok'=>false,'error'=>'cURL unavailable'],500);
  set_time_limit(0);
  $fn=$datasets[$type];$url='https://cdn.rebrickable.com/media/downloads/'.$fn;
  $dest=IMPORT_ROOT.'/'.$fn.'.download';
  $out=fopen($dest,'wb');if(!$out)json_response(['ok'=>false,'error'=>'Cannot open download destination'],500);
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_FILE=>$out,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_FAILONERROR=>true,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>0,CURLOPT_USERAGENT=>'LEGO-Resale/'.APP_VERSION]);
  $ok=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);fclose($out);
  if(!$ok||$code>=400){@unlink($dest);json_response(['ok'=>false,'error'=>"Download failed ($code): $err"],502);}
  $final=IMPORT_ROOT.'/'.$fn;@unlink($final);rename($dest,$final);
  json_response(['ok'=>true,'type'=>$type,'filename'=>$fn,'bytes'=>filesize($final),'server_path'=>$final]);
}
if($action==='seed_path'){
  $p=__DIR__.'/data/catalog_seed/'.$datasets[$type];
  if(!is_file($p))json_response(['ok'=>false,'error'=>'Bundled snapshot missing'],404);
  json_response(['ok'=>true,'type'=>$type,'server_path'=>$p,'bytes'=>filesize($p)]);
}
if($action==='downloaded_path'){
  $p=IMPORT_ROOT.'/'.$datasets[$type];
  if(!is_file($p))json_response(['ok'=>false,'error'=>'Latest file has not been downloaded yet'],404);
  json_response(['ok'=>true,'type'=>$type,'server_path'=>$p,'bytes'=>filesize($p)]);
}
json_response(['ok'=>false,'error'=>'Unknown action'],404);
