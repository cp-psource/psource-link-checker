=== WMS N@W Link Checker ===

Plugin URI:  https://n3rds.work/shop/artikel/defekter-link-checker-plugin/
Contributors: DerN3rd
Donate link: https://n3rds.work/piestingtalfunding/unterstuetze-unsere-psource-free-werke/
Tags: links, broken, detect, seo, usability, 
Requires at least: 4.9
Tested up to: 5.6
Requires PHP: 7.2
Stable tag: 4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Dieses Plugin überwacht Deinen Blog auf defekte Links und teilt Dir mit, ob welche gefunden wurden.

===Eigenschaften===

* Überwacht Links in Deinen Posts, Seiten, Kommentaren, der Blogroll und benutzerdefinierten Feldern (optional).
* Erkennt nicht funktionierende Links, fehlende Bilder und leitet weiter.
* Benachrichtigt Dich entweder über das Dashboard oder per E-Mail.
* Lässt defekte Links in Posts anders angezeigt werden (optional).
* Verhindert, dass Suchmaschinen defekten Links folgen (optional).
* Du kannst Links nach URL, Ankertext usw. suchen und filtern.
* Links können direkt von der Seite des Plugins aus bearbeitet werden, ohne jeden Beitrag manuell zu aktualisieren.
* Sehr konfigurierbar.

===Grundlegende Verwendung===

Nach der Installation analysiert das Plugin Deine Beiträge, Lesezeichen (AKA Blogroll) und andere Inhalte und sucht nach Links. Abhängig von der Größe Deiner Webseite kann dies einige Minuten bis zu einer Stunde oder länger dauern. Wenn die Analyse abgeschlossen ist, überprüft das Plugin jeden Link, um festzustellen, ob er funktioniert. Wie lange dies dauert, hängt wiederum davon ab, wie groß Deine Webseite ist und wie viele Links es gibt. Du kannst den Fortschritt überwachen und verschiedene Optionen für die Linkprüfung unter *Einstellungen -> Link Checker* optimieren.

Die defekten Links, falls vorhanden, werden in einer neuen Registerkarte des WP-Administrationsbereichs angezeigt - *Werkzeuge -> Defekte Links*. Eine Benachrichtigung wird auch im Widget "Defekte Link Checker" im Dashboard angezeigt. Um Speicherplatz zu sparen, kannst Du das Widget geschlossen lassen und so konfigurieren, dass es automatisch erweitert wird, wenn problematische Links erkannt werden. E-Mail-Benachrichtigungen müssen separat aktiviert werden (unter *Einstellungen -> Link Checker*).

Auf der Registerkarte "Defekte Links" wird standardmäßig eine Liste der defekten Links angezeigt, die bisher erkannt wurden. Du kannst jedoch die Links auf dieser Seite verwenden, um Weiterleitungen anzuzeigen oder stattdessen eine Liste aller Links anzuzeigen, die funktionieren oder nicht. Du kannst auch neue Linkfilter erstellen, indem Du eine Suche durchführst und auf die Schaltfläche "Benutzerdefinierten Filter erstellen" klickst. Dies kann beispielsweise verwendet werden, um einen Filter zu erstellen, der nur Kommentarlinks anzeigt.

Jedem Link sind mehrere Aktionen zugeordnet. Sie werden angezeigt, wenn Du mit der Maus über einen der auf der oben genannten Registerkarte aufgeführten Links fährst.

* Mit "URL bearbeiten" kannst Du die URL dieses Links ändern. Wenn der Link an mehr als einer Stelle vorhanden ist (z.B. sowohl in einem Beitrag als auch in der Blogroll), werden alle Vorkommen dieser URL geändert.
* "Verknüpfung aufheben" entfernt den Link, lässt aber den Linktext intakt.
* Mit "Nicht defekt" kannst Du einen "defekten" Link manuell als funktionsfähig markieren. Dies ist nützlich, wenn Du weisst, dass es aufgrund eines Netzwerkfehlers oder eines Fehlers fälschlicherweise als defekt erkannt wurde. Der markierte Link wird weiterhin regelmäßig überprüft, aber das Plugin betrachtet ihn nicht als fehlerhaft, es sei denn, es wird ein neues Ergebnis angezeigt.
* "Verwerfen" verbirgt den Link in den Ansichten "Defekte Links" und "Weiterleitungen". Es wird weiterhin wie gewohnt überprüft und erhält die normalen Linkstile (z.B. einen Strike-Through-Effekt für fehlerhafte Links), wird jedoch erst wieder gemeldet, wenn sich sein Status ändert. Nützlich, wenn Du einen Link als defekt/umgeleitet bestätigst und einfach so lassen möchtest, wie er ist.

Du kannst auch auf den Inhalt der Spalten "Status" oder "Linktext" klicken, um weitere Informationen zum Status der einzelnen Links zu erhalten.