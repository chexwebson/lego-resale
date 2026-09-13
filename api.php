<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ensure_dirs();

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()],500);
}

$action = $_GET['action'] ?? '';

// Owner-only: anything touching money (valuations, pricing, sale/listing economics) or customer PII.
const OWNER_ONLY_ACTIONS = [
    'inventory_summary','valuation_add','pricing_calculate',
    'customers','customer_create','customer_update','interest_add','listing_metric_add','mark_sold',
    'listing_generate','ebay_draft_get','ebay_draft_generate','ebay_draft_save',
];

if ($action === 'whoami') {
    $u = current_user();
    json_response($u ? ['ok'=>true,'user'=>$u] : ['ok'=>false,'error'=>'Not signed in'],$u?200:401);
}

$currentUser = in_array($action, OWNER_ONLY_ACTIONS, true) ? require_role([ROLE_OWNER]) : require_login();

function redact_money_fields(array $row): array {
    foreach (['valuation','pricing','listing','sale','listing_metrics','interests'] as $k) unset($row[$k]);
    return $row;
}

function next_display_code(PDO $pdo, string $setNum): string {
    $stmt = $pdo->prepare("SELECT display_code FROM inventory_units WHERE catalog_set_num=? ORDER BY created_at");
    $stmt->execute([$setNum]);
    $used = [];
    foreach ($stmt->fetchAll() as $r) {
        if (preg_match('/-([A-Z]+)$/', $r['display_code'], $m)) $used[$m[1]] = true;
    }
    for ($i=0;$i<1000;$i++) {
        $n=$i; $suffix='';
        do { $suffix = chr(65 + ($n % 26)) . $suffix; $n = intdiv($n,26)-1; } while($n>=0);
        if (!isset($used[$suffix])) return $setNum . '-' . $suffix;
    }
    return $setNum . '-' . strtoupper(substr(uuidv4(),0,4));
}

function latest_valuation(PDO $pdo, string $unitId): ?array {
    $s=$pdo->prepare("SELECT * FROM valuations WHERE unit_id=? ORDER BY observed_at DESC LIMIT 1");
    $s->execute([$unitId]); return $s->fetch() ?: null;
}
function latest_pricing(PDO $pdo, string $unitId): ?array {
    $s=$pdo->prepare("SELECT * FROM pricing_recommendations WHERE unit_id=? ORDER BY calculated_at DESC LIMIT 1");
    $s->execute([$unitId]); return $s->fetch() ?: null;
}

if ($action === 'status') {
    $counts=[];
    foreach (['catalog_sets','parts','colors','catalog_inventories','catalog_inventory_parts','catalog_minifigs','inventory_units','unit_photos','valuations','sales','customers','customer_interests','listing_metrics','import_batches'] as $t) {
        $counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    json_response(['ok'=>true,'version'=>APP_VERSION,'counts'=>$counts]);
}

if ($action === 'catalog_search') {
    $q=trim($_GET['q'] ?? '');
    if ($q==='') json_response(['ok'=>true,'items'=>[]]);
    $like='%'.$q.'%';
    $s=$pdo->prepare("SELECT s.*, t.name theme_name FROM catalog_sets s LEFT JOIN themes t ON t.theme_id=s.theme_id
                     WHERE s.set_num LIKE ? OR s.base_set_num LIKE ? OR s.name LIKE ?
                     ORDER BY CASE WHEN s.set_num=? THEN 0 WHEN s.base_set_num=? THEN 1 ELSE 2 END, s.year DESC LIMIT 50");
    $s->execute([$like,$like,$like,$q,$q]);
    json_response(['ok'=>true,'items'=>$s->fetchAll()]);
}


if ($action === 'catalog_composition') {
    $setNum=clean_set_num($_GET['set_num']??null);
    if(!$setNum)json_response(['ok'=>false,'error'=>'set_num required'],422);
    $s=$pdo->prepare("SELECT s.*,t.name theme_name FROM catalog_sets s LEFT JOIN themes t ON t.theme_id=s.theme_id WHERE s.set_num=?");
    $s->execute([$setNum]);$set=$s->fetch();if(!$set)json_response(['ok'=>false,'error'=>'Catalog set not found'],404);
    $iv=$pdo->prepare("SELECT * FROM catalog_inventories WHERE set_num=? ORDER BY version DESC, inventory_id DESC");$iv->execute([$setNum]);$inventories=$iv->fetchAll();
    $inventoryId=(int)($_GET['inventory_id']??($inventories[0]['inventory_id']??0));
    $parts=[];$figs=[];$subs=[];
    if($inventoryId){
      $p=$pdo->prepare("SELECT ip.part_num,p.name part_name,ip.color_id,c.name color_name,c.rgb,ip.quantity,ip.is_spare,ip.image_url
        FROM catalog_inventory_parts ip LEFT JOIN parts p ON p.part_num=ip.part_num LEFT JOIN colors c ON c.color_id=ip.color_id
        WHERE ip.inventory_id=? ORDER BY ip.is_spare, c.name, p.name");
      $p->execute([$inventoryId]);$parts=$p->fetchAll();
      $f=$pdo->prepare("SELECT im.fig_num,m.name,m.num_parts,m.image_url,im.quantity FROM catalog_inventory_minifigs im
        LEFT JOIN catalog_minifigs m ON m.fig_num=im.fig_num WHERE im.inventory_id=? ORDER BY m.name");
      $f->execute([$inventoryId]);$figs=$f->fetchAll();
      $q=$pdo->prepare("SELECT cis.set_num,s.name,cis.quantity FROM catalog_inventory_sets cis LEFT JOIN catalog_sets s ON s.set_num=cis.set_num WHERE cis.inventory_id=? ORDER BY cis.set_num");
      $q->execute([$inventoryId]);$subs=$q->fetchAll();
    }
    json_response(['ok'=>true,'set'=>$set,'inventories'=>$inventories,'inventory_id'=>$inventoryId,'parts'=>$parts,'minifigs'=>$figs,'subsets'=>$subs]);
}

if ($action === 'part_candidates') {
    $part=trim($_GET['part_num']??'');$color=$_GET['color_id']??'';
    if($part==='')json_response(['ok'=>true,'items'=>[]]);
    $sql="SELECT i.set_num,s.name,s.year,COUNT(DISTINCT ip.part_num||':'||ip.color_id) matched_keys
          FROM catalog_inventory_parts ip JOIN catalog_inventories i ON i.inventory_id=ip.inventory_id
          LEFT JOIN catalog_sets s ON s.set_num=i.set_num WHERE ip.part_num=?";
    $args=[$part];
    if($color!==''){$sql.=" AND ip.color_id=?";$args[]=(int)$color;}
    $sql.=" GROUP BY i.set_num,s.name,s.year ORDER BY s.year DESC LIMIT 100";
    $st=$pdo->prepare($sql);$st->execute($args);json_response(['ok'=>true,'items'=>$st->fetchAll()]);
}


if ($action === 'inventory_summary') {
    $activeStatuses = ['available','listed','reserved'];
    $activePlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));

    $activeSql = "
      SELECT u.unit_id,
             (
               SELECT v.msrp
               FROM valuations v
               WHERE v.unit_id=u.unit_id
               ORDER BY v.observed_at DESC, v.rowid DESC
               LIMIT 1
             ) AS msrp,
             (
               SELECT CASE
                 WHEN u.sealed_status='sealed' AND v.whole_new IS NOT NULL THEN v.whole_new
                 WHEN u.condition_state='new' AND v.whole_new IS NOT NULL THEN v.whole_new
                 WHEN v.whole_used IS NOT NULL THEN v.whole_used
                 ELSE v.whole_new
               END
               FROM valuations v
               WHERE v.unit_id=u.unit_id
               ORDER BY v.observed_at DESC, v.rowid DESC
               LIMIT 1
             ) AS current_value,
             (
               SELECT p.ask_price
               FROM pricing_recommendations p
               WHERE p.unit_id=u.unit_id
               ORDER BY p.calculated_at DESC, p.rowid DESC
               LIMIT 1
             ) AS ask_price
      FROM inventory_units u
      WHERE u.status IN ($activePlaceholders)
    ";
    $s=$pdo->prepare($activeSql);$s->execute($activeStatuses);$activeRows=$s->fetchAll();

    $active=[
      'units'=>count($activeRows),
      'total_msrp'=>0.0,
      'total_current_value'=>0.0,
      'total_ask'=>0.0,
      'units_with_msrp'=>0,
      'units_with_current_value'=>0,
      'units_with_ask'=>0
    ];

    foreach($activeRows as $r){
      if($r['msrp']!==null){$active['total_msrp']+=(float)$r['msrp'];$active['units_with_msrp']++;}
      if($r['current_value']!==null){$active['total_current_value']+=(float)$r['current_value'];$active['units_with_current_value']++;}
      if($r['ask_price']!==null){$active['total_ask']+=(float)$r['ask_price'];$active['units_with_ask']++;}
    }

    $soldSql = "
      SELECT u.unit_id,
             s.sold_price,
             s.listed_price,
             s.fees,
             s.shipping,
             s.projected_ask, s.projected_target, s.projected_minimum,
             (
               SELECT v.msrp
               FROM valuations v
               WHERE v.unit_id=u.unit_id
               ORDER BY v.observed_at DESC, v.rowid DESC
               LIMIT 1
             ) AS msrp,
             (
               SELECT CASE
                 WHEN u.sealed_status='sealed' AND v.whole_new IS NOT NULL THEN v.whole_new
                 WHEN u.condition_state='new' AND v.whole_new IS NOT NULL THEN v.whole_new
                 WHEN v.whole_used IS NOT NULL THEN v.whole_used
                 ELSE v.whole_new
               END
               FROM valuations v
               WHERE v.unit_id=u.unit_id
               ORDER BY v.observed_at DESC, v.rowid DESC
               LIMIT 1
             ) AS last_market_value
      FROM sales s
      JOIN inventory_units u ON u.unit_id=s.unit_id
      ORDER BY s.sold_at DESC
    ";
    $soldRows=$pdo->query($soldSql)->fetchAll();

    $sold=[
      'units'=>count($soldRows),
      'total_msrp'=>0.0,
      'total_last_market_value'=>0.0,
      'total_listed_price'=>0.0,
      'total_sold_price'=>0.0,
      'total_fees'=>0.0,
      'total_shipping'=>0.0,
      'total_net'=>0.0,
      'units_with_msrp'=>0,
      'units_with_market_value'=>0,
      'units_with_listed_price'=>0,
      'total_projected_ask'=>0.0,'total_projected_target'=>0.0,'total_projected_minimum'=>0.0,
      'variance_to_ask'=>0.0,'variance_to_target'=>0.0
    ];

    foreach($soldRows as $r){
      if($r['msrp']!==null){$sold['total_msrp']+=(float)$r['msrp'];$sold['units_with_msrp']++;}
      if($r['last_market_value']!==null){$sold['total_last_market_value']+=(float)$r['last_market_value'];$sold['units_with_market_value']++;}
      if($r['listed_price']!==null){$sold['total_listed_price']+=(float)$r['listed_price'];$sold['units_with_listed_price']++;}
      $soldPrice=(float)$r['sold_price'];
      $fees=(float)($r['fees']??0);
      $shipping=(float)($r['shipping']??0);
      $sold['total_sold_price'] += $soldPrice;
      $sold['total_fees'] += $fees;
      $sold['total_shipping'] += $shipping;
      $sold['total_net'] += $soldPrice - $fees - $shipping;
      if($r['projected_ask']!==null)$sold['total_projected_ask']+=(float)$r['projected_ask'];
      if($r['projected_target']!==null)$sold['total_projected_target']+=(float)$r['projected_target'];
      if($r['projected_minimum']!==null)$sold['total_projected_minimum']+=(float)$r['projected_minimum'];
    }

    $sold['variance_to_ask']=$sold['total_sold_price']-$sold['total_projected_ask'];
    $sold['variance_to_target']=$sold['total_sold_price']-$sold['total_projected_target'];
    json_response(['ok'=>true,'summary'=>['unsold'=>$active,'sold'=>$sold]]);
}

if ($action === 'units') {
    $q=trim($_GET['q'] ?? '');
    $where=''; $args=[];
    if($q!==''){ $where="WHERE u.display_code LIKE ? OR s.set_num LIKE ? OR s.name LIKE ?"; $args=array_fill(0,3,'%'.$q.'%');}
    $s=$pdo->prepare("SELECT u.*, s.name set_name, s.set_num, s.year, t.name theme_name,
        (SELECT COUNT(*) FROM unit_photos p WHERE p.unit_id=u.unit_id) photo_count
        FROM inventory_units u
        LEFT JOIN catalog_sets s ON s.set_num=u.catalog_set_num
        LEFT JOIN themes t ON t.theme_id=s.theme_id
        $where ORDER BY u.updated_at DESC LIMIT 500");
    $s->execute($args);
    $items=$s->fetchAll();
    foreach($items as &$it){ $it['valuation']=latest_valuation($pdo,$it['unit_id']); $it['pricing']=latest_pricing($pdo,$it['unit_id']);}
    if($currentUser['role']!==ROLE_OWNER) $items=array_map('redact_money_fields',$items);
    json_response(['ok'=>true,'items'=>$items]);
}

if ($action === 'unit_get') {
    $id=$_GET['id'] ?? '';
    $s=$pdo->prepare("SELECT u.*, s.name set_name, s.set_num, s.year, s.num_parts, t.name theme_name
        FROM inventory_units u LEFT JOIN catalog_sets s ON s.set_num=u.catalog_set_num
        LEFT JOIN themes t ON t.theme_id=s.theme_id WHERE u.unit_id=?");
    $s->execute([$id]); $u=$s->fetch();
    if(!$u) json_response(['ok'=>false,'error'=>'Unit not found'],404);
    $p=$pdo->prepare("SELECT * FROM unit_photos WHERE unit_id=? ORDER BY is_primary DESC, sort_order, created_at"); $p->execute([$id]);
    $u['photos']=$p->fetchAll(); $u['valuation']=latest_valuation($pdo,$id); $u['pricing']=latest_pricing($pdo,$id);
    $l=$pdo->prepare("SELECT * FROM listings WHERE unit_id=? ORDER BY created_at DESC LIMIT 1"); $l->execute([$id]); $u['listing']=$l->fetch()?:null;
    $sl=$pdo->prepare("SELECT s.*,c.name customer_name,c.organization customer_organization FROM sales s LEFT JOIN customers c ON c.customer_id=s.customer_id WHERE s.unit_id=?");$sl->execute([$id]);$u['sale']=$sl->fetch()?:null;
    $ci=$pdo->prepare("SELECT i.*,c.name customer_name,c.organization customer_organization FROM customer_interests i JOIN customers c ON c.customer_id=i.customer_id WHERE i.unit_id=? OR (i.unit_id IS NULL AND i.catalog_set_num=?) ORDER BY i.updated_at DESC");$ci->execute([$id,$u['catalog_set_num']]);$u['interests']=$ci->fetchAll();
    $u['listing_metrics']=[]; if($u['listing']){$lm=$pdo->prepare("SELECT * FROM listing_metrics WHERE listing_id=? ORDER BY observed_at DESC LIMIT 20");$lm->execute([$u['listing']['listing_id']]);$u['listing_metrics']=$lm->fetchAll();}
    if($currentUser['role']!==ROLE_OWNER) $u=redact_money_fields($u);
    json_response(['ok'=>true,'item'=>$u]);
}

if ($action === 'unit_create') {
    $d=body_json();
    $setNum=clean_set_num($d['set_num'] ?? null);
    if(!$setNum) json_response(['ok'=>false,'error'=>'Exact full set number is required (example: 76058-1).'],422);
    $c=$pdo->prepare("SELECT set_num FROM catalog_sets WHERE set_num=?"); $c->execute([$setNum]);
    if(!$c->fetchColumn()) json_response(['ok'=>false,'error'=>'Catalog set does not exist. Add/import it first.'],422);
    $unitId=uuidv4(); $display=next_display_code($pdo,$setNum); $now=now_iso();
    $s=$pdo->prepare("INSERT INTO inventory_units(unit_id,item_type,catalog_set_num,display_code,status,condition_state,sealed_status,box_condition,instructions_status,completeness,storage_location,notes,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$unitId,'set',$setNum,$display,$d['status']??'available',$d['condition_state']??'unknown',$d['sealed_status']??'unknown',$d['box_condition']??'unknown',$d['instructions_status']??'unknown',$d['completeness']??'unknown',$d['storage_location']??null,$d['notes']??null,$now,$now]);
    queue_pov_for_set($pdo,$setNum,false);
    json_response(['ok'=>true,'unit_id'=>$unitId,'display_code'=>$display]);
}

if ($action === 'unit_update') {
    $d=body_json(); $id=$d['unit_id']??'';
    $enums=[
      'status'=>['available','listed','reserved','sold','archived'],
      'condition_state'=>['unknown','new','used_excellent','used_good','used_worn','damaged'],
      'sealed_status'=>['unknown','sealed','opened'],
      'box_condition'=>['unknown','excellent','good','worn','damaged','none'],
      'instructions_status'=>['unknown','included','missing','not_applicable'],
      'completeness'=>['unknown','complete','believed_complete','incomplete'],
    ];
    $fields=['status','condition_state','sealed_status','box_condition','instructions_status','completeness','storage_location','notes'];
    $sets=[];$args=[];
    foreach($fields as $f){
        if(!array_key_exists($f,$d)) continue;
        $v=$d[$f];
        if(isset($enums[$f]) && !in_array($v,$enums[$f],true)) json_response(['ok'=>false,'error'=>"Invalid value for $f."],422);
        $sets[]="$f=?";$args[]=$v;
    }
    if(!$sets) json_response(['ok'=>false,'error'=>'Nothing to update'],422);
    $sets[]='updated_at=?';$args[]=now_iso();$args[]=$id;
    $pdo->prepare("UPDATE inventory_units SET ".implode(',',$sets)." WHERE unit_id=?")->execute($args);
    json_response(['ok'=>true]);
}

if ($action === 'valuation_add') {
    $d=body_json(); $unit=$d['unit_id']??'';
    $id=uuidv4(); $when=now_iso();
    $s=$pdo->prepare("INSERT INTO valuations(valuation_id,unit_id,observed_at,currency,source_label,msrp,whole_new,whole_used,part_out_sold,part_out_for_sale,notes)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$id,$unit,$when,'USD',$d['source_label']??'manual',$d['msrp']??null,$d['whole_new']??null,$d['whole_used']??null,$d['part_out_sold']??null,$d['part_out_for_sale']??null,$d['notes']??null]);
    json_response(['ok'=>true,'valuation_id'=>$id]);
}

if ($action === 'pricing_calculate') {
    $d=body_json(); $unit=$d['unit_id']??'';
    $v=latest_valuation($pdo,$unit);
    if(!$v) json_response(['ok'=>false,'error'=>'Add a valuation first.'],422);
    $u=$pdo->prepare("SELECT * FROM inventory_units WHERE unit_id=?"); $u->execute([$unit]); $unitRow=$u->fetch();
    $market = null;
    if (in_array($unitRow['sealed_status'],['sealed','unknown'],true) && $v['whole_new']!==null) $market=(float)$v['whole_new'];
    elseif($v['whole_used']!==null) $market=(float)$v['whole_used'];
    elseif($v['whole_new']!==null) $market=(float)$v['whole_new'];
    if($market===null) json_response(['ok'=>false,'error'=>'Whole-set market reference missing.'],422);
    $pov = $v['part_out_sold']!==null ? (float)$v['part_out_sold'] : null;
    $ratio = ($pov && $market>0) ? $pov/$market : null;
    $povPremium=0.0;
    if($ratio!==null){
        if($ratio>=3.0)$povPremium=.25; elseif($ratio>=2.5)$povPremium=.20; elseif($ratio>=2.0)$povPremium=.15;
        elseif($ratio>=1.5)$povPremium=.09; elseif($ratio>=1.2)$povPremium=.04;
    }
    $conditionAdj=0.0;
    if(in_array($unitRow['box_condition'],['worn','damaged'],true)) $conditionAdj = $unitRow['box_condition']==='damaged' ? -.06 : -.03;
    if($unitRow['condition_state']==='damaged') $conditionAdj-=.08;
    $askFactor=1.06+$povPremium+$conditionAdj;
    $targetFactor=.96+($povPremium*.55)+$conditionAdj;
    $minFactor=.86+($povPremium*.30)+$conditionAdj;
    $round5=fn(float $n)=>max(1, round($n/5)*5);
    $ask=$round5($market*$askFactor); $target=$round5($market*$targetFactor); $min=$round5($market*$minFactor);
    $exp=[
      'Whole-set reference: $'.number_format($market,2),
      $ratio!==null ? 'Sold-based POV ratio: '.number_format($ratio,2).'x' : 'POV unavailable: neutral support',
      'POV ask support: +'.round($povPremium*100).'%',
      'Condition adjustment: '.round($conditionAdj*100).'%',
      'Marketplace negotiation buffer included in ask.'
    ];
    $id=uuidv4();
    $pdo->prepare("INSERT INTO pricing_recommendations(pricing_id,unit_id,valuation_id,calculated_at,market_reference,pov_ratio,ask_price,target_price,minimum_price,strategy,explanation_json)
                   VALUES(?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id,$unit,$v['valuation_id'],now_iso(),$market,$ratio,$ask,$target,$min,'sell_complete',json_encode($exp)]);
    json_response(['ok'=>true,'pricing'=>['pricing_id'=>$id,'market_reference'=>$market,'pov_ratio'=>$ratio,'ask_price'=>$ask,'target_price'=>$target,'minimum_price'=>$min,'explanation'=>$exp]]);
}


if ($action === 'photo_role_update') {
    $d=body_json(); $photoId=$d['photo_id']??''; $role=trim((string)($d['role']??'other'));
    $allowed=['front','back','side','top','bottom','seal','damage','contents','instructions','label','other','unknown'];
    if(!in_array($role,$allowed,true)) json_response(['ok'=>false,'error'=>'Invalid photo role'],400);
    $s=$pdo->prepare("UPDATE unit_photos SET role=? WHERE photo_id=?"); $s->execute([$role,$photoId]);
    json_response(['ok'=>true]);
}


if ($action === 'photo_ai_review_update') {
    $d=body_json();$photoId=$d['photo_id']??'';$flag=!empty($d['requested'])?1:0;
    $pdo->prepare("UPDATE unit_photos SET ai_review_requested=? WHERE photo_id=?")->execute([$flag,$photoId]);
    json_response(['ok'=>true]);
}

if ($action === 'customers') {
    $items=$pdo->query("SELECT c.*,(SELECT COUNT(*) FROM customer_interests i WHERE i.customer_id=c.customer_id) interest_count,(SELECT COUNT(*) FROM sales s WHERE s.customer_id=c.customer_id) purchase_count FROM customers c ORDER BY c.name COLLATE NOCASE")->fetchAll();
    foreach($items as &$c){$q=$pdo->prepare("SELECT i.*,s.name set_name,u.display_code FROM customer_interests i LEFT JOIN catalog_sets s ON s.set_num=i.catalog_set_num LEFT JOIN inventory_units u ON u.unit_id=i.unit_id WHERE i.customer_id=? ORDER BY i.updated_at DESC");$q->execute([$c['customer_id']]);$c['interests']=$q->fetchAll();}
    json_response(['ok'=>true,'items'=>$items]);
}
if ($action === 'customer_create') {
    $d=body_json();$name=trim((string)($d['name']??''));if($name==='')json_response(['ok'=>false,'error'=>'Customer name required'],422);
    $id=uuidv4();$now=now_iso();$pdo->prepare("INSERT INTO customers(customer_id,name,organization,email,phone,marketplace_profile,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$id,$name,trim((string)($d['organization']??''))?:null,trim((string)($d['email']??''))?:null,trim((string)($d['phone']??''))?:null,trim((string)($d['marketplace_profile']??''))?:null,trim((string)($d['notes']??''))?:null,$now,$now]);
    json_response(['ok'=>true,'customer_id'=>$id]);
}
if ($action === 'customer_update') {
    $d=body_json();$id=trim((string)($d['customer_id']??''));$name=trim((string)($d['name']??''));
    if($id==='')json_response(['ok'=>false,'error'=>'Customer ID required'],422);
    if($name==='')json_response(['ok'=>false,'error'=>'Customer name required'],422);
    $q=$pdo->prepare("SELECT customer_id FROM customers WHERE customer_id=?");$q->execute([$id]);
    if(!$q->fetchColumn())json_response(['ok'=>false,'error'=>'Customer not found'],404);
    $pdo->prepare("UPDATE customers SET name=?,organization=?,email=?,phone=?,marketplace_profile=?,notes=?,updated_at=? WHERE customer_id=?")
      ->execute([$name,trim((string)($d['organization']??''))?:null,trim((string)($d['email']??''))?:null,trim((string)($d['phone']??''))?:null,trim((string)($d['marketplace_profile']??''))?:null,trim((string)($d['notes']??''))?:null,now_iso(),$id]);
    json_response(['ok'=>true,'customer_id'=>$id]);
}
if ($action === 'interest_add') {
    $d=body_json();$customer=$d['customer_id']??'';$setNum=clean_set_num($d['catalog_set_num']??null);$unit=trim((string)($d['unit_id']??''))?:null;
    if($customer===''||(!$setNum&&!$unit))json_response(['ok'=>false,'error'=>'Customer and set/unit are required'],422);
    if(!$setNum&&$unit){$q=$pdo->prepare("SELECT catalog_set_num FROM inventory_units WHERE unit_id=?");$q->execute([$unit]);$setNum=$q->fetchColumn()?:null;}
    $target=optional_numeric($d['target_price']??null,'Target price');
    $id=uuidv4();$now=now_iso();$pdo->prepare("INSERT INTO customer_interests(interest_id,customer_id,catalog_set_num,unit_id,status,target_price,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$id,$customer,$setNum,$unit,$d['status']??'interested',$target,trim((string)($d['notes']??''))?:null,$now,$now]);
    json_response(['ok'=>true,'interest_id'=>$id]);
}
function append_customer_note(PDO $pdo, string $customerId, string $note): void {
    if ($note === '') return;
    $s=$pdo->prepare("SELECT notes FROM customers WHERE customer_id=?");$s->execute([$customerId]);
    $existing=trim((string)$s->fetchColumn());
    $stamped='['.now_iso().'] '.$note;
    $pdo->prepare("UPDATE customers SET notes=?,updated_at=? WHERE customer_id=?")
        ->execute([$existing!==''?$existing."\n".$stamped:$stamped, now_iso(), $customerId]);
}
if ($action === 'listing_metric_add') {
    $d=body_json();$listing=$d['listing_id']??'';if($listing==='')json_response(['ok'=>false,'error'=>'Listing required'],422);
    $id=uuidv4();$pdo->prepare("INSERT INTO listing_metrics(metric_id,listing_id,observed_at,clicks,saves,shares,inquiries,notes) VALUES(?,?,?,?,?,?,?,?)")->execute([$id,$listing,now_iso(),max(0,(int)($d['clicks']??0)),max(0,(int)($d['saves']??0)),max(0,(int)($d['shares']??0)),max(0,(int)($d['inquiries']??0)),trim((string)($d['notes']??''))?:null]);
    json_response(['ok'=>true,'metric_id'=>$id]);
}
if ($action === 'mark_sold') {
    $d=body_json();$unit=trim((string)($d['unit_id']??''));
    if($unit==='')json_response(['ok'=>false,'error'=>'Unit is required'],422);
    $sold=require_numeric($d['sold_price']??null,'Sold price');
    if($sold<=0)json_response(['ok'=>false,'error'=>'Sold price must be greater than zero.'],422);
    $fees=optional_numeric($d['fees']??null,'Fees')??0.0;
    $shipping=optional_numeric($d['shipping']??null,'Shipping')??0.0;
    $listedPrice=optional_numeric($d['listed_price']??null,'Listed price');
    $q=$pdo->prepare("SELECT * FROM inventory_units WHERE unit_id=?");$q->execute([$unit]);$u=$q->fetch();if(!$u)json_response(['ok'=>false,'error'=>'Unit not found'],404);
    $pr=latest_pricing($pdo,$unit);$listing=$pdo->prepare("SELECT * FROM listings WHERE unit_id=? ORDER BY updated_at DESC LIMIT 1");$listing->execute([$unit]);$li=$listing->fetch()?:null;
    $now=now_iso();$soldAt=trim((string)($d['sold_at']??''))?:$now;
    $buyerNotes=trim((string)($d['buyer_notes']??($d['notes']??'')));

    $customer=trim((string)($d['customer_id']??''))?:null;
    $newCustomer=$d['new_customer']??null;
    if(!$customer && is_array($newCustomer) && trim((string)($newCustomer['name']??''))!==''){
        $customer=uuidv4();
        $pdo->prepare("INSERT INTO customers(customer_id,name,organization,email,phone,marketplace_profile,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([$customer,trim((string)$newCustomer['name']),trim((string)($newCustomer['organization']??''))?:null,trim((string)($newCustomer['email']??''))?:null,trim((string)($newCustomer['phone']??''))?:null,trim((string)($newCustomer['marketplace_profile']??''))?:null,null,$now,$now]);
    }

    $pdo->beginTransaction();try{
      $pdo->prepare("INSERT INTO sales(sale_id,unit_id,platform,listed_price,sold_price,listed_at,sold_at,fees,shipping,notes,customer_id,projected_ask,projected_target,projected_minimum,projected_market) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(unit_id) DO UPDATE SET platform=excluded.platform,listed_price=excluded.listed_price,sold_price=excluded.sold_price,listed_at=excluded.listed_at,sold_at=excluded.sold_at,fees=excluded.fees,shipping=excluded.shipping,notes=excluded.notes,customer_id=excluded.customer_id,projected_ask=excluded.projected_ask,projected_target=excluded.projected_target,projected_minimum=excluded.projected_minimum,projected_market=excluded.projected_market")
        ->execute([uuidv4(),$unit,$d['platform']??($li['platform']??'facebook_marketplace'),$listedPrice??($li['asking_price']??($pr['ask_price']??null)),$sold,$li['created_at']??null,$soldAt,$fees,$shipping,$buyerNotes?:null,$customer,$pr['ask_price']??null,$pr['target_price']??null,$pr['minimum_price']??null,$pr['market_reference']??null]);
      $pdo->prepare("UPDATE inventory_units SET status='sold',updated_at=? WHERE unit_id=?")->execute([$now,$unit]);
      $pdo->prepare("UPDATE listings SET status='sold',updated_at=? WHERE unit_id=? AND status IN ('draft','active','listed')")->execute([$now,$unit]);
      if($customer && $buyerNotes!=='') append_customer_note($pdo,$customer,$buyerNotes.' (from sale of '.$u['display_code'].')');
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
    json_response(['ok'=>true,'customer_id'=>$customer]);
}

if ($action === 'listing_generate') {
    $d=body_json(); $unit=$d['unit_id']??'';
    $s=$pdo->prepare("SELECT u.*, c.set_num,c.name,c.year,c.num_parts,t.name theme_name
        FROM inventory_units u
        JOIN catalog_sets c ON c.set_num=u.catalog_set_num
        LEFT JOIN themes t ON t.theme_id=c.theme_id
        WHERE u.unit_id=?");
    $s->execute([$unit]);$x=$s->fetch();
    if(!$x) json_response(['ok'=>false,'error'=>'Unit not found'],404);

    $p=latest_pricing($pdo,$unit);
    $conditionBits=[];

    if($x['sealed_status']==='sealed'){
        $conditionBits[]='Factory sealed';
    } elseif($x['sealed_status']==='opened'){
        if($x['condition_state']==='new') $conditionBits[]='Opened but contents are new';
        else $conditionBits[]='Opened';
    }

    $boxMap=[
        'excellent'=>'excellent box condition',
        'good'=>'good box condition',
        'worn'=>'visible shelf/edge wear',
        'damaged'=>'box damage/wear shown in the photos',
        'none'=>'no original box'
    ];
    if(isset($boxMap[$x['box_condition']])) $conditionBits[]=$boxMap[$x['box_condition']];

    if($x['instructions_status']==='included') $conditionBits[]='instructions included';
    elseif($x['instructions_status']==='missing') $conditionBits[]='instructions not included';

    if($x['completeness']==='complete') $conditionBits[]='complete';
    elseif($x['completeness']==='believed_complete') $conditionBits[]='believed complete';
    elseif($x['completeness']==='incomplete') $conditionBits[]='incomplete';
    // Legacy sealed_unverified is intentionally ignored; sealed state is represented by sealed_status.

    $title="LEGO {$x['set_num']} {$x['name']}";
    if($x['sealed_status']==='sealed') $title.=" - New Sealed";

    $theme=trim((string)($x['theme_name']??''));
    $desc="LEGO ".($theme!==''?$theme.' ':'')."set {$x['set_num']} — {$x['name']}.";
    if($x['year']) $desc.=" Released in {$x['year']}.";
    if($x['num_parts']) $desc.=" {$x['num_parts']} pieces.";
    if($conditionBits) $desc.=" Condition: ".implode('; ',$conditionBits).".";
    $desc.=" Photos shown are of the exact item being sold.";
    $desc.=" Local pickup preferred; cross-posted.";

    $now=now_iso();
    $existing=$pdo->prepare("SELECT * FROM listings
        WHERE unit_id=? AND status='draft'
        ORDER BY updated_at DESC, created_at DESC
        LIMIT 1");
    $existing->execute([$unit]);
    $draft=$existing->fetch();

    if($draft){
        $pdo->prepare("UPDATE listings
            SET platform=?,title=?,description=?,asking_price=?,updated_at=?
            WHERE listing_id=?")
            ->execute([
                $draft['platform']?:'facebook_marketplace',
                $title,$desc,$p['ask_price']??null,$now,$draft['listing_id']
            ]);
        $id=$draft['listing_id'];
        $regenerated=true;
    } else {
        $id=uuidv4();
        $pdo->prepare("INSERT INTO listings(
            listing_id,unit_id,platform,status,title,description,asking_price,created_at,updated_at
        ) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([
                $id,$unit,'facebook_marketplace','draft',
                $title,$desc,$p['ask_price']??null,$now,$now
            ]);
        $regenerated=false;
    }

    json_response(['ok'=>true,'regenerated'=>$regenerated,'listing'=>[
        'listing_id'=>$id,
        'title'=>$title,
        'description'=>$desc,
        'asking_price'=>$p['ask_price']??null,
        'updated_at'=>$now
    ]]);
}


if ($action === 'ebay_draft_get') {
 $u=$_GET['unit_id']??'';$s=$pdo->prepare("SELECT * FROM ebay_drafts WHERE unit_id=?");$s->execute([$u]);json_response(['ok'=>true,'draft'=>$s->fetch()?:null]);
}
if ($action === 'ebay_draft_generate') {
 $d=body_json();$u=$d['unit_id']??'';$s=$pdo->prepare("SELECT u.*,c.set_num,c.name,c.year,c.num_parts,t.name theme_name FROM inventory_units u JOIN catalog_sets c ON c.set_num=u.catalog_set_num LEFT JOIN themes t ON t.theme_id=c.theme_id WHERE u.unit_id=?");$s->execute([$u]);$x=$s->fetch();if(!$x)json_response(['ok'=>false,'error'=>'Unit not found'],404);$pr=latest_pricing($pdo,$u);
 $tok=['LEGO'];if(!empty($x['theme_name']))$tok[]=$x['theme_name'];$tok[]=$x['set_num'];$tok[]=$x['name'];if($x['sealed_status']==='sealed'){$tok[]='New';$tok[]='Factory Sealed';$tok[]='Retired';}elseif($x['sealed_status']==='opened')$tok[]='Opened';if(!empty($x['year']))$tok[]=(string)$x['year'];$title=trim(implode(' ',array_filter($tok)));if(mb_strlen($title)>80)$title=mb_substr($title,0,80);
 $desc="LEGO ".(!empty($x['theme_name'])?$x['theme_name'].' ':'')."set {$x['set_num']} — {$x['name']}.";if($x['year'])$desc.=" Original {$x['year']} release.";if($x['sealed_status']==='sealed')$desc.=" New and factory sealed.";elseif($x['sealed_status']==='opened')$desc.=" Opened; condition is shown in the photos.";if($x['box_condition']==='damaged')$desc.=" Box has visible damage as shown in the photos.";elseif($x['box_condition']==='worn')$desc.=" Box has visible shelf/edge wear as shown in the photos.";elseif(in_array($x['box_condition'],['excellent','good'],true))$desc.=" Box is in {$x['box_condition']} overall condition.";$desc.=" Photos are of the exact set being sold. Please review all photos for condition.";
 $sp=array_filter(['Brand'=>'LEGO','Set Number'=>$x['set_num'],'Set Name'=>$x['name'],'Theme'=>$x['theme_name']?:null,'Year'=>$x['year']?:null,'Type'=>'Complete Set'],fn($v)=>$v!==null&&$v!=='');$q=trim("LEGO {$x['set_num']} {$x['name']} ".($x['sealed_status']==='sealed'?'sealed':''));$now=now_iso();
 $pdo->prepare("INSERT INTO ebay_drafts(unit_id,title,description,suggested_price,shipping_mode,research_query,item_specifics_json,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(unit_id) DO UPDATE SET title=excluded.title,description=excluded.description,research_query=excluded.research_query,item_specifics_json=excluded.item_specifics_json,updated_at=excluded.updated_at")->execute([$u,$title,$desc,$pr['ask_price']??null,'buyer_pays',$q,json_encode($sp),$now]);$s=$pdo->prepare("SELECT * FROM ebay_drafts WHERE unit_id=?");$s->execute([$u]);json_response(['ok'=>true,'draft'=>$s->fetch()]);
}
if ($action === 'ebay_draft_save') {
 $d=body_json();$u=$d['unit_id']??'';$pdo->prepare("UPDATE ebay_drafts SET title=?,description=?,suggested_price=?,shipping_mode=?,estimated_shipping=?,research_notes=?,updated_at=? WHERE unit_id=?")->execute([mb_substr(trim((string)($d['title']??'')),0,80),trim((string)($d['description']??'')),($d['suggested_price']??'')===''?null:(float)$d['suggested_price'],in_array(($d['shipping_mode']??''),['buyer_pays','free_shipping'],true)?$d['shipping_mode']:'buyer_pays',($d['estimated_shipping']??'')===''?null:(float)$d['estimated_shipping'],trim((string)($d['research_notes']??'')),now_iso(),$u]);json_response(['ok'=>true]);
}
json_response(['ok'=>false,'error'=>'Unknown action'],404);
