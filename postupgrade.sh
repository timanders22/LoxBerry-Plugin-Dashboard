#!/bin/bash
# Dashboard-Designer - postupgrade
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung.
#
# Hier ist nichts zu tun - und das ist Absicht.
#
# Bis 0.9.12 rief diese Datei postinstall.sh auf. LoxBerry fuehrt beim Update
# aber ohnehin postinstall aus (Reihenfolge: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot). Der Aufruf hier liess also die ganze
# Installation ein ZWEITES Mal laufen: Selbsttest, Rechtevergabe und die
# vollstaendige Ausgabe samt "<OK> Installation abgeschlossen." standen
# doppelt im Installationsprotokoll. Schaden hat es nicht angerichtet -
# postinstall.sh ist durchgehend mehrfach ausfuehrbar, und die Sicherungen
# sind nach dem ersten Lauf abgeraeumt -, aber ein Protokoll, in dem alles
# zweimal steht, verdeckt beim naechsten Mal die eine Zeile, auf die es
# ankommt.
#
# Was frueher hier stand und WO es jetzt steht:
#   - Konfiguration zurueckspielen        postinstall.sh
#   - Langzeitwerte zurueckholen          postinstall.sh (Gegenstueck zu preupgrade.sh)
#   - Dienst wieder starten, falls er lief postinstall.sh (seit 0.9.13)
exit 0
