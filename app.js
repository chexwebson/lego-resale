console.info('LEGO Resale client 1.0-alpha.3.13');
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
let currentUser=null;
const isOwner=()=>currentUser?.role==='owner';
const api=async(url,opt={})=>{const r=await fetch(url,opt);if(r.status===401){window.location.href='login.php';throw new Error('Login required')}const j=await r.json();if(!j.ok)throw new Error(j.error||'Request failed');return j};
const money=v=>v===null||v===undefined||v===''?'—':new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(v));
const normalizeShareText=s=>{let t=(s??'').toString();try{t=decodeURIComponent(t.replace(/\+/g,'%20'))}catch(e){t=t.replace(/\+/g,' ')}return t;};
const esc=s=>(s??'').toString().replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
let statusData={};

function applyRoleVisibility(){
  const owner=isOwner();
  const custNav=$('nav button[data-view="customers"]'); if(custNav)custNav.hidden=!owner;
  if($('#povQueuePanel'))$('#povQueuePanel').hidden=!owner;
  if($('#portfolioPanel'))$('#portfolioPanel').hidden=!owner;
}
async function loadWhoAmI(){
  try{
    const j=await api('api.php?action=whoami');
    currentUser=j.user;
    if($('#userBadge'))$('#userBadge').textContent=`${currentUser.display_name} · ${currentUser.role}`;
    applyRoleVisibility();
    if(isOwner())setTimeout(()=>loadPovQueueStatus().catch(()=>{}),1500);
  }catch(e){}
}
loadWhoAmI();

$$('nav button').forEach(b=>b.onclick=()=>{$$('nav button').forEach(x=>x.classList.remove('active'));b.classList.add('active');$$('.view').forEach(x=>x.classList.remove('active'));$('#'+b.dataset.view).classList.add('active');if(b.dataset.view==='inventory'){loadUnits();if(isOwner()){loadInventorySummary();loadPovQueueStatus()}}if(b.dataset.view==='customers')loadCustomers();if(b.dataset.view==='ai')loadImports()});

async function loadStatus(){try{statusData=await api('api.php?action=status');$('#sync').textContent='Database ready';if($('#versionBadge'))$('#versionBadge').textContent='v'+statusData.version.replace(/-/g,' ');let c=statusData.counts;$('#stats').innerHTML=[
['Catalog sets',c.catalog_sets],['Parts',c.parts],['Colors',c.colors],['Set inventories',c.catalog_inventories],['Inventory part rows',c.catalog_inventory_parts],['Minifigs',c.catalog_minifigs],['Physical units',c.inventory_units],['Photos',c.unit_photos],['Valuations',c.valuations],['Sales',c.sales],['Customers',c.customers],['Interests',c.customer_interests],['Listing snapshots',c.listing_metrics],['AI batches',c.import_batches]
].map(x=>`<div class=card><span>${x[0]}</span><b>${x[1]}</b></div>`).join('')}catch(e){$('#sync').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}}
loadStatus();

let catTimer;
$('#catalogSearch').oninput=()=>{clearTimeout(catTimer);catTimer=setTimeout(searchCatalog,250)};
async function searchCatalog(){
 const q=$('#catalogSearch').value.trim(); if(!q){$('#catalogResults').innerHTML='';return}
 try{let j=await api('api.php?action=catalog_search&q='+encodeURIComponent(q));$('#catalogResults').innerHTML=j.items.length?`<table><tr><th>Exact ID</th><th>Name</th><th>Year</th><th>Theme</th><th>Parts</th></tr>${j.items.map(x=>`<tr class="catrow" data-set="${esc(x.set_num)}"><td><b>${esc(x.set_num)}</b><div class=muted>base ${esc(x.base_set_num)}${x.variant?' · variant '+esc(x.variant):''}</div></td><td>${esc(x.name)}</td><td>${x.year||''}</td><td>${esc(x.theme_name||'')}</td><td>${x.num_parts||''}</td></tr>`).join('')}</table>`:'No matches';$$('.catrow').forEach(r=>r.onclick=()=>openComposition(r.dataset.set))}catch(e){$('#catalogResults').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
}

$('#catalogImport').onclick=async()=>{
 const file=$('#catalogFile').files[0]; if(!file)return alert('Choose a CSV or .gz file.');
 const fd=new FormData();fd.append('type',$('#catalogType').value);fd.append('catalog_file',file);$('#catalogImportStatus').textContent='Importing…';
 try{let j=await api('catalog_import.php',{method:'POST',body:fd});$('#catalogImportStatus').innerHTML=`<span class=good>${j.rows_imported.toLocaleString()} rows imported</span>`;loadStatus()}
 catch(e){$('#catalogImportStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
};

$('#unitSearch').oninput=()=>loadUnits();

async function loadInventorySummary(){
 try{
   const j=await api('api.php?action=inventory_summary');
   const u=j.summary.unsold;
   const s=j.summary.sold;
   const coverage=(n,total)=>`${n}/${total}`;
   const totalUnits=u.units+s.units;
   const totalMsrp=u.total_msrp+s.total_msrp;
   const totalPortfolioValue=u.total_current_value+s.total_net;

   const card=(label,val,sub)=>`<div class=card><span>${esc(label)}</span><b>${val}</b>${sub?`<small class=muted>${esc(sub)}</small>`:''}</div>`;

   $('#portfolioHero').innerHTML=[
     card('Total units',totalUnits,`${u.units} in hand · ${s.units} sold`),
     card('Total invested (MSRP)',money(totalMsrp)),
     card('Total portfolio value',money(totalPortfolioValue),'Remaining market value + realized net')
   ].join('');

   $('#remainingSummary').innerHTML=[
     card('Units in hand',u.units),
     card('Current market value',money(u.total_current_value)),
     card('Current ask total',money(u.total_ask))
   ].join('');

   $('#realizedSummary').innerHTML=[
     card('Units sold',s.units),
     card('Net proceeds',money(s.total_net),`After ${money(s.total_fees)} fees + ${money(s.total_shipping)} shipping`),
     card('Variance to ask',money(s.variance_to_ask),`Sold total ${money(s.total_sold_price)}`)
   ].join('');

   $('#inventorySummary').innerHTML=[
     ['Units',u.units,'Available/listed/reserved'],
     ['Original MSRP',money(u.total_msrp),`Coverage ${coverage(u.units_with_msrp,u.units)}`],
     ['Current market value',money(u.total_current_value),`Coverage ${coverage(u.units_with_current_value,u.units)}`],
     ['Current Ask',money(u.total_ask),`Coverage ${coverage(u.units_with_ask,u.units)}`]
   ].map(x=>`<div class=card><span>${esc(x[0])}</span><b>${x[1]}</b><small class=muted>${esc(x[2])}</small></div>`).join('');

   $('#soldSummary').innerHTML=[
     ['Units sold',s.units,'Completed sales'],
     ['Original MSRP',money(s.total_msrp),`Coverage ${coverage(s.units_with_msrp,s.units)}`],
     ['Last market value',money(s.total_last_market_value),`Coverage ${coverage(s.units_with_market_value,s.units)}`],
     ['Listed total',money(s.total_listed_price),`Coverage ${coverage(s.units_with_listed_price,s.units)}`],
     ['Projected Ask',money(s.total_projected_ask),`Actual variance ${money(s.variance_to_ask)}`],
     ['Projected Target',money(s.total_projected_target),`Actual variance ${money(s.variance_to_target)}`],
     ['Sold total',money(s.total_sold_price),'Gross realized revenue'],
     ['Net proceeds',money(s.total_net),`After ${money(s.total_fees)} fees + ${money(s.total_shipping)} shipping`]
   ].map(x=>`<div class=card><span>${esc(x[0])}</span><b>${x[1]}</b><small class=muted>${esc(x[2])}</small></div>`).join('');
 }catch(e){
   $('#portfolioHero').innerHTML=`<span class=bad>${esc(e.message)}</span>`;
   $('#remainingSummary').innerHTML='';$('#realizedSummary').innerHTML='';
   $('#inventorySummary').innerHTML='';$('#soldSummary').innerHTML='';
 }
}



async function getCustomers(){return (await api('api.php?action=customers')).items}
let allCustomersCache=[];
function filterCustomers(items,q){
 q=(q||'').trim().toLowerCase();if(!q)return items;
 return items.filter(c=>[c.name,c.organization,c.email,c.phone,c.marketplace_profile,c.notes,...(c.interests||[]).map(i=>i.notes)].filter(Boolean).join(' ').toLowerCase().includes(q));
}
function renderCustomerTable(items){
 $('#customerTable').innerHTML=items.length?items.map(c=>`<div class=panel><div class=toolbar><div><h3>${esc(c.name)}${c.organization?` · ${esc(c.organization)}`:''}</h3><div class=muted>${esc(c.email||'')}${c.phone?` · ${esc(c.phone)}`:''}${c.marketplace_profile?` · ${esc(c.marketplace_profile)}`:''}</div></div><span>${c.interest_count} interests · ${c.purchase_count} purchases</span><button type=button class=editCustomer data-id="${esc(c.customer_id)}">Edit</button></div>${c.notes?`<p>${esc(c.notes)}</p>`:''}${c.interests.length?`<table><tr><th>Set / Unit</th><th>Status</th><th>Target</th><th>Notes</th></tr>${c.interests.map(i=>`<tr><td><b>${esc(i.catalog_set_num||'')}</b> ${esc(i.set_name||'')}${i.display_code?`<br>${esc(i.display_code)}`:''}</td><td>${esc(i.status)}</td><td>${money(i.target_price)}</td><td>${esc(i.notes||'')}</td></tr>`).join('')}</table>`:'<p class=muted>No interests recorded.</p>'}</div>`).join(''):'<div class=panel>No customers match.</div>';
 $$('.editCustomer').forEach(b=>b.onclick=()=>openCustomerEditor(allCustomersCache.find(c=>c.customer_id===b.dataset.id)));
}
async function loadCustomers(){
 try{const items=await getCustomers();allCustomersCache=items;renderCustomerTable(filterCustomers(items,$('#customerSearch')?.value));}catch(e){$('#customerTable').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
}
if($('#customerSearch'))$('#customerSearch').oninput=()=>renderCustomerTable(filterCustomers(allCustomersCache,$('#customerSearch').value));
function openCustomerEditor(c=null){const d=$('#customerDialog');$('#customerDialogTitle').textContent=c?'Edit customer':'Add customer';$('#customerId').value=c?.customer_id||'';$('#customerName').value=c?.name||'';$('#customerOrganization').value=c?.organization||'';$('#customerEmail').value=c?.email||'';$('#customerPhone').value=c?.phone||'';$('#customerMarketplace').value=c?.marketplace_profile||'';$('#customerNotes').value=c?.notes||'';$('#customerSaveStatus').textContent='';d.showModal();setTimeout(()=>$('#customerName').focus(),0)}
if($('#newCustomer'))$('#newCustomer').onclick=()=>openCustomerEditor();
if($('#cancelCustomer'))$('#cancelCustomer').onclick=()=>$('#customerDialog').close();
if($('#saveCustomer'))$('#saveCustomer').onclick=async()=>{const id=$('#customerId').value.trim();const payload={customer_id:id,name:$('#customerName').value.trim(),organization:$('#customerOrganization').value.trim(),email:$('#customerEmail').value.trim(),phone:$('#customerPhone').value.trim(),marketplace_profile:$('#customerMarketplace').value.trim(),notes:$('#customerNotes').value.trim()};if(!payload.name){$('#customerSaveStatus').textContent='Customer name is required.';return}try{$('#customerSaveStatus').textContent='Saving…';await api('api.php?action='+(id?'customer_update':'customer_create'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});$('#customerDialog').close();await loadCustomers();await loadStatus()}catch(e){$('#customerSaveStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}};

async function loadUnits(){
 try{let j=await api('api.php?action=units&q='+encodeURIComponent($('#unitSearch').value.trim()));$('#unitTable').innerHTML=j.items.length?`<table><tr><th>Unit</th><th>Set</th><th>Condition</th><th>Photos</th><th>Ask</th><th>Status</th></tr>${j.items.map(x=>`<tr data-id="${x.unit_id}" class=unitrow><td><b>${esc(x.display_code)}</b><div class=muted>${esc(x.unit_id.slice(0,8))}…</div></td><td>${esc(x.set_num||'')}<br>${esc(x.set_name||'')}</td><td>${esc(x.condition_state)}<br><span class=muted>${esc(x.sealed_status)} / box ${esc(x.box_condition)}</span></td><td>${x.photo_count}</td><td>${money(x.pricing?.ask_price)}</td><td>${esc(x.status)}</td></tr>`).join('')}</table>`:'<div class=panel>No physical inventory units yet.</div>';$$('.unitrow').forEach(r=>r.onclick=()=>openUnit(r.dataset.id))}
 catch(e){$('#unitTable').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
}

$('#newUnit').onclick=()=>{$('#addSetNum').value='';$('#addLookup').innerHTML='';$('#addDialog').showModal()};
$('#addSetNum').oninput=async()=>{let q=$('#addSetNum').value.trim();if(!q){$('#addLookup').innerHTML='';return}try{let j=await api('api.php?action=catalog_search&q='+encodeURIComponent(q));$('#addLookup').innerHTML=j.items.slice(0,5).map(x=>`<div class=proposal><b>${esc(x.set_num)}</b> ${esc(x.name)} ${x.year||''}</div>`).join('')}catch(e){}};
$('#createUnit').onclick=async()=>{try{let j=await api('api.php?action=unit_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({set_num:$('#addSetNum').value.trim()})});$('#addDialog').close();await loadUnits();openUnit(j.unit_id)}catch(e){alert(e.message)}};

let povWorkerTimer=null,povWorkerBusy=false;
async function loadPovQueueStatus(){try{const j=await api('pov_queue.php?action=status'),c=j.counts,active=c.pending+c.retry+c.fetching;$('#povQueueStatus').innerHTML=`Queued ${c.pending} · retry ${c.retry} · fetching ${c.fetching} · complete ${c.complete} · failed ${c.failed}${active?`<br><b>${active} remaining</b> · one unique set every ~${j.interval_seconds}s while this page is open.`:'<br><span class=good>Queue idle.</span>'}`;$('#povRecent').innerHTML=j.recent.length?`<table><tr><th>Set</th><th>Sold POV</th><th>For-sale POV</th><th>Checked</th></tr>${j.recent.map(x=>`<tr><td><b>${esc(x.set_num)}</b></td><td>${money(x.sold_average)}</td><td>${money(x.current_average)}</td><td>${esc(x.checked_at)}</td></tr>`).join('')}</table>`:'';if(active&&!povWorkerTimer)schedulePovWorker(j.interval_seconds);if(!active&&povWorkerTimer){clearTimeout(povWorkerTimer);povWorkerTimer=null;}}catch(e){if($('#povQueueStatus'))$('#povQueueStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>';}}
function schedulePovWorker(sec=10){if(povWorkerTimer)clearTimeout(povWorkerTimer);povWorkerTimer=setTimeout(processOnePov,Math.max(5,sec)*1000);}
async function processOnePov(){if(povWorkerBusy)return;povWorkerBusy=true;povWorkerTimer=null;try{await fetch('pov_queue.php?action=process_next',{method:'POST',cache:'no-store'});}catch(e){}povWorkerBusy=false;await loadPovQueueStatus();await loadInventorySummary();await loadUnits();}
$('#queuePov').onclick=async()=>{try{const r=await fetch('pov_queue.php?action=queue_all',{method:'POST'}),j=await r.json();if(!j.ok)throw new Error(j.error||'Queue failed');alert(`${j.queued} unique sets queued; ${j.fresh_cache} already fresh.`);await loadPovQueueStatus();}catch(e){alert(e.message)}};
$('#refreshAllPov').onclick=async()=>{if(!confirm('Force fresh POV lookups for every unique active set?'))return;const fd=new FormData();fd.append('force','1');try{const r=await fetch('pov_queue.php?action=queue_all',{method:'POST',body:fd}),j=await r.json();if(!j.ok)throw new Error(j.error||'Queue failed');alert(`${j.queued} unique sets queued.`);await loadPovQueueStatus();}catch(e){alert(e.message)}};

async function openUnit(id){
 let j=await api('api.php?action=unit_get&id='+encodeURIComponent(id));let x=j.item,v=x.valuation,p=x.pricing,l=x.listing,sale=x.sale,metrics=x.listing_metrics||[],interests=x.interests||[];
 const owner=isOwner();
 let e=null;
 if(owner){try{let er=await api('api.php?action=ebay_draft_get&unit_id='+encodeURIComponent(id));e=er.draft}catch(err){e=null}}
 const quickMoneySection=owner?`<div class=quickMoney><div><span>Market</span><b>${money(p?.market_reference)}</b></div><div><span>Ask</span><b>${money(p?.ask_price)}</b></div><div><span>POV sold</span><b>${money(v?.part_out_sold)}</b></div><div><span>POV ratio</span><b>${p?.pov_ratio?Number(p.pov_ratio).toFixed(2)+'×':'—'}</b></div></div>`:'';
 const pricingSection=owner?`<section class=unitSection><div class=sectionHead><div><span class=eyebrow>Pricing</span><h3>Current recommendation</h3></div><button type=button id=fetchPov>Refresh POV</button></div><div class=pricingStrip><div><span>Market</span><b>${money(p?.market_reference)}</b></div><div><span>POV</span><b>${money(v?.part_out_sold)}</b></div><div><span>Ask</span><b>${money(p?.ask_price)}</b></div><div><span>Target</span><b>${money(p?.target_price)}</b></div><div><span>Minimum</span><b>${money(p?.minimum_price)}</b></div></div><div id=valuationStatus class=muted></div><details><summary>Pricing details & manual values</summary>${num('msrp','Original US MSRP',v?.msrp)}${num('whole_new','Whole-set new',v?.whole_new)}${num('whole_used','Whole-set used',v?.whole_used)}${num('part_out_sold','BrickLink POV — sold',v?.part_out_sold)}${num('part_out_for_sale','BrickLink POV — for sale',v?.part_out_for_sale)}<label>Source <input class=wideInput id=source_label value="${esc(v?.source_label||'manual')}"></label>${p?`<div class=calc>${(JSON.parse(p.explanation_json||'[]')).map(e=>`<div>${esc(e)}</div>`).join('')}</div>`:''}<div class=buttonRow><button type=button id=saveValuation>Save & recalculate</button><button type=button id=calcPrice>Recalculate</button></div></details></section>`:'';
 const sellingSection=owner?`<section class=unitSection id=sellingSection><div class=sectionHead><div><span class=eyebrow>Selling</span><h3>Marketplace drafts</h3></div></div><div class=sellingCard><div class=sellingHeader><div><b>Facebook Marketplace</b><span>${l?'Draft ready · '+money(l.asking_price):'No draft yet'}</span></div><button type=button id=genListing>${l?'Regenerate':'Create draft'}</button></div>${l?`<div class=draftField><span>Title</span><div><b>${esc(normalizeShareText(l.title||''))}</b><button type=button id=copyFbTitle>Copy</button></div></div><div class=draftField><span>Price</span><div><b>${money(l.asking_price)}</b><button type=button id=copyFbPrice>Copy</button></div></div><details><summary>Description · View & copy</summary><p>${esc(normalizeShareText(l.description||''))}</p><button type=button id=copyFbDescription>Copy description</button></details><p class=muted>Use Prepare posting folder to create numbered local files, then add them to the listing in order.</p><button type=button id=openFacebook>Open Marketplace</button>`:''}</div><div class=sellingCard><div class=sellingHeader><div><b>eBay</b><span>${e?'Assisted draft ready':'Not prepared'}</span></div><button type=button id=genEbay>${e?'Regenerate':'Create draft'}</button></div>${e?`<label>Recommended title · <b id=ebayCount>${(e.title||'').length}/80</b><input id=ebayTitle maxlength=80 value="${esc(e.title||'')}"></label><div class=buttonRow><button type=button id=copyEbayTitle>Copy title</button><button type=button id=openEbayResearch>Product Research</button><button type=button id=openEbaySold>Recent sold</button></div><div class=ebayGrid><label>Suggested price<input id=ebayPrice type=number step=.01 value="${esc(e.suggested_price??'')}"></label><label>Shipping<select id=ebayShipping><option value=buyer_pays ${e.shipping_mode==='buyer_pays'?'selected':''}>Buyer pays</option><option value=free_shipping ${e.shipping_mode==='free_shipping'?'selected':''}>Free shipping</option></select></label><label>Estimated shipping<input id=ebayShipCost type=number step=.01 value="${esc(e.estimated_shipping??'')}"></label></div><details><summary>Description · View/edit/copy</summary><textarea id=ebayDescription>${esc(e.description||'')}</textarea><button type=button id=copyEbayDescription>Copy description</button></details><details><summary>Item specifics</summary><div class=specificsList>${Object.entries(JSON.parse(e.item_specifics_json||'{}')).map(([k,val])=>`<div><span>${esc(k)}</span><b>${esc(val)}</b></div>`).join('')}</div></details><details><summary>Research & pricing notes</summary><p class=muted>Compare the exact set and condition. Pay attention to legitimate stronger sales, title wording, accepted offers, and whether shipping was included.</p><textarea id=ebayResearchNotes placeholder="Sold range, upper range, useful title words, shipping pattern...">${esc(e.research_notes||'')}</textarea></details><div class=buttonRow><button type=button id=saveEbay>Save eBay draft</button><button type=button id=copyEbayPrice>Copy price</button><button type=button id=openEbaySell>Open eBay Sell</button></div><p class=muted>Use Prepare posting folder to create numbered local files, then add them to eBay in order.</p>`:''}</div></section>`:'';
 const customerSection=owner?`<section class=unitSection><div class=sectionHead><div><span class=eyebrow>Customer & listing performance</span><h3>Interest and results</h3></div></div>
${interests.length?`<table><tr><th>Customer</th><th>Status</th><th>Target</th><th>Notes</th></tr>${interests.map(i=>`<tr><td>${esc(i.customer_name)}${i.customer_organization?` · ${esc(i.customer_organization)}`:''}</td><td>${esc(i.status)}</td><td>${money(i.target_price)}</td><td>${esc(i.notes||'')}</td></tr>`).join('')}</table>`:'<p class=muted>No customer interest recorded for this set/unit.</p>'}
<div class=buttonRow><button type=button id=addInterest>Add customer interest</button></div>
${l?`<details><summary>Listing performance snapshots</summary><div class=grid5><label>Clicks<input id=metricClicks type=number min=0 value="${metrics[0]?.clicks??0}"></label><label>Saves<input id=metricSaves type=number min=0 value="${metrics[0]?.saves??0}"></label><label>Shares<input id=metricShares type=number min=0 value="${metrics[0]?.shares??0}"></label><label>Inquiries<input id=metricInquiries type=number min=0 value="${metrics[0]?.inquiries??0}"></label><button type=button id=saveMetrics>Save snapshot</button></div>${metrics.length?`<table><tr><th>Observed</th><th>Clicks</th><th>Saves</th><th>Shares</th><th>Inquiries</th></tr>${metrics.map(m=>`<tr><td>${esc(m.observed_at)}</td><td>${m.clicks}</td><td>${m.saves}</td><td>${m.shares}</td><td>${m.inquiries}</td></tr>`).join('')}</table>`:''}</details>`:'<p class=muted>Create a Marketplace listing draft to start performance tracking.</p>'}
${sale?`<div class=saleResult><b>Sold ${money(sale.sold_price)}</b> · ${esc(sale.platform||'')}<br><span class=muted>Projected Ask ${money(sale.projected_ask)} · Target ${money(sale.projected_target)} · Minimum ${money(sale.projected_minimum)} · Net ${money(Number(sale.sold_price)-Number(sale.fees||0)-Number(sale.shipping||0))}${sale.customer_name?` · Buyer ${esc(sale.customer_name)}`:''}</span></div>`:''}
</section>`:'';
 $('#unitDetail').innerHTML=`<section class=unitHero><div><div class=eyebrow>${esc(x.set_num||'')}</div><h2>${esc(x.display_code)} · ${esc(x.set_name||'')}</h2><div class=chipRow><span class=chip>${esc(x.sealed_status==='sealed'?'Sealed':x.sealed_status==='opened'?'Opened':'Seal unknown')}</span><span class=chip>${esc(x.box_condition)} box</span><span class=chip>${esc(x.status)}</span><span class=chip>${x.photos.length} photos</span></div></div><div class=heroActions><button type=button id=editUnit>Edit</button><button type=button id=jumpPhotos>Photos</button>${owner?'<button type=button id=jumpSelling>Selling</button>':''}${x.status!=='sold'&&owner?'<button type=button id=markSold>Mark Sold</button>':''}</div></section>
${quickMoneySection}
<section class=unitSection><div class=sectionHead><div><span class=eyebrow>Overview</span><h3>Physical unit</h3></div></div><div class=overviewGrid><div><span>Condition</span><b>${esc(x.condition_state)}</b></div><div><span>Seal</span><b>${esc(x.sealed_status)}</b></div><div><span>Box</span><b>${esc(x.box_condition)}</b></div><div><span>Instructions</span><b>${esc(x.instructions_status)}</b></div><div><span>Completeness</span><b>${esc(x.completeness)}</b></div></div><div id=unitEditPanel class=editPanel hidden>${select('condition_state',['unknown','new','used_excellent','used_good','used_worn','damaged'],x.condition_state)}${select('sealed_status',['unknown','sealed','opened'],x.sealed_status)}${select('box_condition',['unknown','excellent','good','worn','damaged','none'],x.box_condition)}${select('instructions_status',['unknown','included','missing','not_applicable'],x.instructions_status)}${select('completeness',['unknown','complete','believed_complete','incomplete'],x.completeness==='sealed_unverified'?'unknown':x.completeness)}<label>Internal notes<textarea id=notes>${esc(x.notes||'')}</textarea></label><button type=button id=saveUnit>Save changes</button></div></section>
<section class=unitSection id=photosSection><div class=sectionHead><div><span class=eyebrow>Photos</span><h3>Exact unit photos</h3></div><div class=buttonRow><button type=button id=exportPostingPhotos>Prepare posting folder</button><button type=button id=addPhotoToggle>Add photo</button></div></div><div class=unitPhotoGrid>${x.photos.map((ph,i)=>`<figure class=unitPhotoCard><img src="photo_api.php?action=view&id=${encodeURIComponent(ph.photo_id)}" title="${esc(ph.filename)}"><figcaption><span class=photoNumber>${i+1}</span><select class=photoRoleSelect data-photo="${esc(ph.photo_id)}">${['front','back','side','top','bottom','seal','damage','contents','instructions','label','other','unknown'].map(r=>`<option ${r===ph.role?'selected':''}>${r}</option>`).join('')}</select><label class=checkLine><input type=checkbox class=photoAiReview data-photo="${esc(ph.photo_id)}" ${Number(ph.ai_review_requested)?'checked':''}> AI review/export</label><div class=buttonRow><button type=button class=shareOnePhoto data-id="${esc(ph.photo_id)}" data-name="${esc(ph.filename)}">Save / share</button><button type=button class=deletePhoto data-photo="${esc(ph.photo_id)}">Delete</button></div></figcaption></figure>`).join('')||'<p class=muted>No photos linked.</p>'}</div><div id=addPhotoPanel class=editPanel hidden><label>Photo <input id=photoFile type=file accept="image/jpeg,image/png,image/webp" capture="environment"></label><label>Role <select id=newPhotoRole>${['front','back','side','top','bottom','seal','damage','contents','instructions','label','other','unknown'].map(r=>`<option>${r}</option>`).join('')}</select></label><label class=checkLine><input id=newPhotoAiReview type=checkbox> Mark for AI review/export</label><button type=button id=uploadPhoto>Upload</button></div></section>
${pricingSection}
${sellingSection}
${customerSection}`;
 $('#unitDialog').showModal();
 $('#editUnit').onclick=()=>{$('#unitEditPanel').hidden=!$('#unitEditPanel').hidden};
 $('#jumpPhotos').onclick=()=>$('#photosSection').scrollIntoView({behavior:'smooth'});
 if($('#jumpSelling'))$('#jumpSelling').onclick=()=>$('#sellingSection').scrollIntoView({behavior:'smooth'});
 $('#addPhotoToggle').onclick=()=>{$('#addPhotoPanel').hidden=!$('#addPhotoPanel').hidden};
 $('#uploadPhoto').onclick=async()=>{const f=$('#photoFile').files[0];if(!f)return alert('Choose or take a photo.');const fd=new FormData();fd.append('unit_id',id);fd.append('role',$('#newPhotoRole').value);if($('#newPhotoAiReview').checked)fd.append('ai_review_requested','1');try{await api('photo_api.php?action=upload',{method:'POST',body:fd});await openUnit(id);await loadUnits()}catch(e){alert(e.message)}};
 $$('.photoRoleSelect').forEach(el=>el.onchange=async()=>{try{await api('api.php?action=photo_role_update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo_id:el.dataset.photo,role:el.value})})}catch(e){alert(e.message)}});
 $$('.photoAiReview').forEach(el=>el.onchange=async()=>{try{await api('api.php?action=photo_ai_review_update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo_id:el.dataset.photo,requested:el.checked})})}catch(e){alert(e.message)}});
 $$('.shareOnePhoto').forEach(btn=>btn.onclick=()=>window.open('photo_api.php?action=download&id='+encodeURIComponent(btn.dataset.id),'_blank'));
 if($('#saveMetrics'))$('#saveMetrics').onclick=async()=>{try{await api('api.php?action=listing_metric_add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({listing_id:l.listing_id,clicks:$('#metricClicks').value,saves:$('#metricSaves').value,shares:$('#metricShares').value,inquiries:$('#metricInquiries').value})});await openUnit(id);await loadStatus()}catch(e){alert(e.message)}};
 if($('#addInterest'))$('#addInterest').onclick=()=>openAddInterestDialog(id,x.catalog_set_num,()=>{openUnit(id);loadCustomers()});
 if($('#markSold'))$('#markSold').onclick=()=>openMarkSoldDialog(id,x,p);

 $$('.deletePhoto').forEach(btn=>btn.onclick=async()=>{
   if(!confirm('Delete this photo from the unit? This removes the stored image too.'))return;
   try{const r=await api('photo_api.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({photo_id:btn.dataset.photo})});if(r.warning)alert(r.warning);await openUnit(id)}catch(e){alert(e.message)}
 });
 $('#exportPostingPhotos').onclick=async()=>{
   const photos=Array.isArray(x.photos)?x.photos:[];
   if(!photos.length)return alert('This unit has no photos to export.');
   if(!window.showDirectoryPicker)return alert('Direct folder export is not supported in this browser. Use the individual Download buttons instead.');
   try{
     const dir=await window.showDirectoryPicker({mode:'readwrite'}); let written=0;
     for(let i=0;i<photos.length;i++){
       const ph=photos[i]; const r=await fetch('photo_api.php?action=view&id='+encodeURIComponent(ph.photo_id),{cache:'no-store'});
       if(!r.ok)throw new Error('Could not load '+(ph.filename||'photo'));
       const blob=await r.blob(); const ext=(blob.type||'image/jpeg').includes('png')?'png':((blob.type||'').includes('webp')?'webp':'jpg');
       const role=(ph.role||'photo').toLowerCase().replace(/[^a-z0-9_-]+/g,'-'); const seq=String(i+1).padStart(2,'0');
       const filename=`${seq}-${x.display_code}-${role}.${ext}`; const handle=await dir.getFileHandle(filename,{create:true});
       const writable=await handle.createWritable(); await writable.write(blob); await writable.close(); written++;
     }
     alert(`${written} posting photos saved to the selected folder in listing order.`);
   }catch(e){if(e?.name!=='AbortError')alert(e.message)}
 };
 if(owner){
 const collectValuation=()=>{let d={unit_id:id,source_label:$('#source_label').value.trim()||'manual'};['msrp','whole_new','whole_used','part_out_sold','part_out_for_sale'].forEach(k=>d[k]=$('#'+k).value===''?null:Number($('#'+k).value));return d};
 const persistValuationAndPrice=async(extra={})=>{
   const status=$('#valuationStatus'); status.innerHTML='<span class=warn>Saving valuation…</span>';
   const d={...collectValuation(),...extra};
   await api('api.php?action=valuation_add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
   status.innerHTML='<span class=warn>Saved. Recalculating price…</span>';
   await api('api.php?action=pricing_calculate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unit_id:id})});
   await openUnit(id); await loadUnits(); await loadInventorySummary();
 };
 $('#saveValuation').onclick=async()=>{try{await persistValuationAndPrice()}catch(e){$('#valuationStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}};
 $('#fetchPov').onclick=async()=>{
   const btn=$('#fetchPov'); const status=$('#valuationStatus'); const old=btn.textContent;
   btn.disabled=true; btn.textContent='Fetching…'; status.innerHTML='<span class=warn>Contacting BrickLink…</span>';
   try{
     const condition=(x.condition_state==='new'||x.sealed_status==='sealed')?'N':'U';
     const r=await fetch(`bricklink_pov.php?set=${encodeURIComponent(x.set_num)}&condition=${condition}`,{cache:'no-store'});
     const pov=await r.json().catch(()=>({}));
     if(!r.ok||!pov.ok)throw new Error(pov.error||`BrickLink lookup failed (${r.status})`);
     $('#part_out_sold').value=pov.soldAverage??'';
     $('#part_out_for_sale').value=pov.currentAverage??'';
     let source=$('#source_label').value.trim();
     if(!source.toLowerCase().includes('bricklink pov')) source=(source?source+' + ':'')+'BrickLink POV';
     $('#source_label').value=source;
     const details=[
       `BrickLink POV checked ${pov.checkedAt}`,
       `sold items/lots: ${pov.soldItems??'—'}/${pov.soldLots??'—'}`,
       `for-sale items/lots: ${pov.currentItems??'—'}/${pov.currentLots??'—'}`,
       `source: ${pov.sourceUrl}`
     ].join(' | ');
     status.innerHTML='<span class=good>POV found. Saving and recalculating…</span>';
     await persistValuationAndPrice({notes:details});
   }catch(e){
     status.innerHTML='<span class=bad>'+esc(e.message)+'</span>';
     btn.disabled=false; btn.textContent=old;
   }
 };
 $('#calcPrice').onclick=async()=>{try{await api('api.php?action=pricing_calculate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unit_id:id})});openUnit(id);loadUnits();loadInventorySummary()}catch(e){alert(e.message)}};
 $('#genListing').onclick=async()=>{try{await api('api.php?action=listing_generate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unit_id:id})});await openUnit(id);await loadUnits();if(typeof loadInventorySummary==='function')await loadInventorySummary()}catch(e){alert(e.message)}};
 $('#genEbay').onclick=async()=>{try{await api('api.php?action=ebay_draft_generate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unit_id:id})});await openUnit(id)}catch(err){alert(err.message)}};
 }
 if(e){const copyE=async t=>{try{await navigator.clipboard.writeText(t);alert('Copied.')}catch(err){prompt('Copy this text:',t)}};$('#ebayTitle').oninput=()=>$('#ebayCount').textContent=$('#ebayTitle').value.length+'/80';$('#copyEbayTitle').onclick=()=>copyE($('#ebayTitle').value);$('#copyEbayDescription').onclick=()=>copyE($('#ebayDescription').value);$('#copyEbayPrice').onclick=()=>copyE($('#ebayPrice').value);$('#saveEbay').onclick=async()=>{await api('api.php?action=ebay_draft_save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unit_id:id,title:$('#ebayTitle').value,description:$('#ebayDescription').value,suggested_price:$('#ebayPrice').value,shipping_mode:$('#ebayShipping').value,estimated_shipping:$('#ebayShipCost').value,research_notes:$('#ebayResearchNotes').value})});alert('eBay draft saved.')};const q=encodeURIComponent(e.research_query||('LEGO '+x.set_num+' '+x.set_name));$('#openEbayResearch').onclick=()=>window.open('https://www.ebay.com/sh/research?marketplace=EBAY-US&keywords='+q,'_blank','noopener');$('#openEbaySold').onclick=()=>window.open('https://www.ebay.com/sch/i.html?_nkw='+q+'&LH_Sold=1&LH_Complete=1','_blank','noopener');$('#openEbaySell').onclick=()=>window.open('https://www.ebay.com/sl/sell','_blank','noopener');}

 if(l){
   const copyText=async(t)=>{try{await navigator.clipboard.writeText(t);alert('Copied.')}catch(e){prompt('Copy this text:',t)}};
   $('#copyFbTitle').onclick=()=>copyText(normalizeShareText(l.title||''));
   $('#copyFbDescription').onclick=()=>copyText(normalizeShareText(l.description||''));
   $('#copyFbPrice').onclick=()=>copyText(String(l.asking_price??''));
   $('#openFacebook').onclick=()=>window.open('https://www.facebook.com/marketplace/create/item','_blank','noopener');

 }

}

let markSoldBuyerMap={};
async function openMarkSoldDialog(unitId,x,p){
 $('#markSoldUnitLabel').textContent=`${x.display_code} · ${x.set_name||''}`;
 $('#soldPrice').value=p?.target_price??p?.ask_price??'';
 $('#soldPlatform').value='facebook_marketplace';
 $('#soldFees').value='0';$('#soldShipping').value='0';
 $('#soldDate').value=new Date().toISOString().slice(0,10);
 $('#buyerSearch').value='';$('#newBuyerName').value='';$('#newBuyerOrg').value='';$('#newBuyerEmail').value='';$('#newBuyerPhone').value='';$('#buyerNotes').value='';
 $('#markSoldStatus').textContent='';
 let customers=[];try{customers=await getCustomers()}catch(e){}
 markSoldBuyerMap={};
 customers.forEach(c=>{markSoldBuyerMap[c.name+(c.organization?' · '+c.organization:'')]=c.customer_id});
 $('#buyerDatalist').innerHTML=Object.keys(markSoldBuyerMap).map(label=>`<option value="${esc(label)}">`).join('');
 $('#markSoldDialog').dataset.unitId=unitId;
 $('#markSoldDialog').showModal();
}
if($('#cancelMarkSold'))$('#cancelMarkSold').onclick=()=>$('#markSoldDialog').close();
if($('#confirmMarkSold'))$('#confirmMarkSold').onclick=async()=>{
 const unitId=$('#markSoldDialog').dataset.unitId;
 const soldPriceRaw=$('#soldPrice').value;
 if(soldPriceRaw===''||isNaN(Number(soldPriceRaw))||Number(soldPriceRaw)<=0){$('#markSoldStatus').innerHTML='<span class=bad>Enter a valid sold price greater than zero.</span>';return}
 const typed=$('#buyerSearch').value.trim();
 const matchedId=markSoldBuyerMap[typed]||null;
 const newName=$('#newBuyerName').value.trim();
 const payload={unit_id:unitId,sold_price:soldPriceRaw,platform:$('#soldPlatform').value,fees:$('#soldFees').value||'0',shipping:$('#soldShipping').value||'0',buyer_notes:$('#buyerNotes').value.trim()};
 if($('#soldDate').value)payload.sold_at=$('#soldDate').value;
 if(matchedId)payload.customer_id=matchedId;
 else if(newName)payload.new_customer={name:newName,organization:$('#newBuyerOrg').value.trim(),email:$('#newBuyerEmail').value.trim(),phone:$('#newBuyerPhone').value.trim()};
 try{
   $('#markSoldStatus').textContent='Saving…';
   await api('api.php?action=mark_sold',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
   $('#markSoldDialog').close();
   await openUnit(unitId);await loadUnits();await loadInventorySummary();await loadStatus();
 }catch(e){$('#markSoldStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
};

let interestBuyerMap={};let interestDialogContext={unitId:null,setNum:null,onDone:null};
async function openAddInterestDialog(unitId,setNum,onDone){
 let customers=[];try{customers=await getCustomers()}catch(e){}
 if(!customers.length)return alert('Add a customer first from the Customers tab.');
 interestDialogContext={unitId,setNum,onDone};
 interestBuyerMap={};
 customers.forEach(c=>{interestBuyerMap[c.name+(c.organization?' · '+c.organization:'')]=c.customer_id});
 $('#interestBuyerDatalist').innerHTML=Object.keys(interestBuyerMap).map(label=>`<option value="${esc(label)}">`).join('');
 $('#interestBuyerSearch').value='';$('#interestTarget').value='';$('#interestNotes').value='';$('#addInterestStatus').textContent='';
 $('#addInterestDialog').showModal();
}
if($('#cancelAddInterest'))$('#cancelAddInterest').onclick=()=>$('#addInterestDialog').close();
if($('#confirmAddInterest'))$('#confirmAddInterest').onclick=async()=>{
 const typed=$('#interestBuyerSearch').value.trim();
 const customerId=interestBuyerMap[typed]||null;
 if(!customerId){$('#addInterestStatus').innerHTML='<span class=bad>Pick a customer from the list.</span>';return}
 try{
   $('#addInterestStatus').textContent='Saving…';
   await api('api.php?action=interest_add',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:customerId,catalog_set_num:interestDialogContext.setNum,unit_id:null,status:'interested',target_price:$('#interestTarget').value,notes:$('#interestNotes').value.trim()})});
   $('#addInterestDialog').close();
   if(interestDialogContext.onDone)interestDialogContext.onDone();
 }catch(e){$('#addInterestStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
};

function select(id,opts,val){return `<label>${id.replaceAll('_',' ')} <select id="${id}">${opts.map(o=>`<option ${o===val?'selected':''}>${o}</option>`).join('')}</select></label>`}
function num(id,label,val){return `<label>${label} <input type=number step=.01 id="${id}" value="${val??''}"></label>`}


const catalogOrder=['themes','sets','colors','part_categories','parts','inventories','minifigs','inventory_parts','inventory_minifigs','inventory_sets'];
const catalogFiles={themes:'themes.csv.gz',sets:'sets.csv.gz',colors:'colors.csv.gz',part_categories:'part_categories.csv.gz',parts:'parts.csv.gz',inventories:'inventories.csv.gz',minifigs:'minifigs.csv.gz',inventory_parts:'inventory_parts.csv.gz',inventory_minifigs:'inventory_minifigs.csv.gz',inventory_sets:'inventory_sets.csv.gz'};

async function importServerPath(type,path){
 const fd=new FormData();fd.append('type',type);fd.append('path',path);
 return api('catalog_import.php?mode=server_file',{method:'POST',body:fd});
}
async function runCatalog(mode){
 const el=$('#bulkCatalogStatus');el.innerHTML='';
 for(let i=0;i<catalogOrder.length;i++){
   const type=catalogOrder[i];el.innerHTML=`<span class=warn>${i+1}/${catalogOrder.length} ${esc(type)}…</span>`;
   let pathInfo;
   if(mode==='latest'){
     pathInfo=await api('catalog_update.php?action=download&type='+encodeURIComponent(type));
   }else{
     pathInfo=await api('catalog_update.php?action=seed_path&type='+encodeURIComponent(type));
   }
   const r=await importServerPath(type,pathInfo.server_path);
   el.innerHTML=`<span class=good>${i+1}/${catalogOrder.length} ${esc(type)}: ${Number(r.rows_imported).toLocaleString()} rows</span>`;
 }
 el.innerHTML='<span class=good><b>Catalog load complete.</b></span>';
 await loadStatus();
}
$('#seedAll').onclick=async()=>{if(!confirm('Load the bundled catalog snapshot into the local SQLite catalog?'))return;try{await runCatalog('seed')}catch(e){$('#bulkCatalogStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}};
$('#updateAll').onclick=async()=>{if(!confirm('Download the latest Rebrickable bulk catalog and load it? This can take several minutes, especially inventory_parts.'))return;try{await runCatalog('latest')}catch(e){$('#bulkCatalogStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}};

async function openComposition(setNum){
 try{
  const j=await api('api.php?action=catalog_composition&set_num='+encodeURIComponent(setNum));
  const s=j.set;
  const totalParts=j.parts.filter(x=>!Number(x.is_spare)).reduce((a,x)=>a+Number(x.quantity||0),0);
  $('#compositionDetail').innerHTML=`<h2>${esc(s.set_num)} · ${esc(s.name)}</h2>
   <p class=muted>${s.year||''} · ${esc(s.theme_name||'')} · catalog count ${s.num_parts||'—'} parts</p>
   <div class=cards><div class=card><span>Inventory version</span><b>${j.inventories.find(x=>Number(x.inventory_id)===Number(j.inventory_id))?.version??'—'}</b></div><div class=card><span>Unique part/color rows</span><b>${j.parts.length}</b></div><div class=card><span>Non-spare quantity</span><b>${totalParts}</b></div><div class=card><span>Minifigure types</span><b>${j.minifigs.length}</b></div></div>
   <h3>Minifigures</h3>${j.minifigs.length?`<table><tr><th>ID</th><th>Name</th><th>Qty</th></tr>${j.minifigs.map(x=>`<tr><td>${esc(x.fig_num)}</td><td>${esc(x.name||'')}</td><td>${x.quantity}</td></tr>`).join('')}</table>`:'<p class=muted>None listed.</p>'}
   <h3>Parts by exact color</h3>${j.parts.length?`<div class="compositionParts"><table><tr><th>Part</th><th>Description</th><th>Color</th><th>Qty</th><th>Spare</th></tr>${j.parts.map(x=>`<tr><td><b>${esc(x.part_num)}</b></td><td>${esc(x.part_name||'')}</td><td>${esc(x.color_name||('Color '+x.color_id))}</td><td>${x.quantity}</td><td>${Number(x.is_spare)?'Yes':''}</td></tr>`).join('')}</table></div>`:'<p class=muted>No composition loaded.</p>'}
   ${j.subsets.length?`<h3>Included sub-sets</h3><table><tr><th>Set</th><th>Name</th><th>Qty</th></tr>${j.subsets.map(x=>`<tr><td>${esc(x.set_num)}</td><td>${esc(x.name||'')}</td><td>${x.quantity}</td></tr>`).join('')}</table>`:''}`;
  $('#compositionDialog').showModal();
 }catch(e){alert(e.message)}
}


$('#packageUpload').onclick=async()=>{
  const f=$('#packageFile').files[0]; if(!f)return alert('Choose an LRX ZIP package.');
  const fd=new FormData();fd.append('package',f);$('#packageStatus').textContent='Uploading and staging…';
  try{
    const j=await api('ai_import.php?action=upload_package',{method:'POST',body:fd});
    let msg=`${j.proposal_count} proposals · ${j.matched_photos}/${j.referenced_photos} referenced photos matched`;
    if(j.missing_photos.length) msg+=` · ${j.missing_photos.length} missing`;
    $('#packageStatus').innerHTML=`<span class="${j.missing_photos.length?'warn':'good'}">${esc(msg)}</span>`;
    loadImports();loadStatus();
  }catch(e){$('#packageStatus').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
};

async function loadImports(){
 try{
  let j=await api('ai_import.php?action=list');
  $('#imports').innerHTML=j.items.map(b=>`<div class=panel><b>${esc(b.external_batch_id||b.import_id)}</b> · ${b.proposal_count} proposals · ${b.staged_file_count} staged photos · ${esc(b.status)}
  <button class=viewImport data-id="${b.import_id}">Review</button><div id="imp_${b.import_id}"></div></div>`).join('')||'<div class=panel>No model-assisted imports yet.</div>';
  $$('.viewImport').forEach(b=>b.onclick=()=>showProposals(b.dataset.id))
 }catch(e){$('#imports').innerHTML='<span class=bad>'+esc(e.message)+'</span>'}
}

async function showProposals(id){
 let j=await api('ai_import.php?action=proposals&import_id='+encodeURIComponent(id)); j.items.forEach(x=>x.import_id=id);
 $('#imp_'+id).innerHTML=j.items.map(p=>{
   const matched=p.photo_status.filter(x=>x.matched).length,total=p.photo_status.length; const explicitUnits=Array.isArray(p.payload?.units)?p.payload.units.length:(p.payload?.physical_units||1);
   const cls=matched===total?'good':'warn';
   return `<div class=proposal>
     <b>${esc(p.proposed_set_num||'Unidentified')}</b> ${esc(p.proposed_name||'')}
     <span class=confidence>${p.confidence!==null?Math.round(p.confidence*100)+'%':''}</span>
     <div>${esc(p.item_type)} · <b>${explicitUnits} physical unit${explicitUnits===1?'':'s'}</b> · ${p.review_required?'review requested':'high confidence'} · ${esc(p.decision)}</div>
     <div class="${cls}">Photos: ${matched}/${total} matched</div>
     ${p.payload?.condition?`<div class=muted>Condition: ${esc(p.payload.condition.state||'unknown')} · ${esc(p.payload.condition.sealed_status||'unknown')} · box ${esc(p.payload.condition.box_condition||'unknown')}</div>`:''}
     ${p.payload?.valuation?`<div class=muted>Valuation included: new ${money(p.payload.valuation.whole_new)} · used ${money(p.payload.valuation.whole_used)} · POV sold ${money(p.payload.valuation.part_out_sold)}</div>`:''}
     ${p.physical_unit_validation?`<div class="${p.physical_unit_validation.errors?.length?'bad':'good'}">Unit validation: ${p.physical_unit_validation.errors?.length?esc(p.physical_unit_validation.errors.join(' | ')):'passed'}</div>`:''}
     ${p.payload?.physical_unit_analysis?`<div class=muted>Unit analysis: ${p.payload.physical_unit_analysis.units_detected} units · ${p.payload.physical_unit_analysis.photos_examined} photos · confidence ${Math.round((p.payload.physical_unit_analysis.confidence||0)*100)}%</div>`:''}
     ${Array.isArray(p.payload?.units)?p.payload.units.map((u,idx)=>{
       const photos=(u.photos||[]);
       return `<div class="unitReview">
         <div class="unitReviewHeader"><b>Unit ${esc(u.unit_label||String.fromCharCode(65+idx))}</b>
         <span class=muted>${esc(u.condition?.state||p.payload?.condition?.state||'unknown')} · ${esc(u.condition?.sealed_status||p.payload?.condition?.sealed_status||'unknown')} · box ${esc(u.condition?.box_condition||p.payload?.condition?.box_condition||'unknown')}</span></div>
         <div class="reviewGallery">${photos.map(ph=>{
           const fn=ph.filename||'';
           const matched=p.photo_status.some(s=>s.filename===fn&&s.matched);
           const src=`ai_import.php?action=view_staged_photo&import_id=${encodeURIComponent(p.import_id||id)}&filename=${encodeURIComponent(fn)}`;
           return `<figure class="${matched?'':'missingPhoto'}">
             ${matched?`<img class="reviewThumb" src="${src}" data-full="${src}" alt="${esc(fn)}">`:`<div class="missingBox">Missing</div>`}
             <figcaption><b>${esc(ph.role||'unknown')}</b><br>${esc(fn)}</figcaption>
           </figure>`
         }).join('')}</div>
       </div>`
     }).join(''):''}
     ${p.decision==='pending'?`<button class=approveProposal data-id="${p.proposal_id}">Approve → create unit + photos + valuation</button> <button class=rejectProposal data-id="${p.proposal_id}">Reject</button>`:''}
   </div>`
 }).join('');
 $$('.reviewThumb').forEach(img=>img.onclick=()=>{ $('#imageDialogImg').src=img.dataset.full; $('#imageDialog').showModal(); });
 $$('.approveProposal').forEach(b=>b.onclick=async()=>{
   if(!confirm('Create the physical unit, copy its exact photos, and save the included valuation observation?'))return;
   try{
     let x=await api('ai_import.php?action=approve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({proposal_id:b.dataset.id})});
     alert('Created: '+x.created_units.map(u=>`${u.display_code} (${u.photo_count} photos)`).join(', '));
     showProposals(id);loadStatus();loadUnits();
   }catch(e){alert(e.message)}
 });
 $$('.rejectProposal').forEach(b=>b.onclick=async()=>{await api('ai_import.php?action=reject',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({proposal_id:b.dataset.id})});showProposals(id)});
}
