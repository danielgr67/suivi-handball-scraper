<?php
declare(strict_types=1);

/**
 * =====================================================================
 * SCRAPER AUTONOME — à exécuter sur GitHub Actions (PAS sur l'hébergement Free)
 * =====================================================================
 * Variables d'environnement attendues (secrets GitHub) :
 *   IMPORT_URL   ex: https://votresite.free.fr/import.php
 *   IMPORT_TOKEN la même valeur que IMPORT_SECRET dans config.php
 * =====================================================================
 */

const USER_AGENT = 'SuiviHandball-SiteClub/1.0 (contact: danielg.1@free.fr)';
const DELAI_ENTRE_REQUETES_SEC = 1;

// Les 4 équipes suivies, leur poule FFHB, et le mot-clé de LEUR club
// (utilisé pour repérer l'adresse de leurs propres matchs) — mêmes
// valeurs que schema.sql.
const EQUIPES = [
    ['categorie' => 'SM1',   'motCle' => 'BETSCHDORF', 'url' => 'https://www.ffhandball.fr/competitions/saison-2026-2027-22/departemental/67-02-2e-division-territoriale-masculins-32548/poule-191123/'],
    ['categorie' => 'SM2',   'motCle' => 'BETSCHDORF', 'url' => 'https://www.ffhandball.fr/competitions/saison-2026-2027-22/departemental/67-04-4e-division-territoriale-masculins-32568/poule-191193/'],
    ['categorie' => 'U13M1', 'motCle' => 'BETSCHDORF', 'url' => 'https://www.ffhandball.fr/competitions/saison-2026-2027-22/departemental/67-10-13-ans-territoriale-masculins-32574/poule-191637/'],
    ['categorie' => 'U17F2', 'motCle' => 'SELTZ',      'url' => 'https://www.ffhandball.fr/competitions/saison-2026-2027-22/departemental/67-15-17-territoriale-feminines-id-cea-32583/poule-191261/'],
];

function telechargerPage(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Accept-Language: fr-FR,fr;q=0.9'],
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($body === false) {
        throw new RuntimeException("Échec cURL sur $url : $err");
    }
    if ($code >= 400) {
        throw new RuntimeException("HTTP $code sur $url");
    }
    return $body;
}

function extraireComposant(string $html, string $nom): ?array
{
    $pattern = "/name='" . preg_quote($nom, '/') . "' attributes=\"(.*?)\"><\/smartfire-component>/s";
    if (!preg_match($pattern, $html, $m)) {
        return null;
    }
    $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $data = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : null;
}

$GLOBALS['cacheAdresses'] = [];

function formaterAdresse(array $equipement): string
{
    $parts = array_filter([
        $equipement['libelle'] ?? null,
        $equipement['rue'] ?? null,
        trim(($equipement['codePostal'] ?? '') . ' ' . ($equipement['ville'] ?? '')),
    ]);
    return implode(', ', $parts);
}

function recupererAdresse(string $baseUrl, array $r): ?string
{
    $equipementId = $r['equipementId'] ?? null;
    $extRencontreId = $r['ext_rencontreId'] ?? null;
    if (!$extRencontreId) {
        return null;
    }
    if ($equipementId && isset($GLOBALS['cacheAdresses'][$equipementId])) {
        return $GLOBALS['cacheAdresses'][$equipementId];
    }

    try {
        $htmlDetail = telechargerPage($baseUrl . "rencontre-{$extRencontreId}/");
        $salleData = extraireComposant($htmlDetail, 'competitions---rencontre-salle');
        $adresse = $salleData && isset($salleData['equipement']) ? formaterAdresse($salleData['equipement']) : null;
    } catch (Throwable $e) {
        $adresse = null;
    }

    if ($equipementId) {
        $GLOBALS['cacheAdresses'][$equipementId] = $adresse;
    }
    sleep(DELAI_ENTRE_REQUETES_SEC);
    return $adresse;
}

/** Le match concerne-t-il le club de CETTE équipe (mot-clé fourni) ? */
function concerneNotreClub(array $r, string $motCle): bool
{
    $noms = ($r['equipe1Libelle'] ?? '') . ' ' . ($r['equipe2Libelle'] ?? '');
    return stripos($noms, $motCle) !== false;
}

function envoyerVersFree(string $categorie, array $classements, array $rencontres): void
{
    $importUrl = getenv('IMPORT_URL');
    $importToken = getenv('IMPORT_TOKEN');
    if (!$importUrl || !$importToken) {
        throw new RuntimeException('IMPORT_URL / IMPORT_TOKEN manquants (secrets GitHub non configurés)');
    }

    $body = json_encode([
        'categorie'   => $categorie,
        'classements' => $classements,
        'rencontres'  => $rencontres,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($importUrl . '?token=' . urlencode($importToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    echo "  → import.php ($categorie) : HTTP $code — $response\n";
    if ($code >= 400) {
        throw new RuntimeException("Échec de l'envoi vers Free pour $categorie (HTTP $code)");
    }
}

function traiterEquipe(array $equipe): void
{
    $categorie = $equipe['categorie'];
    $motCle = $equipe['motCle'];
    $baseUrl = $equipe['url'];
    echo "Équipe $categorie ($motCle)...\n";

    $html = telechargerPage($baseUrl);

    $classementData = extraireComposant($html, 'competitions---mini-classement-or-ads');
    $classements = $classementData['classements'] ?? [];

    $rencontreData = extraireComposant($html, 'competitions---rencontre-list');
    if (!$rencontreData || !isset($rencontreData['poule']['journees'])) {
        throw new RuntimeException("Composant rencontre-list introuvable pour $categorie");
    }
    $journees = json_decode($rencontreData['poule']['journees'], true) ?? [];
    $journeeCouranteAffichee = (int)($rencontreData['selected_numero_journee'] ?? 0);

    $toutesLesRencontres = [];
    foreach ($journees as $j) {
        $numero = (int)$j['journee_numero'];
        if ($numero === $journeeCouranteAffichee) {
            $data = $rencontreData;
        } else {
            $htmlJournee = telechargerPage($baseUrl . "journee-{$numero}/");
            $data = extraireComposant($htmlJournee, 'competitions---rencontre-list');
            sleep(DELAI_ENTRE_REQUETES_SEC);
        }
        foreach ($data['rencontres'] ?? [] as $r) {
            if (concerneNotreClub($r, $motCle)) {
                $r['lieu'] = recupererAdresse($baseUrl, $r);
            }
            $toutesLesRencontres[] = $r;
        }
    }

    envoyerVersFree($categorie, $classements, $toutesLesRencontres);
}

foreach (EQUIPES as $equipe) {
    try {
        traiterEquipe($equipe);
    } catch (Throwable $e) {
        echo "ERREUR (" . $equipe['categorie'] . ") : " . $e->getMessage() . "\n";
    }
    sleep(DELAI_ENTRE_REQUETES_SEC);
}

echo "Terminé.\n";
