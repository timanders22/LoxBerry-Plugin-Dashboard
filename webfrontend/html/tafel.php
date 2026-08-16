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
 * Sie holt einmal ihre Struktur (aktion=seite) und danach nur noch Werte -
 * entweder im Takt (aktion=werte) oder geschoben (aktion=strom). Geschaltet
 * wird ueber aktion=befehl beziehungsweise aktion=szene.
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

/* Was die Anzeigeseite von der Konfiguration wissen muss - und nur das.
 * Zugangsdaten, Token des Miniservers und Pfade gehen sie nichts an. */
$konf = array(
    'takt'      => max(1, min(30, (int) $cfg['takt'])),
    'sse'       => !empty($cfg['sse']) ? 1 : 0,
    'rotation'  => max(0, min(3600, (int) $cfg['rotation'])),
    'nacht_von' => preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['nacht_von'])
                   ? (string) $cfg['nacht_von'] : '',
    'nacht_bis' => preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['nacht_bis'])
                   ? (string) $cfg['nacht_bis'] : '',
    'nacht_hell' => max(0, min(100, (int) $cfg['nacht_helligkeit'])),
    'verlauf'   => !empty($cfg['verlauf']) ? 1 : 0,
    'haptik'    => !empty($cfg['haptik']) ? 1 : 0,
    'vollbild'  => !empty($cfg['vollbild']) ? 1 : 0,
    'wach'      => !empty($cfg['wach']) ? 1 : 0,
);
$seitenliste = array();
foreach ($seiten as $s) {
    if (!is_array($s)) { continue; }
    $seitenliste[] = array('schluessel' => (string) (isset($s['schluessel']) ? $s['schluessel'] : ''),
                           'name' => (string) (isset($s['name']) ? $s['name'] : ''));
}
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
/* Nachtabsenkung. Ein eigener Schleier statt CSS-filter auf dem Koerper:
   filter erzeugt einen neuen Bezugsrahmen, und die fest stehenden Elemente
   (PIN-Fenster, Stoerband) sprangen dadurch an die falsche Stelle. */
#nachtschleier{position:fixed;inset:0;z-index:40;background:#000;opacity:0;
  pointer-events:none;transition:opacity .8s ease}
@media (prefers-reduced-motion: reduce){#raster,#nachtschleier{transition:none}}
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
.k.alarm{border-color:var(--fehl)}
.k .t{font-size:.83rem;color:var(--leise);line-height:1.25;margin-bottom:auto;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.k .w{font-size:1.7rem;font-weight:650;letter-spacing:-.02em;line-height:1.1}
.k .w small{font-size:.9rem;font-weight:500;color:var(--leise);margin-left:2px}
.k .u{font-size:.78rem;color:var(--leise);margin-top:3px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.k.fehlt{border-style:dashed;opacity:.65}
/* Schloss an gesicherten und Punkt an nur lesenden Bausteinen. Ein Knopf,
   der stumm scheitert, ist schlimmer als gar keiner - hier steht sichtbar,
   warum es keinen gibt. */
.k .marke{position:absolute;top:8px;right:10px;font-size:.8rem;color:var(--leise)}
.kurve{margin-top:6px;height:22px;width:100%;display:block;opacity:.85}
.knoepfe{display:flex;gap:7px;margin-top:8px;flex-wrap:wrap}
button{font:inherit;font-size:.83rem;padding:8px 13px;border-radius:10px;cursor:pointer;
  border:1px solid var(--rand);background:var(--kachel2);color:var(--text);min-height:38px}
button:active{transform:scale(.97)}
button.stark{background:var(--an);border-color:var(--an);color:#fff}
button.leise{color:var(--leise)}
button.warn{border-color:var(--warn);color:var(--warn)}
button:disabled{opacity:.45;cursor:not-allowed}
input[type=range]{width:100%;margin:8px 0 2px;accent-color:var(--an);height:30px}
.balken{height:7px;border-radius:4px;background:var(--kachel2);overflow:hidden;margin-top:8px}
.balken i{display:block;height:100%;background:var(--an)}
.rollo{display:flex;align-items:center;gap:9px;margin-top:6px}
.rollo .schacht{flex:1;height:44px;border-radius:8px;background:var(--kachel2);
  border:1px solid var(--rand);overflow:hidden;position:relative}
.rollo .schacht i{position:absolute;left:0;top:0;right:0;background:var(--aus);display:block}
.farbfeld{height:26px;border-radius:8px;border:1px solid var(--rand);margin-top:6px}
.farbregler{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;align-items:center;
  margin-top:4px;font-size:.74rem;color:var(--leise)}
.farbregler input[type=range]{margin:2px 0}
.pin{position:fixed;inset:0;background:rgba(0,0,0,.72);display:flex;align-items:center;
  justify-content:center;z-index:60}
.pin div{background:var(--kachel);padding:26px;border-radius:var(--r);width:min(320px,88vw);
  border:1px solid var(--rand)}
.pin input{width:100%;font-size:1.5rem;text-align:center;letter-spacing:.4em;padding:12px;
  border-radius:10px;border:1px solid var(--rand);background:var(--kachel2);color:var(--text)}
.blase{position:fixed;left:50%;transform:translateX(-50%);bottom:26px;z-index:70;
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
<div id="nachtschleier"></div>

<script>
"use strict";
/* JSON_UNESCAPED_SLASHES ist hier bewusst NICHT gesetzt.
 *
 * Das Flag verhindert genau die Maskierung des schliessenden Skript-Tags,
 * die den Ausbruch aus diesem Block unmoeglich macht. Kacheltitel fallen auf
 * den Bausteinnamen aus Loxone Config zurueck, und der wird nirgends von
 * spitzen Klammern und Schraegstrichen befreit - ein Baustein, dessen Name
 * ein schliessendes Skript-Tag enthaelt, beendete bis 0.9.5 an dieser Stelle
 * das script-Element. In db_json_raus() darf das Flag bleiben: dort ist der
 * Inhaltstyp application/json, kein HTML.
 *
 * In diesem Kommentar steht die Zeichenfolge DESHALB NICHT ausgeschrieben.
 * Sie tat es beim ersten Anlauf - und beendete den Skriptblock genau so, wie
 * der Kommentar es beschreibt. Ein Beispiel, das den beschriebenen Fehler
 * selbst begeht, ist kein Beispiel. */
var BASIS = <?= json_encode($basis, JSON_UNESCAPED_UNICODE) ?>;
var SEITE = <?= json_encode($wunsch, JSON_UNESCAPED_UNICODE) ?>;
var DATEN = <?= json_encode($daten, JSON_UNESCAPED_UNICODE) ?>;
var KONF  = <?= json_encode($konf, JSON_UNESCAPED_UNICODE) ?>;
var LISTE = <?= json_encode($seitenliste, JSON_UNESCAPED_UNICODE) ?>;
var TAKT  = KONF.takt * 1000;
var PIN   = "";

/* ---------- Kleinteile ---------- */
function e(t){ var d=document.createElement("div"); d.textContent=t==null?"":String(t); return d.innerHTML; }
function zahl(v,n){ if(v==null||v==="")return "–";
  var f=parseFloat(v); if(isNaN(f))return String(v);
  return f.toFixed(n===undefined?(Math.abs(f)>=100?0:1):n).replace(".",","); }
function an(v){ return v!=null && parseFloat(v)>0; }
function zahl_oder(v, ersatz){ var f=parseFloat(v); return isNaN(f)?ersatz:f; }

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

/* ---------- Absenden ---------- */
function absenden(pfad){
  if (DATEN.pin && !PIN) {
    return pin_fragen().then(function(p){
      if(p === null){ return; }
      PIN = p;
      return absenden(pfad);
    });
  }
  var u = BASIS + pfad + "&seite=" + encodeURIComponent(SEITE)
        + (PIN ? "&pin=" + encodeURIComponent(PIN) : "");
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

/* &seite= ist Pflicht. Die PIN haengt an der SEITE, nicht am Baustein:
   liegt ein Tuerschloss auf einer ungeschuetzten und zusaetzlich auf einer
   geschuetzten Seite, muss der Endpunkt wissen, von welcher der Druck kam. */
function senden(uuid, befehl){
  return absenden("&aktion=befehl&uuid=" + encodeURIComponent(uuid)
                  + "&befehl=" + encodeURIComponent(befehl));
}
function szene_ausloesen(nr){
  return absenden("&aktion=szene&kachel=" + encodeURIComponent(nr));
}

/* ---------- Verlaufskurve ----------
   Ein <svg> aus einem einzigen Polygonzug, ohne Achsen und ohne Zahlen. Sie
   soll die Richtung zeigen, nicht abgelesen werden - dafuer steht der Wert
   gross darueber. */
function kurve(werte){
  if(!KONF.verlauf || !werte || werte.length < 3){ return ""; }
  var min = Math.min.apply(null, werte), max = Math.max.apply(null, werte);
  var spanne = (max - min) || 1;
  var n = werte.length;
  var punkte = werte.map(function(w, i){
    var x = (i / (n - 1)) * 100;
    var y = 20 - ((w - min) / spanne) * 18;
    return x.toFixed(1) + "," + y.toFixed(1);
  }).join(" ");
  return '<svg class="kurve" viewBox="0 0 100 22" preserveAspectRatio="none" aria-hidden="true">'+
         '<polyline points="'+punkte+'" fill="none" stroke="currentColor" '+
         'stroke-width="1.2" vector-effect="non-scaling-stroke" opacity=".55"/></svg>';
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
  /* Grenzen aus dem Baustein, wenn der Miniserver sie mitschickt. Bis 0.9.5
     waren 0..100 fest verdrahtet, obwohl min/max/step abonniert werden. */
  var lo = zahl_oder(k.min, 0), hi = zahl_oder(k.max, 100), st = zahl_oder(k.step, 1);
  if (hi <= lo) { lo = 0; hi = 100; }
  var p = w.position==null?lo:Math.round(parseFloat(w.position));
  return {klasse: p>lo?"an":"", inhalt:
    '<div class="w">'+p+'<small>%</small></div>'+ kurve(k.verlauf) +
    '<input type="range" min="'+lo+'" max="'+hi+'" step="'+(st>0?st:1)+'" value="'+p+'" data-w="1">'+
    '<div class="knoepfe"><button data-b="on">Ein</button><button data-b="off">Aus</button></div>'};
};

BAUER.schieber = function(k, w){
  var lo = zahl_oder(k.min, 0), hi = zahl_oder(k.max, 100), st = zahl_oder(k.step, 1);
  if (hi <= lo) { lo = 0; hi = 100; }
  var v = w.value==null?lo:parseFloat(w.value);
  if (isNaN(v)) { v = lo; }
  return {klasse:"", inhalt:
    '<div class="w">'+zahl(v)+'<small>'+e(k.einheit_kurz||"")+'</small></div>'+ kurve(k.verlauf) +
    '<input type="range" min="'+lo+'" max="'+hi+'" step="'+(st>0?st:1)+'" value="'+
      Math.max(lo,Math.min(hi,v))+'" data-w="1">'};
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

/* Die aeltere Lichtsteuerung (LightController ohne V2).
   Sie kennt keine Stimmungsliste, sondern Szenennummern: activescene und
   sceneList. Bis 0.9.5 lief sie ueber dieselbe Kachel wie V2 und griff damit
   auf moodList/activeMoods zu - Felder, die V1 nie sendet. Die Kachel stand
   deshalb dauerhaft auf "Aus", und ihr einziger Knopf schickte changeTo/0,
   was die Befehlsliste von V1 gar nicht kennt. */
BAUER.lichtszene = function(k, w){
  var szenen = [];
  var roh = w.sceneList;
  if (typeof roh === "string" && roh !== "") {
    /* sceneList kommt als Text. Zwei Formen sind im Umlauf: eine
       JSON-Liste und eine Aufzaehlung "0=Aus,1=Hell,...". Beide werden
       gelesen; was zu keiner passt, wird uebergangen statt geraten. */
    try {
      var j = JSON.parse(roh);
      if (Array.isArray(j)) {
        szenen = j.map(function(x, i){
          return (x && typeof x === "object")
            ? {nr: (x.id!==undefined?x.id:i), name: x.name||String(i)}
            : {nr: i, name: String(x)};
        });
      }
    } catch(x) {
      roh.split(",").forEach(function(teil){
        var t = teil.split("=");
        if (t.length === 2 && /^\d+$/.test(t[0].trim())) {
          szenen.push({nr: parseInt(t[0],10), name: t[1].trim()});
        }
      });
    }
  }
  var jetzt = w.activescene;
  var kn = '<div class="knoepfe">';
  var gezeigt = 0;
  for (var i=0;i<szenen.length && gezeigt<4;i++){
    var s = szenen[i];
    var ist = String(s.nr) === String(jetzt);
    kn += '<button data-b="'+e(s.nr)+'" class="'+(ist?"stark":"")+'">'+e(s.name)+'</button>';
    gezeigt++;
  }
  if(!gezeigt){
    kn += '<button data-b="on" class="stark">Ein</button>'+
          '<button data-b="off">Aus</button>';
  }
  kn += '<button data-b="plus" class="leise">+</button>'+
        '<button data-b="minus" class="leise">&minus;</button></div>';
  var text = szenen.length
      ? (szenen.filter(function(s){return String(s.nr)===String(jetzt);})
               .map(function(s){return s.name;})[0] || ("Szene "+(jetzt==null?"–":jetzt)))
      : (jetzt==null ? "–" : "Szene "+jetzt);
  return {klasse: (jetzt!=null && String(jetzt)!=="0")?"an":"",
          inhalt:'<div class="w" style="font-size:1.05rem">'+e(text)+'</div>'+kn};
};

BAUER.jalousie = function(k, w){
  /* position: 0 = ganz oben, 1 = ganz unten  [Structure File, Jalousie] */
  var p = w.position==null?0:Math.round(parseFloat(w.position)*100);
  var auto = an(w.autoActive);
  return {klasse:"", inhalt:
    '<div class="w">'+p+'<small>% zu</small></div>'+
    '<div class="rollo"><div class="schacht"><i style="height:'+p+'%"></i></div></div>'+
    /* Der Schieberegler fehlte bis 0.9.5, obwohl manualPosition/$wert in der
       Befehlsliste stand und der Regler-Handler ihn behandelte - der Zweig
       konnte nie ausloesen. */
    '<input type="range" min="0" max="100" step="1" value="'+p+'" data-w="1" data-art="jalousie">'+
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
    '<div class="w">'+zahl(ist)+'<small>&deg;C</small></div>'+ kurve(k.verlauf) +
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

/* Auswahl (Radio buttons). Die Namen der Ausgaenge stehen in den Details des
   Bausteins unter 'outputs', die Beschriftung fuer "nichts gewaehlt" unter
   'allOff' - [S] Radio, Details. Bis 0.9.5 standen hier fest die Knoepfe
   1, 2 und 3: bei einem Baustein mit acht Ausgaengen waren fuenf davon nicht
   erreichbar, und bei einem mit den IDs 1,2,5,8 zeigten zwei ins Leere.
   'next' und 'prev' gibt es seit 13.3.1.10. */
BAUER.auswahl = function(k, w){
  var a = parseInt(w.activeOutput||0,10);
  var ausg = k.ausgaenge || {};
  var ids = Object.keys(ausg);
  var kn = '<div class="knoepfe">';
  if (ids.length) {
    ids.slice(0, 6).forEach(function(id){
      kn += '<button data-b="'+e(id)+'" class="'+(String(a)===String(id)?"stark":"")+'">'+
            e(ausg[id])+'</button>';
    });
    if (ids.length > 6) {
      kn += '<button data-b="prev" class="leise">&larr;</button>'+
            '<button data-b="next" class="leise">&rarr;</button>';
    }
  } else {
    /* Ohne Namen wird NICHT geraten, wie viele Ausgaenge es gibt - dann
       bleiben nur die beiden Blaetterknoepfe und das Abwaehlen. */
    kn += '<button data-b="prev" class="leise">&larr;</button>'+
          '<button data-b="next" class="leise">&rarr;</button>';
  }
  kn += '<button data-b="reset" class="leise">'+e(k.allesaus || "Aus")+'</button></div>';
  var text = a>0 ? (ausg[String(a)] || ("Nr. "+a)) : (k.allesaus || "Aus");
  return {klasse: a>0?"an":"", inhalt:
    '<div class="w" style="font-size:1.15rem">'+e(text)+'</div>'+kn};
};

BAUER.wert = function(k, w){
  return {klasse:"", inhalt:'<div class="w">'+zahl(w.value)+
    '<small>'+e(k.einheit_kurz||"")+'</small></div>'+ kurve(k.verlauf)};
};

BAUER.zustand = function(k, w){
  var ein = an(w.active);
  /* Ein gesperrter Praesenzmelder meldet nichts mehr - das gehoert auf die
     Kachel, sonst haelt man ihn fuer defekt ([S] PresenceDetector, States
     locked und infoText). */
  var gesperrt = an(w.locked);
  return {klasse: ein?"an":"", inhalt:'<div class="w">'+(ein?"Ja":"Nein")+'</div>'+
    (gesperrt ? '<div class="u">gesperrt'+(w.infoText?' · '+e(w.infoText):'')+'</div>' : '')};
};

BAUER.text = function(k, w){
  var t = w.text!=null?w.text:(w.textAndIcon!=null?w.textAndIcon:"");
  return {klasse:"", inhalt:'<div class="w" style="font-size:1.05rem;line-height:1.3">'+e(t||"–")+'</div>'};
};

/* Zaehler. totalDay und totalWeek gibt es seit Config 13.01 ([S] Meter,
   States); aeltere Bausteine liefern sie nicht - dann steht die Zeile nicht
   da, statt eine Null vorzutaeuschen. */
BAUER.zaehler = function(k, w){
  var unten = [];
  if (w.total != null && w.actual != null) { unten.push("Summe "+zahl(w.total)); }
  if (w.totalDay != null) { unten.push("heute "+zahl(w.totalDay)); }
  if (w.totalWeek != null) { unten.push("Woche "+zahl(w.totalWeek)); }
  return {klasse:"", inhalt:'<div class="w">'+zahl(w.actual!=null?w.actual:w.total)+
    '<small>'+e(k.einheit_kurz||"")+'</small></div>'+ kurve(k.verlauf) +
    (unten.length ? '<div class="u">'+e(unten.join(" · "))+'</div>' : '')};
};

BAUER.alarm = function(k, w){
  var scharf = an(w.armed);
  var stufe = parseFloat(w.level)||0;
  /* armedAt und nextLevelAt sind Unix-Zeitstempel und ersetzen seit Config
     13.0 die abgekuendigten armedDelay/nextLevelDelay ([S] Alarm, States).
     Ist der Zeitpunkt in der Zukunft, laeuft gerade eine Verzoegerung. */
  var jetzt = Math.floor(Date.now()/1000);
  var rest = 0;
  if (!scharf && w.armedAt > jetzt) { rest = w.armedAt - jetzt; }
  else if (stufe === 0 && w.nextLevelAt > jetzt) { rest = w.nextLevelAt - jetzt; }
  /* Bis 0.9.5 stand hier stufe>0?"":"" - beide Zweige leer, die Kachel bekam
     ihre Hervorhebung also nie. */
  return {klasse: stufe>0?"alarm":(scharf?"an":""), inhalt:
    '<div class="w" style="font-size:1.2rem;'+(stufe>0?"color:var(--fehl)":"")+'">'+
      (stufe>0?"ALARM":(scharf?"scharf":"unscharf"))+'</div>'+
    (rest>0 ? '<div class="u">noch '+Math.round(rest)+'&nbsp;s</div>' : '')+
    '<div class="knoepfe">'+
      (stufe>0?'<button data-b="quit" class="warn">Quittieren</button>':
        (scharf?'<button data-b="off" class="warn">Unscharf</button>':
                '<button data-b="on" class="warn">Scharf schalten</button>'))+
    '</div>'};
};

/* Brandmelder - eine EIGENE Kachel.
   Er liefert kein 'armed'. Bis 0.9.5 lief er ueber die Alarm-Kachel, die
   genau das liest: die Kachel eines Brandmelders zeigte deshalb dauerhaft
   "unscharf" und bot einen Knopf "Scharf schalten" an, den die Befehlsliste
   (nur "quit") abwies. Falsche Beschriftung und toter Knopf an einem
   Sicherheitsgeraet. */
BAUER.brandmelder = function(k, w){
  var stufe = parseFloat(w.level)||0;
  var ursache = w.alarmCause;
  var akustisch = an(w.acousticAlarm), test = an(w.testAlarm);
  var text = stufe>0 ? "ALARM" : (test ? "Test laeuft" : "ruhig");
  var unten = [];
  if (stufe>0 && ursache!=null && ursache!=="") { unten.push("Ursache "+ursache); }
  if (akustisch) { unten.push("Sirene an"); }
  return {klasse: stufe>0?"alarm":"", inhalt:
    '<div class="w" style="font-size:1.2rem;'+(stufe>0?"color:var(--fehl)":"")+'">'+e(text)+'</div>'+
    (unten.length?'<div class="u">'+e(unten.join(" · "))+'</div>':'')+
    '<div class="knoepfe">'+
      (stufe>0||akustisch
        ? '<button data-b="quit" class="warn">Quittieren</button>'
        : '<button disabled title="Ein Brandmelder kennt nur Quittieren.">Quittieren</button>')+
    '</div>'};
};

/* Farbwahl. Bis 0.9.5 stand hier "Farbwahl: bitte in der Loxone-App" - der
   Baustein war also gelistet, aber nicht bedienbar. Zwei Dinge fehlten: die
   Bedienelemente und, unbemerkt, der passende Eintrag in der Befehlsliste
   ($wert laesst nur Zahlen durch, hsv(...) waere abgewiesen worden).

   Alles hier ist aus [S] belegt, nichts geraten:
     ColorPickerV2  color = "hsv(h,s,v)" oder "temp(Helligkeit,Kelvin)"
                    Befehle hsv(...), temp(...), setBrightness/{value}
                    Details TWMin/TWMax (Vorgabe 2700/6500), pickerType
     ColorPicker    color = "hsv(...)" oder "lumitech(Helligkeit,Kelvin)"
                    Befehle hsv(...), lumitech(...), on, off

   'on'/'off' stehen nur beim aelteren ColorPicker. Beim V2 gibt es sie laut
   Dokument nicht - dort wird ueber die Helligkeit 0 ausgeschaltet. */
BAUER.farbe = function(k, w){
  var roh = String(w.color||"");
  var bef = k.befehle || [];
  var weissbefehl = bef.indexOf("$lumitech") >= 0 ? "lumitech" : "temp";
  var kannFarbe = String(k.pickertyp||"").toLowerCase() !== "tunablewhite";
  var twmin = k.twmin || 2700, twmax = k.twmax || 6500;

  var h=0, s=0, v=0, kelvin=twmin, art="";
  var m = roh.match(/^hsv\((\d+),(\d+),(\d+)\)$/i);
  if (m) { art="hsv"; h=+m[1]; s=+m[2]; v=+m[3]; }
  else {
    var t = roh.match(/^(?:temp|lumitech)\((\d+),(\d+)\)$/i);
    if (t) { art="weiss"; v=+t[1]; kelvin=+t[2]; }
  }
  /* Vorschau. Beim Weisston wird die Kelvinzahl grob in einen Farbton
     uebersetzt - warm ist gelblich, kalt blaeulich. Das ist eine Anzeige,
     keine Messung, deshalb bewusst grob. */
  var anteil = Math.max(0, Math.min(1, (kelvin - twmin) / Math.max(1, twmax - twmin)));
  var vorschau = art==="hsv"
      ? "hsl("+h+","+s+"%,"+Math.max(10,Math.min(90,v/2+10))+"%)"
      : (art==="weiss"
         ? "hsl("+Math.round(40 - anteil*20)+",100%,"+Math.max(20,Math.min(92,v/2+45))+"%)"
         : "var(--kachel2)");
  var kopf = art==="weiss" ? (kelvin+" K · "+v+"%")
           : (art==="hsv" ? (v+"%") : (roh||"–"));

  var regler = '';
  if (kannFarbe) {
    regler += '<span>Farbe</span><input type="range" min="0" max="360" step="1" value="'+h+'" data-farbe="h">'+
              '<span>Saett.</span><input type="range" min="0" max="100" step="1" value="'+s+'" data-farbe="s">';
  }
  regler += '<span>Hell</span><input type="range" min="0" max="100" step="1" value="'+v+'" data-farbe="v">'+
            '<span>Weiss</span><input type="range" min="'+twmin+'" max="'+twmax+'" step="50" value="'+kelvin+'" data-weiss="1">';

  var kn = '<div class="knoepfe">';
  if (bef.indexOf("off") >= 0) { kn += '<button data-b="off">Aus</button>'; }
  else { kn += '<button data-dunkel="1">Aus</button>'; }
  if (bef.indexOf("on") >= 0) { kn += '<button data-b="on">Ein</button>'; }
  kn += '</div>';

  return {klasse: v>0?"an":"", inhalt:
    '<div class="w" style="font-size:1.2rem">'+e(kopf)+'</div>'+
    '<div class="farbfeld" style="background:'+vorschau+'"></div>'+
    '<div class="farbregler" data-weissbefehl="'+e(weissbefehl)+'">'+regler+'</div>'+ kn};
};

/* Szene: mehrere Befehle auf einen Druck. Sie haengt an keinem Baustein,
   deshalb hat sie keine UUID - der Endpunkt findet sie ueber die laufende
   Nummer der Kachel auf dieser Seite und prueft JEDEN Schritt einzeln. */
BAUER.szene = function(k, w){
  return {klasse:"", inhalt:
    '<div class="w" style="font-size:1.05rem">'+(k.schritte||0)+' Schritte</div>'+
    ((k.beschreibung&&k.beschreibung.length)
      ? '<div class="u">'+e(k.beschreibung.slice(0,2).join(" · "))+'</div>' : '')+
    '<div class="knoepfe"><button data-szene="1" class="stark">Ausloesen</button></div>'};
};

/* Wetter. Die Werte kommen als eigene Ereignistabelle (Kennung 7), nicht als
   Zahlenwert - deshalb steht unter w.actual kein Wert, sondern ein Objekt
   mit 'stand' und 'eintraege'. Feldnamen und Reihenfolge stammen aus
   [K] "Event-Table of Weather-States". Der Klartext zur Wetterlage kommt aus
   [S] weatherTypeTexts; fehlt er, steht die Zahl da - erfunden wird nichts. */
BAUER.wetter = function(k, w){
  var jetzt = (w.actual && w.actual.eintraege && w.actual.eintraege[0]) || null;
  var vor = (w.forecast && w.forecast.eintraege) || [];
  if (!jetzt && vor.length) { jetzt = vor[0]; }
  if (!jetzt) {
    return {klasse:"", inhalt:'<div class="u" style="margin-top:auto">'+
      'Noch keine Wetterdaten empfangen.</div>'};
  }
  function lage(nr){
    var t = k.wettertexte || {};
    if (t[nr] != null) { return String(t[nr]); }
    if (t[String(nr)] != null) { return String(t[String(nr)]); }
    return "Lage " + nr;
  }
  function windrose(grad){
    var r = ["N","NO","O","SO","S","SW","W","NW"];
    return r[Math.round(((parseFloat(grad)||0) % 360) / 45) % 8];
  }
  /* Die Vorhersage als Reihe kleiner Saeulen: Temperatur als Hoehe,
     Niederschlag als Farbe. Keine Achsen, keine Zahlen - dafuer steht der
     aktuelle Wert gross darueber. */
  var reihe = "";
  if (vor.length > 1) {
    var temps = vor.map(function(x){ return x.temperatur; });
    var lo = Math.min.apply(null, temps), hi = Math.max.apply(null, temps);
    var spanne = (hi - lo) || 1;
    reihe = '<div style="display:flex;gap:1px;align-items:flex-end;height:26px;margin-top:6px">'+
      vor.slice(0, 24).map(function(x){
        var hoehe = 20 * (x.temperatur - lo) / spanne + 4;
        var nass = (x.niederschlag || 0) > 0.05;
        return '<div title="'+e(zahl(x.temperatur)+" °C")+'" style="flex:1;height:'+
               hoehe.toFixed(0)+'px;background:'+(nass?"var(--warn)":"var(--an)")+
               ';opacity:.75;border-radius:1px"></div>';
      }).join("") + '</div>';
  }
  return {klasse:"", inhalt:
    '<div class="w">'+zahl(jetzt.temperatur)+'<small>&deg;C</small></div>'+
    '<div class="u">'+e(lage(jetzt.art))+
      ' &middot; gefuehlt '+zahl(jetzt.gefuehlt)+'&nbsp;&deg;C'+
      ' &middot; '+zahl(jetzt.feuchte,0)+'&nbsp;% rF</div>'+
    '<div class="u">Wind '+zahl(jetzt.wind)+'&nbsp;km/h aus '+e(windrose(jetzt.windrichtung))+
      ' &middot; '+zahl(jetzt.niederschlag)+'&nbsp;mm'+
      ' &middot; '+zahl(jetzt.druck,0)+'&nbsp;hPa</div>'+ reihe};
};

/* Zeitschaltuhr. Die Eintraege kommen als Tageszeit-Ereignistabelle
   (Kennung 4) unter 'entriesAndDefaultValue'; nFrom und nTo sind Minuten
   seit Mitternacht [K] "Event-Table of Daytimer-States". */
BAUER.tageszeit = function(k, w){
  var d = w.entriesAndDefaultValue || {};
  var eintraege = d.eintraege || [];
  function uhr(min){
    var m = Math.max(0, parseInt(min, 10) || 0);
    return ("0"+Math.floor(m/60)).slice(-2)+":"+("0"+(m%60)).slice(-2);
  }
  var jetzt = new Date().getHours()*60 + new Date().getMinutes();
  var aktiv = eintraege.some(function(x){ return jetzt >= x.von && jetzt < x.bis; });
  /* Ein Tagesbalken: 24 Stunden von links nach rechts, die Eintraege als
     Abschnitte. Das ist auf einen Blick lesbar, eine Liste von Uhrzeiten
     nicht. */
  var balken = eintraege.slice(0, 12).map(function(x){
    var l = 100 * x.von / 1440, b = 100 * Math.max(0, x.bis - x.von) / 1440;
    return '<i style="position:absolute;left:'+l.toFixed(2)+'%;width:'+b.toFixed(2)+
           '%;top:0;bottom:0;background:var(--an);opacity:.65"></i>';
  }).join("");
  var wert = w.value;
  return {klasse: aktiv?"an":"", inhalt:
    '<div class="w" style="font-size:1.2rem">'+
      (wert==null ? (aktiv?"aktiv":"aus") : zahl(wert))+'</div>'+
    '<div style="position:relative;height:12px;border-radius:4px;background:var(--kachel2);'+
      'overflow:hidden;margin-top:6px">'+balken+
      '<i style="position:absolute;left:'+(100*jetzt/1440).toFixed(2)+
      '%;top:0;bottom:0;width:2px;background:var(--text);opacity:.8"></i></div>'+
    '<div class="u">'+(eintraege.length
        ? e(eintraege.slice(0,2).map(function(x){ return uhr(x.von)+"–"+uhr(x.bis); }).join(", ")
            + (eintraege.length>2 ? " …" : ""))
        : "keine Eintraege")+'</div>'+
    (w.override ? '<div class="u">Handbetrieb noch '+zahl(w.override,0)+'&nbsp;s</div>' : '')};
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
    /* Gesichert und nur-lesend werden SICHTBAR gemacht und die Knoepfe
       abgeschaltet. Bis 0.9.5 lieferte der Endpunkt beide Merkmale mit, und
       die Anzeige wertete keines aus: ein gesicherter Baustein bekam volle
       Knoepfe, die dann am Miniserver scheiterten. */
    var marke = "";
    if (k.gesichert) { marke = '<span class="marke" title="In Loxone Config gesichert - '+
      'verlangt das Visualisierungs-Passwort">&#128274;</span>'; }
    else if (k.nurlesen) { marke = '<span class="marke" title="In Loxone auf nur lesen '+
      'gesetzt">&#128065;</span>'; }
    d.innerHTML = '<div class="t">'+e(k.titel)+'</div>'+ marke + r.inhalt;
    /* Das Schloss bleibt stehen, auch wenn geschaltet werden darf - man soll
       sehen, dass der Baustein gesichert ist. Gesperrt wird nur, was wirklich
       nicht geht. */
    if (k.gesperrt) {
      d.querySelectorAll("button[data-b],button[data-szene],button[data-dunkel],input[type=range]")
       .forEach(function(el){ el.disabled = true; });
    }
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
  if (!KONF.haptik) { return; }
  try { if (navigator.vibrate) { navigator.vibrate(40); } } catch(e){}
}

function kachel_von(el){
  var kachel = el.closest ? el.closest(".k") : null;
  if(!kachel) return null;
  var i = parseInt(kachel.dataset.i,10);
  return isNaN(i) ? null : {i:i, k:DATEN.kacheln[i], el:kachel};
}

document.addEventListener("click", function(ev){
  var ziel = ev.target;
  var b = ziel.closest ? ziel.closest("button") : null;
  if(!b || b.disabled) return;
  var kk = kachel_von(b);
  if(!kk || !kk.k) return;
  if (b.dataset.szene) { ruetteln(); szene_ausloesen(kk.i); return; }
  if (b.dataset.dunkel) {
    /* Der ColorPickerV2 kennt laut Dokument kein 'off' - ausgeschaltet wird
       ueber die Helligkeit 0, Farbton und Saettigung bleiben stehen. */
    ruetteln();
    var fh = kk.el.querySelector('[data-farbe="h"]');
    var fs = kk.el.querySelector('[data-farbe="s"]');
    senden(kk.k.uuid, "hsv("+(fh?fh.value:0)+","+(fs?fs.value:0)+",0)");
    return;
  }
  if (b.dataset.b === undefined) return;
  ruetteln();
  senden(kk.k.uuid, b.dataset.b);
});

document.addEventListener("change", function(ev){
  var s = ev.target;
  if(!s || s.type!=="range" || s.disabled) return;
  var kk = kachel_von(s);
  if(!kk || !kk.k) return;

  /* Farbregler. Farbton, Saettigung und Helligkeit gehoeren zusammen und
     ergeben EINEN hsv-Befehl. Der Weissregler ist ein eigener Befehl -
     temp(...) beim ColorPickerV2, lumitech(...) beim aelteren ColorPicker;
     welcher, sagt die Befehlsliste des Bausteins, nicht eine Vermutung. */
  if (s.dataset.weiss) {
    var vv = kk.el.querySelector('[data-farbe="v"]');
    var kasten = kk.el.querySelector('.farbregler');
    var wort = (kasten && kasten.dataset.weissbefehl) || "temp";
    senden(kk.k.uuid, wort+"("+(vv?vv.value:100)+","+s.value+")");
    return;
  }
  if (s.dataset.farbe) {
    var h = kk.el.querySelector('[data-farbe="h"]');
    var sa = kk.el.querySelector('[data-farbe="s"]');
    var v = kk.el.querySelector('[data-farbe="v"]');
    /* Nur-Weisston-Bausteine (pickerType TunableWhite) haben keinen
       Farbregler - dort bedeutet der Helligkeitsregler den Weissbefehl. */
    if (!h) {
      var kasten2 = kk.el.querySelector('.farbregler');
      var wort2 = (kasten2 && kasten2.dataset.weissbefehl) || "temp";
      var kw = kk.el.querySelector('[data-weiss="1"]');
      senden(kk.k.uuid, wort2+"("+(v?v.value:0)+","+(kw?kw.value:2700)+")");
      return;
    }
    senden(kk.k.uuid, "hsv("+h.value+","+(sa?sa.value:0)+","+(v?v.value:0)+")");
    return;
  }
  if (!s.dataset.w) return;

  /* Was der Wert genau bedeutet, haengt am Typ. Deshalb wird hier NICHT
     geraten, sondern die Befehlsliste des Bausteins befragt. */
  var bef = kk.k.befehle || [];
  if (s.dataset.art === "jalousie" || kk.k.kachel === "jalousie") {
    senden(kk.k.uuid, "manualPosition/"+s.value);
  } else if (bef.indexOf("$wert")>=0) {
    senden(kk.k.uuid, s.value);
  } else {
    senden(kk.k.uuid, s.value);
  }
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
var letzte_tafel_nr = (DATEN.tafel && DATEN.tafel.nr) || 0;

function uebernehmen(d){
  fehlversuche = 0;
  DATEN.kacheln.forEach(function(k){
    if(d.werte && d.werte[k.uuid]) k.werte = d.werte[k.uuid];
    if(d.verlauf && d.verlauf[k.uuid]) k.verlauf = d.verlauf[k.uuid];
  });
  zeichnen();
  stand_zeigen(d);
  if (d.tafel) { tafel_befolgen(d.tafel); }
}

function werte_holen(){
  fetch(BASIS+"&aktion=werte&seite="+encodeURIComponent(SEITE), {cache:"no-store"})
  .then(function(a){ return a.json(); })
  .then(uebernehmen)
  .catch(function(){
    fehlversuche++;
    stand_zeigen(null);
  });
}

/* Werte geschoben statt abgefragt.
   Faellt der Strom aus (kein EventSource, Zwischenstation puffert, Endpunkt
   antwortet nicht), wird auf die Abfrage im Takt zurueckgefallen UND das
   angezeigt - sonst wird aus dem Ersatz unbemerkt der Normalfall. */
var strom = null, taktgeber = null, ersatzweg = false;

function abfrage_starten(){
  if (taktgeber) { return; }
  taktgeber = setInterval(werte_holen, TAKT);
  werte_holen();
}

function strom_starten(){
  if (!("EventSource" in window)) { ersatzweg = true; abfrage_starten(); return; }
  try {
    strom = new EventSource(BASIS+"&aktion=strom&seite="+encodeURIComponent(SEITE));
  } catch(x) { ersatzweg = true; abfrage_starten(); return; }
  strom.onmessage = function(ev){
    try { uebernehmen(JSON.parse(ev.data)); } catch(x){}
  };
  strom.onerror = function(){
    /* EventSource verbindet von selbst neu; der Endpunkt beendet den Lauf
       ohnehin nach fuenf Minuten. Erst wenn das mehrfach scheitert, wird
       umgeschaltet. */
    fehlversuche++;
    stand_zeigen(null);
    if (fehlversuche >= 3 && !ersatzweg) {
      ersatzweg = true;
      try { strom.close(); } catch(x){}
      strom = null;
      blase("Der Werte-Schub kam nicht durch. Es wird wieder im Takt abgefragt.", true);
      abfrage_starten();
    }
  };
}

function stoerung(an, text){
  document.body.classList.toggle("gestoert", !!an);
  var band = document.getElementById("stoerband");
  if(band) band.textContent = text || "";
}

function stand_zeigen(d){
  var p = document.getElementById("punkt");
  var t = document.getElementById("stand");
  var weg = ersatzweg ? " – Ersatzweg: Abfrage im Takt" : "";
  if(!d){
    p.className = "punkt tot";
    t.textContent = "Der LoxBerry antwortet nicht ("+fehlversuche+" Versuche)."+weg;
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
    t.textContent = "Die Werte sind "+d.alter+" Sekunden alt."+weg;
    /* Alte Werte sind noch keine Stoerung - sie werden nur benannt. */
    stoerung(false);
    return;
  }
  p.className = "punkt gut";
  t.textContent = "Verbunden"+(d.weg==="http"?" – ueber HTTP-Abfrage, nicht ueber WebSocket":"")+"."+weg;
  stoerung(false);
}

/* ---------- Steuerung durch Loxone ----------
   Der Miniserver legt seinen Wunsch ueber den Endpunkt ab; hier wird er beim
   naechsten Takt befolgt. Nur eine NEUE laufende Nummer loest etwas aus -
   sonst spraenge die Seite bei jedem Takt erneut um und waere nicht mehr
   bedienbar. */
var hand_hell = null;
function tafel_befolgen(t){
  if (!t || !t.nr || t.nr <= letzte_tafel_nr) { return; }
  letzte_tafel_nr = t.nr;
  if (t.hell >= 0) { hand_hell = t.hell; schleier_setzen(); }
  if (t.wach === 1) { hand_hell = null; wach(); schleier_setzen(); }
  if (t.seite && t.seite !== SEITE) {
    location.href = "?token="+encodeURIComponent(<?= json_encode($ist, JSON_UNESCAPED_UNICODE) ?>)
                  + "&seite="+encodeURIComponent(t.seite);
  }
}

/* ---------- Nachtabsenkung ---------- */
function minuten(hhmm){
  var m = String(hhmm||"").match(/^(\d{1,2}):(\d{2})$/);
  return m ? (parseInt(m[1],10)*60 + parseInt(m[2],10)) : -1;
}
function ist_nacht(){
  var von = minuten(KONF.nacht_von), bis = minuten(KONF.nacht_bis);
  if (von < 0 || bis < 0 || von === bis) { return false; }
  var jetzt = new Date().getHours()*60 + new Date().getMinutes();
  /* Ueber Mitternacht hinweg: 22:30 bis 06:00 heisst "spaet ODER frueh". */
  return (von < bis) ? (jetzt >= von && jetzt < bis) : (jetzt >= von || jetzt < bis);
}
function schleier_setzen(){
  var el = document.getElementById("nachtschleier");
  if (!el) { return; }
  var hell = (hand_hell !== null) ? hand_hell : (ist_nacht() ? KONF.nacht_hell : 100);
  el.style.opacity = String(Math.max(0, Math.min(1, (100 - hell) / 100)));
  el.style.pointerEvents = hell <= 0 ? "auto" : "none";
}
/* Eine Beruehrung hebt die Absenkung fuer fuenf Minuten auf - wer nachts vor
   dem Tablet steht, will es lesen koennen. */
document.addEventListener("pointerdown", function(){
  if (hand_hell === null && !ist_nacht()) { return; }
  hand_hell = 100;
  schleier_setzen();
  clearTimeout(window._nachtfrist);
  window._nachtfrist = setTimeout(function(){ hand_hell = null; schleier_setzen(); }, 300000);
}, true);
setInterval(schleier_setzen, 30000);

/* ---------- Seitenrotation ---------- */
var letzte_beruehrung = 0;
document.addEventListener("pointerdown", function(){ letzte_beruehrung = Date.now(); }, true);
if (KONF.rotation > 0 && LISTE.length > 1) {
  setInterval(function(){
    /* Nicht weiterblaettern, solange jemand bedient: das Tablet unter der
       Hand umzuschalten ist der sicherste Weg zu einem Fehlgriff. */
    if (Date.now() - letzte_beruehrung < 60000) { return; }
    var i = 0;
    for (var n=0; n<LISTE.length; n++) { if (LISTE[n].schluessel === SEITE) { i = n; break; } }
    var naechste = LISTE[(i+1) % LISTE.length].schluessel;
    if (naechste && naechste !== SEITE) {
      location.href = "?token="+encodeURIComponent(<?= json_encode($ist, JSON_UNESCAPED_UNICODE) ?>)
                    + "&seite="+encodeURIComponent(naechste);
    }
  }, KONF.rotation * 1000);
}

/* ---------- Wandtablet-Kleinigkeiten ---------- */
var sperre = null;
function wach(){
  if(!KONF.wach) return;
  if(!("wakeLock" in navigator)) return;
  navigator.wakeLock.request("screen").then(function(s){ sperre=s; }).catch(function(){});
}
if (KONF.wach) {
  document.addEventListener("visibilitychange", function(){
    if(document.visibilityState==="visible"){ wach(); werte_holen(); }
  });
  wach();
  document.addEventListener("click", wach, {once:true});
}

if (KONF.vollbild) {
  /* Vollbild erst nach der ersten Beruehrung - Browser lassen es anders nicht zu. */
  document.addEventListener("click", function ersteBeruehrung(){
    document.removeEventListener("click", ersteBeruehrung);
    var el = document.documentElement;
    if(el.requestFullscreen) el.requestFullscreen().catch(function(){});
  }, {once:true});
}

zeichnen();
stand_zeigen({ok:DATEN.ok, weg:DATEN.weg, alter:DATEN.alter});
schleier_setzen();
if (KONF.sse) { strom_starten(); } else { abfrage_starten(); }
</script>

<?php } ?>
</body>
</html>
