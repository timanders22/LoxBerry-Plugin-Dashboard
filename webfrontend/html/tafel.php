<?php
/**
 * Dashboard-Designer - die Anzeigeseite fuer das Tablet
 *
 * Diese Seite liegt im unangemeldeten Bereich, damit ein Wandtablet sie ohne
 * Anmeldung dauerhaft offen halten kann. Geschuetzt ist sie durch dasselbe
 * Token wie der Endpunkt.
 *
 * Sie ist bewusst EINE Datei ohne fremde Bibliotheken: kein jQuery, kein
 * Framework, keine Schrift aus dem Netz. Ein Wandtablet soll auch dann
 * funktionieren, wenn das Haus kein Internet hat - und genau darum geht es
 * bei diesem Plugin.
 *
 * Die Seite holt einmal ihre Struktur (aktion=seite) und danach im Takt nur
 * noch die Werte (aktion=werte). Geschaltet wird ueber aktion=befehl.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/db_lib.php';

$cfg = db_config();
$soll = (string) $cfg['aktionstoken'];
$ist = isset($_GET['token']) ? (string) $_GET['token'] : '';

header('Content-Type: text/html; charset=utf-8');

if ($soll === '' || !hash_equals($soll, $ist)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Kein Zugang</title></head><body style="font-family:system-ui;padding:2em">'
       . '<h1>Kein Zugang</h1><p>Das Token in der Adresse stimmt nicht. Die richtige '
       . 'Adresse steht im Plugin unter <i>Dashboards</i>.</p></body></html>';
    exit;
}

$seiten = db_seiten();
$wunsch = isset($_GET['seite']) ? (string) $_GET['seite'] : '';
if ($wunsch !== '' && !preg_match('/^[a-z0-9-]{1,60}$/', $wunsch)) { $wunsch = ''; }
if ($wunsch === '' && $seiten) { $wunsch = (string) $seiten[0]['schluessel']; }
$daten = $wunsch !== '' ? db_seite_daten($wunsch) : null;

$dunkel = ((string) $cfg['farbe']) !== 'hell';
$basis = '/plugins/' . db_paths()['plugin'] . '/index.php?token=' . rawurlencode($ist);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="<?= $dunkel ? '#14171c' : '#f2f4f7' ?>">
<title><?= db_e($daten ? $daten['name'] : 'Dashboard') ?></title>
<style>
:root{
  --bg:<?= $dunkel ? '#14171c' : '#eef1f5' ?>;
  --kachel:<?= $dunkel ? '#1e232b' : '#ffffff' ?>;
  --kachel2:<?= $dunkel ? '#262d37' : '#f6f8fa' ?>;
  --text:<?= $dunkel ? '#e7ebf0' : '#1b2027' ?>;
  --leise:<?= $dunkel ? '#8b96a5' : '#66717f' ?>;
  --rand:<?= $dunkel ? '#2e3540' : '#dde3ea' ?>;
  --an:#6dac20; --anweich:<?= $dunkel ? '#2c4114' : '#e8f3d8' ?>;
  --aus:<?= $dunkel ? '#4a5361' : '#aab4c0' ?>;
  --warn:#e08a24; --fehl:#d0453c;
  --r:16px;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  -webkit-user-select:none;user-select:none;overscroll-behavior:none}
body{padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left)}
header{display:flex;align-items:center;gap:14px;padding:14px 18px 6px;flex-wrap:wrap}
header h1{font-size:1.35rem;margin:0;font-weight:650;letter-spacing:-.01em}
.seiten{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.seiten a{display:block;padding:7px 14px;border-radius:999px;text-decoration:none;
  color:var(--leise);background:var(--kachel);border:1px solid var(--rand);font-size:.86rem}
.seiten a.hier{color:var(--text);border-color:var(--an);background:var(--anweich)}
.zeile{display:flex;align-items:center;gap:10px;padding:0 18px 10px;font-size:.8rem;color:var(--leise)}
/* Abdunkeln bei Verbindungsverlust.
   Auf einem Wandtablet sieht man einen neun Pixel grossen Punkt aus drei
   Metern nicht. Also wird das ganze Raster gedaempft und ein Band eingeblendet
   - erkennbar im Vorbeigehen, ohne dass die Seite unbrauchbar wird.
   pointer-events bleibt AN: wer trotzdem druecken will, darf das. Ein Befehl
   in einer Stoerung wird ohnehin sauber abgewiesen, und eine Kachel, die auf
   Beruehrung gar nicht mehr reagiert, haelt man fuer kaputt. */
#raster{transition:opacity .35s ease, filter .35s ease}
body.gestoert #raster{opacity:.45;filter:grayscale(.7)}
#stoerband{position:fixed;left:0;right:0;bottom:0;z-index:50;display:none;
  padding:10px 14px;background:var(--fehl);color:#fff;font-size:1.05em;
  text-align:center;box-shadow:0 -2px 10px rgba(0,0,0,.35)}
body.gestoert #stoerband{display:block}
@media (prefers-reduced-motion: reduce){#raster{transition:none}}
.punkt{width:9px;height:9px;border-radius:50%;background:var(--aus);flex:0 0 auto}
.punkt.gut{background:var(--an)}
.punkt.alt{background:var(--warn)}
.punkt.tot{background:var(--fehl)}
main{display:grid;gap:12px;padding:0 18px 24px;
  grid-template-columns:repeat(var(--spalten,6),minmax(0,1fr));
  grid-auto-rows:118px}
.k{background:var(--kachel);border:1px solid var(--rand);border-radius:var(--r);
  padding:13px 15px;display:flex;flex-direction:column;overflow:hidden;position:relative;
  transition:background .12s,border-color .12s}
.k.an{border-color:var(--an);background:var(--anweich)}
.k .t{font-size:.83rem;color:var(--leise);line-height:1.25;margin-bottom:auto;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.k .w{font-size:1.7rem;font-weight:650;letter-spacing:-.02em;line-height:1.1}
.k .w small{font-size:.9rem;font-weight:500;color:var(--leise);margin-left:2px}
.k .u{font-size:.78rem;color:var(--leise);margin-top:3px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.k.fehlt{border-style:dashed;opacity:.65}
.knoepfe{display:flex;gap:7px;margin-top:8px;flex-wrap:wrap}
button{font:inherit;font-size:.83rem;padding:8px 13px;border-radius:10px;cursor:pointer;
  border:1px solid var(--rand);background:var(--kachel2);color:var(--text);min-height:38px}
button:active{transform:scale(.97)}
button.stark{background:var(--an);border-color:var(--an);color:#fff}
button.leise{color:var(--leise)}
button.warn{border-color:var(--warn);color:var(--warn)}
input[type=range]{width:100%;margin:8px 0 2px;accent-color:var(--an);height:30px}
.balken{height:7px;border-radius:4px;background:var(--kachel2);overflow:hidden;margin-top:8px}
.balken i{display:block;height:100%;background:var(--an)}
.rollo{display:flex;align-items:center;gap:9px;margin-top:6px}
.rollo .schacht{flex:1;height:44px;border-radius:8px;background:var(--kachel2);
  border:1px solid var(--rand);overflow:hidden;position:relative}
.rollo .schacht i{position:absolute;left:0;top:0;right:0;background:var(--aus);display:block}
.pin{position:fixed;inset:0;background:rgba(0,0,0,.72);display:flex;align-items:center;
  justify-content:center;z-index:50}
.pin div{background:var(--kachel);padding:26px;border-radius:var(--r);width:min(320px,88vw);
  border:1px solid var(--rand)}
.pin input{width:100%;font-size:1.5rem;text-align:center;letter-spacing:.4em;padding:12px;
  border-radius:10px;border:1px solid var(--rand);background:var(--kachel2);color:var(--text)}
.blase{position:fixed;left:50%;transform:translateX(-50%);bottom:26px;z-index:60;
  background:var(--kachel);border:1px solid var(--rand);border-left:4px solid var(--an);
  padding:11px 17px;border-radius:12px;font-size:.88rem;box-shadow:0 8px 28px rgba(0,0,0,.32);
  max-width:min(520px,90vw)}
.blase.fehl{border-left-color:var(--fehl)}
.leer{padding:60px 18px;color:var(--leise);text-align:center;line-height:1.6}
@media (max-width:900px){ main{grid-template-columns:repeat(4,minmax(0,1fr))} }
@media (max-width:560px){ main{grid-template-columns:repeat(2,minmax(0,1fr));grid-auto-rows:110px}
  header{padding:10px 12px 4px} main{padding:0 12px 18px} .zeile{padding:0 12px 8px} }
</style>
</head>
<body>

<?php if ($daten === null) { ?>
<div class="leer">
  <h1>Noch kein Dashboard</h1>
  <p>Im Plugin unter <b>Dashboards</b> einen Entwurf erzeugen lassen &mdash;<br>
     danach steht hier etwas.</p>
</div>
<?php } else { ?>

<header>
  <h1><?= db_e($daten['name']) ?></h1>
  <?php if (count($seiten) > 1) { ?>
  <nav class="seiten">
    <?php foreach ($seiten as $s) {
        $k = (string) $s['schluessel']; ?>
      <a href="?token=<?= db_e($ist) ?>&amp;seite=<?= db_e($k) ?>"<?= $k === $wunsch ? ' class="hier"' : '' ?>><?= db_e($s['name']) ?></a>
    <?php } ?>
  </nav>
  <?php } ?>
</header>

<div class="zeile">
  <span class="punkt" id="punkt"></span>
  <span id="stand">&nbsp;</span>
</div>

<main id="raster" style="--spalten:<?= (int) $daten['spalten'] ?>"></main>

<script>
"use strict";
var BASIS = <?= json_encode($basis) ?>;
var SEITE = <?= json_encode($wunsch) ?>;
var DATEN = <?= json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var TAKT  = <?= max(1, min(30, (int) $cfg['takt'])) * 1000 ?>;
var PIN   = "";

/* ---------- Kleinteile ---------- */
function e(t){ var d=document.createElement("div"); d.textContent=t==null?"":String(t); return d.innerHTML; }
function zahl(v,n){ if(v==null||v==="")return "–";
  var f=parseFloat(v); if(isNaN(f))return String(v);
  return f.toFixed(n===undefined?(Math.abs(f)>=100?0:1):n).replace(".",","); }
function an(v){ return v!=null && parseFloat(v)>0; }

function blase(text, fehl){
  var alt=document.querySelector(".blase"); if(alt) alt.remove();
  var d=document.createElement("div"); d.className="blase"+(fehl?" fehl":"");
  d.textContent=text; document.body.appendChild(d);
  setTimeout(function(){ if(d.parentNode) d.remove(); }, fehl?6000:2600);
}

/* ---------- PIN ----------
   Die PIN wird nur im Arbeitsspeicher gehalten, nicht gespeichert: wer das
   Tablet neu startet, gibt sie neu ein. Genau das ist der Sinn einer PIN. */
function pin_fragen(){
  return new Promise(function(fertig){
    var d = document.createElement("div");
    d.className = "pin";
    d.innerHTML = '<div><p style="margin:0 0 14px">'+e(DATEN.name)+' &ndash; PIN</p>'+
      '<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="10" autofocus>'+
      '<div style="display:flex;gap:9px;margin-top:16px">'+
      '<button style="flex:1" data-ab="1">Abbrechen</button>'+
      '<button style="flex:1" class="stark" data-ok="1">Weiter</button></div></div>';
    document.body.appendChild(d);
    var feld = d.querySelector("input");
    feld.focus();
    function schliessen(wert){ d.remove(); fertig(wert); }
    d.querySelector("[data-ab]").onclick = function(){ schliessen(null); };
    d.querySelector("[data-ok]").onclick = function(){ schliessen(feld.value); };
    feld.addEventListener("keydown", function(ev){
      if(ev.key==="Enter"){ schliessen(feld.value); }
      if(ev.key==="Escape"){ schliessen(null); }
    });
  });
}

/* ---------- Befehl absetzen ---------- */
function senden(uuid, befehl){
  if (DATEN.pin && !PIN) {
    return pin_fragen().then(function(p){
      if(p === null){ return; }
      PIN = p;
      return senden(uuid, befehl);
    });
  }
  /* &seite= ist Pflicht. Die PIN haengt an der SEITE, nicht am Baustein:
     liegt ein Tuerschloss auf einer ungeschuetzten und zusaetzlich auf einer
     geschuetzten Seite, muss der Endpunkt wissen, von welcher der Druck kam.
     Ohne diesen Parameter nahm er bis 0.9.0 die erstbeste - und damit liess
     sich die PIN der geschuetzten Seite umgehen. */
  var u = BASIS+"&aktion=befehl&seite="+encodeURIComponent(SEITE)
        + "&uuid="+encodeURIComponent(uuid)
        + "&befehl="+encodeURIComponent(befehl)+(PIN?"&pin="+encodeURIComponent(PIN):"");
  return fetch(u,{cache:"no-store"}).then(function(a){ return a.json().catch(function(){
      return {ok:0,meldung:"Der LoxBerry hat keine lesbare Antwort geschickt."}; }); })
    .then(function(d){
      if(d.ok===1){ setTimeout(werte_holen, 250); }
      else if(d.ok===2){ blase(d.meldung||"Abgeschickt, aber ohne Bestaetigung."); }
      else {
        /* Falsche PIN: den gemerkten Wert verwerfen, sonst fragt die Seite
           nie wieder nach und jeder weitere Druck geht ins Leere. */
        if(d.grund === "PIN"){ PIN = ""; }
        blase(d.meldung||"Der Befehl ging nicht durch.", true);
      }
      return d;
    })
    .catch(function(){ blase("Der LoxBerry ist nicht erreichbar.", true); });
}

/* ---------- Kacheln ---------- */
var BAUER = {};

BAUER.schalter = function(k, w){
  var ein = an(w.active);
  return {klasse: ein?"an":"", inhalt:
    '<div class="w">'+(ein?"Ein":"Aus")+'</div>'+
    '<div class="knoepfe"><button data-b="on" class="'+(ein?"stark":"")+'">Ein</button>'+
    '<button data-b="off" class="'+(ein?"":"stark")+'">Aus</button></div>'};
};

BAUER.taster = function(k, w){
  return {klasse: an(w.active)?"an":"", inhalt:
    '<div class="w">'+(an(w.active)?"Aktiv":"Bereit")+'</div>'+
    '<div class="knoepfe"><button data-b="pulse" class="stark">Ausloesen</button></div>'};
};

BAUER.dimmer = function(k, w){
  var p = w.position==null?0:Math.round(parseFloat(w.position));
  return {klasse: p>0?"an":"", inhalt:
    '<div class="w">'+p+'<small>%</small></div>'+
    '<input type="range" min="0" max="100" step="1" value="'+p+'" data-w="1">'+
    '<div class="knoepfe"><button data-b="on">Ein</button><button data-b="off">Aus</button></div>'};
};

BAUER.schieber = function(k, w){
  var v = w.value==null?0:parseFloat(w.value);
  return {klasse:"", inhalt:
    '<div class="w">'+zahl(v)+'<small>'+e(k.einheit_kurz||"")+'</small></div>'+
    '<input type="range" min="0" max="100" step="1" value="'+(isNaN(v)?0:Math.max(0,Math.min(100,v)))+'" data-w="1">'};
};

BAUER.licht = function(k, w){
  var moods = [];
  try { moods = JSON.parse(w.moodList||"[]"); } catch(x){ moods = []; }
  var aktiv = [];
  try { aktiv = JSON.parse(w.activeMoods||"[]"); } catch(x){ aktiv = []; }
  var kn = '<div class="knoepfe">';
  var gezeigt = 0;
  for (var i=0;i<moods.length && gezeigt<4;i++){
    var m = moods[i]; if(!m || m.id===undefined) continue;
    var ist = aktiv.indexOf(m.id)>=0 || aktiv.indexOf(String(m.id))>=0;
    kn += '<button data-b="changeTo/'+e(m.id)+'" class="'+(ist?"stark":"")+'">'+e(m.name||m.id)+'</button>';
    gezeigt++;
  }
  if(!gezeigt){ kn += '<button data-b="on" class="stark">Ein</button>'; }
  kn += '<button data-b="changeTo/0" class="leise">Aus</button></div>';
  var text = aktiv.length ? (moods.filter(function(m){return aktiv.indexOf(m.id)>=0;})
                                  .map(function(m){return m.name;}).join(", ") || "Ein") : "Aus";
  return {klasse: (aktiv.length && !(aktiv.length===1 && (aktiv[0]===0||aktiv[0]==="0")))?"an":"",
          inhalt:'<div class="w" style="font-size:1.05rem">'+e(text)+'</div>'+kn};
};

BAUER.jalousie = function(k, w){
  /* position: 0 = ganz oben, 1 = ganz unten  [Structure File, Jalousie] */
  var p = w.position==null?0:Math.round(parseFloat(w.position)*100);
  var auto = an(w.autoActive);
  return {klasse:"", inhalt:
    '<div class="w">'+p+'<small>% zu</small></div>'+
    '<div class="rollo"><div class="schacht"><i style="height:'+p+'%"></i></div></div>'+
    '<div class="knoepfe"><button data-b="FullUp">&uarr;</button>'+
    '<button data-b="stop">&#9632;</button>'+
    '<button data-b="FullDown">&darr;</button>'+
    '<button data-b="shade" class="leise">Beschatten</button>'+
    '<button data-b="'+(auto?"NoAuto":"auto")+'" class="'+(auto?"stark":"leise")+'">Automatik</button></div>'+
    (w.infoText?'<div class="u">'+e(w.infoText)+'</div>':'')};
};

BAUER.raumregler = function(k, w){
  var ist = w.tempActual, soll = w.tempTarget;
  var offen = an(w.openWindow);
  return {klasse:"", inhalt:
    '<div class="w">'+zahl(ist)+'<small>&deg;C</small></div>'+
    '<div class="u">Soll '+zahl(soll)+'&nbsp;&deg;C'+(offen?' &middot; Fenster offen':'')+'</div>'+
    '<div class="knoepfe">'+
      '<button data-b="setComfortTemperature/'+(Math.round((parseFloat(soll)||20)*2-1)/2)+'">&minus;</button>'+
      '<button data-b="setComfortTemperature/'+(Math.round((parseFloat(soll)||20)*2+1)/2)+'">+</button>'+
    '</div>'};
};

BAUER.tor = function(k, w){
  /* position: 1 = offen, 0 = zu; active: -1 zu, 0 steht, 1 auf  [Structure File, Gate] */
  var p = w.position==null?0:Math.round(parseFloat(w.position)*100);
  var b = parseFloat(w.active)||0;
  var text = b>0?"faehrt auf":(b<0?"faehrt zu":(p>95?"offen":(p<5?"geschlossen":p+"% offen")));
  return {klasse: p>5?"an":"", inhalt:
    '<div class="w" style="font-size:1.2rem">'+e(text)+'</div>'+
    '<div class="balken"><i style="width:'+p+'%"></i></div>'+
    '<div class="knoepfe"><button data-b="open">Auf</button>'+
    '<button data-b="stop">Stopp</button><button data-b="close">Zu</button></div>'};
};

BAUER.treppenlicht = function(k, w){
  var rest = parseFloat(w.deactivationDelay);
  var text = rest===-1?"dauernd an":(rest>0?Math.round(rest)+" s":"aus");
  return {klasse: (rest!==0)?"an":"", inhalt:
    '<div class="w" style="font-size:1.3rem">'+e(text)+'</div>'+
    '<div class="knoepfe"><button data-b="pulse" class="stark">Start</button>'+
    '<button data-b="on">Dauer</button><button data-b="off">Aus</button></div>'};
};

BAUER.auswahl = function(k, w){
  var a = parseInt(w.activeOutput||0,10);
  return {klasse: a>0?"an":"", inhalt:
    '<div class="w">'+(a>0?("Nr. "+a):"Aus")+'</div>'+
    '<div class="knoepfe"><button data-b="1">1</button><button data-b="2">2</button>'+
    '<button data-b="3">3</button><button data-b="reset" class="leise">Aus</button></div>'};
};

BAUER.wert = function(k, w){
  return {klasse:"", inhalt:'<div class="w">'+zahl(w.value)+
    '<small>'+e(k.einheit_kurz||"")+'</small></div>'};
};

BAUER.zustand = function(k, w){
  var ein = an(w.active);
  return {klasse: ein?"an":"", inhalt:'<div class="w">'+(ein?"Ja":"Nein")+'</div>'};
};

BAUER.text = function(k, w){
  var t = w.text!=null?w.text:(w.textAndIcon!=null?w.textAndIcon:"");
  return {klasse:"", inhalt:'<div class="w" style="font-size:1.05rem;line-height:1.3">'+e(t||"–")+'</div>'};
};

BAUER.zaehler = function(k, w){
  return {klasse:"", inhalt:'<div class="w">'+zahl(w.actual!=null?w.actual:w.total)+
    '<small>'+e(k.einheit_kurz||"")+'</small></div>'+
    (w.total!=null&&w.actual!=null?'<div class="u">Summe '+zahl(w.total)+'</div>':'')};
};

BAUER.alarm = function(k, w){
  var scharf = an(w.armed);
  var stufe = parseFloat(w.level)||0;
  return {klasse: stufe>0?"":"", inhalt:
    '<div class="w" style="font-size:1.2rem;'+(stufe>0?"color:var(--fehl)":"")+'">'+
      (stufe>0?"ALARM":(scharf?"scharf":"unscharf"))+'</div>'+
    '<div class="knoepfe">'+
      (stufe>0?'<button data-b="quit" class="warn">Quittieren</button>':
        (scharf?'<button data-b="off">Unscharf</button>':
                '<button data-b="on" class="warn">Scharf schalten</button>'))+
    '</div>'};
};

BAUER.farbe = function(k, w){
  return {klasse:"", inhalt:'<div class="w" style="font-size:1rem">'+e(w.color||"–")+'</div>'+
    '<div class="u">Farbwahl: bitte in der Loxone-App</div>'};
};

BAUER.generisch = function(k, w){
  var zeilen = [];
  for (var n in w) { if(Object.prototype.hasOwnProperty.call(w,n)) {
    zeilen.push('<div class="u">'+e(n)+': <b>'+e(typeof w[n]==="number"?zahl(w[n]):w[n])+'</b></div>');
  } }
  if (!zeilen.length) zeilen.push('<div class="u">keine Werte</div>');
  return {klasse:"", inhalt:'<div style="margin-top:auto">'+zeilen.slice(0,4).join("")+'</div>'};
};

BAUER.fehlt = function(k, w){
  return {klasse:"fehlt", inhalt:
    '<div class="u" style="margin-top:auto">Dieser Baustein steht nicht mehr in der '+
    'Loxone-Konfiguration.</div>'};
};

/* ---------- Zeichnen ---------- */
function einheit_kurz(f){
  if(!f) return "";
  var m = String(f).replace(/%[\d.]*[a-zA-Z]/,"").replace(/&deg;/g,"°").trim();
  return m.length>6?"":m;
}

function zeichnen(){
  var raster = document.getElementById("raster");
  raster.innerHTML = "";
  DATEN.kacheln.forEach(function(k, i){
    k.einheit_kurz = einheit_kurz(k.einheit);
    var f = BAUER[k.kachel] || BAUER.generisch;
    var r;
    try { r = f(k, k.werte||{}); }
    catch(x){ r = {klasse:"", inhalt:'<div class="u">Diese Kachel liess sich nicht '+
                   'darstellen.</div>'}; }
    var g = (k.groesse||"1x1").split("x");
    var d = document.createElement("div");
    d.className = "k "+(r.klasse||"");
    d.style.gridColumn = "span "+Math.max(1,Math.min(6,parseInt(g[0],10)||1));
    d.style.gridRow = "span "+Math.max(1,Math.min(3,parseInt(g[1],10)||1));
    d.dataset.i = i;
    d.innerHTML = '<div class="t">'+e(k.titel)+'</div>'+r.inhalt;
    raster.appendChild(d);
  });
}

/* Kurzes Ruetteln beim Antippen.
   Auf einem Tablet ohne Tastenklick fehlt die Rueckmeldung, dass ein Druck
   angekommen ist - und wer nichts spuert, drueckt ein zweites Mal. 40 ms sind
   ein Antippen, kein Alarm.
   navigator.vibrate gibt es nur auf Android und nur nach einer Nutzeraktion;
   fehlt es, passiert einfach nichts. Wer es nicht will, schaltet es in den
   Einstellungen ab. */
function ruetteln(){
  <?php if (!empty($cfg['haptik'])) { ?>
  try { if (navigator.vibrate) { navigator.vibrate(40); } } catch(e){}
  <?php } ?>
}

document.addEventListener("click", function(ev){
  var b = ev.target.closest ? ev.target.closest("button[data-b]") : null;
  if(!b) return;
  var kachel = b.closest(".k"); if(!kachel) return;
  var k = DATEN.kacheln[parseInt(kachel.dataset.i,10)];
  if(!k) return;
  ruetteln();
  senden(k.uuid, b.dataset.b);
});

document.addEventListener("change", function(ev){
  var s = ev.target;
  if(!s || s.type!=="range" || !s.dataset.w) return;
  var kachel = s.closest(".k"); if(!kachel) return;
  var k = DATEN.kacheln[parseInt(kachel.dataset.i,10)];
  if(!k) return;
  /* Was der Wert genau bedeutet, haengt am Typ. Deshalb wird hier NICHT
     geraten, sondern die Befehlsliste des Bausteins befragt. */
  var bef = k.befehle || [];
  if (bef.indexOf("$wert")>=0) { senden(k.uuid, s.value); }
  else if (k.kachel==="jalousie") { senden(k.uuid, "manualPosition/"+s.value); }
  else { senden(k.uuid, s.value); }
});

/* ---------- Stoerungsband ---------- */
(function(){
  var band = document.createElement("div");
  band.id = "stoerband";
  band.setAttribute("role", "status");
  document.body.appendChild(band);
})();

/* ---------- Werte holen ---------- */
var fehlversuche = 0;
function werte_holen(){
  fetch(BASIS+"&aktion=werte&seite="+encodeURIComponent(SEITE), {cache:"no-store"})
  .then(function(a){ return a.json(); })
  .then(function(d){
    fehlversuche = 0;
    DATEN.kacheln.forEach(function(k){
      if(d.werte && d.werte[k.uuid]) k.werte = d.werte[k.uuid];
    });
    zeichnen();
    stand_zeigen(d);
  })
  .catch(function(){
    fehlversuche++;
    stand_zeigen(null);
  });
}

function stoerung(an, text){
  document.body.classList.toggle("gestoert", !!an);
  var band = document.getElementById("stoerband");
  if(band) band.textContent = text || "";
}

function stand_zeigen(d){
  var p = document.getElementById("punkt");
  var t = document.getElementById("stand");
  if(!d){
    p.className = "punkt tot";
    t.textContent = "Der LoxBerry antwortet nicht ("+fehlversuche+" Versuche).";
    /* Erst nach dem ZWEITEN Fehlversuch abdunkeln. Ein einzelner Aussetzer
       - ein WLAN-Paket, das verloren geht - ist Alltag; das Tablet soll
       deswegen nicht bei jedem Takt aufblinken. */
    stoerung(fehlversuche >= 2,
             "Keine Verbindung zum LoxBerry – die Werte sind nicht aktuell.");
    return;
  }
  if(!d.ok){
    p.className = "punkt tot";
    t.textContent = "Der Dienst hat keine Verbindung zum Miniserver.";
    stoerung(true, "Keine Verbindung zum Miniserver – die Werte sind nicht aktuell.");
    return;
  }
  if(d.alter > 60){
    p.className = "punkt alt";
    t.textContent = "Die Werte sind "+d.alter+" Sekunden alt.";
    /* Alte Werte sind noch keine Stoerung - sie werden nur benannt. */
    stoerung(false);
    return;
  }
  p.className = "punkt gut";
  t.textContent = "Verbunden"+(d.weg==="http"?" – ueber HTTP-Abfrage, nicht ueber WebSocket":"")+".";
  stoerung(false);
}

/* ---------- Wandtablet-Kleinigkeiten ---------- */
<?php if (!empty($cfg['wach'])) { ?>
/* Bildschirm wach halten, solange die Seite sichtbar ist. Der Browser
   erlaubt das nur nach einer Nutzeraktion oder gar nicht - schlaegt es fehl,
   passiert einfach nichts. */
var sperre = null;
function wach(){
  if(!("wakeLock" in navigator)) return;
  navigator.wakeLock.request("screen").then(function(s){ sperre=s; }).catch(function(){});
}
document.addEventListener("visibilitychange", function(){
  if(document.visibilityState==="visible"){ wach(); werte_holen(); }
});
wach();
document.addEventListener("click", wach, {once:true});
<?php } ?>

<?php if (!empty($cfg['vollbild'])) { ?>
/* Vollbild erst nach der ersten Beruehrung - Browser lassen es anders nicht zu. */
document.addEventListener("click", function ersteBeruehrung(){
  document.removeEventListener("click", ersteBeruehrung);
  var el = document.documentElement;
  if(el.requestFullscreen) el.requestFullscreen().catch(function(){});
}, {once:true});
<?php } ?>

zeichnen();
stand_zeigen({ok:DATEN.ok, weg:DATEN.weg, alter:DATEN.alter});
setInterval(werte_holen, TAKT);
</script>

<?php } ?>
</body>
</html>
