<?php

declare(strict_types=1);

namespace Drupal\mersch_dsknet;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Appels en lecture seule (GET) à DSK-API.
 *
 * L'adresse de base contient le préfixe (http://APPS/API/dsknet pour
 * DSK-API 1.6.138.43) ; les chemins s'écrivent sans lui (/pers/12/solde).
 */
class Client {

  public function __construct(
    private readonly ClientInterface $http,
    private readonly Reglages $reglages,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Appelle DSK-API et retourne le champ « data » de la réponse.
   *
   * @param string $chemin
   *   Chemin de l'OpenAPI sans /dsknet/v2, par exemple « /pers/12/solde ».
   * @param array $requete
   *   Paramètres de requête.
   * @param bool $texte
   *   Accepter une réponse en texte brut (/version et /test en DSK-API 1.6).
   *
   * @throws \Drupal\mersch_dsknet\AppelAbsent
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  public function get(string $chemin, array $requete = [], bool $texte = FALSE): mixed {
    // Le journal ne garde que le modèle du chemin (sans n° d'agent).
    $modele = preg_replace('#/\d+(?=/|$)#', '/{n}', $chemin);
    try {
      $reponse = $this->http->request('GET', $this->reglages->base() . $chemin, [
        'query' => $requete,
        'headers' => [
          'Authorization' => 'Bearer ' . $this->jeton(),
          'Accept' => 'application/json',
        ],
        'timeout' => (float) $this->reglages->get('delai'),
        'connect_timeout' => 3,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('DSKnet injoignable (@chemin) : @classe', ['@chemin' => $modele, '@classe' => get_class($e)]);
      throw new DsknetIndisponible("DSKnet injoignable ($modele)");
    }

    $statut = $reponse->getStatusCode();
    if ($statut === 401) {
      $this->logger->error('Jeton DSKnet refusé ou expiré (@chemin) : en demander un nouveau.', ['@chemin' => $modele]);
      throw new DsknetIndisponible("jeton refusé ou expiré ($modele)");
    }
    if ($statut === 403) {
      // Droit manquant au compte du jeton dans DSK : le bloc concerné affiche
      // « non disponible », les autres continuent.
      $this->logger->warning('DSKnet refuse l\'appel @chemin (403) : droit manquant au compte du jeton.', ['@chemin' => $modele]);
      throw new AppelAbsent("appel refusé par DSK (403, droit manquant) : $modele");
    }
    if ($statut === 404) {
      throw new AppelAbsent("appel absent de DSK-API : $modele");
    }
    if ($statut !== 200) {
      $this->logger->error('DSKnet a répondu @statut (@chemin).', ['@statut' => $statut, '@chemin' => $modele]);
      throw new DsknetIndisponible("DSKnet a répondu $statut ($modele)");
    }
    // Retire un éventuel BOM UTF-8 avant de lire le JSON.
    $brut = preg_replace('/^\xEF\xBB\xBF/', '', (string) $reponse->getBody());
    $corps = json_decode(trim($brut), TRUE);
    if (!is_array($corps)) {
      if ($texte) {
        return trim($brut);
      }
      $type = $reponse->getHeaderLine('Content-Type') ?: '?';
      if (str_contains($type, 'html')) {
        // Page HTML (accueil ou erreur du serveur) : l'appel n'existe pas ici.
        throw new AppelAbsent("appel absent de DSK-API (page HTML) : $modele");
      }
      // Le journal ne garde que le type et la taille, jamais le contenu.
      $this->logger->error('Réponse DSKnet illisible (@chemin) : @type, @taille octets.', ['@chemin' => $modele, '@type' => $type, '@taille' => strlen($brut)]);
      $e = new DsknetIndisponible("réponse illisible ($modele) : $type, " . strlen($brut) . ' octets');
      $e->extrait = mb_substr(preg_replace('/\s+/', ' ', $brut), 0, 300);
      throw $e;
    }
    return array_key_exists('data', $corps) ? $corps['data'] : $corps;
  }

  /**
   * Code HTTP, type de contenu et titre HTML d'un appel (diagnostic).
   *
   * Aucune donnée de la réponse n'est retournée, sauf le titre d'une page
   * HTML (page d'erreur ou d'accueil).
   *
   * @return array{0: int, 1: string, 2: string}
   *   Code (0 si injoignable), type de contenu, titre HTML ou ''.
   */
  public function sonde(string $chemin, array $requete = []): array {
    try {
      $reponse = $this->http->request('GET', $this->reglages->base() . $chemin, [
        'query' => $requete,
        'headers' => ['Authorization' => 'Bearer ' . $this->jeton(), 'Accept' => 'application/json'],
        'timeout' => (float) $this->reglages->get('delai'),
        'connect_timeout' => 3,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException | DsknetIndisponible) {
      return [0, '', ''];
    }
    $type = $reponse->getHeaderLine('Content-Type');
    $titre = '';
    if (str_contains($type, 'html') && preg_match('#<title[^>]*>(.*?)</title>#is', (string) $reponse->getBody(), $m)) {
      $titre = mb_substr(trim(html_entity_decode(strip_tags($m[1]))), 0, 80);
    }
    return [$reponse->getStatusCode(), $type, $titre];
  }

  /**
   * Le fichier du jeton est-il lisible par le serveur web ?
   */
  public function jetonLisible(): bool {
    try {
      $this->jeton();
      return TRUE;
    }
    catch (DsknetIndisponible) {
      return FALSE;
    }
  }

  /**
   * Lit le jeton dans le fichier du serveur, à chaque appel (jamais gardé).
   *
   * @throws \Drupal\mersch_dsknet\DsknetIndisponible
   */
  private function jeton(): string {
    $fichier = (string) $this->reglages->get('fichier_jeton');
    $lignes = is_readable($fichier) ? file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : FALSE;
    foreach ($lignes ?: [] as $ligne) {
      if (preg_match('/^\s*(?:export\s+)?DSKNET_TOKEN\s*=\s*(.*)$/', $ligne, $m)) {
        $jeton = trim(trim($m[1]), "\"'");
        if ($jeton !== '' && !preg_match('/[\s\r\n]/', $jeton)) {
          return $jeton;
        }
      }
    }
    $this->logger->error('Jeton DSKnet illisible : fichier @fichier absent, illisible ou sans ligne DSKNET_TOKEN=.', ['@fichier' => $fichier]);
    throw new DsknetIndisponible('jeton illisible');
  }

}
