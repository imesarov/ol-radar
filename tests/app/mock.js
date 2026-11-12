(function(){
const J = o => typeof o === "string" ? ({content:[{type:"text",text:o}], payload:o}) : ({content:[{type:"text",text:JSON.stringify(o)},{type:"text",text:JSON.stringify({apiUsageCosts:{"units-cost-total-actual":50}})}], payload:o});
const isoL = d => new Date(d.getTime() - d.getTimezoneOffset()*6e4).toISOString().slice(0,10);
const MODE = location.search;
const days=[]; const base=new Date(); for(let i=90;i>=1;i--){const d=new Date(base); d.setDate(d.getDate()-i); days.push(d.toISOString().slice(0,10));}
const daily = "["+days.length+"]{Date,clicks,impressions,ctr,position}:\n"+days.map((d,i)=>`  ${d},${8+Math.round(Math.sin(i/5)*4+i/8)},${380+i*5+Math.round(Math.cos(i/3)*60)},0.025,${(8.5-i/60).toFixed(2)}`).join("\n");
const qp = `[16]{query,page,clicks,impressions,ctr,position}:
  (unknown),https://www.optikaljiljana.hr/,230,9212,0.025,6.19
  optika ljiljana,https://www.optikaljiljana.hr/,56,162,0.3457,1.04
  progresivne naočale iskustva,https://www.optikaljiljana.hr/progresivne-naocale-iskustva/,15,97,0.1546,3.38
  optika ljiljana sesvete,https://www.optikaljiljana.hr/,13,63,0.2063,1.17
  progresivne naočale cijena,https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/,4,150,0.0266,6.6
  progresivne naočale cijena,https://www.optikaljiljana.hr/progresivne-naocalne-lece/,2,60,0.033,7.1
  naočale za vožnju po noći,https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/,4,57,0.0702,6.21
  varilux progresivne leće cijena,https://www.optikaljiljana.hr/progresivne-naocalne-lece/,4,34,0.1176,5.35
  optika sesvete,https://www.optikaljiljana.hr/,3,139,0.0216,11.64
  cijena progresivnih naočala,https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/,2,130,0.0154,6.4
  essilor stakla iskustva,https://www.optikaljiljana.hr/progresivne-naocale-iskustva/,2,41,0.0488,7.27
  naočale za noćnu vožnju,https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/,2,252,0.0079,6.85
  progresivna stakla cijena,https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/,2,62,0.0323,4.29
  žute naočale za noćnu vožnju,https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/,2,36,0.0556,1.44
  besplatni pregled vida,https://www.optikaljiljana.hr/besplatan-pregled-vida-sesvete/,1,39,0.0256,6.23
  dioptrijske naočale,https://www.optikaljiljana.hr/dioptrijske-naocale/,0,88,0,38.2`;
const pages = `[5]{page,clicks,impressions,position}:
  https://www.optikaljiljana.hr/,180,4200,5.1
  https://www.optikaljiljana.hr/progresivne-naocale-iskustva/,40,900,4.2
  https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/,30,1500,6.4
  https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/,20,1100,6.6
  https://www.optikaljiljana.hr/stellest-lece-za-djecu/,4,300,9.2`;
const pagesPrev = `[5]{page,clicks,impressions,position}:
  https://www.optikaljiljana.hr/,150,3900,5.5
  https://www.optikaljiljana.hr/progresivne-naocale-iskustva/,20,700,5.2
  https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/,12,800,7.4
  https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/,35,1200,6.1
  https://www.optikaljiljana.hr/stellest-lece-za-djecu/,9,320,8.2`;
const reels = [
 {igId:"1",caption:"Prije okvira: provjera vida od 60 minuta. WAM 800 mjeri rožnicu",playCount:41,durationSeconds:18,timestamp:"2026-11-10T18:23:37.000Z",permalink:"https://www.instagram.com/reel/a/",shares:0,saves:0,avgWatchedPct:8.3,skipRate:90.2,isPossibleTrial:true},
 {igId:"2",caption:"Prije okvira: provjera vida od 60 minuta. WAM 800 mjeri rožnicu",playCount:6,durationSeconds:18,timestamp:"2026-11-10T18:22:19.000Z",permalink:"https://www.instagram.com/reel/b/",shares:0,saves:0,avgWatchedPct:5.2,skipRate:100,isPossibleTrial:true},
 {igId:"3",caption:"Ovako nastaju vaše naočale. Tracer očita oblik okvira",playCount:327,durationSeconds:26,timestamp:"2026-11-01T17:47:20.000Z",permalink:"https://www.instagram.com/reel/c/",shares:1,saves:1,avgWatchedPct:21.9,skipRate:58.9},
 {igId:"5",caption:"Sutra pomičemo sat i mrak će padati već oko pet. 😎 #a #b #c #d #e #f",playCount:351,durationSeconds:39,timestamp:"2026-10-24T17:00:48.000Z",permalink:"https://www.instagram.com/reel/e/",shares:0,saves:0,avgWatchedPct:12.8,skipRate:82.8}];
const media = [
 {id:"1",media_type:"VIDEO",media_product_type:"REELS",caption:reels[0].caption,timestamp:"2026-11-10T18:23:37+0000",permalink:"https://www.instagram.com/reel/a/",insights:{data:[{name:"views",values:[{value:41}]},{name:"reach",values:[{value:40}]},{name:"total_interactions",values:[{value:0}]}]}},
 {id:"2",media_type:"VIDEO",media_product_type:"REELS",caption:reels[1].caption,timestamp:"2026-11-10T18:22:19+0000",permalink:"https://www.instagram.com/reel/b/",insights:{data:[{name:"views",values:[{value:6}]},{name:"reach",values:[{value:4}]},{name:"total_interactions",values:[{value:0}]}]}},
 {id:"4",media_type:"IMAGE",media_product_type:"FEED",caption:"Od danas i Ray-Ban sunčane naočale u Optici Ljiljana.\n#rayban #suncanenaocale #optikaljiljana #sesvete #zagreb",timestamp:"2026-11-06T21:03:24+0000",permalink:"https://www.instagram.com/p/x/",insights:{data:[{name:"views",values:[{value:65}]},{name:"reach",values:[{value:40}]},{name:"likes",values:[{value:3}]},{name:"total_interactions",values:[{value:3}]}]}},
 {id:"3",media_type:"VIDEO",media_product_type:"REELS",caption:reels[2].caption,timestamp:"2026-11-01T17:47:20+0000",permalink:"https://www.instagram.com/reel/c/",insights:{data:[{name:"views",values:[{value:327}]},{name:"reach",values:[{value:260}]},{name:"shares",values:[{value:1}]},{name:"saved",values:[{value:1}]},{name:"total_interactions",values:[{value:6}]}]}},
 {id:"5",media_type:"VIDEO",media_product_type:"REELS",caption:reels[3].caption,timestamp:"2026-10-24T17:00:48+0000",permalink:"https://www.instagram.com/reel/e/",insights:{data:[{name:"views",values:[{value:351}]},{name:"reach",values:[{value:300}]},{name:"total_interactions",values:[{value:2}]}]}}];
const audit = [
 {issue_id:"a1",name:"Noindex page",importance:"Warning",category:"Indexability",is_indexable:null,crawled:10,change:0},
 {issue_id:"a2",name:"Slow server response for AI crawlers",importance:"Warning",category:"AI Discoverability",crawled:1,change:0},
 {issue_id:"a3",name:"Page and SERP titles do not match",importance:"Notice",category:"Content",is_indexable:true,crawled:5,change:0},
 {issue_id:"a4",name:"Meta description tag missing or empty",importance:"Warning",category:"Content",is_indexable:false,crawled:10,change:0},
 {issue_id:"a5",name:"External 4XX",importance:"Notice",category:"External pages",crawled:41,change:0},
 {issue_id:"a6",name:"404 page",importance:"Error",category:"Internal pages",crawled:0,change:0}];
const refd=[{domain:"waze.com",domain_rating:92,dofollow_links:0,links_to_target:1,first_seen:"2025-02-11T14:01:27Z",is_spam:false},{domain:"factmags.com",domain_rating:74,dofollow_links:0,links_to_target:1,first_seen:"2026-05-07T21:47:15Z",is_spam:true},{domain:"hotfri.com",domain_rating:61,dofollow_links:1,links_to_target:1,first_seen:"2026-09-15T03:00:47Z",is_spam:true}];
/* OpenSEO: tekstualne tablice kao pravi alat */
const rowsOf = t => t.split("\n").slice(1).map(l => l.trim()).filter(Boolean).map(l => l.split(","));
const QP = rowsOf(qp).map(c => ({q:c[0], page:c[1], c:+c[2], i:+c[3], pos:+c[5]})).concat([{q:"stellest stakla", page:"https://www.optikaljiljana.hr/stellest-lece-za-djecu/", c:1, i:29, pos:3.5}]);
const PG = rowsOf(pages).map(c => ({page:c[0], c:+c[1], i:+c[2], pos:+c[3]})), PGP = rowsOf(pagesPrev).map(c => ({page:c[0], c:+c[1], i:+c[2], pos:+c[3]}));
function osText(dims, rows, range, startRow){
  const per = dims.join(",") === "query,page" ? 6 : 1000, slice = rows.slice(startRow, startRow + per), more = startRow + per < rows.length;
  return "https://www.optikaljiljana.hr/ · " + dims.join("+") + " · " + range + " · " + slice.length + " rows" + (more ? " (more available — call again with startRow=" + (startRow + per) + ")" : "") +
    "\nkey | clicks | impressions | CTR | position\n" + slice.map(r => `${r.key} | ${r.c} | ${r.i} | ${r.i ? (r.c / r.i * 100).toFixed(1) : "0.0"}% | ${r.pos}`).join("\n") + "resultId: 1b21ee6e (optional: pass it to show_search_console_performance for an interactive view; valid for one hour)";
}
function osGsc(input){
  const d = input.dimensions.join(","), range = input.startDate + "→" + input.endDate, sr = input.startRow || 0;
  const end3 = isoL(new Date(Date.now() - 3*864e5));
  const prevWin = input.endDate !== end3;
  if (d === "date") { const rows = []; for (let i = 0; i < 90; i++) { const dd = new Date(input.endDate + "T12:00:00"); dd.setDate(dd.getDate() - i); const k = isoL(dd); if (k < input.startDate) break; rows.unshift({key:k, c: 8 + (i % 7), i: 380 + i * 4 + (i % 5) * 20, pos: (6 + (i % 9) / 10).toFixed(1)}); } return osText(["date"], rows, range, sr); }
  if (d === "query,page") { const rows = QP.map(x => ({key: x.q + " / " + x.page, c:x.c, i:x.i, pos:x.pos}));
    /* zadnjih 7 dana: stara cjenovna adresa (301) više nema prikazivanja */
    if (input.startDate > isoL(new Date(Date.now() - 12*864e5))) return osText(["query","page"], rows.filter(r => !/koliko-kostaju/.test(r.key)), range, sr);
    return osText(["query","page"], rows, range, sr); }
  if (d === "page") return osText(["page"], (prevWin ? PGP : PG).map(x => ({key:x.page, c:x.c, i:x.i, pos:x.pos})), range, sr);
  if (d === "query") { const m = new Map(); QP.forEach(x => { const o = m.get(x.q) || {key:x.q, c:0, i:0, pos:x.pos}; o.c += x.c; o.i += x.i; m.set(x.q, o); }); let rows = [...m.values()].map(o => ({...o, i: Math.round(o.i / 4), c: Math.round(o.c / 4)}));
    if (prevWin) rows = rows.filter(o => !/noćnu vožnju/.test(o.key)).map((o, k) => ({...o, i: k % 2 ? o.i + 9 : Math.max(0, o.i - 12)})); return osText(["query"], rows, range, sr); }
  return osText(input.dimensions, [], range, 0);
}
const RK = [["k1","besplatan pregled vida","8","8","https://www.optikaljiljana.hr/besplatan-pregled-vida-sesvete/"],["k2","cijena progresivnih naočala","1","4","https://www.optikaljiljana.hr/progresivne-naocalne-lece/"],["k3","progresivne naočale cijena","7","3","https://www.optikaljiljana.hr/progresivne-naocalne-lece/"],["k4","optika","—","35",""],["k5","stellest stakla","6","6","https://www.optikaljiljana.hr/journal/"],["k6","optika sesvete","2","2","https://www.optikaljiljana.hr/"]];
function osRank(input){
  if (!input.trackerId) return "Rank trackers (1):\ndomain | location | lang | devices | depth | schedule | last checked | skip reason | trackerId\noptikaljiljana.hr | 2191 · Zagreb,City of Zagreb,Croatia | hr | mobile | 40 | weekly | 2026-11-11T15:28:25.368Z | — | 7d777ee3-4d5d-4297-9d4a-24c00fa6c248";
  return "Tracker " + input.trackerId + " (optikaljiljana.hr):\nKeyword page: offset=0, limit=200, " + RK.length + " of " + RK.length + " rows.\nSchedule: weekly, devices: mobile, depth: 40, location: 2191 · Zagreb,City of Zagreb,Croatia, lang: hr\nLatest run: 2026-11-11T15:28:25.368Z\nKeywords (" + RK.length + "):\ntrackingKeywordId | keyword | desktop | prev (desktop) | mobile | prev (mobile) | pinned | ranking url | google serp\n" + RK.map(r => `${r[0]} | ${r[1]} | — | — | ${r[2]} | ${r[3]} | no | ${r[4] || "—"} | https://www.google.com/search?q=x`).join("\n");
}
function osHist(){ return "Rank history for tracker x (optikaljiljana.hr), mobile, latest 2 completed check(s), newest first.\nKeyword page: offset=0, limit=200, 6 of 6 rows.\nCells: position; — = not found in the results; not checked = the check got no result; blank = not tracked yet.\ntrackingKeywordId | keyword | 2026-11-11 14:37 | 2026-11-04 14:30\n" + RK.map(r => `${r[0]} | ${r[1]} | ${r[2]} | ${r[0] === "k6" ? "" : r[3]}`).join("\n").replace(/k6 \| optika sesvete \| 2 \| $/m, "k6 | optika sesvete | 2 | "); }
const WP_LIST = {ukupno:4,stranica:1,stranica_ukupno:1,stavke:[
 {id:1393,vrsta:"page",url:"https://www.optikaljiljana.hr/",naslov:"Naslovnica",promijenjeno:isoL(new Date())+" 10:00:00",rank_math:{naslov:"Optika Sesvete",opis:"Optika Ljiljana, Zagrebačka 1, Sesvete.",fokus:"optika sesvete",dodatne:[],robots:["index"],canonical:"",pillar:false},crawl:{status:200,dohvaceno:"2026-11-11 16:25:07 UTC",naslov_html:"Optika Sesvete — WAM 800 provjera vida | Optika Ljiljana",opis_html:"Optika Ljiljana, Zagrebačka 1, Sesvete: optometrijska provjera vida od 60 minuta, progresivne leće Varilux, Ray-Ban i Furla. Besplatan parking.",h1:["Optika u Sesvetama"],rijeci:1900,robots:"follow, index"}},
 {id:1109,vrsta:"page",url:"https://www.optikaljiljana.hr/progresivne-naocalne-lece/",naslov:"Cijena",promijenjeno:"2026-10-01 09:00:00",rank_math:{naslov:"Cijena progresivnih naočala i stakala: 500–1200 € za par",opis:"Kratko.",fokus:"cijena progresivnih naočala",dodatne:["progresivne naočale cijena"],robots:["index"],canonical:"",pillar:false},crawl:{status:200,naslov_html:"Cijena progresivnih naočala i stakala: 500–1200 € za par",opis_html:"Kratko.",h1:["Cijena progresivnih naočala i leća"],rijeci:3102,robots:"follow, index"}},
 {id:4568,vrsta:"page",url:"https://www.optikaljiljana.hr/hvala-na-rezervaciji/",naslov:"Hvala",promijenjeno:"2026-09-01 09:00:00",rank_math:{naslov:"Hvala",opis:"",fokus:"",dodatne:[],robots:["noindex"],canonical:"",pillar:false},crawl:{status:200,naslov_html:"Hvala",opis_html:"",h1:["Hvala"],rijeci:40,robots:"noindex, follow"}},
 {id:5584,vrsta:"page",url:"https://www.optikaljiljana.hr/moj-termin/",naslov:"Moj termin",promijenjeno:"2026-09-01 09:00:00",rank_math:{naslov:"Moj termin",opis:"",fokus:"",dodatne:[],robots:["index"],canonical:"",pillar:false},crawl:null}]};
const WP_AUDIT = {stanje_crawla:{stranica_u_popisu:42,dohvaceno:42,zastarjelo:0,zadnja_serija:"2026-11-11T21:26:08+00:00",zadnji_potpuni:"2026-11-11T21:26:08+00:00",zadnje_greske:[]},ukupno_nalaza:5,po_kodu:{bez_opisa:1,bez_ulaznih_linkova:2,noindex:1,naslov_prekratak:1},skraceno:false,nalazi:[
 {kod:"bez_opisa",ozbiljnost:"srednja",opis:"Nema meta opisa.",url:"https://www.optikaljiljana.hr/moj-termin/",post_id:5584,detalj:null},
 {kod:"bez_ulaznih_linkova",ozbiljnost:"srednja",opis:"Nijedna druga stranica ne linka ovamo iz sadržaja dofollow linkom.",url:"https://www.optikaljiljana.hr/hvala-na-rezervaciji/",post_id:4568,detalj:{iz_navigacije:0}},
 {kod:"bez_ulaznih_linkova",ozbiljnost:"srednja",opis:"Nijedna druga stranica ne linka ovamo iz sadržaja dofollow linkom.",url:"https://www.optikaljiljana.hr/moj-termin/",post_id:5584,detalj:{iz_navigacije:0}},
 {kod:"noindex",ozbiljnost:"srednja",opis:"Stranica ima noindex.",url:"https://www.optikaljiljana.hr/hvala-na-rezervaciji/",post_id:4568,detalj:null},
 {kod:"naslov_prekratak",ozbiljnost:"niska",opis:"SEO naslov je kraći od 30 znakova.",url:"https://www.optikaljiljana.hr/moj-termin/",post_id:5584,detalj:null}]};
let wpCalls = 0;
function handle(server, tool, input){
  if(server==="OpenSEO"){
    if(MODE.includes("osdead")) throw {code:"server_unavailable",message:"mock: OpenSEO ne odgovara",retryable:false};
    if(input.projectId!=="e456f622-c40c-4085-b08d-d51a6f22349e") throw {code:"tool_error",message:"mock: krivi projekt"};
    if(tool==="get_search_console_performance") return osGsc(input);
    if(tool==="get_rank_tracker") return osRank(input);
    if(tool==="get_rank_history") return osHist(input);
  }
  if(server==="Optika Ljiljana web" && tool==="mcp-adapter-execute-ability"){
    if(MODE.includes("osdead")) throw {code:"server_not_connected",message:"mock: nije povezan"};
    if(!/^ol-radar\//.test(input.ability_name)) throw {code:"tool_error",message:"mock: zabranjena sposobnost"};
    if(++wpCalls === 1) throw {code:"tool_error",message:"Error POSTing to endpoint: MCP server returned 403 Forbidden."};
    if(input.ability_name==="ol-radar/popis-sadrzaja") return {success:true,data:WP_LIST};
    if(input.ability_name==="ol-radar/audit") return {success:true,data:WP_AUDIT};
  }
  if(server==="Ahrefs"){
    switch(tool){
      case "subscription-info-limits-and-usage": return {limits_and_usage:{units_limit_workspace:200000,units_usage_workspace:26000,usage_reset_date:"2026-11-30T00:00:00Z"}};
      case "site-explorer-domain-rating": return {domain_rating:{domain_rating:input.target==="jojo-optika.hr"?26:0}};
      case "site-explorer-backlinks-stats": return {metrics:{live:670,live_refdomains:581}};
      case "site-explorer-metrics": return {metrics:{org_keywords:8,org_keywords_1_3:7,org_traffic:98,org_cost:595}};
      case "site-explorer-metrics-history": return {metrics:[{date:"2026-09-01T00:00:00Z",org_traffic:40},{date:"2026-10-01T00:00:00Z",org_traffic:61}]};
      case "gsc-performance-history": if(input.history_grouping==="daily") return {metrics:[{date:"2026-10-14T00:00:00Z",clicks:13,impressions:345},{date:"2026-10-15T00:00:00Z",clicks:11,impressions:342}]};
        return {metrics:[["2025-08",235],["2025-09",300],["2025-10",290],["2025-11",126],["2025-12",72],["2026-01",110],["2026-02",103],["2026-03",129],["2026-04",120],["2026-05",197],["2026-06",207],["2026-07",280],["2026-08",235],["2026-09",329],["2026-10",165]].map(([d,c])=>({date:d+"-01T00:00:00Z",clicks:c,impressions:c*30}))};
      case "site-audit-issues": return {issues:audit};
      case "site-explorer-referring-domains": return {refdomains:refd};
      case "keywords-explorer-overview": return {keywords:input.keywords.split(",").map((k,i)=>({keyword:k,volume:[600,1300,100][i]||50,difficulty:0,cpc:25,traffic_potential:150,parent_topic:k,intents:{commercial:true,local:i===2},serp_features:["ai_overview","image_th"],global_volume:800,clicks:876}))};
      case "keywords-explorer-matching-terms": return {keywords:[["progresivne naočale",600,"ct"],["progresivne naočale cijena",400,"c"],["naočale za čitanje",900,"ct"],["kontaktne leće",2000,"ct"],["leće za naočale",300,"ct"],["optika zagreb",500,"cl"]].map(([k,v,it])=>({keyword:k,volume:v,traffic_potential:v/2,parent_topic:k.split(" ").slice(0,2).join(" "),intents:{commercial:it.includes("c"),transactional:it.includes("t"),local:it.includes("l")},global_volume:v*1.3}))};
      case "keywords-explorer-volume-history": { const out=[]; for(let i=0;i<72;i++){const d=new Date(2020,i,1); out.push({date:d.toISOString().slice(0,10)+"T00:00:00Z",volume:400+Math.round(200*Math.sin(i/12*6.28))+i*3});} return {metrics:out}; }
      case "site-explorer-organic-keywords": return {keywords:[{keyword:"naočale za čitanje",volume:900,best_position:4,best_position_url:"https://"+input.target+"/citanje/",sum_traffic:60,is_local:false},{keyword:"jojo optika",volume:250,best_position:1,best_position_url:"https://"+input.target+"/",sum_traffic:200,is_local:true}]};
      case "site-explorer-top-pages": return {pages:[{url:"https://"+input.target+"/",sum_traffic:190,keywords:16,top_keyword:"x",top_keyword_volume:400,top_keyword_best_position:2}]};
      case "site-audit-page-explorer": return {pages:[{url:"https://www.optikaljiljana.hr/",http_code:200,title:["Optika"],content_nr_word:1900,compliant:true,depth:0}]};
      case "serp-overview": return {positions:[{position:1,url:"https://www.optikaljiljana.hr/",title:"Optika Ljiljana",domain_rating:0,refdomains:4,traffic:142,type:["organic"]},{position:2,url:"https://jojo-optika.hr/",title:"Optika JO-JO",domain_rating:26,refdomains:701,traffic:235,type:["organic"]},{position:3,url:"https://www.optometrija.net/adresar-optika/",title:"Adresar",domain_rating:30,refdomains:0,traffic:6,type:["organic"]}]};
    }
  }
  if(server==="Supermetrics Marketing Analytics"){
    if(tool==="accounts_discovery") return {success:true,data:'accounts[1]{account_id,account_name}:\n  "2686598307","ivan"'};
    if(tool==="campaign_and_resource_get") return {success:true,data:{data:{results:(input.params.keywords||[]).flatMap((k,i)=>input.resource_type==="keyword_ideas"?[{keyword:k,avg_monthly_searches:"720",competition:"MEDIUM",low_bid_micros:200000,high_bid_micros:800000},{keyword:k+" cijena",avg_monthly_searches:"480",competition:"MEDIUM",low_bid_micros:120000,high_bid_micros:790000},{keyword:"ghetaldus "+k,avg_monthly_searches:"300",competition:"LOW",low_bid_micros:1,high_bid_micros:2}]:[{keyword:k,avg_monthly_searches:String(700+i*100),competition:"MEDIUM",low_bid_micros:"215147",high_bid_micros:"832889"}])}}};
    if(input.fields && input.fields.includes("page_title_tag")) return {success:true,data:{success:true,schedule_id:"abc",message:"submitted"}};
    if(tool==="get_async_query_results") return {success:true,data:{success:true,status:"completed",data:'[3]{page,page_title_tag,page_h1_tag,page_tag_meta_description,page_tag_canonical,page_tag_meta_robots,clicks,impressions}:\n  "https://www.optikaljiljana.hr/",Optika Sesvete — WAM 800 provjera vida | Optika Ljiljana,Optika u Sesvetama.Vid kakav zaslužujete.,"Optika Ljiljana, Zagrebačka 1, Sesvete: optometrijska provjera vida od 60 minuta, progresivne leće Varilux, Ray-Ban i Furla. Besplatan parking, ocjena 5,0.","https://www.optikaljiljana.hr/","follow, index",83,1612\n  "https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/","Naočale za noćnu vožnju: pomažu li žute leće? Sve što trebate znati prije kupnje",\"\\r\\n Naočale za noćnu vožnju\\r\\n \","","https://www.optikaljiljana.hr/naocale-za-nocnu-voznju/","noindex, follow",33,941\n  "https://www.optikaljiljana.hr/progresivne-naocalne-lece/","Cijena progresivnih",H1,"Kratko.","https://www.optikaljiljana.hr/koliko-kostaju-progresivne-naocale/","index",42,1522'},notes:["Your free trial expired"]};
    const f=input.fields; let t = f.startsWith("Date")?daily: f.startsWith("query")?qp: (input.date_range_type==="custom"?pagesPrev:pages);
    return {success:true,data:{success:true,status:"completed",data:t},notes:["Your free trial on team Team optikaljiljana has expired on 2026-05-08."]};
  }
  if(server==="vidIQ"){
    switch(tool){
      case "vidiq_social_account_list": return {accounts:[{platform:"INSTAGRAM",platformAccountId:"29208480685431356",username:"optikaljiljana"},{platform:"YOUTUBE",platformAccountId:"UCLisK4rLqV6RrIDHhnmE_2A"}]};
      case "vidiq_instagram_owner_insights":
        if(input.mode==="account") return {account:{name:"Optika Ljiljana",biography:"Obiteljska optika u Sesvetama od 1993.\nWAM 800 provjera vida i progresivne naočale po mjeri.\nRezervirajte termin putem linka.",followers_count:302,follows_count:186,media_count:183,website:"https://www.optikaljiljana.hr/rezerviraj-termin-i-provjeri-vid/"},data:[{name:"views",total_value:{value:2024}},{name:"reach",total_value:{value:1668}}]};
        if(input.mode==="reels") return {reels};
        return {data:media};
      case "vidiq_user_channels": return {channels:[{channelId:"UCLisK4rLqV6RrIDHhnmE_2A",title:"Optika Ljiljana",handle:"@optikaljiljana",subscriberCount:1,viewCount:187,videoCount:6}]};
      case "vidiq_channel_videos": return {videos: input.videoFormat==="short"?[{videoId:"38lsUhgRWhM",title:"Prije okvira: provjera vida od 60 minuta | Optika Ljiljana Sesvete",publishedAt:"2026-11-10T19:00:30Z",viewCount:1},{videoId:"EWRCDneiVvs",title:"Kako nastaju progresivne naočale – Varilux XR u radionici | Optika Ljiljana Sesvete",publishedAt:"2026-11-01T17:57:45Z",viewCount:91}]:[]};
      case "vidiq_channel_analytics": return {rows:[["EWRCDneiVvs",88,17,5,11,0,0]]};
    }
  }
  throw {code:"tool_error",message:"mock: "+server+" "+tool};
}
const store={}; window.__store=store; if(location.search.includes("off")) store["cfg/settings"]={ahrefsOff:true};
function mkDoc(p){return {id:p.split("/").pop(),path:p,get:async()=>({exists:!!store[p],data:()=>store[p]}),set:async d=>{store[p]=JSON.parse(JSON.stringify(d));},update:async d=>{Object.assign(store[p],d)},delete:async()=>{delete store[p]},onSnapshot:(n)=>{n({exists:!!store[p],data:()=>store[p]});return()=>{}}};}
function mkCol(c){const q={where:()=>q,orderBy:()=>q,limit:()=>q,get:async()=>{const docs=Object.keys(store).filter(k=>k.startsWith(c+"/")).map(k=>({id:k.split("/").pop(),exists:true,data:()=>store[k]}));return {docs,size:docs.length,empty:!docs.length}},onSnapshot:(n)=>{q.get().then(s=>n({...s,docChanges:()=>[]}));return()=>{}},doc:id=>mkDoc(c+"/"+id)};return q;}
const sample = async (input,opts)=>{const t="Test odgovor Claudea."; opts?.onText?.({text:t,delta:t}); return {text:t,truncated:false};};
sample.json = async ()=>({dijagnoza:"Naslov stranice ne sadrži upit.",zasto:"Bez upita u naslovu Google rjeđe prikazuje stranicu.",koraci:["Promijeni SEO naslov u Rank Mathu","Dodaj internu poveznicu s početne"],gotov_tekst:[{oznaka:"Naslov A",tekst:"Naočale za noćnu vožnju | Optika Ljiljana Sesvete"},{oznaka:"Opis A",tekst:"Bliješte vas farovi? Provjera vida od 60 minuta i leće za vožnju po mraku u Sesvetama. Rezervirajte termin."}],gdje:"Rank Math → stranica",ucinak:"2–4 tjedna",provjera:"GSC CTR"});
sample.limits = async ()=>({maxPromptBytes:262144,tools:{maxCount:8}});
window.claude = {use: async (n)=> n==="mcp"?{callTool:async(s,t,i)=>{await new Promise(r=>setTimeout(r,30)); return J(handle(s,t,i));},invalidate:async()=>{}}: n==="db"?{doc:mkDoc,collection:mkCol}: n==="sample"?sample: n==="downloads"?{save:async({filename,data})=>{window.__download={filename,data:String(data)};return {status:"saved"};}}:null};
})();
