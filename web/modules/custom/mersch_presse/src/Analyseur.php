<?php

declare(strict_types=1);

namespace Drupal\mersch_presse;

/**
 * Lit un flux RSS 2.0 ou Atom et filtre ses articles par mots-clés.
 *
 * Sans dépendance à Drupal, pour être testé seul (tests/src/Unit).
 */
final class Analyseur {

  /**
   * Articles d'un flux : liste de { titre, lien, extrait, date, media }.
   *
   * @param string $xml
   *   Contenu du flux.
   * @param string $media
   *   Nom du média de la source ; pour Google Actualités, le média indiqué
   *   par Google pour chaque article le remplace.
   * @param int $maintenant
   *   Heure actuelle : une date absente ou dans le futur la prend.
   *
   * @throws \RuntimeException
   *   Si le contenu n'est pas un flux lisible.
   */
  public static function articles(string $xml, string $media, int $maintenant, int $longueur = 300): array {
    $precedent = libxml_use_internal_errors(TRUE);
    // LIBXML_NONET : aucune ressource externe n'est chargée.
    $flux = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($precedent);
    if ($flux === FALSE) {
      throw new \RuntimeException('Flux illisible');
    }

    $articles = [];
    if (isset($flux->channel->item)) {
      // RSS 2.0.
      foreach ($flux->channel->item as $item) {
        $source = trim((string) $item->source);
        $titre = self::texte((string) $item->title);
        // Google Actualités termine le titre par « - Média ».
        if ($source !== '' && str_ends_with($titre, ' - ' . $source)) {
          $titre = substr($titre, 0, -strlen(' - ' . $source));
        }
        $articles[] = [
          'titre' => $titre,
          'lien' => trim((string) $item->link),
          // Chez Google, la description ne répète que le titre : inutile.
          'extrait' => $source !== '' ? '' : self::extrait((string) $item->description, $longueur),
          'date' => self::date((string) $item->pubDate, $maintenant),
          'media' => $source !== '' ? $source : $media,
          // Site du média d'origine (Google Actualités), sinon vide.
          'site' => $source !== '' ? trim((string) $item->source['url']) : '',
        ];
      }
    }
    elseif (isset($flux->entry)) {
      // Atom.
      foreach ($flux->entry as $entree) {
        $lien = '';
        foreach ($entree->link as $l) {
          $rel = (string) $l['rel'];
          if ($rel === '' || $rel === 'alternate') {
            $lien = (string) $l['href'];
            break;
          }
        }
        $articles[] = [
          'titre' => self::texte((string) $entree->title),
          'lien' => trim($lien),
          'extrait' => self::extrait((string) $entree->summary !== '' ? (string) $entree->summary : (string) $entree->content, $longueur),
          'date' => self::date((string) $entree->published !== '' ? (string) $entree->published : (string) $entree->updated, $maintenant),
          'media' => $media,
          'site' => '',
        ];
      }
    }
    else {
      throw new \RuntimeException('Ni RSS ni Atom');
    }

    return array_values(array_filter($articles, fn (array $a) => $a['titre'] !== '' && preg_match('#^https?://#i', $a['lien'])));
  }

  /**
   * L'article parle-t-il de la commune ?
   *
   * Vrai si le titre ou l'extrait contient un mot qui commence par l'un des
   * mots-clés (« Merscher » est trouvé par « Mersch »), sans tenir compte des
   * majuscules.
   */
  public static function concerne(array $article, array $motsCles): bool {
    $motsCles = array_filter(array_map('trim', $motsCles), 'strlen');
    if (!$motsCles) {
      return FALSE;
    }
    $motif = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn ($m) => preg_quote($m, '/'), $motsCles)) . ')/iu';
    return (bool) preg_match($motif, $article['titre'] . ' ' . $article['extrait']);
  }

  /**
   * Le site d'un article Google Actualités est-il accepté ?
   *
   * Vrai si le nom d'hôte du site se termine par l'une des terminaisons
   * (« .lu » garde tous les médias luxembourgeois).
   */
  public static function siteAccepte(string $site, array $terminaisons): bool {
    $hote = strtolower((string) parse_url($site, PHP_URL_HOST));
    if ($hote === '') {
      return FALSE;
    }
    foreach ($terminaisons as $terminaison) {
      $terminaison = strtolower(trim($terminaison));
      if ($terminaison !== '' && ($hote === ltrim($terminaison, '.') || str_ends_with($hote, '.' . ltrim($terminaison, '.')))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Le lien de l'article est-il exclu (rubrique internationale, etc.) ?
   */
  public static function lienExclu(string $lien, array $exclusions): bool {
    $chemin = strtolower((string) parse_url($lien, PHP_URL_PATH));
    foreach ($exclusions as $exclusion) {
      $exclusion = strtolower(trim($exclusion));
      if ($exclusion !== '' && str_contains($chemin, $exclusion)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Empreinte d'un titre, insensible aux majuscules, accents et ponctuation.
   */
  public static function cleTitre(string $titre): string {
    $titre = mb_strtolower($titre);
    if (function_exists('transliterator_transliterate')) {
      $titre = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $titre);
    }
    return sha1((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $titre));
  }

  /**
   * Texte brut : sans balises, entités décodées, espaces resserrés.
   */
  private static function texte(string $html): string {
    $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $texte));
  }

  /**
   * Extrait de $longueur caractères au plus, coupé à un mot.
   */
  private static function extrait(string $html, int $longueur): string {
    $texte = self::texte($html);
    if (mb_strlen($texte) <= $longueur) {
      return $texte;
    }
    $coupe = mb_substr($texte, 0, $longueur - 1);
    $espace = mb_strrpos($coupe, ' ');
    if ($espace !== FALSE && $espace > $longueur / 2) {
      $coupe = mb_substr($coupe, 0, $espace);
    }
    return rtrim($coupe, " ,;:.-") . '…';
  }

  /**
   * Horodatage d'une date de flux ; jamais dans le futur.
   */
  private static function date(string $date, int $maintenant): int {
    $horodatage = $date !== '' ? strtotime($date) : FALSE;
    return ($horodatage === FALSE || $horodatage > $maintenant) ? $maintenant : $horodatage;
  }

}
