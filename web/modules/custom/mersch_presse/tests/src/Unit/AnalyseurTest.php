<?php

declare(strict_types=1);

namespace Drupal\Tests\mersch_presse\Unit;

use Drupal\mersch_presse\Analyseur;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des flux et filtre par mots-clés (US-39).
 */
#[Group('mersch_presse')]
class AnalyseurTest extends TestCase {

  private const MAINTENANT = 1795100000;

  private const MOTS = ['Mersch', 'Miersch', 'Beringen', 'Pëtteng'];

  public function testRss(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>RTL</title>
<item><title>Nei Schoul zu Miersch</title><link>https://www.rtl.lu/a/1</link><pubDate>Thu, 19 Nov 2026 08:00:00 GMT</pubDate><description><![CDATA[<p>D'Gemeng &amp; de Schäffen…</p>]]></description></item>
<item><title>Sans lien</title><description>x</description></item>
<item><title>Futur</title><link>https://www.rtl.lu/a/2</link><pubDate>Thu, 19 Nov 2099 08:00:00 GMT</pubDate></item>
</channel></rss>
XML;
    $articles = Analyseur::articles($xml, 'RTL', self::MAINTENANT);
    $this->assertCount(2, $articles);
    $this->assertSame('Nei Schoul zu Miersch', $articles[0]['titre']);
    $this->assertSame("D'Gemeng & de Schäffen…", $articles[0]['extrait']);
    $this->assertSame('RTL', $articles[0]['media']);
    $this->assertSame(strtotime('2026-11-19 08:00:00 UTC'), $articles[0]['date']);
    $this->assertSame(self::MAINTENANT, $articles[1]['date']);
  }

  public function testGoogle(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
<item><title>Six blessés à Mersch - Virgule.lu</title><link>https://news.google.com/rss/articles/abc</link><pubDate>Sat, 14 Nov 2026 18:08:53 GMT</pubDate><description>&lt;a href="x"&gt;Six blessés&lt;/a&gt;</description><source url="https://www.virgule.lu">Virgule.lu</source></item>
</channel></rss>
XML;
    $article = Analyseur::articles($xml, 'Google', self::MAINTENANT)[0];
    $this->assertSame('Six blessés à Mersch', $article['titre']);
    $this->assertSame('Virgule.lu', $article['media']);
    $this->assertSame('', $article['extrait']);
  }

  public function testAtom(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>X</title>
<entry><title>Beringen feiert</title><link rel="alternate" href="https://ex.lu/b"/><published>2026-11-18T10:00:00+01:00</published><summary>Kurz</summary></entry>
</feed>
XML;
    $article = Analyseur::articles($xml, 'X', self::MAINTENANT)[0];
    $this->assertSame('https://ex.lu/b', $article['lien']);
    $this->assertSame('Kurz', $article['extrait']);
    $this->assertSame(strtotime('2026-11-18T09:00:00Z'), $article['date']);
  }

  public function testIllisible(): void {
    $this->expectException(\RuntimeException::class);
    Analyseur::articles('<html><body>404</body></html>', 'X', self::MAINTENANT);
  }

  public function testExtraitCoupe(): void {
    $long = str_repeat('Merscher Gemengerot ', 40);
    $xml = '<rss><channel><item><title>T</title><link>https://a.lu/1</link><description>' . $long . '</description></item></channel></rss>';
    $extrait = Analyseur::articles($xml, 'X', self::MAINTENANT, 300)[0]['extrait'];
    $this->assertLessThanOrEqual(300, mb_strlen($extrait));
    $this->assertStringEndsWith('…', $extrait);
  }

  public function testConcerne(): void {
    $a = fn (string $titre, string $extrait = '') => ['titre' => $titre, 'extrait' => $extrait];
    $this->assertTrue(Analyseur::concerne($a('Merscher Gemengerot'), self::MOTS));
    $this->assertTrue(Analyseur::concerne($a('Accident', 'à MERSCH hier'), self::MOTS));
    $this->assertTrue(Analyseur::concerne($a('Fest zu Pëtteng'), self::MOTS));
    $this->assertFalse(Analyseur::concerne($a('Ettelbruck'), self::MOTS));
    // Le mot-clé doit commencer un mot.
    $this->assertFalse(Analyseur::concerne($a('Vermersch'), self::MOTS));
    $this->assertFalse(Analyseur::concerne($a('Mersch'), []));
  }

  public function testCleTitre(): void {
    $this->assertSame(Analyseur::cleTitre('Six blessés à Mersch !'), Analyseur::cleTitre('six blesses a mersch'));
    $this->assertNotSame(Analyseur::cleTitre('A'), Analyseur::cleTitre('B'));
  }

}
