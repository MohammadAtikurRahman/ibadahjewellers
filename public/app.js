'use strict';

let products = [];
const categories = ['All','Earrings','Necklaces','Rings','Bracelets'];
const IMAGE_BASE = 'assets/images/';
const BAG_KEY = 'ibadah-jewellers-bag-v2';
const WISHLIST_KEY = 'ibadah-jewellers-wishlist-v1';
const CHECKOUT_RETRY_KEY = 'ibadah-jewellers-checkout-retry-v1';
const MAX_PER_VARIANT = 99;
const FREE_DELIVERY = 2500;
const WRAP_FEE = 100;
const $ = id => document.getElementById(id);
const productById = id => products.find(p=>p.id===id);
const imageFor = product => IMAGE_BASE+encodeURIComponent(
  /^[a-z0-9][a-z0-9_.-]*\.(jpe?g|png|webp|avif)$/i.test(product.image) ? product.image : 'hero-hoops.jpg'
);
const money = amount => '৳'+new Intl.NumberFormat('en-BD').format(amount);
const escapeHTML = value => String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon = (name,classes='') => `<svg class="icon ${classes}" aria-hidden="true"><use href="#icon-${name}"/></svg>`;
const storageRead = key => {try{return JSON.parse(localStorage.getItem(key));}catch{return null;}};
const storageWrite = (key,value) => {try{localStorage.setItem(key,JSON.stringify(value));}catch{/* Browsing and the shopping bag still work without storage. */}};
const stockFor = (product,size) => Number(product?.stock?.[size]) || 0;
const isSoldOut = product => !product.sizes.some(size=>stockFor(product,size)>0);
const visibleFinish = product => ['gold','silver','rose'].includes(product.finish) ? product.finish : '';
const defaultSize = product => {
  const available = product.sizes.filter(size=>stockFor(product,size)>0);
  return available.includes('US 7') ? 'US 7' : available[0] ?? product.sizes[0];
};
const itemKey = (id,size) => `${id}::${size}`;
let filters={category:'All',query:'',finishes:[],maxPrice:null,savedOnly:false};
let wishlist=[];
let bag={items:[],giftWrap:false,coupon:''};
let catalogueState='loading';
let checkoutEnabled=false;
let submittingOrder=false;
let retryKeys=readRetryKeys();
let deliveryZone='dhaka';
let selectedProduct=null;
let selectedSize='';
let detailQuantity=1;
let resumeProductAfterSize=false;
let heroSlide=0;

function readWishlist(){
  const saved=storageRead(WISHLIST_KEY);
  return Array.isArray(saved) ? [...new Set(saved.filter(id=>productById(id)))] : [];
}
function readBag(){
  const saved=storageRead(BAG_KEY);
  if(saved && typeof saved==='object' && Array.isArray(saved.items)){
    const unique=new Map();
    for(const item of saved.items){
      if(!item || typeof item!=='object')continue;
      const p=productById(item.productId);
      if(!p || !p.sizes.includes(item.size) || !Number.isInteger(item.quantity) || item.quantity<1 || item.quantity>MAX_PER_VARIANT)continue;
      const key=itemKey(p.id,item.size);
      if(!unique.has(key))unique.set(key,{key,productId:p.id,size:item.size,quantity:item.quantity});
    }
    const items=[...unique.values()];
    return {items,giftWrap:items.length>0 && saved.giftWrap===true,coupon:items.length>0 && saved.coupon==='IBADAH10' ? 'IBADAH10' : ''};
  }
  const legacy=storageRead('nira-shopping-bag-v1');
  const items=[];
  if(legacy && typeof legacy==='object' && !Array.isArray(legacy)){
    for(const p of products){const quantity=legacy[p.id];if(Number.isInteger(quantity)&&quantity>0&&quantity<=MAX_PER_VARIANT){const size=p.sizes.includes('US 7')?'US 7':p.sizes[0];items.push({key:itemKey(p.id,size),productId:p.id,size,quantity});}}
  }
  return {items,giftWrap:false,coupon:''};
}
async function loadCatalogue(){
  const firstLoad=catalogueState!=='ready';
  if(firstLoad)catalogueState='loading';
  renderProducts();
  try{
    const response=await fetch('./api/catalog.php',{
      cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.timeout(10000)
    });
    if(!response.ok)throw new Error('Catalogue could not be loaded');
    const catalogue=await response.json();
    if(!Array.isArray(catalogue.products))throw new Error('Invalid catalogue');
    products=catalogue.products;
    checkoutEnabled=catalogue.checkoutEnabled===true;
    catalogueState='ready';
    if(firstLoad){
      wishlist=readWishlist();
      bag=readBag();
    }else{
      wishlist=wishlist.filter(id=>productById(id));
      bag.items=bag.items.filter(item=>productById(item.productId)?.sizes.includes(item.size));
    }
  }catch{
    if(firstLoad)catalogueState='unavailable';
    checkoutEnabled=false;
  }
  syncFilterForm();
  renderProducts();
  renderWishlist();
  renderCart();
  updateDetailStock();
  renderSearch();
}
function readRetryKeys(){
  try{
    const stored=JSON.parse(sessionStorage.getItem(CHECKOUT_RETRY_KEY));
    if(stored && typeof stored==='object' && !Array.isArray(stored))return stored;
  }catch{/* Browsing still works with storage disabled. */}
  return {};
}
function saveRetryKeys(){
  try{sessionStorage.setItem(CHECKOUT_RETRY_KEY,JSON.stringify(retryKeys));}
  catch{/* The current page still retains retry keys in memory. */}
}
async function getOrderAttempt({expectedTotal,items,...details}){
  const sortedItems=[...items].sort((a,b)=>itemKey(a.productId,a.size).localeCompare(itemKey(b.productId,b.size)));
  const bytes=new TextEncoder().encode(JSON.stringify({...details,items:sortedItems}));
  const digest=await crypto.subtle.digest('SHA-256',bytes);
  const signature=Array.from(new Uint8Array(digest),byte=>byte.toString(16).padStart(2,'0')).join('');
  return {signature,order:retryKeys[signature] ?? {key:crypto.randomUUID(),expectedTotal}};
}
function insufficientStock(){
  return bag.items.some(item=>item.quantity>stockFor(productById(item.productId),item.size));
}
function addLimit(id,size){
  const inBag=bag.items.find(item=>item.key===itemKey(id,size))?.quantity ?? 0;
  return Math.max(0,Math.min(MAX_PER_VARIANT,stockFor(productById(id),size))-inBag);
}
function saveBag(){
  if(!bag.items.length){bag.giftWrap=false;bag.coupon='';$('giftMessage').value='';}
  storageWrite(BAG_KEY,bag);
}
function totals(){
  const count=bag.items.reduce((sum,item)=>sum+item.quantity,0);
  const subtotal=bag.items.reduce((sum,item)=>sum+productById(item.productId).price*item.quantity,0);
  const discount=bag.coupon==='IBADAH10' ? Math.floor((subtotal+5)/10) : 0;
  const merchandise=subtotal-discount;
  const wrapping=count&&bag.giftWrap ? WRAP_FEE : 0;
  const shipping=!count||merchandise>=FREE_DELIVERY ? 0 : deliveryZone==='outside'?120:80;
  return {count,subtotal,discount,merchandise,wrapping,shipping,total:merchandise+wrapping+shipping};
}
function notify(message){
  $('toastMessage').textContent=message;
  bootstrap.Toast.getOrCreateInstance($('shopToast'),{delay:3000}).show();
}
function showModal(id){
  const next=$(id);
  const current=document.querySelector('.modal.show,.offcanvas.show');
  if(current && current!==next){
    const type=current.classList.contains('modal')?'modal':'offcanvas';
    current.addEventListener(`hidden.bs.${type}`,()=>bootstrap.Modal.getOrCreateInstance(next).show(),{once:true});
    (type==='modal'?bootstrap.Modal:bootstrap.Offcanvas).getOrCreateInstance(current).hide();
  }else bootstrap.Modal.getOrCreateInstance(next).show();
}
function hideDrawer(id){bootstrap.Offcanvas.getOrCreateInstance($(id)).hide();}
function goToSection(id,updateHash=true){
  const target=id ? $(id) : document.body;
  if(!target)return;
  const scroll=()=>{
    const top=id ? target.getBoundingClientRect().top+window.scrollY-$('siteHeader').offsetHeight-20 : 0;
    const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top:Math.max(0,top),behavior:reduced?'auto':'smooth'});
    if(updateHash)history.replaceState(null,'',id?'#'+id:'#');
    if(id==='main')target.focus({preventScroll:true});
  };
  const drawer=document.querySelector('.offcanvas.show');
  if(drawer){drawer.addEventListener('hidden.bs.offcanvas',scroll,{once:true});bootstrap.Offcanvas.getOrCreateInstance(drawer).hide();}else scroll();
}

function renderCategoryTabs(){
  $('shopCategories').innerHTML=categories.map(category=>`<button class="shop-tab ${filters.category===category?'is-active':''}" data-category="${category}" aria-pressed="${filters.category===category}">${category==='All'?'All pieces':category}</button>`).join('');
}
function filteredProducts(){
  const query=filters.query.trim().toLowerCase();
  const result=products.filter(p=>(filters.category==='All'||p.category===filters.category)&&(!query||`${p.name} ${p.category} ${p.finishLabel}`.toLowerCase().includes(query))&&(!filters.finishes.length||filters.finishes.includes(p.finish))&&(filters.maxPrice===null||p.price<=filters.maxPrice)&&(!filters.savedOnly||wishlist.includes(p.id)));
  const sort=$('productSort').value;
  if(sort==='price-asc')result.sort((a,b)=>a.price-b.price);
  if(sort==='price-desc')result.sort((a,b)=>b.price-a.price);
  if(sort==='name')result.sort((a,b)=>a.name.localeCompare(b.name));
  return result;
}
function renderProducts(){
  const visible=filteredProducts();
  $('catalogueStatus').hidden=catalogueState==='ready' && checkoutEnabled;
  $('catalogueMessage').textContent=catalogueState==='loading'
    ? 'Loading the collection…'
    : catalogueState==='unavailable' ? 'The collection is temporarily unavailable.' : 'Online ordering is currently unavailable.';
  $('retryCatalogue').hidden=catalogueState==='loading';
  $('productGrid').innerHTML=visible.map(p=>`<article class="product-card"><div class="product-photo"><button class="product-image-button" data-view="${escapeHTML(p.id)}" aria-label="View ${escapeHTML(p.name)}"><img src="${imageFor(p)}" alt="${escapeHTML(p.name)}" width="640" height="800" loading="lazy"></button><span class="product-tag">${isSoldOut(p)?'Sold out':escapeHTML(p.tag)}</span><button class="save-product ${wishlist.includes(p.id)?'is-saved':''}" data-save="${escapeHTML(p.id)}" aria-label="${wishlist.includes(p.id)?'Remove':'Save'} ${escapeHTML(p.name)} ${wishlist.includes(p.id)?'from':'to'} wishlist" aria-pressed="${wishlist.includes(p.id)}">${icon('heart')}</button><button class="quick-add" ${!checkoutEnabled||isSoldOut(p)?'hidden':''} data-quick-add="${escapeHTML(p.id)}" aria-label="${p.sizes.length>1?'Choose size for':'Add'} ${escapeHTML(p.name)}${p.sizes.length>1?'':' to bag'}"><span>${p.sizes.length>1?'Choose your size':'Add a little to your bag'}</span>${icon('plus')}</button></div><div class="product-information"><div class="product-meta"><span>${escapeHTML(p.category)}</span><span class="finish-dot finish-${visibleFinish(p)}" title="${escapeHTML(p.finishLabel)}"></span></div><h3><button class="product-name" data-view="${escapeHTML(p.id)}">${escapeHTML(p.name)}</button></h3><div class="product-price"><span>${money(p.price)}</span>${p.comparePrice>p.price?`<del>${money(p.comparePrice)}</del>`:''}</div><div class="product-description-line">${escapeHTML(p.note)}</div></div></article>`).join('');
  $('resultCount').textContent=catalogueState==='ready'?`${visible.length} ${visible.length===1?'piece':'pieces'}` : '';
  $('emptyProducts').hidden=catalogueState!=='ready'||visible.length>0;
  renderFilterChips();
}
function renderFilterChips(){
  const chips=[];
  const names={gold:'Gold tone',silver:'Silver tone',rose:'Rose-gold tone'};
  for(const finish of filters.finishes)chips.push(`<button class="filter-chip" data-clear-finish="${finish}">${names[finish]} ${icon('close')}</button>`);
  if(filters.maxPrice!==null)chips.push(`<button class="filter-chip" data-clear-budget>Under ${money(filters.maxPrice)} ${icon('close')}</button>`);
  if(filters.savedOnly)chips.push(`<button class="filter-chip" data-clear-saved>Saved pieces ${icon('close')}</button>`);
  if(filters.query)chips.push(`<button class="filter-chip" data-clear-query>“${escapeHTML(filters.query)}” ${icon('close')}</button>`);
  $('activeFilters').innerHTML=chips.join('');
  const count=filters.finishes.length+(filters.maxPrice!==null?1:0)+(filters.savedOnly?1:0);
  $('filterCount').textContent=count;$('filterCount').hidden=count===0;
}
function syncFilterForm(){
  document.querySelectorAll('#filterForm input[name=finish]').forEach(input=>input.checked=filters.finishes.includes(input.value));
  const prices=products.map(product=>product.price);
  const min=Math.min(1000,...prices);
  const max=Math.max(3000,...prices);
  const slider=$('priceRange');
  slider.min=min;
  slider.max=max;
  slider.value=filters.maxPrice ?? max;
  $('minBudget').textContent=money(min);
  $('maxBudget').textContent=money(max);
  updateBudgetOutput();
  $('savedOnly').checked=filters.savedOnly;
}
function updateBudgetOutput(){
  const slider=$('priceRange');
  $('budgetOutput').textContent=slider.value===slider.max?'Any price':money(Number(slider.value));
}
function selectCategory(category,resetExtra=false){
  if(!categories.includes(category))return;
  filters.category=category;filters.query='';$('shopSearch').value='';
  if(resetExtra){filters.finishes=[];filters.maxPrice=null;filters.savedOnly=false;syncFilterForm();}
  renderCategoryTabs();renderProducts();
}
function resetFilters(){
  filters={category:'All',query:'',finishes:[],maxPrice:null,savedOnly:false};
  $('shopSearch').value='';$('productSort').value='featured';syncFilterForm();renderCategoryTabs();renderProducts();
}

function toggleWishlist(id){
  const p=productById(id);if(!p)return;
  const adding=!wishlist.includes(id);
  wishlist=adding?[...wishlist,id]:wishlist.filter(value=>value!==id);
  storageWrite(WISHLIST_KEY,wishlist);
  renderProducts();renderWishlist();
  if(selectedProduct===id)updateDetailSave();
  notify(adding?`${p.name}, saved for a little later.`:`${p.name} removed from your wishlist.`);
}
function renderWishlist(){
  $('wishlistCount').textContent=wishlist.length;$('wishlistCount').hidden=wishlist.length===0;
  if(!wishlist.length){$('wishlistItems').innerHTML=`<div class="empty-state">${icon('heart')}<h3>A little room for favourites.</h3><p>Tap the heart on a piece you love. We'll keep it here for a little later.</p><button class="button button-dark" data-explore-from="wishlistPanel">Find your favourite ${icon('arrow')}</button></div>`;return;}
  $('wishlistItems').innerHTML=wishlist.map(id=>{const p=productById(id);return `<article class="wishlist-item"><img src="${imageFor(p)}" alt="${escapeHTML(p.name)}" width="100" height="125"><div><h3><button class="product-name" data-view="${escapeHTML(p.id)}">${escapeHTML(p.name)}</button></h3><p>${money(p.price)} · ${escapeHTML(p.finishLabel)}</p><button class="button button-dark" data-quick-add="${escapeHTML(p.id)}" ${!checkoutEnabled||isSoldOut(p)?'hidden':''}>${p.sizes.length>1?'Choose a size':'Add to bag'} ${icon('plus')}</button><br><button class="editorial-link" data-save="${escapeHTML(p.id)}">Remove from wishlist</button></div></article>`;}).join('');
}

function addToBag(id,size,quantity=1){
  const p=productById(id);
  if(!checkoutEnabled||!p||!p.sizes.includes(size)||!Number.isInteger(quantity)||quantity<1)return;
  const key=itemKey(id,size);const existing=bag.items.find(item=>item.key===key);
  if(quantity>addLimit(id,size)){notify('There is no more stock available for this size.');return;}
  if(existing)existing.quantity+=quantity;else bag.items.push({key,productId:id,size,quantity});
  saveBag();renderCart();if(selectedProduct===id)updateDetailStock();notify(`${p.name} added to your little treasures.`);
}
function quickAdd(id){const p=productById(id);if(!p)return;if(p.sizes.length>1)openProduct(id);else addToBag(id,p.sizes[0]);}
function changeQuantity(key,delta){
  const item=bag.items.find(item=>item.key===key);if(!item||![-1,1].includes(delta))return;
  if(delta>0 && (!checkoutEnabled || addLimit(item.productId,item.size)===0))return;
  item.quantity+=delta;
  if(item.quantity<=0)bag.items=bag.items.filter(item=>item.key!==key);
  saveBag();renderCart();
}
function removeItem(key){bag.items=bag.items.filter(item=>item.key!==key);saveBag();renderCart();}
function summaryHTML(t,{removableCoupon=false}={}){
  return `<div class="summary-row"><span>Subtotal</span><span>${money(t.subtotal)}</span></div>${t.discount?`<div class="summary-row discount"><span>IBADAH10 · 10% off ${removableCoupon?'<button class="remove-coupon" data-remove-coupon>Remove</button>':''}</span><span>−${money(t.discount)}</span></div>`:''}${t.wrapping?`<div class="summary-row"><span>A little gift wrap</span><span>${money(t.wrapping)}</span></div>`:''}<div class="summary-row"><span>Delivery</span><span>${t.shipping?money(t.shipping):'Complimentary'}</span></div><div class="summary-row total"><span>Total</span><span>${money(t.total)}</span></div>`;
}
function renderCart(){
  const t=totals();
  const stockIssue=insufficientStock();
  $('cartStockMessage').hidden=!stockIssue;
  $('cartStockMessage').textContent=Object.keys(retryKeys).length
    ? 'Stock changed. If you already submitted this order, you can check it by retrying with the same details.'
    : 'Update the quantities shown above to match available stock.';
  $('checkoutButton').disabled=!checkoutEnabled || (stockIssue && !Object.keys(retryKeys).length);
  $('cartCheckoutMessage').textContent=checkoutEnabled?'Pay in cash when your order arrives.':'Online ordering is currently unavailable.';
  $('cartCount').textContent=t.count;$('cartTitleCount').textContent=`(${t.count})`;
  for(const id of ['deliveryProgress','cartExtras','cartSummary','cartFooter'])$(id).hidden=!t.count;
  $('giftWrap').checked=bag.giftWrap;$('giftMessageField').hidden=!bag.giftWrap;
  if(!t.count){$('cartItems').innerHTML=`<div class="empty-state">${icon('bag')}<h3>Your bag is waiting<br>for a little sparkle.</h3><p>Find something that feels like you. We'll keep your favourites right here.</p><button class="button button-dark" data-explore-from="cartPanel">Discover the collection ${icon('arrow')}</button></div>`;$('couponMessage').textContent='';$('couponInput').value='';}
  else{
    $('deliveryMessage').textContent=t.merchandise>=FREE_DELIVERY?'A little extra joy. Your delivery is complimentary.':`You're ${money(FREE_DELIVERY-t.merchandise)} away from complimentary delivery.`;
    $('deliveryMeter').style.width=Math.min(100,t.merchandise/FREE_DELIVERY*100)+'%';
    $('cartItems').innerHTML=bag.items.map(item=>{const p=productById(item.productId);return `<article class="bag-item"><img src="${imageFor(p)}" alt="${escapeHTML(p.name)}" width="91" height="116"><div><div class="bag-item-top"><h3>${escapeHTML(p.name)}</h3><button class="icon-button" data-remove-item="${escapeHTML(item.key)}" aria-label="Remove ${escapeHTML(p.name)}${p.sizes.length>1?' '+escapeHTML(item.size):''}">${icon('close')}</button></div><p>${escapeHTML(p.finishLabel)}</p>${p.sizes.length>1?`<p>Size: ${escapeHTML(item.size)}</p>`:''}${item.quantity>stockFor(p,item.size)?`<p class="stock-warning">${stockFor(p,item.size) ? `Only ${stockFor(p,item.size)} available` : 'Sold out'}</p>`:''}<div class="bag-item-bottom"><div class="quantity-control" role="group" aria-label="Quantity for ${escapeHTML(p.name)} ${escapeHTML(item.size)}"><button data-quantity="${escapeHTML(item.key)}" data-delta="-1" aria-label="Decrease quantity of ${escapeHTML(p.name)} ${escapeHTML(item.size)}">${icon('minus')}</button><span>${item.quantity}</span><button data-quantity="${escapeHTML(item.key)}" data-delta="1" ${!checkoutEnabled||addLimit(item.productId,item.size)===0?'disabled':''} aria-label="Increase quantity of ${escapeHTML(p.name)} ${escapeHTML(item.size)}">${icon('plus')}</button></div><span class="bag-item-price">${money(p.price*item.quantity)}</span></div></div></article>`;}).join('');
    $('cartSummary').innerHTML=summaryHTML(t,{removableCoupon:true});
    if(bag.coupon){$('couponMessage').textContent='A little treat applied: 10% off your pieces.';$('couponMessage').classList.remove('error');}
  }
  renderCheckout();
}
function applyCoupon(code){
  if(!bag.items.length){notify('Add a little something to your bag first.');return;}
  if(code.trim().toUpperCase()!=='IBADAH10'){$('couponMessage').textContent="That code isn't available.";$('couponMessage').classList.add('error');return;}
  bag.coupon='IBADAH10';$('couponInput').value='';saveBag();renderCart();notify('A little treat — 10% off your pieces.');
}

function openProduct(id){
  const p=productById(id);if(!p)return;
  selectedProduct=id;selectedSize=defaultSize(p);detailQuantity=1;
  $('productDetails').innerHTML=`<div class="product-detail-grid"><div class="detail-gallery"><div class="detail-main" id="detailMain"><img src="${imageFor(p)}" alt="${escapeHTML(p.name)}" width="640" height="800"></div><div class="detail-thumbnails"><button class="detail-thumbnail is-active" data-detail-zoom="false" aria-label="Show full product photograph" aria-pressed="true"><img src="${imageFor(p)}" alt=""></button><button class="detail-thumbnail" data-detail-zoom="true" aria-label="Show closer product detail" aria-pressed="false"><img src="${imageFor(p)}" alt=""></button></div><p class="detail-image-hint">A closer look at your next favourite.</p></div><div class="detail-information"><span class="eyebrow">THE IBADAH EDIT / ${escapeHTML(p.category.toUpperCase())}</span><h2 id="productTitle">${escapeHTML(p.name)}</h2><div class="detail-price"><span>${money(p.price)}</span>${p.comparePrice>p.price?`<del>${money(p.comparePrice)}</del>`:''}</div><p class="detail-description">${escapeHTML(p.description)}</p><div class="detail-field-heading"><span>THE FINISH</span></div><div class="detail-finish"><span class="finish-dot finish-${visibleFinish(p)}"></span>${escapeHTML(p.finishLabel)}</div>${p.sizes.length>1?`<div class="detail-field-heading"><span>YOUR SIZE</span><button class="inline-button" data-size-guide>Size guide</button></div><div class="size-options" role="group" aria-label="Choose ring size">${p.sizes.map(size=>`<button class="size-option ${size===selectedSize?'is-active':''}" data-size="${escapeHTML(size)}" aria-pressed="${size===selectedSize}" ${stockFor(p,size)?'':'disabled'}>${escapeHTML(size.replace('US ',''))}</button>`).join('')}</div>`:''}<p id="detailStock" class="detail-stock" role="status"></p><div class="detail-purchase"><div class="quantity-control" role="group" aria-label="Product quantity"><button data-detail-quantity="-1" aria-label="Decrease product quantity">${icon('minus')}</button><span id="detailQuantity">1</span><button data-detail-quantity="1" aria-label="Increase product quantity">${icon('plus')}</button></div><button class="button button-dark" id="detailAdd">Add to your bag ${icon('plus')}</button></div><button class="detail-save" id="detailSave" data-save="${escapeHTML(p.id)}"></button><div class="accordion accordion-flush" id="detailAccordion"><div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#detailDescription" aria-expanded="false" aria-controls="detailDescription">The little details</button></h3><div id="detailDescription" class="accordion-collapse collapse" data-bs-parent="#detailAccordion"><div class="accordion-body">${escapeHTML(p.details)}</div></div></div><div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#detailDelivery" aria-expanded="false" aria-controls="detailDelivery">Delivery & a little care</button></h3><div id="detailDelivery" class="accordion-collapse collapse" data-bs-parent="#detailAccordion"><div class="accordion-body">Delivery: ৳80 within Dhaka, ৳120 outside Dhaka; complimentary at ৳2,500 after discounts. Keep your piece dry, away from perfume, and gently wipe with a soft cloth.</div></div></div></div></div></div>`;
  updateDetailSave();updateDetailStock();showModal('productModal');
}
function updateDetailStock(){
  if(!$('detailAdd'))return;
  const product=productById(selectedProduct);
  const stock=stockFor(product,selectedSize);
  const remaining=addLimit(selectedProduct,selectedSize);
  detailQuantity=Math.max(1,Math.min(detailQuantity,remaining));
  $('detailQuantity').textContent=detailQuantity;
  $('detailStock').textContent=!checkoutEnabled ? 'Online ordering is currently unavailable.'
    : stock===0 ? 'Sold out' : remaining===0 ? 'All available stock is in your bag.' : stock<=5 ? `Only ${stock} available` : 'In stock';
  $('detailAdd').disabled=!checkoutEnabled||remaining===0;
  $('detailQuantity').previousElementSibling.disabled=detailQuantity<=1;
  $('detailQuantity').nextElementSibling.disabled=!checkoutEnabled||detailQuantity>=remaining;
}
function updateDetailSave(){
  const el=$('detailSave');if(!el||!selectedProduct)return;
  const saved=wishlist.includes(selectedProduct);el.classList.toggle('is-saved',saved);el.setAttribute('aria-pressed',saved);el.innerHTML=icon('heart')+(saved?'Saved to your little favourites':'Save for a little later');
}
function renderSearch(){
  const query=$('globalSearch').value.trim().toLowerCase();
  const found=products.filter(p=>!query||`${p.name} ${p.category} ${p.finishLabel}`.toLowerCase().includes(query));
  $('searchResults').innerHTML=catalogueState!=='ready' ? '<p class="search-empty">The collection is currently unavailable.</p>' : found.length?found.map(p=>`<button class="search-result" data-view="${escapeHTML(p.id)}"><img src="${imageFor(p)}" alt="" width="52" height="62"><span><strong>${escapeHTML(p.name)}</strong><small>${escapeHTML(p.category)} · ${escapeHTML(p.finishLabel)}</small></span><span>${money(p.price)}</span></button>`).join(''):'<p class="search-empty">No little treasures found. Try a category or a different word.</p>';
}

function renderCheckout(){
  const t=totals();
  $('checkoutSubmit').disabled=submittingOrder||!checkoutEnabled||!t.count;
  $('checkoutItems').innerHTML=bag.items.map(item=>{const p=productById(item.productId);return `<div class="checkout-line-item"><img src="${imageFor(p)}" alt="${escapeHTML(p.name)}" width="52" height="65"><span>${escapeHTML(p.name)}<small>${p.sizes.length>1?escapeHTML(item.size)+' · ':''}Qty ${item.quantity}</small></span><span>${money(p.price*item.quantity)}</span></div>`;}).join('');
  $('checkoutSummary').innerHTML=`<div class="bag-summary">${summaryHTML(t)}</div>`;
  if(!t.count && $('checkoutSuccess').hidden && !$('checkoutError').textContent && $('checkoutModal').classList.contains('show')){
    bootstrap.Modal.getOrCreateInstance($('checkoutModal')).hide();notify('Your bag is empty. Find a little something first.');
  }
}
function syncDeliveryZone(){
  deliveryZone=$('deliveryZone').value;
  renderCart();
}
function showCheckoutError(message){
  const error=$('checkoutError');
  error.textContent=message;
  error.hidden=!message;
  if(message)error.focus();
}
function displayOrderConfirmation(order,payload){
  $('orderReceipt').innerHTML=`<div class="summary-row"><span>Order reference</span><strong>${escapeHTML(order.reference)}</strong></div><div class="summary-row"><span>Delivery to ${escapeHTML(payload.city)}</span></div><div class="summary-row total"><span>Total to pay</span><strong>${money(order.total)}</strong></div>`;
  $('checkoutFormView').hidden=true;
  $('checkoutSuccess').hidden=false;
  $('checkoutModal').querySelector('.modal-body').scrollTop=0;
  $('checkoutSuccessTitle').focus();
}
async function submitOrder(event){
  event.preventDefault();
  if(submittingOrder || !bag.items.length || !checkoutEnabled)return;
  if(!window.isSecureContext || !globalThis.crypto?.subtle || !crypto.randomUUID){
    showCheckoutError('Open the shop over HTTPS to place your order.');
    return;
  }
  showCheckoutError('');
  submittingOrder=true;
  $('checkoutSubmit').disabled=true;
  $('checkoutSubmit').setAttribute('aria-busy','true');
  $('checkoutSubmitLabel').textContent='Placing order…';
  const payload={
    items:bag.items.map(({productId,size,quantity})=>({productId,size,quantity})),
    name:$('customerName').value.trim(),
    phone:$('customerPhone').value.trim(),
    email:$('customerEmail').value.trim(),
    address:$('customerAddress').value.trim(),
    city:$('customerCity').value.trim(),
    fulfillment:'delivery',
    zone:deliveryZone,
    coupon:bag.coupon,
    giftWrap:bag.giftWrap,
    giftMessage:bag.giftWrap?$('giftMessage').value.trim():'',
    expectedTotal:totals().total
  };
  let awaitingResponse=false;
  try{
    const attempt=await getOrderAttempt(payload);
    if(insufficientStock() && !retryKeys[attempt.signature]){
      showCheckoutError('Some quantities in your bag are no longer available. Update your bag to continue.');
      return;
    }
    awaitingResponse=true;
    retryKeys[attempt.signature]=attempt.order;
    saveRetryKeys();
    const response=await fetch('./api/checkout.php',{
      method:'POST',
      headers:{'Content-Type':'application/json',Accept:'application/json'},
      body:JSON.stringify({...payload,expectedTotal:attempt.order.expectedTotal,requestKey:attempt.order.key}),
      signal:AbortSignal.timeout(15000)
    });
    const order=await response.json();
    if(!response.ok){
      if(response.status>=500 || response.status===408 || response.status===429)throw new Error('Confirmation pending');
      delete retryKeys[attempt.signature];
      saveRetryKeys();
      awaitingResponse=false;
      showCheckoutError(typeof order.message==='string'?order.message:'Please check your details and try again.');
      if(response.status===409)await loadCatalogue();
      return;
    }
    if(typeof order.reference!=='string'||!Number.isInteger(order.total))throw new Error('Invalid confirmation');
    awaitingResponse=false;
    delete retryKeys[attempt.signature];
    saveRetryKeys();
    displayOrderConfirmation(order,payload);
    const purchased=new Map(payload.items.map(item=>[itemKey(item.productId,item.size),item.quantity]));
    bag.items=bag.items.map(item=>({...item,quantity:item.quantity-(purchased.get(item.key)??0)})).filter(item=>item.quantity>0);
    saveBag();
    renderCart();
    void loadCatalogue();
  }catch{
    showCheckoutError(awaitingResponse
      ? 'We could not confirm whether your order was received. Retry with the same details in this tab to check it.'
      : 'Checkout is temporarily unavailable. Please try again.');
  }finally{
    submittingOrder=false;
    $('checkoutSubmit').disabled=!checkoutEnabled||!bag.items.length;
    $('checkoutSubmit').removeAttribute('aria-busy');
    $('checkoutSubmitLabel').textContent='Place order';
  }
}
const heroSlides=[
  {title:'The pieces<br>we keep <em>close.</em>',description:'For the ordinary days that turn into your favourite memories. Discover jewellery that feels a little more like you.',image:'model-necklace.jpg',alt:'Delicate layered necklaces styled with a soft white blouse',caption:'THE ART OF EVERYDAY ADORNMENT',position:'50% 37%'},
  {title:'A little gold.<br>A lot of <em>you.</em>',description:'An effortless hoop. A favourite golden link. A little warm light, to take with you wherever your day goes.',image:'hero-hoops.jpg',alt:'Sculptural twisted hoop earrings in warm sunlight on pale stones',caption:'SMALL DETAILS / EVERYDAY FAVOURITES',position:'50% 50%'},
  {title:'Your moment.<br>Your <em>colour.</em>',description:'Deep greens, soft pinks, a midnight blue. Discover the little details that tell a colour story all your own.',image:'pendant.jpg',alt:'A deep green geometric pendant with a gold-tone chain',caption:'A LITTLE COLOUR GOES A LONG WAY',position:'50% 50%'}
];
function changeHero(delta){
  heroSlide=(heroSlide+delta+heroSlides.length)%heroSlides.length;const slide=heroSlides[heroSlide];
  $('heroTitle').innerHTML=slide.title;$('heroDescription').textContent=slide.description;
  $('heroImage').src=IMAGE_BASE+slide.image;$('heroImage').alt=slide.alt;$('heroImage').style.objectPosition=slide.position;
  $('heroCaption').textContent=slide.caption;$('heroIndex').textContent=String(heroSlide+1).padStart(2,'0');$('heroImageIndex').textContent='IBADAH / '+String(heroSlide+1).padStart(2,'0');$('heroProgress').style.width=((heroSlide+1)/3*100)+'%';
  if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches)$('heroImage').animate([{opacity:.4},{opacity:1}],{duration:450,easing:'ease-out'});
}
const articles={
  layering:{title:'The art of a little layering.',image:'model-necklace.jpg',body:`<p>There is something beautifully personal about a jewellery stack. No two have to be the same. A favourite chain, a piece you were given, a pendant you chose just because — together, they become a little story only you can tell.</p><h3>Start with a piece you love.</h3><p>Let one piece lead. It might be a pendant with a little colour or a simple chain you wear every day. Put that on first and build around it, instead of trying to make everything compete for attention.</p><h3>Leave a little breathing room.</h3><p>Different lengths help each necklace find its place. Pair a shorter chain with a longer pendant, and check how they sit against the neckline you are wearing. For bracelets, try balancing one open-link piece with a finer silhouette.</p><h3>Keep it feeling like you.</h3><p>Matching every finish is one option, not a rule. Warm and cool tones can work together when you repeat a small detail. The important part is comfort: a stack should move with you, without tangling or feeling heavy. Try two pieces before adding a third.</p><p>And if one piece feels just right on its own? That is a beautiful kind of layering too — the piece, the outfit, and you.</p>`},
  care:{title:'A little care. A lasting sparkle.',image:'layered.jpg',body:`<p>Favourite pieces tend to come along for everything. A few small habits can help them keep their finish and make getting dressed feel a little easier.</p><h3>The last thing on, the first thing off.</h3><p>Allow perfume, moisturiser and other products to dry before putting jewellery on. Remove pieces before showering, swimming, exercising or using cleaning products. Water, friction and chemicals can affect decorative finishes.</p><h3>A gentle little clean.</h3><p>After wearing, gently wipe with a clean, soft, dry cloth. Avoid abrasive polishes, toothpaste, harsh chemicals and ultrasonic cleaners unless the manufacturer specifically recommends them for that exact piece. Decorative stones, coatings and adhesives may need different care.</p><h3>Give each piece its own space.</h3><p>Keep necklaces fastened and separate to reduce tangling. A dry pouch or a lined jewellery box helps limit rubbing and dust. Keep pieces away from direct heat and store delicate stone settings where they will not be knocked by heavier jewellery.</p><p>For a specific material or finish, ask the store for its care instructions. A little attention, after all, is a lovely thing to give your everyday favourites.</p>`},
  gifting:{title:'A gift that feels like them.',image:'pearl-necklace.jpg',body:`<p>The most thoughtful gifts often begin with noticing. The earrings they always reach for. The colour they come back to. The small details that make their style feel unmistakably theirs.</p><h3>Notice their everyday favourites.</h3><p>If they love simple pieces, look for a familiar silhouette with one considered detail. If they enjoy colour, a pendant or a drop earring can feel personal without requiring a complete change of style. Necklaces and earrings can also be easier to choose when you are unsure of a ring size.</p><h3>Think about the moment.</h3><p>A gift does not have to announce itself loudly. A quiet little piece can mark a thank you, a beginning, a birthday or an ordinary day you wanted to make memorable. Picture how they might wear it, rather than choosing only for the occasion.</p><h3>Add a little note.</h3><p>Sometimes the words are what make the piece a keepsake. A short sentence about why you thought of them can mean more than a long message. Our bag includes gift wrap with space for a short message.</p><p>For questions about materials, sizing or packaging, contact the shop. The nicest little surprise starts with getting the details right.</p>`}
};
function openArticle(id){const article=articles[id];if(!article)return;$('editorialBody').innerHTML=`<img class="article-image" src="${IMAGE_BASE+article.image}" alt="Jewellery inspiration for ${escapeHTML(article.title)}"><h2 id="editorialTitle">${article.title}</h2>${article.body}<button class="editorial-link" data-article-shop>Find your everyday favourite ${icon('arrow')}</button>`;showModal('editorialModal');}

// One delegated listener keeps generated catalogue, bag and wishlist controls consistent.
document.addEventListener('click',event=>{
  const target=event.target;
  let button;
  if((button=target.closest('[data-save]'))){toggleWishlist(button.dataset.save);return;}
  if((button=target.closest('[data-view]'))){openProduct(button.dataset.view);return;}
  if((button=target.closest('[data-quick-add]'))){quickAdd(button.dataset.quickAdd);return;}
  if((button=target.closest('[data-category]'))){selectCategory(button.dataset.category);return;}
  if((button=target.closest('[data-quantity]'))){changeQuantity(button.dataset.quantity,Number(button.dataset.delta));return;}
  if((button=target.closest('[data-remove-item]'))){removeItem(button.dataset.removeItem);return;}
  if((button=target.closest('[data-size]'))){selectedSize=button.dataset.size;detailQuantity=1;updateDetailStock();document.querySelectorAll('[data-size]').forEach(el=>{const active=el.dataset.size===selectedSize;el.classList.toggle('is-active',active);el.setAttribute('aria-pressed',active);});return;}
  if((button=target.closest('[data-detail-quantity]'))){detailQuantity+=Number(button.dataset.detailQuantity);updateDetailStock();return;}
  if(target.closest('#detailAdd')){addToBag(selectedProduct,selectedSize,detailQuantity);return;}
  if((button=target.closest('[data-detail-zoom]'))){const zoom=button.dataset.detailZoom==='true';$('detailMain').classList.toggle('is-zoomed',zoom);document.querySelectorAll('[data-detail-zoom]').forEach(el=>{const active=el===button;el.classList.toggle('is-active',active);el.setAttribute('aria-pressed',active);});return;}
  if((button=target.closest('[data-clear-finish]'))){filters.finishes=filters.finishes.filter(f=>f!==button.dataset.clearFinish);syncFilterForm();renderProducts();return;}
  if(target.closest('[data-clear-budget]')){filters.maxPrice=null;syncFilterForm();renderProducts();return;}
  if(target.closest('[data-clear-saved]')){filters.savedOnly=false;syncFilterForm();renderProducts();return;}
  if(target.closest('[data-clear-query]')){filters.query='';$('shopSearch').value='';renderProducts();return;}
  if(target.closest('[data-remove-coupon]')){bag.coupon='';$('couponMessage').textContent='';saveBag();renderCart();return;}
  if((button=target.closest('[data-explore-from]'))){hideDrawer(button.dataset.exploreFrom);goToSection('shop');return;}
  if((button=target.closest('[data-article]'))){openArticle(button.dataset.article);return;}
  if(target.closest('[data-article-shop]')){const modal=$('editorialModal');modal.addEventListener('hidden.bs.modal',()=>goToSection('shop'),{once:true});bootstrap.Modal.getOrCreateInstance(modal).hide();return;}
  if(target.closest('[data-size-guide]')){resumeProductAfterSize=$('productModal').classList.contains('show');showModal('sizeModal');return;}
  const categoryLink=target.closest('[data-category-link]');if(categoryLink)selectCategory(categoryLink.dataset.categoryLink,true);
  const anchor=target.closest('a[href^="#"]');
  if(anchor&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey){const id=anchor.getAttribute('href').slice(1);if(!id||$(id)){event.preventDefault();goToSection(id);}}
});
$('shopSearch').addEventListener('input',event=>{filters.query=event.target.value.trim();renderProducts();});
$('productSort').addEventListener('change',renderProducts);
$('clearAllFilters').addEventListener('click',resetFilters);
$('priceRange').addEventListener('input',updateBudgetOutput);
$('retryCatalogue').addEventListener('click',loadCatalogue);
$('filterPanel').addEventListener('show.bs.offcanvas',syncFilterForm);
$('filterForm').addEventListener('submit',event=>{event.preventDefault();filters.finishes=[...document.querySelectorAll('#filterForm input[name=finish]:checked')].map(input=>input.value);filters.maxPrice=$('priceRange').value===$('priceRange').max?null:Number($('priceRange').value);filters.savedOnly=$('savedOnly').checked;renderProducts();hideDrawer('filterPanel');});
$('resetDrawerFilters').addEventListener('click',()=>{filters.finishes=[];filters.maxPrice=null;filters.savedOnly=false;syncFilterForm();renderProducts();});
$('giftWrap').addEventListener('change',event=>{bag.giftWrap=event.target.checked;saveBag();renderCart();});
$('couponForm').addEventListener('submit',event=>{event.preventDefault();applyCoupon($('couponInput').value);});
$('tryCoupon').addEventListener('click',()=>applyCoupon('IBADAH10'));
$('globalSearch').addEventListener('input',renderSearch);
$('searchModal').addEventListener('shown.bs.modal',()=>{$('globalSearch').focus();renderSearch();});
$('searchModal').addEventListener('hidden.bs.modal',()=>{$('globalSearch').value='';});
$('checkoutButton').addEventListener('click',()=>{
  if(!checkoutEnabled||!bag.items.length)return;
  $('checkoutFormView').hidden=false;
  $('checkoutSuccess').hidden=true;
  $('checkoutSubmit').disabled=false;
  renderCheckout();
  showModal('checkoutModal');
});
$('checkoutForm').addEventListener('submit',submitOrder);
$('checkoutModal').addEventListener('hide.bs.modal',event=>{if(submittingOrder)event.preventDefault();});
$('checkoutEditBag').addEventListener('click',()=>{
  if(submittingOrder)return;
  const checkout=$('checkoutModal');
  checkout.addEventListener('hidden.bs.modal',()=>bootstrap.Offcanvas.getOrCreateInstance($('cartPanel')).show(),{once:true});
  bootstrap.Modal.getOrCreateInstance(checkout).hide();
});
$('deliveryZone').addEventListener('change',syncDeliveryZone);
$('checkoutModal').addEventListener('hidden.bs.modal',()=>{
  if(!$('checkoutSuccess').hidden){
    $('checkoutForm').reset();
    $('orderReceipt').replaceChildren();
  }
  $('checkoutFormView').hidden=false;
  $('checkoutSuccess').hidden=true;
  showCheckoutError('');
  syncDeliveryZone();
});
$('sizeModal').addEventListener('hidden.bs.modal',()=>{if(resumeProductAfterSize){resumeProductAfterSize=false;bootstrap.Modal.getOrCreateInstance($('productModal')).show();}});
$('heroPrevious').addEventListener('click',()=>changeHero(-1));$('heroNext').addEventListener('click',()=>changeHero(1));
$('shopGifts').addEventListener('click',()=>{resetFilters();filters.maxPrice=1800;syncFilterForm();renderProducts();goToSection('shop');});
$('backToTop').addEventListener('click',()=>goToSection(''));
window.addEventListener('scroll',()=>$('backToTop').hidden=window.scrollY<700,{passive:true});
window.addEventListener('storage',event=>{if(catalogueState!=='ready')return;if(event.key===BAG_KEY||event.key===null){bag=readBag();renderCart();}if(event.key===WISHLIST_KEY||event.key===null){wishlist=readWishlist();renderWishlist();renderProducts();updateDetailSave();}});
$('year').textContent=new Date().getFullYear();renderCategoryTabs();renderWishlist();renderCart();renderSearch();
void loadCatalogue();
