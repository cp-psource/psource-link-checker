=== PS Broken Link Checker ===

Plugin URI:  https://github.com/cp-psource/shop/artikel/defekter-link-checker-plugin/
Contributors: DerN3rd
Donate link: https://github.com/cp-psource/piestingtalfunding/unterstuetze-unsere-psource-free-werke/
Tags: links, broken, detect, seo, usability, classicpress-plugin
Requires at least: 4.9
Tested up to: 5.6
Requires PHP: 7.2
Stable tag: 1.0.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Dieses Plugin überwacht Deinen Blog auf defekte Links und teilt Dir mit, ob welche gefunden wurden.

This plugin monitors your blog for broken links and lets you know if any were found.

== Description ==

= Deutsch =

= Eigenschaften =

* Überwacht Links in Deinen Posts, Seiten, Kommentaren, der Blogroll und benutzerdefinierten Feldern (optional).
* Erkennt nicht funktionierende Links, fehlende Bilder und leitet weiter.
* Benachrichtigt Dich entweder über das Dashboard oder per E-Mail.
* Lässt defekte Links in Posts anders angezeigt werden (optional).
* Verhindert, dass Suchmaschinen defekten Links folgen (optional).
* Du kannst Links nach URL, Ankertext usw. suchen und filtern.
* Links können direkt von der Seite des Plugins aus bearbeitet werden, ohne jeden Beitrag manuell zu aktualisieren.
* Sehr konfigurierbar.

= Grundlegende Verwendung =

Nach der Installation analysiert das Plugin Deine Beiträge, Lesezeichen (AKA Blogroll) und andere Inhalte und sucht nach Links. Abhängig von der Größe Deiner Webseite kann dies einige Minuten bis zu einer Stunde oder länger dauern. Wenn die Analyse abgeschlossen ist, überprüft das Plugin jeden Link, um festzustellen, ob er funktioniert. Wie lange dies dauert, hängt wiederum davon ab, wie groß Deine Webseite ist und wie viele Links es gibt. Du kannst den Fortschritt überwachen und verschiedene Optionen für die Linkprüfung unter *Einstellungen -> Link Checker* optimieren.

Die defekten Links, falls vorhanden, werden in einer neuen Registerkarte des WP-Administrationsbereichs angezeigt - *Werkzeuge -> Defekte Links*. Eine Benachrichtigung wird auch im Widget "Defekte Link Checker" im Dashboard angezeigt. Um Speicherplatz zu sparen, kannst Du das Widget geschlossen lassen und so konfigurieren, dass es automatisch erweitert wird, wenn problematische Links erkannt werden. E-Mail-Benachrichtigungen müssen separat aktiviert werden (unter *Einstellungen -> Link Checker*).

Auf der Registerkarte "Defekte Links" wird standardmäßig eine Liste der defekten Links angezeigt, die bisher erkannt wurden. Du kannst jedoch die Links auf dieser Seite verwenden, um Weiterleitungen anzuzeigen oder stattdessen eine Liste aller Links anzuzeigen, die funktionieren oder nicht. Du kannst auch neue Linkfilter erstellen, indem Du eine Suche durchführst und auf die Schaltfläche "Benutzerdefinierten Filter erstellen" klickst. Dies kann beispielsweise verwendet werden, um einen Filter zu erstellen, der nur Kommentarlinks anzeigt.

Jedem Link sind mehrere Aktionen zugeordnet. Sie werden angezeigt, wenn Du mit der Maus über einen der auf der oben genannten Registerkarte aufgeführten Links fährst.

* Mit "URL bearbeiten" kannst Du die URL dieses Links ändern. Wenn der Link an mehr als einer Stelle vorhanden ist (z.B. sowohl in einem Beitrag als auch in der Blogroll), werden alle Vorkommen dieser URL geändert.
* "Verknüpfung aufheben" entfernt den Link, lässt aber den Linktext intakt.
* Mit "Nicht defekt" kannst Du einen "defekten" Link manuell als funktionsfähig markieren. Dies ist nützlich, wenn Du weisst, dass es aufgrund eines Netzwerkfehlers oder eines Fehlers fälschlicherweise als defekt erkannt wurde. Der markierte Link wird weiterhin regelmäßig überprüft, aber das Plugin betrachtet ihn nicht als fehlerhaft, es sei denn, es wird ein neues Ergebnis angezeigt.
* "Verwerfen" verbirgt den Link in den Ansichten "Defekte Links" und "Weiterleitungen". Es wird weiterhin wie gewohnt überprüft und erhält die normalen Linkstile (z.B. einen Strike-Through-Effekt für fehlerhafte Links), wird jedoch erst wieder gemeldet, wenn sich sein Status ändert. Nützlich, wenn Du einen Link als defekt/umgeleitet bestätigst und einfach so lassen möchtest, wie er ist.

Du kannst auch auf den Inhalt der Spalten "Status" oder "Linktext" klicken, um weitere Informationen zum Status der einzelnen Links zu erhalten.

= English =

= Properties =

* Monitors links in your posts, pages, comments, blogroll and custom fields (optional).
* Detects broken links, missing images and redirects.
* Notifies you either via the dashboard or via email.
* Make broken links in posts appear differently (optional).
* Prevents search engines from following broken links (optional).
* You can search and filter links by URL, anchor text, etc.
* Links can be edited directly from the plugin's page without manually updating each post.
* Highly configurable.

= Basic Usage =

Once installed, the plugin analyzes your posts, bookmarks (AKA blogroll), and other content, looking for links. Depending on the size of your website, this can take anywhere from a few minutes to an hour or more. When the analysis is complete, the plugin will check each link to see if it works. Again, how long this takes depends on how big your website is and how many links it has. You can monitor the progress and tweak various link checker options in *Settings -> Link Checker*.

The broken links, if any, will be displayed in a new tab of the WP admin panel - *Tools -> Broken Links*. A notification is also displayed in the Broken Link Checker widget on the dashboard. To save disk space, you can leave the widget closed and configure it to expand automatically when problematic links are detected. E-mail notifications must be activated separately (under *Settings -> Link Checker*).

By default, the Broken Links tab shows a list of broken links that have been detected so far. However, you can use the links on this page to show redirects or see a list of all links that may or may not work instead. You can also create new link filters by doing a search and clicking the "Create Custom Filter" button. For example, this can be used to create a filter that only shows comment links.

Several actions are assigned to each link. They will appear when you hover over any of the links listed in the above tab.

* With "Edit URL" you can change the URL of this link. If the link exists in more than one place (e.g. both in a post and in the blogroll), all occurrences of that URL will be changed.
* "Unlink" removes the link but leaves the link text intact.
* With "Not broken" you can manually mark a "broken" link as working. This is useful when you know it was incorrectly identified as broken due to a network error or an error. The marked link will still be checked regularly, but the plugin will not consider it broken unless a new result is shown.
* Discard hides the link in Broken Links and Redirects views. It will still be checked as usual and given the normal link styles (e.g. a strike-through effect for broken links) but will not be reported again until its status changes. Useful if you want to confirm a broken/redirected link and just leave it as is.

You can also click the contents of the "Status" or "Link Text" columns for more information about the status of each link.

[POWERED BY PSOURCE](https://github.com/cp-psource/psource_kategorien/cp-powersource/)

== Languages ==

* Deutsch: de_DE
* English: en_US

Du kannst uns gerne Deine optimierten .po/.mo Dateien für Deine Muttersprache zukommen lassen. 
Nutze die Möglichkeit dazu auf GitHub oder sende Deine Dateien an: webmaster@n3rds.work

You are welcome to send us your optimized .po/.mo files for your native language. 
Use the opportunity to do so on GitHub or send your files to: webmaster@n3rds.work

== CP PSOURCE ==

= DEUTSCH =

= Finde mehr CP-Powersource =

Wirf einen Blick in unser [PSOURCE Sortiment](https://github.com/cp-psource/psource_kategorien/cp-powersource/) und hole noch mehr aus Deinem ClassicPress!

Halte Dich mit unserem [Newsletter](https://github.com/cp-psource/webmasterservice-n3rdswork-digalize-das-piestingtal/newsletter-management/) über unsere CP-Powersource informiert!

= Unterstütze PSOURCE =

Viele, viele Kaffees konsumieren wir während wir an unseren Plugins und Themes arbeiten.
Wie wärs? Möchtest Du uns mit einer Kaffee-Spende bei der Arbeit an unseren Plugins unterstützen?

Mach eine [Spende per Überweisung oder PayPal](https://github.com/cp-psource/spendenaktionen/unterstuetze-unsere-psource-free-werke/) wir Danken Dir!


= ENGLISH =

= Find more CP-Powersource =

Take a look at our [PSOURCE range](https://github.com/cp-psource/psource_categories/cp-powersource/) and get even more out of your ClassicPress!

Keep yourself informed about our CP-Powersource with our [Newsletter](https://github.com/cp-psource/webmasterservice-n3rdswork-digalize-das-piestingtal/newsletter-management/)!

= Support PSOURCE =

We consume many, many coffees while working on our plugins and themes.
how about Would you like to support us with a coffee donation while working on our plugins?

Make a [donation by bank transfer or PayPal](https://github.com/cp-psource/spenderaktionen/unterstuetze-unsere-psource-free-werke/) we thank you!

== ChangeLog ==

= 1.0.7 =

* Release Psource