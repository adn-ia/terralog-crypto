<?php
/**
 * relais.php — le seul fichier joignable depuis le web. Tout le reste est derrière.
 *
 * ⚠️ POURQUOI IL EST ICI ET NON DANS `relais/`.
 * Le dossier `relais/` est fermé par son `.htaccess` — il contient les identifiants de la
 * base et la clé de signature. Fermé veut dire fermé : **l'entrée y était refusée elle
 * aussi**, et le relais était injoignable. Constaté au premier déploiement réel, le 25/08.
 * On aurait pu percer une exception pour un seul fichier ; une exception se trompe un jour,
 * un dossier fermé ne se trompe pas. L'entrée vit donc dehors, et n'expose rien : elle ne
 * fait qu'appeler ce qui est derrière.
 *
 * ═══ LA RÈGLE QUI GOUVERNE CE FICHIER ═══
 *
 * **Le même chemin pour tout le monde jusqu'au dernier moment.** Quelle que soit l'action
 * demandée, quel que soit le lien présenté, on exécute **exactement la même requête** avant
 * la moindre décision. Les droits se vérifient ENSUITE, en mémoire, hors base.
 *
 * Pourquoi : si un lien inconnu coûtait une requête et un lien connu deux, le temps de
 * réponse dirait lequel est lequel. Cent essais suffisent à mesurer ça. On énumérerait les
 * liens vivants, on trouverait les maîtres. Le refus doit être **identique en forme, en
 * contenu et en durée** — sans quoi il renseigne.
 *
 * Corollaire tenu à la lettre : **aucune condition avant la vérification du lien**, aucun
 * message qui distingue « action inconnue » de « lien mal formé » de « lien révoqué » de
 * « carnet purgé ». Un seul refus, toujours le même : « Refusé. »
 *
 * ═══ QUI A LE DROIT DE QUOI ═══
 *
 *   relever    un lien vivant suffit — maître ou famille.
 *   commenter  un lien vivant suffit. **C'est le pilier 2** : les proches commentent SANS
 *              compte. Exiger le lien maître ici aurait rendu la famille muette — c'était le
 *              cas dans la première version de ce fichier, corrigé le 25/08 au soir.
 *   abonner    un lien vivant suffit : la famille veut être prévenue, elle aussi.
 *   ecrire     lien maître ET jeton. Publications, documents, retraits.
 *   purger     lien maître ET jeton.
 *   liens      lien maître ET jeton.
 *
 * ═══ CE QUI N'EST PAS ICI, ET N'Y SERA JAMAIS ═══
 *
 * Aucune page, aucun gabarit, aucun message destiné à être lu par un humain. Ce fichier rend
 * du JSON et rien d'autre. Les phrases sont dans l'application, où elles pourront être
 * traduites — un point d'entrée qui se met à parler français est un point d'entrée qu'il
 * faudra traduire deux fois.
 */

require_once __DIR__ . '/relais/base.php';
require_once __DIR__ . '/relais/proprietaire.php';
require_once __DIR__ . '/relais/liens.php';
require_once __DIR__ . '/relais/envoyer.php';   // pour `push_adresse_sure` : une adresse d'abonnement n'est pas de confiance

/** Le recouvrement de la relève. Voir `rel_relever` : c'est lui qui empêche de perdre une ligne. */
const REL_RECOUVREMENT = 2000;

/** Combien de lignes au plus dans une relève, pour qu'une réponse reste une réponse. */
const REL_PLAFOND = 500;

/**
 * Ce qu'un lot pèse au plus. Le plafond de lignes ne suffit pas : deux cents photos font
 * moins de cinq cents lignes et **quarante-huit mégaoctets**, mesurés. Un membre de la
 * famille sur un appareil neuf payait ça d'un coup, avant de voir la première photo.
 */
const REL_LOT_OCTETS = 1500000;

/** Ce qu'une ligne coûte en JSON par-dessus son contenu : guillemets, noms de colonnes. */
const REL_MARGE_LIGNE = 256;

/** La pesée elle-même se borne : sur un gros retard, on ne lit pas un million de `maj`. */
const REL_PESEE_MAX = 4000;

/**
 * Tant que les documents tiennent là-dedans, ils partent ENTIERS dès le premier lot, même
 * s'ils ont été écrits en dernier. L'itinéraire est le squelette : sans lui, les photos qui
 * descendent n'ont nulle part où se poser, et l'écran reste vide jusqu'au dernier lot.
 * Mesuré : 1 134 octets depuis que les en-têtes en sont sortis (ligne 99).
 */
const REL_DOC_DABORD = 200000;

/** Un commentaire ne dépasse pas ça, un nom non plus. Les mêmes bornes que le code d'origine. */
/* ⚠️ 2000 DEPUIS LE 28/08, ET LA COLONNE AVEC. Le commentaire part chiffré ; le plafond
 * comptait encore des caractères en clair contre une colonne qui reçoit du chiffré.
 * Un mot de la famille dépassant ~610 signes était refusé pour une raison invisible. */
const REL_TEXTE_MAX = 2000;
/*
 * ⚠️ DEUX PLAFONDS, DEUX RAISONS — ligne 106, tranchée le 26/08.
 *
 * REL_TEXTE_MAX suit la COLONNE, et c'est la seule règle qui vaille ici : le monter sans
 * toucher la table ferait tronquer par MySQL, en silence, exactement le défaut qu'on passe
 * ses journées à traquer. La colonne est passée à VARCHAR(2000) le 28/08 (`base_ajuster`),
 * et la constante avec — pas pour laisser écrire des romans, mais parce que le texte part
 * CHIFFRÉ : ~610 signes de famille suffisaient à remplir les 1000 d'avant. Le plafond réel
 * offert à la famille reste donc de l'ordre de 1200 signes ; un commentaire n'est pas un
 * journal, et il n'a pas à le devenir.
 *
 * REL_NOTE_MAX est le journal, lui. `publications.note` est un TEXT : 65 535 OCTETS, pas
 * caractères. 12 000 caractères tiennent même si chacun est un emoji à 4 octets (48 000).
 * Aucune migration, aucune colonne touchée. Le mur n'était pas la base, c'était la constante.
 */
const REL_NOTE_MAX = 12000;
/* ⚠️ 255 DEPUIS LE 28/08 : le nom d'un lien de famille part CHIFFRÉ, et le chiffrement
 * allonge le texte d'environ 60 %. À 60, « Maman » passait encore, un prénom composé non —
 * et le refus aurait été nommé « nom_invalide », ce qui n'aurait rien expliqué à personne. */
const REL_NOM_MAX = 255;

/** Le ménage borné, fait à l'écriture puisqu'il n'existe aucune tâche programmée. */
const REL_MENAGE = 200;
const REL_DELAI_EFFACEMENT = 30 * 24 * 3600 * 1000;   // trente jours en millisecondes

/** Une réponse, toujours de la même forme, et toujours avec l'heure du serveur. */
function rel_rendre(array $corps, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, nofollow');
  // L'heure du serveur voyage dans CHAQUE réponse. Le téléphone repart de cette valeur pour
  // sa relève suivante et jamais de son horloge : il traverse des fuseaux, elle ment.
  echo json_encode(['maintenant' => base_maintenant()] + $corps,
                   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** Le seul refus qui existe. Même forme, même contenu, quelle que soit la cause. */
function rel_refus(): never { rel_rendre(['ok' => false, 'erreur' => 'Refusé.'], 403); }

/** Un entier, et rien qui lui ressemble. Une chaîne, un flottant, un signe : refusés. */
function rel_entier($v, int $min = 0, int $max = PHP_INT_MAX): ?int {
  if (is_int($v)) $n = $v;
  elseif (is_string($v) && preg_match('/^\d{1,19}$/', $v)) $n = (int)$v;
  else return null;
  return ($n >= $min && $n <= $max) ? $n : null;
}

/** Ce que la requête apporte. Du JSON si elle en apporte, sinon les champs ordinaires. */
function rel_entree(): array {
  $brut = file_get_contents('php://input') ?: '';
  if ($brut !== '') {
    $j = json_decode($brut, true);
    if (is_array($j)) return $j;
  }
  return $_POST;
}

/* ─────────────────────────────────────────────────────────────────────────────
   LES GESTES
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Ce qui a changé depuis un instant.
 *
 * ⚠️ LE PIÈGE QUE PERSONNE NE VOIT VENIR. Si l'on rendait « l'heure d'à présent » et qu'on
 * relevait ensuite `maj > cette heure`, une écriture faite **dans la même milliseconde**,
 * juste après le SELECT, serait perdue **pour toujours** : la relève suivante partirait
 * d'après elle. Une ligne disparue sans erreur, sans trace, et qu'on ne retrouverait qu'en
 * comparant deux téléphones.
 *
 * La parade est un **recouvrement** : on relève deux secondes avant l'instant demandé. Le
 * client revoit donc des lignes qu'il connaît déjà — sans conséquence, puisqu'il les range
 * par identifiant et qu'écrire deux fois la même ligne au même endroit ne change rien.
 * **Mieux vaut renvoyer deux fois que perdre une fois.**
 */
/**
 * ⚠️ LE BUDGET NE TRAVERSE PAS. Décision de Helmy, 26/08 : « le budget ne peut pas être
 * transféré. » Il partait à la famille sans que personne ne l'ait décidé — le relais rendait
 * TOUS les documents à n'importe quel lien valide, et le budget en est un.
 *
 * On coupe à la SOURCE, pas à l'affichage : cacher le bouton laisserait la donnée arriver
 * dans l'appareil de la famille, où il suffit de savoir regarder. Ce qui ne doit pas être vu
 * ne doit pas être envoyé.
 *
 * `$prouve` est la même preuve que partout ailleurs : lien maître ET jeton de propriétaire.
 */
function rel_relever(PDO $pdo, string $carnet, int $depuis, bool $prouve = false, string $scope = ''): array {
  // Le fragment s'ajoute à TOUTES les requêtes sur `documents` — la pesée, la repesée, et le
  // service. En oublier une seule laisserait passer soit la donnée, soit un poids qui ment.
  $sauf_budget = $prouve ? '' : " AND nom <> 'budget'";
  // Le recouvrement ne sert qu'AU BORD DU DIRECT. Il protège d'une écriture tombant dans la
  // même milliseconde que notre lecture ; or une écriture prend l'heure COURANTE. Si le point
  // de reprise est déjà dans le passé — c'est tout un rattrapage, sauf son dernier tour —
  // aucune écriture ne peut plus y atterrir, et rejouer deux secondes à chaque tour ne fait
  // que renvoyer des photos déjà servies. Mesuré sur un carnet de cent photos : 4 914 702
  // octets avec le recouvrement partout, contre 3 932 808 sans. Un quart du forfait, pour rien.
  $recouvrir = ($depuis >= base_maintenant() - REL_RECOUVREMENT);
  $borne = $recouvrir ? max(0, $depuis - REL_RECOUVREMENT) : $depuis;

  // ── LE TOUR VIDE NE COÛTE RIEN ────────────────────────────────────────────────────────
  // Tout ce qui suit demande `maj > $borne`. Si le plus grand `maj` du carnet est déjà sous
  // cette borne, aucune ligne ne peut répondre : on le dit sans ouvrir une seule table.
  // C'est le cas de plus de neuf tours sur dix. Repère inconnu ⇒ on le bâtit une fois, puis
  // on continue normalement ce tour-ci ; on ne devine pas.
  $repere = rel_repere_lire($carnet);
  if ($repere === null) $repere = rel_repere_batir($pdo, $carnet);
  if ($repere !== null && $repere <= $borne) {
    return ['ok' => true, 'lot' => [], 'jusqu_a' => $depuis, 'complet' => true];
  }

  // ── PASSE COURTE « itineraire » : LE DOCUMENT SEUL, TOUT DE SUITE ────────────────────────
  // 19/09/2026 — mesuré : le suiveur ne voyait départ/routes/titre qu'à ~30 s, parce que le
  // document (léger) partait dans le MÊME lot que toutes les photos (jusqu'à 1,5 Mo), et le
  // client déchiffre tout le lot AVANT d'appliquer le document. On offre donc une passe qui ne
  // renvoie QUE les documents — minuscule, immédiate. On N'AVANCE PAS le curseur (`jusqu_a` =
  // `$depuis`) : la passe complète qui suit rejoue tout depuis le même point, photos comprises.
  if ($scope === 'itineraire') {
    $q = $pdo->prepare("SELECT nom, contenu, maj FROM `documents`
                        WHERE carnet = ? AND maj > ?" . $sauf_budget . " ORDER BY maj");
    $q->execute([$carnet, $borne]);
    $docs = array_map(function ($l) { unset($l['carnet']); return $l; }, $q->fetchAll());
    return ['ok' => true, 'lot' => ['documents' => $docs, 'publications' => [], 'commentaires' => []],
            'jusqu_a' => $depuis, 'complet' => true];
  }

  // Les trois tables, avec DE QUOI SE PÈSE chacune. `commentaires` porte son texte dans
  // `texte`, pas dans `contenu` : peser la mauvaise colonne rendrait un poids nul et
  // ramènerait le défaut qu'on répare ici.
  $tables = [
    ['documents',    'carnet, nom, contenu, maj', 'COALESCE(LENGTH(contenu),0)'],   // filtré par $sauf_budget
    ['publications', '*',                         'CASE WHEN retire THEN 0 ELSE COALESCE(LENGTH(media),0) + COALESCE(LENGTH(vignette),0) END'],
    ['commentaires', '*',                         'COALESCE(LENGTH(texte),0)'],
  ];

  // ── 1. On pèse AVANT de servir ──────────────────────────────────────────────
  // On ne demande ici que `maj` et une longueur : jamais les contenus. Charger les
  // quarante-huit mégaoctets en mémoire pour en jeter quarante-six coûterait presque aussi
  // cher que de les envoyer.
  $poids = []; $poids_doc = 0;
  foreach ($tables as [$table, , $mesure]) {
    $q = $pdo->prepare("SELECT maj, $mesure AS poids FROM `$table`
                        WHERE carnet = ? AND maj > ?" . ($table === 'documents' ? $sauf_budget : '')
                        . " ORDER BY maj LIMIT " . REL_PESEE_MAX);
    $q->execute([$carnet, $borne]);
    foreach ($q->fetchAll() as $l) {
      $p = (int)$l['poids'] + REL_MARGE_LIGNE;
      if ($table === 'documents') { $poids_doc += $p; continue; }   // pesés à part : voir plus bas
      $poids[] = [(int)$l['maj'], $p];
    }
  }
  // Les documents passent DEVANT tant qu'ils sont légers : ils donnent l'itinéraire, donc les
  // étapes où les photos vont se poser. Trop lourds, ils reprennent leur rang dans la file.
  $doc_dabord = ($poids_doc <= REL_DOC_DABORD);
  if (!$doc_dabord) {
    $q = $pdo->prepare("SELECT maj, COALESCE(LENGTH(contenu),0) AS poids FROM `documents`
                        WHERE carnet = ? AND maj > ?" . $sauf_budget
                        . " ORDER BY maj LIMIT " . REL_PESEE_MAX);
    $q->execute([$carnet, $borne]);
    foreach ($q->fetchAll() as $l) $poids[] = [(int)$l['maj'], (int)$l['poids'] + REL_MARGE_LIGNE];
  }
  /* ⚠️ « RIEN À PESER » NE VEUT PAS DIRE « RIEN À SERVIR » — corrigé le 27/08.
   *
   * Ce raccourci sortait dès que `$poids` était vide. Or les documents assez légers pour passer
   * devant sont pesés À PART, dans `$poids_doc`, et ne sont jamais ajoutés à `$poids` (l. 208).
   * **Un carnet dont le seul contenu est son itinéraire sortait donc ici, en rendant un lot
   * vide, alors que le document était bel et bien en base.**
   *
   * Mesuré le 27/08, reproduit deux fois sur deux carnets neufs, et isolé de l'application :
   * l'écriture répondait « ok », la relève rendait zéro document — et **écrire un commentaire
   * faisait apparaître les DEUX d'un coup**, parce que `$poids` cessait alors d'être vide.
   *
   * Le défaut existait avant la dérivation ; elle l'a seulement mis en pleine lumière, un
   * carnet fraîchement adopté ne contenant longtemps QUE son itinéraire. Pour un acheteur,
   * c'était son premier voyage invisible à sa famille jusqu'à ce que quelqu'un commente.
   *
   * ⚠️ ET LA COUPURE DOIT AVANCER, sinon on répare une moitié pour en casser une autre : la
   * borne rendue est la coupure, et sans elle le client redemanderait son itinéraire à CHAQUE
   * tour — toutes les cinq secondes. On peut la porter au dernier document sans risque
   * **précisément parce qu'il n'y a rien d'autre** : la règle qui interdit d'avancer (des
   * photos en attente seraient sautées) ne s'applique qu'à ce qui reste à servir, et ici il ne
   * reste rien. */
  $seulsDocuments = (!$poids && $doc_dabord && $poids_doc > 0);
  if (!$poids && !$seulsDocuments)
    return ['ok' => true, 'lot' => ['documents' => [], 'publications' => [], 'commentaires' => []],
            'jusqu_a' => $depuis, 'complet' => true];
  usort($poids, fn($a, $b) => $a[0] <=> $b[0]);

  // ── 2. La coupure ───────────────────────────────────────────────────────────
  // ⚠️ ON NE COUPE JAMAIS AU MILIEU D'UN MÊME `maj`. Le client repart de `maj > jusqu_a` :
  // s'il restait des lignes portant la borne, il les SAUTERAIT — définitivement, sans erreur
  // et sans trace. Ce défaut existait déjà avec l'ancien plafond de 500 lignes.
  // Deux plafonds, pas un : le poids pour la 4G, le nombre pour que dix mille commentaires
  // — qui ne pèsent rien — ne fassent pas un lot de dix mille lignes.
  $cumul = $doc_dabord ? $poids_doc : 0;      // ce que les documents ont déjà pris au budget
  $lignes_prises = 0; $coupure = $borne; $reste = false;
  $i = 0; $n = count($poids);
  while ($i < $n) {
    $maj = $poids[$i][0]; $groupe = 0; $combien = 0;
    while ($i < $n && $poids[$i][0] === $maj) { $groupe += $poids[$i][1]; $combien++; $i++; }
    if ($cumul + $groupe <= REL_LOT_OCTETS && $lignes_prises + $combien <= REL_PLAFOND) {
      $cumul += $groupe; $lignes_prises += $combien; $coupure = $maj;
    } elseif ($cumul === 0) {
      // Un groupe à lui seul dépasse le plafond : on l'envoie ENTIER, sinon plus rien
      // n'avance jamais. Mieux vaut un lot trop lourd qu'un carnet qui ne s'ouvre pas.
      $coupure = $maj; $reste = ($i < $n); break;
    } else {
      $reste = true; break;                       // ce groupe sera le premier du tour suivant
    }
  }
  if ($i >= $n && $n >= REL_PESEE_MAX) $reste = true;   // la pesée elle-même était bornée
  if ($seulsDocuments) {
    // Rien d'autre n'existe : la coupure peut aller jusqu'au dernier document, sinon le client
    // le redemanderait indéfiniment. Voir l'avertissement plus haut.
    $q = $pdo->prepare("SELECT MAX(maj) FROM `documents` WHERE carnet = ? AND maj > ?" . $sauf_budget);
    $q->execute([$carnet, $borne]);
    $coupure = (int)($q->fetchColumn() ?: $borne);
  }

  // ── 3. Alors seulement, les contenus ────────────────────────────────────────
  $lot = [];
  foreach ($tables as [$table, $colonnes, ]) {
    $sans_plafond = ($table === 'documents' && $doc_dabord);
    $q = $pdo->prepare("SELECT $colonnes FROM `$table`
                        WHERE carnet = ? AND maj > ?" . ($sans_plafond ? "" : " AND maj <= ?")
                        . ($table === 'documents' ? $sauf_budget : '') . " ORDER BY maj");
    $q->execute($sans_plafond ? [$carnet, $borne] : [$carnet, $borne, $coupure]);
    $lignes = $q->fetchAll();
    // Une publication RETIRÉE n'a plus rien à dire que son identifiant. Elle transportait
    // pourtant sa photo entière à chaque relève : sur le carnet de l'auteur, neuf retirées
    // pesaient sept cents kilo-octets renvoyés à chaque ouverture de page, pour rien.
    if ($table === 'publications') foreach ($lignes as &$l) {
      // `pos`, `lat` et `lng` partent avec le reste : une étape retirée n'a plus à dire où
      // elle était. Elles ne coûtent rien en poids — elles coûtent en sens.
      if ((int)($l['retire'] ?? 0)) { $l['vignette'] = null; $l['media'] = null; $l['note'] = ''; $l['titre'] = ''; $l['lieu'] = '';
                                      $l['pos'] = null; $l['lat'] = null; $l['lng'] = null; }
    }
    unset($l);
    // ⚠️ L'IDENTITÉ DU CARNET NE SORT PAS — mesuré le 27/08, ligne 8 du tableau.
    // `liens.php` l. 7 le promet en toutes lettres : « l'identité du carnet : interne. Jamais
    // dans une adresse, JAMAIS RENDUE À UN NAVIGATEUR ». Elle l'était pourtant : `documents`
    // la sélectionnait nommément, et `publications` comme `commentaires` partaient en `*`.
    // Vu depuis un vrai lien de famille en lecture seule : la réponse portait
    // `"carnet":"YBZWoOJn0nMj3ycAIQ3k1jdd"` — les 24 caractères de l'identité interne.
    //
    // On la retire ICI, à la sortie, et non dans les requêtes : peser une table demande ses
    // colonnes, et enlever la mauvaise passerait inaperçu jusqu'au jour où quelque chose
    // manquerait. Le client ne l'a jamais lue — vérifié dans les 36 blocs.
    $lignes = array_map(function ($l) { unset($l['carnet']); return $l; }, $lignes);
    $lot[$table] = $lignes;
  }
  // ⚠️ LA BORNE RENDUE EST LA COUPURE, PAS LE PLUS GRAND `maj` DU LOT. Un document parti
  // devant (voir plus haut) porte un `maj` postérieur à la coupure : le rendre comme borne
  // ferait repartir le client d'APRÈS, et les photos encore en attente seraient SAUTÉES —
  // définitivement. La coupure, elle, est le dernier instant dont TOUT a été servi.
  return ['ok' => true, 'lot' => $lot, 'jusqu_a' => $coupure, 'complet' => !$reste];
}

/**
 * Combien de fois l'hébergeur nous a coupés, jour par jour.
 *
 * Un fichier par jour, et rien d'autre : ça survit à une base pleine, et ça se lit encore
 * quand MySQL est tombé — c'est-à-dire exactement le jour où on veut le lire. Un `LOCK_EX`
 * tenu le temps d'un ajout suffit ; à quatre appels par seconde, il n'y a pas de bousculade.
 *
 * ⚠️ On ne garde AUCUN cumul, et on ne projette rien. Un cumul invite à extrapoler, et une
 * extrapolation ment : un carnet qui subit une longue saturation accumule sans pouvoir
 * transmettre, donc le compteur **masque les pointes** plutôt qu'il ne les exagère. On rend
 * la mesure brute, par jour, et rien de plus.
 */
function rel_noter_charge(int $combien): void {
  if ($combien <= 0 || $combien > 10000) return;
  $dossier = __DIR__ . '/relais/charge';
  if (!is_dir($dossier)) @mkdir($dossier, 0700, true);
  $jour = gmdate('Y-m-d');
  @file_put_contents($dossier . '/' . $jour . '.txt', $combien . "\n", FILE_APPEND | LOCK_EX);
  // Le ménage se fait ici, faute de tâche programmée : soixante jours, pas plus.
  foreach (glob($dossier . '/*.txt') ?: [] as $f) {
    if (basename($f, '.txt') < gmdate('Y-m-d', time() - 60 * 86400)) @unlink($f);
  }
}

/**
 * ═══ LE REPÈRE — pour qu'un tour VIDE ne coûte aucune requête ═══════════════════════════
 *
 * Dix membres d'une famille sondent toutes les 20 s. Plus de 95 % de ces tours n'ont rien à
 * rapporter, et chacun jouait quand même TROIS `SELECT` de pesée. Sur du mutualisé, c'est ce
 * qui plafonne à quatre ou cinq appels par seconde et par carnet.
 *
 * Le repère est le plus grand `maj` connu du carnet, gardé dans un fichier. La relève demande
 * toujours `maj > borne` : si le repère est ≤ borne, AUCUNE ligne ne peut satisfaire la
 * requête, et on répond vide sans toucher la base.
 *
 * ── CE QUI REND LE RACCOURCI SÛR ────────────────────────────────────────────────────────
 * Un repère TROP BAS ferait sauter des écritures — une perte silencieuse, interdite ici. Un
 * repère TROP HAUT ne coûte qu'une requête inutile. Toute la construction penche donc du
 * côté cher :
 *
 *   1. Le repère est haussé AVANT l'écriture SQL, avec le `maj` exact de la ligne à venir.
 *      Si l'écriture échoue ensuite, le repère est en avance : on interroge la base pour
 *      rien. Jamais l'inverse.
 *   2. Si la hausse échoue, on EFFACE le fichier. Un repère absent veut dire « je ne sais
 *      pas » et renvoie au chemin complet. On ne garde jamais un repère dont on doute.
 *   3. La condition est `borne >= repere`, PAS `depuis >= repere`. La nuance décide de tout :
 *      au bord du direct, `borne = depuis - 2 s` pour rattraper l'écriture tombée dans la
 *      même milliseconde que la lecture précédente. Avec `depuis`, cette écriture-là serait
 *      justement celle qu'on saute — le recouvrement existe pour elle.
 *
 * ── CE QUE J'AI REFUSÉ À DEEPSEEK ───────────────────────────────────────────────────────
 * Il proposait un compteur monotone dans le fichier, incrémenté avant l'écriture, et de s'en
 * servir COMME `maj`. Ici `maj` est une horloge en millisecondes que le client nous renvoie
 * comme point de reprise : la remplacer par un compteur casserait la reprise de tous les
 * carnets déjà en service. Le principe — majorer, jamais minorer — est gardé ; le véhicule,
 * non. Il écartait aussi APCu (cache local au processus, vidé sans prévenir) et la table à
 * une ligne (fiable, mais elle coûte la requête qu'on cherche à supprimer) : d'accord sur
 * les deux.
 */
/**
 * PRÉVENIR LE PROPRIÉTAIRE QU'UN COMMENTAIRE EST ARRIVÉ (ligne 13b, quatrième geste).
 *
 * ── POURQUOI PAS AU MOMENT DU COMMENTAIRE ──────────────────────────────────────────────────
 *
 * Poster à un service de push prend de cent millisecondes à plusieurs secondes, et parfois il
 * ne répond pas. Le faire dans la requête qui range le commentaire, c'est faire attendre celle
 * qui écrit — et lui laisser croire que son mot n'est pas parti alors qu'il est déjà en base.
 *
 * ── ET PAS APRÈS LA RÉPONSE NON PLUS ───────────────────────────────────────────────────────
 *
 * `fastcgi_finish_request()` n'existe que sous PHP-FPM ; sur un mutualisé en `mod_php` il n'y
 * est pas, et `register_shutdown_function` s'exécute AVANT que la réponse ne soit close — ça
 * ferait attendre pareil. C'est ce que je refuse à DeepSeek, qui proposait les deux.
 *
 * ── CE QU'ON FAIT : LA FILE, C'EST LA TABLE DES COMMENTAIRES ───────────────────────────────
 *
 * Pas de table d'attente — la base tient en cinq tables « et rien de plus », et une file
 * séparée redirait ce que `commentaires` sait déjà. Un second fichier de repère retient
 * seulement JUSQU'OÙ l'on a prévenu. À la requête suivante sur ce carnet, on regarde ce qui est
 * arrivé depuis, et on prévient. C'est un envoi tiré par le passage de quelqu'un, pas poussé
 * par une horloge : sur un hébergement sans tâche programmée, il n'y a pas d'autre honnêteté.
 *
 * ⚠️ LA LIMITE, ET ELLE DOIT ÊTRE DITE : une notification part **au plus tôt deux minutes**
 * après le commentaire, et **seulement si quelqu'un touche le relais**. Un carnet que personne
 * ne consulte ne prévient personne.
 *
 * ── CE QU'ON MET DEDANS : RIEN DU TEXTE ────────────────────────────────────────────────────
 *
 * Le message traverse Google ou Apple. On n'y met donc **pas une ligne** de ce qu'a écrit la
 * famille : un nombre, et rien d'autre. Une notification est un signal, pas un contenant.
 */
const REL_AVIS_FENETRE = 120000;   // deux minutes : dix commentaires d'affilée n'en font qu'un
const REL_AVIS_MAX     = 20;       // on ne poste jamais plus de vingt fois dans une requête

/**
 * LES FICHIERS QUE PLUS PERSONNE NE DÉSIGNE (ligne 14b).
 *
 * `dep_menage()` (`depot.php` l. 71) balaie déjà les MORCEAUX abandonnés en route. Reste
 * l'autre résidu : un fichier recollé et renommé dans `depot/`, dont la publication n'a jamais
 * été écrite — l'appel suivant a échoué, la personne a fermé l'onglet. Plus rien ne le
 * désigne, et rien ne l'efface : il reste là pour toujours.
 *
 * ── CE QU'ON NE FAIT PAS ───────────────────────────────────────────────────────────────────
 *
 * **Pas de table de registre.** DeepSeek en proposait deux — une pour inscrire chaque fichier,
 * une pour la file de suppression. La base tient en cinq tables « et rien de plus », et
 * `publications.media` sait déjà quels fichiers sont vivants. **Pas de `SELECT … FOR UPDATE`**
 * non plus : un verrou de ligne MySQL ne protège pas un système de fichiers, et croire qu'il
 * rend la suppression atomique est un rite, pas une garantie.
 *
 * ── LES TROIS GARDES, ET IL EN FAUT TROIS ──────────────────────────────────────────────────
 *
 *   1. **Assez vieux** — trois jours. Un envoi lent sur un réseau de montagne ne se fait pas
 *      détruire ; un fichier de trois jours qui n'intéresse personne, si.
 *   2. **Personne ne le désigne** — la base est interrogée nom par nom, jamais en bloc.
 *   3. **Pas touché depuis** — on relit la date de modification JUSTE avant d'effacer. C'est la
 *      seule protection réelle contre une écriture concurrente, et elle ne demande aucun verrou.
 *
 * ── ET ON NE BALAIE PAS À CHAQUE REQUÊTE ───────────────────────────────────────────────────
 *
 * Le dossier public peut porter des milliers de fichiers : le parcourir pour servir une relève
 * serait absurde. Un fichier de repère retient le dernier balayage ; on n'y revient qu'au bout
 * de six heures, et on n'examine jamais plus de trois cents entrées d'un coup.
 */
const REL_BALAI_REPOS   = 6 * 3600;        // six heures entre deux balayages
const REL_BALAI_AGE     = 3 * 24 * 3600;   // un fichier de moins de trois jours ne se touche pas
const REL_BALAI_MAX     = 300;             // jamais plus de trois cents entrées par passage

function rel_balayer_depot(PDO $pdo): void {
  $dossier = __DIR__ . '/depot';
  if (!is_dir($dossier)) return;
  $marque = __DIR__ . '/relais/reperes/depot.balai';
  $dernier = (int)@file_get_contents($marque);
  if (time() - $dernier < REL_BALAI_REPOS) return;
  @file_put_contents($marque, (string)time(), LOCK_EX);   // avant, pas après : un échec ne se rejoue pas en boucle

  $d = @opendir($dossier);
  if (!$d) return;
  $limite = time() - REL_BALAI_AGE;
  $vus = 0;
  $q = $pdo->prepare('SELECT 1 FROM publications WHERE media = ? LIMIT 1');
  while (($nom = readdir($d)) !== false && $vus < REL_BALAI_MAX) {
    if ($nom === '.' || $nom === '..' || $nom[0] === '.') continue;
    $chemin = $dossier . '/' . $nom;
    if (!is_file($chemin)) continue;
    $vus++;
    clearstatcache(true, $chemin);
    $quand = @filemtime($chemin);
    if ($quand === false || $quand > $limite) continue;      // garde 1 : trop jeune
    $q->execute(['depot/' . $nom]);
    if ($q->fetchColumn()) continue;                         // garde 2 : quelqu'un le désigne
    clearstatcache(true, $chemin);
    if (@filemtime($chemin) !== $quand) continue;            // garde 3 : il a bougé entre-temps
    @unlink($chemin);
  }
  closedir($d);
}

function rel_avis_fichier(string $carnet): ?string {
  $f = rel_repere_fichier($carnet);
  return $f === null ? null : substr($f, 0, -4) . '.avis';
}

function rel_prevenir(PDO $pdo, string $carnet): void {
  $f = rel_avis_fichier($carnet);
  if ($f === null || !is_file(__DIR__ . '/relais/push.php')) return;
  $depuis = (int)@file_get_contents($f);
  $borne  = base_maintenant() - REL_AVIS_FENETRE;
  if ($borne <= $depuis) return;                       // rien d'assez mûr : on ne fait rien

  $q = $pdo->prepare('SELECT COUNT(*) FROM commentaires
                      WHERE carnet = ? AND retire = 0 AND cree > ? AND cree <= ?');
  $q->execute([$carnet, $depuis, $borne]);
  $combien = (int)$q->fetchColumn();
  // On avance le repère MÊME S'IL N'Y A RIEN : sans cela on rejouerait la même requête à
  // chaque passage, pour toujours, sur les carnets les plus calmes.
  @file_put_contents($f, (string)$borne, LOCK_EX);
  if ($combien < 1) return;

  require_once __DIR__ . '/relais/push.php';
  require_once __DIR__ . '/relais/envoyer.php';
  if (!defined('PUSH_PEM') || !defined('PUSH_PUBLIQUE')) return;
  $cles = ['pem' => PUSH_PEM, 'publique' => PUSH_PUBLIQUE, 'sujet' => PUSH_SUJET];

  $a = $pdo->prepare('SELECT adresse, point, secret, echecs FROM abonnements WHERE carnet = ? LIMIT ' . REL_AVIS_MAX);
  $a->execute([$carnet]);
  foreach ($a->fetchAll() as $ab) {
    $message = json_encode(['n' => $combien, 'url' => './'], JSON_UNESCAPED_UNICODE);
    $v = push_envoyer($ab, $message, $cles, (int)$ab['echecs']);
    // ⚠️ ON N'EFFACE QUE SUR « effacer ». Un 500 ou un 503 veut dire que le service a eu une
    // mauvaise minute, pas que l'abonnement est mort : effacer là-dessus détruirait un
    // abonnement valable. C'est ce que je refuse à DeepSeek, qui écrivait « 404, 410, etc. :
    // idem, on efface » — et se contredisait au paragraphe suivant.
    // if (($v['verdict'] ?? '') === 'effacer') {
    //   $pdo->prepare('DELETE FROM abonnements WHERE carnet = ? AND adresse = ?')
    //       ->execute([$carnet, $ab['adresse']]);
    // } elseif (($v['code'] ?? 0) >= 200 && ($v['code'] ?? 0) < 300) {
    //   $pdo->prepare('UPDATE abonnements SET echecs = 0 WHERE carnet = ? AND adresse = ?')
    //       ->execute([$carnet, $ab['adresse']]);
    // } else {
    //   $pdo->prepare('UPDATE abonnements SET echecs = echecs + 1 WHERE carnet = ? AND adresse = ?')
    //       ->execute([$carnet, $ab['adresse']]);
    // }
    /* ✚ 09/11/2026 — 429 ET 413 NE COMPTENT PLUS (Helmy, DeepSeek). Un 429, c'est NOUS qui
     * parlons trop (jusqu'à REL_AVIS_MAX envois d'un coup), et le délai qu'il demande n'est
     * gardé nulle part : le compter effaçait les proches d'un carnet après quelques rafales. Un
     * 413 (`trop_gros`), c'est NOTRE message qui dépasse. Ni l'un ni l'autre ne dit que
     * l'appareil est mort. Le reste ne change pas : définitif ⇒ effacé au premier ; passager
     * ⇒ compté, et le ménage efface au cinquième d'affilée (rel_menage). */
    $verdict = (string)($v['verdict'] ?? '');
    $code    = (int)($v['code'] ?? 0);
    if ($verdict === 'effacer') {
      $pdo->prepare('DELETE FROM abonnements WHERE carnet = ? AND adresse = ?')
          ->execute([$carnet, $ab['adresse']]);
    } elseif ($code >= 200 && $code < 300) {
      $pdo->prepare('UPDATE abonnements SET echecs = 0 WHERE carnet = ? AND adresse = ?')
          ->execute([$carnet, $ab['adresse']]);
    } elseif ($verdict === 'trop_gros' || $verdict === 'jeton' || $verdict === 'redirige' || $code === 429) {
      // rien : la faute est de notre côté (message, débit, signature VAPID refusée en 401/403),
      // ou un renvoi que push_expliquer dit déjà « non compté » — rien ne dit que l'appareil est mort
    } else {
      $pdo->prepare('UPDATE abonnements SET echecs = echecs + 1 WHERE carnet = ? AND adresse = ?')
          ->execute([$carnet, $ab['adresse']]);
    }
  }
}

function rel_repere_fichier(string $carnet): ?string {
  // Un identifiant de carnet entre dans un CHEMIN : on ne fait confiance à rien.
  if (!preg_match('/^[A-Za-z0-9_-]{1,24}$/', $carnet)) return null;
  $dossier = __DIR__ . '/relais/reperes';
  if (!is_dir($dossier) && !@mkdir($dossier, 0700, true) && !is_dir($dossier)) return null;
  return $dossier . '/' . $carnet . '.txt';
}

/** Hausse le repère à au moins $maj. À APPELER AVANT l'écriture SQL, jamais après. */
function rel_repere_hausser(string $carnet, int $maj): void {
  $f = rel_repere_fichier($carnet);
  if ($f === null) return;
  $fp = @fopen($f, 'c+');
  if (!$fp) { @unlink($f); return; }               // on doute ⇒ pas de repère du tout
  if (!@flock($fp, LOCK_EX)) { fclose($fp); @unlink($f); return; }
  // ⚠️ LE FICHIER VIDE — trouvé le 26/08 en REGARDANT le serveur, pas le code.
  // `fopen('c+')` crée le fichier ; si l'on n'écrit que quand `$maj > $vu`, un carnet neuf
  // (max = 0) repart avec un fichier de zéro octet. `rel_repere_lire` le juge « inconnu », et
  // le relais rebâtit le repère à CHAQUE tour : une requête de plus, pour toujours, sur les
  // carnets les plus vides. On écrit donc aussi quand le fichier n'a rien à dire.
  $brut = stream_get_contents($fp);
  $vu   = (int)$brut;
  if (trim($brut) === '' || $maj > $vu) {
    rewind($fp); ftruncate($fp, 0); fwrite($fp, (string)max($maj, $vu)); fflush($fp);
  }
  flock($fp, LOCK_UN); fclose($fp);
}

/** Le repère, ou `null` quand on ne sait pas — et « je ne sais pas » n'autorise aucun raccourci. */
function rel_repere_lire(string $carnet): ?int {
  $f = rel_repere_fichier($carnet);
  if ($f === null || !is_file($f)) return null;
  $v = @file_get_contents($f);
  // Un fichier VIDE veut dire « je ne sais pas » et interdit le raccourci ; un fichier qui
  // contient « 0 » veut dire « ce carnet n'a jamais rien reçu », et c'est un savoir.
  return ($v === false || trim($v) === '') ? null : (int)$v;
}

/**
 * Reconstruit le repère quand le fichier manque — première relève, fichier effacé, carnet
 * d'avant cette mécanique. Une requête, une seule fois, puis plus jamais.
 */
function rel_repere_batir(PDO $pdo, string $carnet): ?int {
  try {
    $q = $pdo->prepare("SELECT MAX(m) AS m FROM (
                          SELECT MAX(maj) AS m FROM documents    WHERE carnet = ?
                          UNION ALL SELECT MAX(maj) FROM publications WHERE carnet = ?
                          UNION ALL SELECT MAX(maj) FROM commentaires WHERE carnet = ?
                        ) t");
    $q->execute([$carnet, $carnet, $carnet]);
    $m = (int)($q->fetchColumn() ?: 0);
  } catch (PDOException $e) { return null; }
  rel_repere_hausser($carnet, $m);
  return $m;
}

/**
 * L'INVENTAIRE DE CE QUE CE CARNET A DÉPOSÉ — et surtout de ce qui MANQUE.
 *
 * On ne rend jamais le contenu, seulement des poids et des états. Un fichier absent alors
 * qu'une ligne le désigne, c'est une photo publiée que personne ne peut voir : le genre de
 * défaut qui ne se signale pas tout seul et qu'on découvre six mois trop tard.
 */
function rel_tracer_depot(PDO $pdo, string $carnet): array {
  $q = $pdo->prepare('SELECT id, media, genre, cree, retire FROM publications
                      WHERE carnet = ? AND media IS NOT NULL ORDER BY cree DESC LIMIT 500');
  $q->execute([$carnet]);
  $vus = 0; $poids = 0; $absents = []; $retires = 0; $genres = [];
  foreach ($q->fetchAll() as $l) {
    $chemin = (string)$l['media'];
    $genre  = (string)($l['genre'] ?: 'image');
    $genres[$genre] = ($genres[$genre] ?? 0) + 1;
    if ((int)$l['retire']) $retires++;
    $sur = __DIR__ . '/' . $chemin;
    if (preg_match('~^depot/[0-9a-f]{32}\.[a-z0-9]{2,5}$~', $chemin) && is_file($sur)) {
      $vus++; $poids += (int)filesize($sur);
    } else {
      $absents[] = ['id' => $l['id'], 'chemin' => $chemin, 'genre' => $genre,
                    'quand' => (int)$l['cree'], 'retire' => (int)$l['retire']];
    }
  }
  return ['fichiers' => $vus, 'octets' => $poids, 'retires' => $retires,
          'genres' => $genres, 'absents' => array_slice($absents, 0, 50),
          'absents_total' => count($absents)];
}

/** Ce qu'on montre au propriétaire : un jour, un nombre. Aucune moyenne, aucune prédiction. */
function rel_lire_charge(): array {
  $dossier = __DIR__ . '/relais/charge';
  $out = [];
  foreach (glob($dossier . '/*.txt') ?: [] as $f) {
    $n = 0;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) $n += (int)$l;
    $out[basename($f, '.txt')] = $n;
  }
  krsort($out);
  return array_slice($out, 0, 60, true);
}

/**
 * Le ménage. Il n'y a **aucune tâche programmée** sur ce serveur : le ménage se fait donc à
 * l'écriture, borné, et jamais à la lecture — personne n'attendra qu'on range pour lire.
 */
/**
 * ⚠️ UN FICHIER DÉPOSÉ NE S'EFFACE PAS TOUT SEUL — trouvé le 26/08, en REGARDANT le dossier
 * après avoir retiré deux vidéos d'essai : les publications avaient disparu de la base, les
 * fichiers étaient toujours là. Sans ceci, le disque se remplit d'orphelins et la promesse
 * « on sait où est la donnée » (pilier 3) serait fausse.
 *
 * On efface au moment où la LIGNE disparaît pour de bon, pas au moment du retrait : le
 * retrait laisse trente jours (`REL_DELAI_EFFACEMENT`), et supprimer le fichier plus tôt
 * rendrait ce délai mensonger.
 *
 * Le chemin vient de NOTRE base, mais on ne lui fait pas confiance pour autant : on n'efface
 * que dans `depot/`, et seulement un nom qui a la forme que `depot.php` fabrique.
 */
function rel_effacer_fichier(?string $chemin): void {
  if (!is_string($chemin) || $chemin === '') return;
  if (!preg_match('~^depot/[0-9a-f]{32}\.[a-z0-9]{2,5}$~', $chemin)) return;
  @unlink(__DIR__ . '/' . $chemin);
}

function rel_menage(PDO $pdo, string $carnet): void {
  $avant = base_maintenant() - REL_DELAI_EFFACEMENT;
  // On lit les chemins AVANT de supprimer les lignes : après, plus personne ne sait où
  // vivaient ces fichiers, et ils resteraient là pour toujours.
  $q = $pdo->prepare("SELECT media FROM publications
                      WHERE carnet = ? AND retire = 1 AND maj < ? AND media IS NOT NULL
                      LIMIT " . REL_MENAGE);
  $q->execute([$carnet, $avant]);
  foreach ($q->fetchAll() as $l) rel_effacer_fichier($l['media']);

  foreach (['publications', 'commentaires'] as $t) {
    $pdo->prepare("DELETE FROM `$t` WHERE carnet = ? AND retire = 1 AND maj < ? LIMIT " . REL_MENAGE)
        ->execute([$carnet, $avant]);
  }
  $pdo->prepare('DELETE FROM abonnements WHERE carnet = ? AND echecs >= 5 LIMIT 50')->execute([$carnet]);
}

/**
 * Tout reprendre. Le téléphone garde son contenu ; c'est le serveur qu'on vide.
 *
 * ⚠️ ON N'EFFACE QUE CE QUI A ÉTÉ SAUVEGARDÉ. L'appelant doit présenter l'`horloge` que
 * `export.php` a inscrite dans le manifeste de l'archive. Si le carnet a bougé depuis — un
 * commentaire de la famille arrivé pendant que la personne rangeait son fichier, une photo
 * publiée d'un autre appareil — cette horloge ne correspond plus, et **on refuse**.
 *
 * C'est la condition que DeepSeek posait, et elle est juste : sans état figé, la purge peut
 * effacer ce que l'export n'a pas vu. Un refus se répare en refaisant l'export ; une perte,
 * non.
 */
/* ⚠️ L'ARCHIVE EST PROPOSÉE, ELLE N'EST PLUS EXIGÉE — 28/08, décision de Helmy : « déjà une
 * que je comprends : l'archivage n'est pas exigé, il est proposé, jamais exigé ».
 *
 * Le verrou d'origine refusait toute purge sans horloge (`export_exige`) : on ne pouvait donc
 * pas effacer sans avoir d'abord téléchargé. L'intention était bonne — protéger d'une perte
 * irréversible — mais une revue contradictoire du même jour l'a montré intenable : **le droit
 * à l'effacement de l'article 17 ne se conditionne pas.** Une précaution ne se troque pas
 * contre un droit.
 *
 * Le contrôle d'horloge, lui, RESTE quand une horloge est fournie : il protège le chemin
 * « archive puis purge » d'un carnet qui aurait bougé entre les deux. Ce qui change, c'est
 * qu'on ne l'exige plus. */
function rel_purger(PDO $pdo, string $carnet, ?int $horloge = null): array {
  if ($horloge === null) return rel_purger_vraiment($pdo, $carnet);
  $q = $pdo->prepare("SELECT MAX(m) FROM (
        SELECT MAX(maj) AS m FROM documents    WHERE carnet = ?
        UNION ALL SELECT MAX(maj) FROM publications WHERE carnet = ?
        UNION ALL SELECT MAX(maj) FROM commentaires WHERE carnet = ?) t");
  $q->execute([$carnet, $carnet, $carnet]);
  $maintenant = (int)($q->fetchColumn() ?: 0);
  if ($maintenant !== $horloge) {
    return ['ok' => false, 'motif' => 'carnet_a_bouge',
            'attendu' => $horloge, 'trouve' => $maintenant];
  }
  return rel_purger_vraiment($pdo, $carnet);
}

// function rel_purger_vraiment(PDO $pdo, string $carnet): array {
//   // Purger, c'est TOUT reprendre. Les fichiers déposés partent avec le reste — sinon la
//   // personne croit avoir tout effacé et ses photos dorment encore sur le disque.
//   try {
//     $q = $pdo->prepare('SELECT media FROM publications WHERE carnet = ? AND media IS NOT NULL');
//     $q->execute([$carnet]);
//     foreach ($q->fetchAll() as $l) rel_effacer_fichier($l['media']);
//   } catch (Throwable $e) { /* la base décide, pas le disque : on continue la purge */ }
//
//   $pdo->beginTransaction();
//   try {
//     foreach (['abonnements', 'commentaires', 'publications', 'documents'] as $t)
//       $pdo->prepare("DELETE FROM `$t` WHERE carnet = ?")->execute([$carnet]);
//     // Les liens de famille meurent : ce qu'ils ouvraient n'existe plus. Le maître survit,
//     // sinon le propriétaire perdrait l'accès à son propre carnet vidé.
//     //
//     /* 🔴 DEUX TEXTES EN CLAIR SURVIVAIENT À LA PURGE — trouvé le 28/08 en vérifiant
//      * l'affirmation « la ligne ne permet pas d'identifier la personne ». Elle était presque
//      * vraie, et « presque » ne suffit pas ici :
//      *   · `carnets.titre` gardait le NOM DU VOYAGE. « Voyage de Marie et Paul » identifie deux
//      *     personnes, et la purge n'y touchait pas ;
//      *   · `liens.nom` gardait le PRÉNOM donné à chaque lien de famille — « Maman », « Yvonne ».
//      *     Révoquer un lien coupait son usage, pas son étiquette.
//      * La politique de confidentialité, elle, écrivait que la ligne subsistait « vide ». C'était
//      * faux. On vide donc pour de bon : après une purge, il ne reste qu'un identifiant tiré au
//      * sort, des empreintes, et des dates. Plus un seul mot écrit par un humain. */
//     $pdo->prepare('UPDATE liens SET revoque = ?, nom = ? WHERE carnet = ? AND role = ? AND revoque = 0')
//         ->execute([base_maintenant(), '', $carnet, 'famille']);
//     $pdo->prepare("UPDATE liens SET nom = '' WHERE carnet = ?")->execute([$carnet]);
//     $pdo->prepare('UPDATE carnets SET titre = ?, purgee = ?, maj = ? WHERE id = ?')
//         ->execute(['', base_maintenant(), base_maintenant(), $carnet]);
//     $pdo->commit();
//   } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
//   return ['ok' => true, 'purge' => true];
// }
/* ✚ 09/11/2026 — ANCIENNE PURGE CI-DESSUS (gardait des dates et les liens révoqués). */
function rel_purger_vraiment(PDO $pdo, string $carnet): array {
  /* ✚ 09/11/2026 — LA PIERRE TOMBALE (Helmy, choix « B », principe n° 1 ; DeepSeek).
   * Après une purge, il ne reste QUE ce qui empêche quelqu'un d'autre de reprendre l'adresse :
   *   · `carnets` : l'identifiant tiré au sort, l'empreinte salée du jeton, le sel — titre vide,
   *     et AUCUNE DATE (cree = maj = 0 ; purgee = 1 : « vidé », pas « vidé le … ») ;
   *   · `liens` : la seule ligne du lien maître (empreinte, rôle), date à 0. Sans elle,
   *     `adopter {creer}` sur cette adresse créerait un carnet neuf pour qui l'a lue dans les
   *     journaux de l'hébergeur ; avec elle, seul le vrai jeton rouvre.
   * Les liens de famille sont SUPPRIMÉS (plus « révoqués ») : leurs valeurs étaient tirées au
   * hasard, rien ne les fera revivre. Le proche voit « lien coupé » et garde sa copie (20/10).
   * Ordre : les chemins des fichiers se lisent avant, les lignes partent dans la transaction,
   * les fichiers s'effacent APRÈS le commit — un échec de transaction ne touche alors à rien, et
   * un `unlink` raté ne laisse qu'un orphelin que `rel_balayer_depot` ramasse.
   * ⚠️ COÛT ACCEPTÉ : un second appareil du propriétaire n'apprend plus « le serveur a été vidé »
   * par la date de purge (il n'y en a plus) ; il garde sa copie, comme tout le monde. */
  $medias = [];
  try {
    $q = $pdo->prepare('SELECT media FROM publications WHERE carnet = ? AND media IS NOT NULL');
    $q->execute([$carnet]);
    foreach ($q->fetchAll() as $l) $medias[] = (string)$l['media'];
  } catch (Throwable $e) { /* la base décide, pas le disque : on continue la purge */ }

  $pdo->beginTransaction();
  try {
    foreach (['abonnements', 'commentaires', 'publications', 'documents'] as $t)
      $pdo->prepare("DELETE FROM `$t` WHERE carnet = ?")->execute([$carnet]);
    // 🔴 ON NE GARDE QUE LE MAÎTRE VIVANT (DeepSeek). Les liens de famille ET les maîtres
    // RÉVOQUÉS partent : un ancien maître tourné (`lien_tourner_maitre`) est une adresse qui a
    // fui, et lui rendre `revoque = 0` la RESSUSCITERAIT — la lecture rendue à qui la détient.
    $pdo->prepare("DELETE FROM liens WHERE carnet = ?
                     AND (role <> 'maitre' OR COALESCE(revoque,0) <> 0)")->execute([$carnet]);
    $pdo->prepare("UPDATE liens SET nom = '', cree = 0 WHERE carnet = ? AND role = 'maitre'")
        ->execute([$carnet]);
    $pdo->prepare("UPDATE carnets SET titre = '', cree = 0, maj = 0, purgee = 1 WHERE id = ?")
        ->execute([$carnet]);
    // Invariant : sans maître vivant, la pierre tombale ne protège plus l'adresse. (Il en reste
    // toujours un : la purge n'est accessible qu'au propriétaire PROUVÉ par ce maître.)
    $q = $pdo->prepare("SELECT COUNT(*) FROM liens WHERE carnet = ? AND role = 'maitre' AND revoque = 0");
    $q->execute([$carnet]);
    if ((int)$q->fetchColumn() < 1) throw new RuntimeException('pierre_tombale_incomplete');
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

  foreach ($medias as $m) rel_effacer_fichier($m);
  rel_purger_residus($carnet);
  return ['ok' => true, 'purge' => true];
}

/** ✚ 09/11/2026 — Ce que la base ne désigne pas mais que le disque garde, pour CE carnet. */
function rel_purger_residus(string $carnet): void {
  if (!preg_match('/^[A-Za-z0-9_-]{1,24}$/', $carnet)) return;
  // Les deux repères (dernier `maj`, fenêtre des avis) : des dates, et un nom de carnet.
  @unlink(__DIR__ . '/relais/reperes/' . $carnet . '.txt');
  @unlink(__DIR__ . '/relais/reperes/' . $carnet . '.avis');
  // Les envois de photos interrompus : leur `etat.json` porte le carnet (depot.php). Un envoi
  // du même carnet EN COURS sur un autre appareil est détruit aussi : il échoue proprement.
  foreach (glob(__DIR__ . '/relais/morceaux/*', GLOB_ONLYDIR) ?: [] as $d) {
    $e = @json_decode((string)@file_get_contents($d . '/etat.json'), true);
    if (!is_array($e) || ($e['carnet'] ?? null) !== $carnet) continue;
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @rmdir($d);
  }
  // Les archives d'export ne portent pas le carnet (nom tiré au sort) : on fait au moins le
  // ménage des vieilles (plus de 2 h, comme `exp_menage` d'export.php), qui n'avait lieu
  // qu'au prochain export de quelqu'un. ⚠️ 2 h = EXP_TTL d'export.php : à changer ensemble.
  foreach (glob(__DIR__ . '/relais/exports/*') ?: [] as $f)
    if (is_file($f) && filemtime($f) < time() - 2 * 3600) @unlink($f);
}

/**
 * Une chaîne, débarrassée de ce qui n'est pas imprimable.
 *
 * ⚠️ ELLE NE TRONQUE PLUS. Couper à mille caractères sans le dire, c'est le même silence qui
 * a coûté une soirée : la personne écrit, appuie, et une phrase revient amputée sans qu'on
 * lui explique. Trop long ⇒ `false`, et l'appelant refuse en le NOMMANT.
 */
function rel_texte($v, int $max) {
  if ($v === null) return null;
  if (!is_string($v)) return false;
  $t = trim(preg_replace('/[^\P{C}\n]+/u', ' ', $v) ?? '');
  if ($t === '') return null;
  return mb_strlen($t) > $max ? false : $t;
}

/**
 * Un refus qui DIT pourquoi. Il n'est employé qu'APRÈS la vérification du lien : à ce
 * moment-là, celui qui écrit a déjà prouvé qu'il a le droit d'être là, et lui cacher la
 * raison ne protège plus personne — ça l'empêche seulement de comprendre.
 */
/**
 * La petite image, ou rien — mais jamais un silence.
 *
 * Absente : `null`, c'est légitime, une publication peut n'être qu'un mot. Trop lourde : on
 * refuse et on le NOMME, pour que l'écran puisse le dire. Ce qu'on ne fait plus, c'est
 * l'effacer en répondant « ok ».
 */
const REL_VIGNETTE_MAX = 700000;
function rel_vignette($v) {
  if ($v === null || $v === '') return null;
  if (!is_string($v)) return false;                       // `false` ⇒ la boucle en dessous se plaint
  if (strlen($v) > REL_VIGNETTE_MAX) rel_plainte('vignette_trop_grosse');
  return $v;
}

function rel_plainte(string $quoi): never {
  rel_rendre(['ok' => false, 'erreur' => 'Refusé.', 'motif' => $quoi], 422);
}

/** Un nombre, ou rien. Présent mais illisible ⇒ `false` : on refuse, on ne range pas un vide. */
function rel_nombre($v) {
  if ($v === null || $v === '') return null;
  return is_numeric($v) ? (float)$v : false;
}

/**
 * Un identifiant d'étape ou de visite, tel que le téléphone les nomme.
 *
 * ⚠️ ON NE SUPPOSE PLUS LA FORME. Ce champ valait `INT` borné à 9 999 ; une étape s'appelle
 * « 178768011340601 ». Le numéro était refusé **en silence**, le commentaire rangé sans étape,
 * et il ne s'affichait sur aucune fiche. C'est le silence qui a coûté la soirée : un refus
 * franc aurait dit « commentaire non envoyé » et se serait trouvé en une minute.
 *
 * Rend `false` quand la valeur est présente mais mal formée — l'appelant refuse alors, il ne
 * range pas un `null` en faisant comme si de rien n'était.
 */
function rel_ref($v) {
  if ($v === null || $v === '') return null;
  if (is_int($v)) $v = (string)$v;
  return (is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $v)) ? $v : false;
}

/** Un identifiant fourni par le téléphone. C'est LUI qui empêche le doublon au rejeu. */
function rel_identifiant($v): ?string {
  return (is_string($v) && preg_match('/^[A-Za-z0-9_-]{8,24}$/', $v)) ? $v : null;
}

/**
 * Un commentaire. **La famille écrit ici, et nulle part ailleurs** — c'est le pilier 2 : les
 * proches lisent et commentent sans compte. Un lien vivant suffit ; le lien maître n'est pas
 * requis, et ne donne aucun droit de plus sur ce geste.
 *
 * L'identifiant vient du téléphone. Si une écriture est partie et que la réponse s'est perdue,
 * le rejeu présentera le même identifiant : **la clé primaire refusera le doublon d'elle-même**.
 * Sans cela le commentaire apparaîtrait deux fois — un défaut invisible au bureau et ravageur
 * sur un réseau qui vacille.
 */
function rel_commenter(PDO $pdo, string $carnet, array $in): array {
  $id    = rel_identifiant($in['id'] ?? null);
  if ($id === null) rel_refus();
  $texte = rel_texte($in['texte'] ?? null, REL_TEXTE_MAX);
  if ($texte === false) rel_plainte('texte_trop_long');
  if ($texte === null)  rel_plainte('texte_vide');
  $etape  = rel_ref($in['etape'] ?? null);
  $visite = rel_ref($in['visite'] ?? null);
  $parent = rel_ref($in['parent'] ?? null);
  // Mal formé ⇒ on REFUSE. On ne range pas un vide en laissant croire que c'est parti.
  if ($etape === false || $visite === false || $parent === false) rel_refus();
  /* ⚠️ `rel_ref` ADMET 32 SIGNES, CES DEUX COLONNES EN FONT 24 — 24/09.
   * `commentaires.visite` et `commentaires.parent` sont des VARCHAR(24) ; `commentaires.etape`
   * et `publications.etape`/`visite` sont en 32. Entre 25 et 32 signes, c'était MySQL qui
   * décidait : en mode strict une PDOException 1406, avalée par le `catch (Throwable)` de la
   * fin, donc un « Refusé. » SANS MOTIF ; en mode permissif une TRONCATURE MUETTE à 24 — et le
   * commentaire se rattachait alors à une référence qui ne désigne rien, invisible sur toutes
   * les fiches. C'est exactement le défaut que `rel_ref` a été écrit pour tuer, et son propre
   * commentaire le dit : « c'est le silence qui a coûté la soirée ».
   *
   * Les valeurs réelles n'en approchent pas. `nextCustomN()` (`20-itineraire.js` l. 1219) rend
   * `Date.now()*100 + seq`, donc une visite s'appelle 'v' + 17 chiffres = 18 signes ; un
   * `parent` est un identifiant de commentaire, borné à 24 par `rel_identifiant`. Au-delà, ce
   * n'est plus l'application qui parle — on le DIT, au lieu de laisser la base trancher.
   *
   * On ne touche ni à `rel_ref` — sa borne de 32 est juste pour `etape` — ni aux colonnes :
   * les élargir les ferait accepter ce qui ne peut jamais être légitime. */
  if (($visite !== null && mb_strlen($visite) > 24) ||
      ($parent !== null && mb_strlen($parent) > 24)) rel_plainte('reference_trop_longue');
  if ($etape === null && $visite === null) rel_refus();   // un commentaire tient à quelque chose
  $nom    = rel_texte($in['nom'] ?? null, REL_NOM_MAX);
  if ($nom === false) rel_plainte('nom_trop_long');
  $nom = $nom ?? '';
  $t = base_maintenant();
  rel_repere_hausser($carnet, $t);   // AVANT le SQL : le repère majore, il ne suit pas
  try {
    $pdo->prepare('INSERT INTO commentaires (id,carnet,etape,visite,parent,nom,texte,cree,maj) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $carnet, $etape, $visite, $parent, $nom, $texte, $t, $t]);
    return ['ok' => true, 'id' => $id, 'nouveau' => true];
  } catch (PDOException $e) {
    // Déjà reçu : ce n'est pas une erreur, c'est un rejeu. On le dit sans rien changer.
    if (($e->errorInfo[1] ?? 0) === 1062) return ['ok' => true, 'id' => $id, 'nouveau' => false];
    throw $e;
  }
}

/**
 * Ce que le propriétaire écrit : une publication, un document d'un bloc — l'itinéraire, le
 * budget —, ou le retrait d'une ligne. **Deux preuves exigées**, le lien maître et le jeton.
 *
 * Un retrait MARQUE, il n'efface pas : sans quoi la relève par delta ne verrait jamais la
 * disparition, et l'étape retirée resterait à l'écran des autres pour toujours.
 */
function rel_ecrire(PDO $pdo, string $carnet, array $in): array {
  $quoi = is_string($in['quoi'] ?? null) ? $in['quoi'] : '';
  $t = base_maintenant();
  // Un seul endroit pour les quatre écritures qui passent par ici — document, publication,
  // retrait, abonnement. Haussé même si l'écriture est refusée plus bas : un repère en avance
  // coûte une requête, un repère en retard coûte une photo.
  rel_repere_hausser($carnet, $t);

  if ($quoi === 'document') {
    $nom = rel_texte($in['nom'] ?? null, 32);
    if ($nom === null || !preg_match('/^[a-z]{3,32}$/', $nom)) rel_refus();
    /* 🔴 LE BUDGET NE MONTE PAS — 28/08, Helmy : « le budget ne devrait pas monter du tout ».
     *
     * ⚠️ LE TEST VIVAIT PLUS BAS, ET IL N'ÉTAIT JAMAIS ATTEINT. Il est écrit plus loin dans
     * cette même fonction, après le `return` INCONDITIONNEL de ce bloc : `budget` satisfait
     * `^[a-z]{3,32}$`, donc il traversait ici, était INSÉRÉ, et la réponse était `ok`. Le
     * commentaire d'en bas affirmait « la porte est fermée à l'ÉCRITURE, ce qui ne dépend plus
     * de rien » — c'était faux : la seule protection restante était `$sauf_budget` EN LECTURE,
     * celle-là même que ce commentaire déclare insuffisante. Trouvé le 23/09 en lisant le
     * fichier en entier, après que Helmy eut posé la règle « le code entier, pas d'extrait ».
     *
     * Il est donc ICI, AVANT l'INSERT : la seule place où il décide de quelque chose.
     * ⚠️ On efface aussi ce qui traîne : un carnet qui en a déposé un avant aujourd'hui doit
     * s'en débarrasser, sans quoi la décision ne vaudrait que pour l'avenir. */
    if ($nom === 'budget') {
      $pdo->prepare('DELETE FROM documents WHERE carnet = ? AND nom = ?')->execute([$carnet, 'budget']);
      return ['ok' => true, 'quoi' => 'document', 'nom' => 'budget', 'refuse' => 'budget_local'];
    }
    $contenu = $in['contenu'] ?? null;
    if (!is_array($contenu) && !is_string($contenu)) rel_refus();
    $json = is_string($contenu) ? $contenu : json_encode($contenu, JSON_UNESCAPED_UNICODE);
    // Un itinéraire réel pèse une vingtaine de kilo-octets. Deux mégaoctets, c'est autre chose
    // qui se passe — mieux vaut le dire que de le laisser gonfler sans limite.
    if (strlen($json) > 2000000) rel_plainte('document_trop_gros');
    $pdo->prepare('INSERT INTO documents (carnet,nom,contenu,maj) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE contenu = VALUES(contenu), maj = VALUES(maj)')
        ->execute([$carnet, $nom, $json, $t]);
    return ['ok' => true, 'quoi' => 'document', 'nom' => $nom];
  }

  if ($quoi === 'publication') {
    $id = rel_identifiant($in['id'] ?? null);
    if ($id === null) rel_refus();
    $champs = [
      'etape'  => rel_ref($in['etape'] ?? null),
      'visite' => rel_ref($in['visite'] ?? null),
      /* ⚠️ `lat` ET `lng` NE SONT PLUS ENVOYÉES PAR L'APPLICATION — 28/08. Elles restent
       * acceptées, parce qu'un téléphone hors réseau peut avoir une écriture en attente
       * d'avant le changement, et qu'on ne jette pas ce qu'il a écrit. Ce qui arrive
       * aujourd'hui, c'est `pos` : le couple chiffré par l'appareil. Le relais ne s'en est
       * jamais servi pour trier ni filtrer — c'est ce qui rendait le chiffrement possible
       * sans rien casser ici. */
      'lat'    => rel_nombre($in['lat'] ?? null),
      'lng'    => rel_nombre($in['lng'] ?? null),
      'pos'    => rel_texte($in['pos'] ?? null, 512),
      'lieu'   => rel_texte($in['lieu'] ?? null, 512),
      'titre'  => rel_texte($in['titre'] ?? null, 512),
      'note'   => rel_texte($in['note'] ?? null, REL_NOTE_MAX),
      'genre'  => rel_texte($in['genre'] ?? null, 16),
      'media'  => rel_texte($in['media'] ?? null, 255),
      // La petite image voyage dans la base, comme le faisait le carnet d'origine sans
      // espace de fichiers. Les grosses vidéos attendront le téléversement par morceaux.
      // ⚠️ UNE IMAGE TROP LOURDE ÉTAIT MISE À `null` ET L'ÉCRITURE ACCEPTÉE.
      // Le serveur répondait « ok », l'écran annonçait « Photo ajoutée et partagée », et la
      // famille recevait une publication SANS IMAGE. L'auteur ne pouvait pas le savoir : rien
      // ne le lui disait, ni chez lui, ni chez elle. C'était la seule ligne de ce bloc à
      // violer la règle énoncée juste en dessous — un champ trop long se NOMME, il ne se
      // range pas vide. Elle la respecte maintenant : au-delà du plafond, on REFUSE.
      // (Le plafond n'est pas imposé par la colonne — `vignette` est un MEDIUMTEXT, 16 Mo.
      //  C'est notre choix : ces images voyagent dans les lots de relève, ligne 101.)
      'vignette' => rel_vignette($in['vignette'] ?? null),
    ];
    // Rien ne passe en silence : un champ illisible ou trop long est NOMMÉ, pas rangé vide.
    foreach ($champs as $quoi => $val) if ($val === false) rel_plainte($quoi . '_invalide');
    /* ⚠️ LA FORME DE `media` N'ÉTAIT VÉRIFIÉE QUE POUR EFFACER, JAMAIS POUR ÉCRIRE — 24/09.
     * `rel_effacer_fichier` et `rel_tracer_depot` exigent tous deux
     * `^depot/[0-9a-f]{32}\.[a-z0-9]{2,5}$` — la forme que `depot.php` l. 271 fabrique
     * (`'depot/' . $nom`). L'écriture, elle, se contentait de `rel_texte(…, 255)` : n'importe
     * quelle chaîne de 255 signes pouvait s'y ranger, EN CLAIR, et repartir telle quelle dans
     * la trace du dépôt. Le contre-exemple était dans ce même bloc : `documents.nom` est forcé
     * par `^[a-z]{3,32}$`. Ce qu'une porte exige pour détruire, l'autre doit l'exiger pour
     * écrire. */
    if ($champs['media'] !== null
        && !preg_match('~^depot/[0-9a-f]{32}\.[a-z0-9]{2,5}$~', $champs['media']))
      rel_plainte('media_invalide');
    /* 🔴 `lat` ET `lng` NE SE RANGENT PLUS — 24/09, et ce n'est NI un refus NI un oubli.
     *
     * Le commentaire ci-dessus dit pourquoi elles restent ACCEPTÉES : un téléphone hors réseau
     * peut porter une écriture d'avant le 28/08, et on ne jette pas ce qu'il a écrit. Cette
     * raison tient. Mais le modèle de menace posé par Helmy dit l'autre moitié : « on chiffre
     * ce qui désigne quelqu'un, pas ce qui pèse » — et une coordonnée désigne quelqu'un, c'est
     * précisément pour ça qu'elles ont cessé d'être alimentées le 28/08 au profit de `pos`,
     * chiffré par l'appareil. « Là où l'on dort chaque nuit, c'est chez soi. »
     *
     * Refuser l'écriture contredirait le premier ; la ranger contredirait le second. On garde
     * donc l'écriture ET on vide la position : la personne conserve son titre, sa note et sa
     * photo, le serveur ne détient aucune coordonnée en clair. La validation par `rel_nombre`
     * reste au-dessus, intacte : une valeur illisible est toujours NOMMÉE avant d'arriver ici.
     *
     * ⚠️ CE QUI EST DÉJÀ EN BASE N'EST PAS PURGÉ. Helmy, 23/09 : « les anciennes on s'en fout,
     * ce qui est ancien est ancien. » Balayer les lignes existantes est un autre geste.
     *
     * 🔴 MAIS CETTE PHRASE EST DEVENUE À MOITIÉ FAUSSE LE 24/09, et il faut le dire : depuis
     * qu'on a repris le correctif v692 du classique, le `catch` ci-dessous ne se contente plus
     * d'ignorer un rejeu — il RAFRAÎCHIT la ligne. Un renvoi d'une écriture ancienne, qui
     * portait encore `lat`/`lng`, VIDE donc ces deux colonnes sur une ligne déjà en base.
     * C'est cohérent avec l'intention — le serveur ne doit pas détenir de position en clair —
     * mais ce n'est plus « on ne touche à rien » : c'est « on ne balaie pas, et ce qui repasse
     * se nettoie au passage ». Relevé par DeepSeek en relisant la fusion. */
    $champs['lat'] = null;
    $champs['lng'] = null;
    $champs['lieu']  = $champs['lieu']  ?? '';
    $champs['titre'] = $champs['titre'] ?? '';
    try {
      $pdo->prepare('INSERT INTO publications (id,carnet,etape,visite,lat,lng,pos,lieu,titre,note,genre,media,vignette,cree,maj)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute(array_merge([$id, $carnet], array_values($champs), [$t, $t]));
      $nouveau = true;
    } catch (PDOException $e) {
      if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
      /* 🔴 UN « ok » QUI MENTAIT SUR UNE COLLISION D'IDENTIFIANT — 25/09/2026, relevé par
       * DeepSeek en relisant la fusion v693. `publications.id` est une clé primaire GLOBALE,
       * pas par carnet. Si l'identifiant appartenait déjà à un AUTRE carnet : l'INSERT levait
       * 1062, l'UPDATE ci-dessous ne touchait AUCUNE ligne — le `WHERE carnet = ?` protège bien
       * la ligne étrangère, c'est son bon côté — et on répondait quand même `ok:true`.
       * L'écriture était donc perdue EN SILENCE : exactement le motif que le correctif v692
       * voulait tuer (« invisible to family »).
       *
       * Inatteignable sans deviner l'identifiant d'autrui, qui est de haute entropie : c'est une
       * collision, pas une faille. Mais une écriture perdue sans un mot est précisément ce que
       * ce fichier passe ses journées à supprimer.
       *
       * ⚠️ ON LIT LE PROPRIÉTAIRE, ON NE COMPTE PAS LES LIGNES TOUCHÉES. Le réflexe serait
       * `if ($stmt->rowCount() === 0) rel_refus();` — et ce serait un défaut neuf : MySQL rend
       * ZÉRO pour un UPDATE qui trouve sa ligne mais n'y change rien. Un rejeu à l'identique,
       * dans la même milliseconde que le précédent (`maj` inchangé), serait alors REFUSÉ alors
       * qu'il est parfaitement légitime. On demande donc à qui appartient la ligne. */
      $q = $pdo->prepare('SELECT carnet FROM publications WHERE id = ?');
      $q->execute([$id]);
      if ($q->fetchColumn() !== $carnet) rel_refus();   // l'identifiant est à un autre carnet
      // Rejeu (clé primaire dupliquée) : au lieu d'IGNORER, on RAFRAÎCHIT la ligne — sans
      // toucher `cree` (l'ordre d'affichage) ni `carnet` (la propriété). Un renvoi met donc à
      // jour média/note/étape/pos plutôt que de laisser une version périmée. `maj` = maintenant.
      // ⚠️ VENU DU CLASSIQUE, v692 (`e160b21`) — repris tel quel. Il dépend du MÊME ordre de
      // clés de `$champs` que l'INSERT au-dessus : `array_values` les rend dans l'ordre
      // d'insertion, et nos affectations plus haut (`lat`/`lng` à null) ne déplacent rien
      // puisque les clés existent déjà. Vérifié avec DeepSeek le 24/09.
      $pdo->prepare('UPDATE publications
                        SET etape=?,visite=?,lat=?,lng=?,pos=?,lieu=?,titre=?,note=?,genre=?,media=?,vignette=?,maj=?
                      WHERE id=? AND carnet=?')
          ->execute(array_merge(array_values($champs), [$t, $id, $carnet]));
      $nouveau = false;                                  // rejeu : rafraîchi, cree préservé
    }
    rel_menage($pdo, $carnet);
    return ['ok' => true, 'quoi' => 'publication', 'id' => $id, 'nouveau' => $nouveau];
  }

  /* 🔴 CE BLOC EST MORT, ET ON LE LAISSE ÉCRIT — 23/09.
   *
   * Il portait la décision du 28/08 (« le budget ne devrait pas monter du tout ») et affirmait
   * « la porte est maintenant fermée à l'ÉCRITURE, ce qui ne dépend plus de rien ». C'ÉTAIT
   * FAUX, et de la pire façon : le bloc `document` ci-dessus rend la main INCONDITIONNELLEMENT,
   * donc ce test n'était JAMAIS atteint. `budget` satisfait `^[a-z]{3,32}$` — il était inséré
   * comme n'importe quel document, la réponse était `ok`, et la seule protection réelle restait
   * `$sauf_budget` EN LECTURE : précisément celle que ce commentaire déclarait insuffisante.
   *
   * Le test vit maintenant dans le bloc `document`, AVANT l'INSERT. Ces lignes restent pour que
   * personne ne reparte sur l'idée qu'une vérification placée après un `return` protège quoi
   * que ce soit — et parce qu'un commentaire qui affirme une garantie qu'il n'a pas est plus
   * dangereux qu'une absence de commentaire. */
  if ($quoi === 'document' && ($in['nom'] ?? '') === 'budget') {
    $pdo->prepare('DELETE FROM documents WHERE carnet = ? AND nom = ?')->execute([$carnet, 'budget']);
    return ['ok' => true, 'quoi' => 'document', 'nom' => 'budget', 'refuse' => 'budget_local'];
  }

  if ($quoi === 'retirer') {
    $ou = is_string($in['ou'] ?? null) ? $in['ou'] : '';
    if (!in_array($ou, ['publications', 'commentaires'], true)) rel_refus();
    $id = rel_identifiant($in['id'] ?? null);
    if ($id === null) rel_refus();
    $pdo->prepare("UPDATE `$ou` SET retire = 1, maj = ? WHERE id = ? AND carnet = ?")
        ->execute([$t, $id, $carnet]);
    rel_menage($pdo, $carnet);
    return ['ok' => true, 'quoi' => 'retirer', 'id' => $id];
  }

  rel_refus();
}

/** Inscrire un appareil aux notifications. Un lien vivant suffit : la famille en veut aussi. */
function rel_abonner(PDO $pdo, string $carnet, array $in): array {
  $adresse = is_string($in['adresse'] ?? null) ? $in['adresse'] : '';
  $point   = base64_decode(strtr((string)($in['point'] ?? ''), '-_', '+/'), true);
  $secret  = base64_decode(strtr((string)($in['secret'] ?? ''), '-_', '+/'), true);
  if (!push_adresse_sure($adresse) || strlen((string)$point) !== 65 || strlen((string)$secret) !== 16)
    rel_refus();
  $pdo->prepare('INSERT INTO abonnements (carnet,adresse,empreinte,point,secret,cree) VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE point = VALUES(point), secret = VALUES(secret), echecs = 0')
      // ->execute([$carnet, mb_substr($adresse, 0, 500), hash('sha256', $adresse), $point, $secret, base_maintenant()]);
      /* ✚ 09/11/2026 — PLUS AUCUNE DATE DANS `abonnements` (Helmy, principe n° 1). `cree` est
       * NOT NULL et sans DEFAULT : l'omettre lèverait 1364 en mode strict. On y écrit 0 —
       * « pas de date ». `dernier` n'est écrit nulle part et vaut 0 par défaut. */
      ->execute([$carnet, mb_substr($adresse, 0, 500), hash('sha256', $adresse), $point, $secret, 0]);
  return ['ok' => true, 'abonne' => true];
}

/* ─────────────────────────────────────────────────────────────────────────────
   LE CHEMIN COMMUN — identique pour toute requête, jusqu'au dernier moment
   ───────────────────────────────────────────────────────────────────────────── */

$in = rel_entree();
$action = is_string($in['action'] ?? null) ? $in['action'] : '';
$lien   = is_string($in['lien'] ?? null) ? $in['lien'] : '';

/**
 * CRÉER UN CARNET — la seule action qui n'a pas de lien, puisqu'elle en fabrique un.
 *
 * Elle passe donc AVANT la résolution du lien, et elle SORT : rien ne doit retomber dans le
 * chemin commun, qui suppose partout un carnet résolu.
 *
 * ── FERMÉE PAR DÉFAUT, ET C'EST LE VRAI VERROU ─────────────────────────────────────────────
 *
 * `relais/creation.php` n'existe pas dans le dépôt. Tant qu'il n'est pas posé à la main sur le
 * serveur, cette action refuse comme tout le reste. Le produit se vendra une fois pour la vie ;
 * la licence, elle, n'est pas écrite (ligne 30 du tableau). Une porte de création ouverte
 * laisserait n'importe qui fabriquer des carnets à l'infini sur le domaine. **Ce n'est pas une
 * sécurité de vente : c'est un bouton pause, le temps que la licence existe.** Dit ici pour que
 * personne ne le prenne pour autre chose.
 *
 * ── LE SECRET VOYAGE DANS LE CORPS, PAS EN EN-TÊTE ─────────────────────────────────────────
 *
 * Un hébergement mutualisé journalise les en-têtes ; le corps d'un POST, presque jamais. Un
 * secret dans `X-...` finirait en clair dans un fichier de journal que nous ne contrôlons pas.
 *
 * ── LE FREIN N'EST PAS UN VERROU DE FICHIER ────────────────────────────────────────────────
 *
 * C'est ce que DeepSeek proposait, et il l'a réfuté lui-même dans le même souffle : sur cet
 * hébergeur, PHP et le FTP partagent l'utilisateur, donc quiconque écrit le supprime. Le frein
 * est un PLAFOND compté sur la table qui existe déjà. Une requête, aucune table de plus, et un
 * emballement s'arrête net.
 *
 * Refusé aussi : son anti-rejeu à horodatage. Rejouer « crée un carnet » ne dérobe rien, et une
 * fenêtre de cinq minutes fermerait la porte au premier téléphone dont l'horloge dérive.
 *
 * Tous les refus se confondent — fichier absent, secret faux, plafond atteint : la même réponse
 * que pour une action inconnue. On ne renseigne pas celui qui essaie.
 */
if ($action === 'creer_carnet') {
  $porte = __DIR__ . '/relais/creation.php';
  if (!is_file($porte)) rel_refus();
  require_once $porte;                        // pose CREATION_SECRET et CREATION_PLAFOND
  $donne = is_string($in['secret'] ?? null) ? $in['secret'] : '';
  if (!defined('CREATION_SECRET') || !defined('CREATION_PLAFOND')
      || $donne === '' || !hash_equals(CREATION_SECRET, $donne)) rel_refus();
  try {
    $pdo = base();
    /* ⚠️ 08/10/2026 — PLAFOND LEVÉ, décision de Helmy : « il faut lever la limite des carnets, pas de
     * chiffre ». Ce test comptait TOUS les carnets du serveur ; un itinéraire = un carnet, il était
     * devenu une limite d'usage totale (le banc a atteint 100 : 403, écran figé sur « On fabrique ton
     * carnet… »). 🔴 LES DEUX LIGNES SONT COMMENTÉES ENSEMBLE : commenter le seul `if` laisserait
     * `rel_refus();` s'exécuter toujours, et la porte serait murée. `CREATION_PLAFOND` reste EXIGÉE
     * (test `defined()` plus haut) : elle ne plafonne plus, elle marque la porte ouverte. (DeepSeek) */
    // if ((int)$pdo->query('SELECT COUNT(*) FROM carnets')->fetchColumn() >= CREATION_PLAFOND)
    //   rel_refus();
    $ne     = prop_creer($pdo, is_string($in['titre'] ?? null) ? $in['titre'] : '');
    $maitre = lien_poser($pdo, $ne['carnet'], 'maitre');
    // Le jeton n'existe en clair QU'ICI et qu'une fois : le serveur n'en garde que l'empreinte.
    // S'il se perd, le carnet est perdu avec lui — il n'y a pas de seconde remise.
    rel_rendre(['ok' => true, 'lien' => $maitre['lien'], 'jeton' => $ne['jeton']]);
  } catch (Throwable $e) { rel_refus(); }
}

/**
 * ══ ADOPTER UN CARNET CALCULÉ SUR LE TÉLÉPHONE ═══════════════════════════════════════════
 *
 * Décidé par Helmy le 27/08, après qu'on lui eut posé le choix : garder le verrou, ou ouvrir
 * la porte. Il a répondu « b ».
 *
 * Dans son modèle, l'adresse du carnet et le jeton du propriétaire se CALCULENT sur le
 * téléphone à partir de trois choses qu'il tape — prénom, code à six chiffres, phrase secrète
 * d'au moins quatre mots. Un téléphone neuf retrouve alors le même carnet en retapant les
 * mêmes choses : aucun compte, aucun mot de passe à récupérer, et rien à se rappeler ici.
 *
 * Conséquence, et elle est assumée : **le serveur doit accepter un carnet qu'il n'a jamais
 * vu.** C'est l'inverse de `creer_carnet`, qui exige un secret posé à la main.
 *
 * ── CE QUI N'EST PAS AFFAIBLI ──────────────────────────────────────────────────────────────
 *
 * Le serveur ne range toujours que des EMPREINTES — celle du lien, celle du jeton, avec un sel
 * tiré au sort ici. Il ne peut reconstituer ni l'un ni l'autre. Ce qui change, c'est seulement
 * QUI décide de la valeur.
 *
 * ── OUVRIR ET CRÉER SONT DEUX GESTES, ET C'EST LE POINT LE PLUS IMPORTANT ──────────────────
 *
 * C'est ce que DeepSeek a relevé et il a raison : si l'on créait dès qu'un lien est inconnu,
 * **une faute de frappe dans la phrase secrète fabriquerait un carnet VIDE en silence**, à la
 * place de celui qu'on cherchait. Le lien change avec la moindre lettre : le serveur ne voit
 * pas « un jeton qui diffère », il voit « un lien inconnu ».
 *
 * Donc : sans `creer`, un lien inconnu rend un refus NOMMÉ — l'écran peut dire « aucun carnet
 * ne correspond, vérifie ce que tu as tapé ». Avec `creer`, et seulement là, on crée.
 *
 * ── CE QUI RETIENT LES ABUS ────────────────────────────────────────────────────────────────
 *
 * Il n'y a plus de secret : le PLAFOND est le seul frein, et c'est dit sans détour. Un carnet
 * vide pèse quelques octets, et le vrai mur de ce produit est le débit de requêtes, pas le
 * nombre de carnets (ligne 125). **Pour refermer la porte : effacer `relais/creation.php`** —
 * le même geste que pour l'autre, et rien d'autre à défaire.
 *
 * ⚠️ Refusé à DeepSeek : sa preuve de travail à la création. Elle ferait ramer un téléphone
 * d'entrée de gamme pendant que la personne attend, pour un gain qu'un attaquant décidé
 * paierait sans broncher. Le plafond dit la même chose et ne coûte rien à personne.
 */
if ($action === 'adopter') {
  $porte = __DIR__ . '/relais/creation.php';
  if (!is_file($porte)) rel_refus();
  require_once $porte;
  if (!defined('CREATION_PLAFOND')) rel_refus();

  $lien  = is_string($in['lien']  ?? null) ? $in['lien']  : '';
  $jeton = is_string($in['jeton'] ?? null) ? $in['jeton'] : '';
  $creer = (($in['creer'] ?? null) === true);
  if (!lien_forme($lien) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $jeton)) rel_refus();

  try {
    $pdo = base();
    $ouvert = lien_ouvrir($pdo, $lien);
    if ($ouvert !== null) {
      // Le carnet existe : le jeton doit correspondre. Sinon on refuse comme pour le reste.
      if (!prop_verifier($pdo, $ouvert['carnet'], $jeton)) rel_refus();
      rel_rendre(['ok' => true, 'nouveau' => false]);
    }
    // Inconnu, et on ne nous demande pas de créer : on le DIT, au lieu de créer dans le dos.
    if (!$creer) rel_rendre(['ok' => false, 'motif' => 'carnet_inconnu']);
    /* ⚠️ 08/10/2026 — PLAFOND LEVÉ, même décision et même raison que dans `creer_carnet`. Ce bloc n'a
     * pas de secret : la porte qui reste est `relais/creation.php` et sa constante — effacer le
     * fichier referme tout. (DeepSeek) */
    // if ((int)$pdo->query('SELECT COUNT(*) FROM carnets')->fetchColumn() >= CREATION_PLAFOND) rel_refus();
    $ne = prop_creer_avec_jeton($pdo, $jeton, is_string($in['titre'] ?? null) ? $in['titre'] : '');
    lien_poser_valeur($pdo, $ne['carnet'], $lien, 'maitre');
    /* On pose le repère dès la naissance : un carnet neuf doit partir avec un repère à jour
     * plutôt qu'à zéro, comme tout autre carnet après sa première écriture.
     *
     * ⚠️ ET IL FAUT DIRE CE QUE CECI NE RÈGLE PAS. Je l'ai écrit en croyant tenir la cause d'un
     * défaut mesuré le 27/08 — **un document écrit sur un carnet fraîchement adopté est accepté
     * (`ok`) et n'est jamais rendu par la relève ; écrire un COMMENTAIRE fait apparaître les
     * deux d'un coup**. Reproduit deux fois, sur deux carnets neufs différents.
     *
     * **Cette ligne n'y change rien : la théorie du raccourci était FAUSSE**, et je le laisse
     * écrit pour que personne ne reparte dessus. Ce qui reste établi : le document EST en base
     * (il ressort dès qu'un commentaire existe), donc rien n'est perdu — c'est la LECTURE qui
     * le tait. La cause n'est pas trouvée. Voir la ligne du tableau. */
    rel_repere_hausser($ne['carnet'], base_maintenant());
    rel_rendre(['ok' => true, 'nouveau' => true]);
  } catch (Throwable $e) { rel_refus(); }
}

// La liste blanche est consultée SANS répondre : on ne dit pas encore si l'action existe.
// $connue = in_array($action, ['relever', 'commenter', 'ecrire', 'purger', 'liens', 'abonner', 'charge', 'depot', 'cle_push'], true);
/* ✚ 09/11/2026 — `horloge` MANQUAIT À LA LISTE (DeepSeek, Helmy : « on ne laisse rien derrière »). Son `case`
 * existe depuis le 28/08, réservé au propriétaire prouvé (`if (!$prouve) rel_refus()`), mais l'action
 * était refusée ici avant d'y arriver : `__horlogeCarnet` (couche-relais.js) ne pouvait qu'échouer. */
$connue = in_array($action, ['relever', 'commenter', 'ecrire', 'purger', 'liens', 'abonner', 'charge', 'depot', 'cle_push', 'horloge'], true);

// LA requête, la seule, exécutée pour toute action — même inconnue, même interdite.
try { $pdo = base(); $ouvert = lien_ouvrir($pdo, $lien); }
// catch (Throwable $e) { rel_refus(); }
/* ✚ 19/10/2026 — UNE PANNE N'EST PAS UN REFUS. Une base injoignable rendait le même 403 qu'un lien
 * coupé ; or le suiveur retire désormais sa copie quand son lien est refusé (couche-relais.js,
 * « lien coupé »). Une panne de base effacerait donc la copie de tous les proches. Elle répond 503 :
 * le client la traite déjà comme passagère (attente, nouvelle tentative). Rien n'est dit de plus
 * sur le lien. (essai du 19/10, remarque 4 ; DeepSeek) */
catch (Throwable $e) { rel_rendre(['ok' => false, 'erreur' => 'Indisponible.'], 503); }

// À partir d'ici seulement, on décide. Toutes les causes de refus se confondent.
if (!$connue || $ouvert === null) rel_refus();

$carnet = $ouvert['carnet'];
$maitre = ($ouvert['role'] === 'maitre');
$jeton  = prop_jeton_presente();
$prouve = $maitre && $jeton !== '' && prop_verifier($pdo, $carnet, $jeton);

// Les gestes qui écrivent exigent DEUX preuves : le lien maître ET le jeton.
if (in_array($action, ['ecrire', 'purger', 'liens'], true) && !$prouve) rel_refus();
// `$prouve` sert aussi à la relève : elle le rend, pour que l'écran sache s'il commande.

// ⚠️ LE COMPTEUR DE SATURATION SE GREFFE, IL NE DEMANDE JAMAIS RIEN.
//
// Depuis la ligne 100, l'application encaisse un 429 en silence : plus d'erreur, plus de
// plainte, juste des familles qui trouvent que « c'est lent ». Le mur est devenu invisible —
// et c'est sur lui que se décide la bascule vers un vrai serveur (ligne 125).
//
// Or **`relais.php` ne voit JAMAIS ses propres refus** : le 429 vient de l'hébergeur, qui
// répond une page HTML AVANT que PHP ne s'exécute. Seul le client les voit. Il les compte
// donc chez lui et les greffe ici, **sur une requête qui passe** — jamais un appel de plus,
// surtout pas au moment où le serveur sature. C'est ce que je refuse à DeepSeek, qui voulait
// tenter l'envoi tout de suite : PHP ne tournant pas sur un 429, ce serait pure perte.
if (isset($in['charge'])) rel_noter_charge((int)$in['charge']);

try {
  switch ($action) {

    case 'relever':
      // ⚠️ ON PRÉVIENT PENDANT LA RELÈVE DE LA FAMILLE, PAS CELLE DU PROPRIÉTAIRE.
      // Celui qui recevrait la notification est justement en train de regarder son carnet quand
      // il relève : le prévenir serait du bruit, et lui faire payer l'attente d'un envoi encore
      // pire. La famille, elle, relève juste après avoir commenté — c'est le passage naturel.
      if (!$prouve) { try { rel_prevenir($pdo, $carnet); } catch (Throwable $e) { /* jamais au prix de la relève */ } }
      // Le balai des fichiers orphelins voyage avec : il ne coûte rien tant que les six heures
      // ne sont pas passées, et il ne s'exécute jamais pendant la relève du propriétaire.
      if (!$prouve) { try { rel_balayer_depot($pdo); } catch (Throwable $e) { /* jamais au prix de la relève */ } }
      $depuis = rel_entier($in['depuis'] ?? 0, 0, base_maintenant());
      if ($depuis === null) rel_refus();
      // $q = $pdo->prepare('SELECT purgee FROM carnets WHERE id = ?');
      // $q->execute([$carnet]);
      // $purgee = (int)($q->fetchColumn() ?: 0);
      /* ✚ 09/11/2026 — PLUS DE DATE DE PURGE (pierre tombale : `purgee` vaut 1, « vidé », jamais un
       * instant). La branche ci-dessous ne peut plus se déclencher : on ne paie plus une requête à
       * CHAQUE relève pour la lire. La clé `purgee` reste dans la réponse, à 0 : des téléphones
       * déjà installés la lisent (couche-relais.js, `purgeeConnue`), et y restent inertes. */
      $purgee = 0;
      // Le carnet a été vidé après le dernier passage de ce téléphone : il doit oublier ce
      // qu'il avait de CE carnet — jamais ses propres notes, qui ne sont pas ici.
      if ($purgee > 0 && $depuis > 0 && $depuis < $purgee)
        rel_rendre(['ok' => true, 'purge' => true, 'lot' => [], 'jusqu_a' => $purgee,
                    'complet' => true, 'role' => $ouvert['role'], 'proprietaire' => $prouve]);
      // Le rôle voyage dans la réponse : la couche cliente doit savoir si elle tient un lien
      // maître ou un lien de famille pour n'afficher que les boutons qui aboutiront. Le dire
      // ne renseigne personne — celui qui présente le lien sait déjà lequel il tient.
      // Et surtout : **le jeton a-t-il été accepté**. Dire seulement « ce lien est un lien
      // maître » ne suffit pas — un jeton révoqué laisserait l'écran croire qu'il commande,
      // et chaque bouton échouerait sans qu'on sache pourquoi.
      // `purgee` voyage dans TOUTES les réponses, pas seulement quand on annonce une purge.
      // Un téléphone qui garde son repère peut se retrouver en avance sur l'instant de la
      // purge : il ne recevrait jamais l'ordre, et continuerait d'afficher ce qui n'existe
      // plus. Avec la date, il compare et tranche lui-même.
      $scope = is_string($in['scope'] ?? null) ? (string)$in['scope'] : '';
      rel_rendre(rel_relever($pdo, $carnet, $depuis, $prouve, $scope)
                 + ['role' => $ouvert['role'], 'proprietaire' => $prouve, 'purgee' => $purgee]);

    // Le propriétaire, et lui seul, peut regarder la charge. Ce n'est pas un secret d'État,
    // mais ça décrit la santé du service : ça ne regarde pas la famille.
    // La clé PUBLIQUE des notifications. Elle n'est pas un secret — le navigateur en a besoin
    // pour s'abonner — mais elle ne se donne qu'à qui présente un lien valable : celui qui n'a
    // pas de carnet n'a rien à en faire. La clé PRIVÉE vit dans `relais/push.php`, hors du dépôt,
    // dans un dossier fermé à la lecture directe. Elle n'a jamais transité par le réseau : elle a
    // été écrite sur place le 27/08 par une sonde effacée aussitôt.
    case 'cle_push':
      if (!is_file(__DIR__ . '/relais/push.php')) rel_refus();
      require_once __DIR__ . '/relais/push.php';
      if (!defined('PUSH_PUBLIQUE')) rel_refus();
      rel_rendre(['ok' => true, 'cle' => PUSH_PUBLIQUE]);

    case 'charge':
      if (!$prouve) rel_refus();
      rel_rendre(['ok' => true, 'jours' => rel_lire_charge()]);

    // ⚠️ CE QUI EST DÉPOSÉ DOIT POUVOIR SE TRACER. Sans cela, un fichier arrive dans `depot/`
    // sous un nom tiré au sort et plus rien ne dit ce qu'il est ni s'il sert encore : le
    // pilier « on sait où est la donnée » resterait une affirmation.
    //
    // Deux questions, deux réponses, et la seconde est la plus utile :
    //   · ce que ce carnet garde — combien de fichiers, quel poids ;
    //   · les lignes qui DÉSIGNENT un fichier absent. Celles-là sont des photos que la famille
    //     ne verra jamais, sans que personne ne s'en aperçoive. Une trace sert à ça.
    //
    // ON NE MONTRE QUE CE CARNET. Le disque entier regarde celui qui tient le service, pas
    // ses clients : compter ici les orphelins de tout le monde renseignerait chaque acheteur
    // sur le trafic des autres.
    case 'depot':
      if (!$prouve) rel_refus();
      rel_rendre(['ok' => true] + rel_tracer_depot($pdo, $carnet));

    /* ⚠️ L'HORLOGE SE DEMANDE SANS PASSER PAR L'ARCHIVE — 28/08, décidé par Helmy : « la vérité,
     * c'est que tout est sur le téléphone ; si on efface le serveur on peut proposer par
     * sécurité une sauvegarde de l'application et de son contenu avec la purge ».
     *
     * La purge exige une horloge, et c'est JUSTE : elle refuse d'effacer un carnet qui a bougé
     * depuis qu'on l'a regardé (l. 681) — sans quoi on effacerait un mot de la famille arrivé
     * entre-temps, sans l'avoir lu. Mais cette horloge n'arrivait QUE par le manifeste de
     * l'archive complète : pour effacer, il fallait télécharger. Le garde technique servait de
     * péage, ce qu'il n'a jamais eu à être.
     * Elle se demande maintenant seule. Lecture pure, réservée à qui prouve le carnet. */
    case 'horloge':
      if (!$prouve) rel_refus();
      $q = $pdo->prepare("SELECT MAX(m) FROM (
            SELECT MAX(maj) AS m FROM documents    WHERE carnet = ?
            UNION ALL SELECT MAX(maj) FROM publications WHERE carnet = ?
            UNION ALL SELECT MAX(maj) FROM commentaires WHERE carnet = ?) t");
      $q->execute([$carnet, $carnet, $carnet]);
      rel_rendre(['ok' => true, 'horloge' => (int)($q->fetchColumn() ?: 0)]);

    case 'purger':
      rel_rendre(rel_purger($pdo, $carnet,
                 isset($in['horloge']) ? (int)$in['horloge'] : null));

    case 'liens':
      $quoi = is_string($in['quoi'] ?? null) ? $in['quoi'] : '';
      switch ($quoi) {
        case 'liste':   rel_rendre(['ok' => true, 'liens' => lien_liste($pdo, $lien, $jeton)]);
        /* 🔴 LE NOM PASSE PAR `rel_texte`, ET IL NE PASSAIT PAS — 24/09.
         * `trim((string)…)` n'avait aucun plafond : le seul garde était le `mb_substr($nom, 0, 255)`
         * de `lien_poser`, qui TRONQUE. Or ce nom arrive CHIFFRÉ (`__liensCreer`, couche-relais.js
         * l. 1924) : 'RTC1:' + base64url de [IV 12][chiffré N][tag 16]. À 159 octets de clair la
         * chaîne fait 255 pile ; à 160 elle fait 256 et perd un caractère de base64 — donc six bits,
         * donc le tag GCM ne vérifie plus et le nom devient INDÉCHIFFRABLE. Le lien fonctionnait
         * quand même — le jeton ne dépend pas du nom — et la corruption n'apparaissait qu'au premier
         * AUTRE appareil : un libellé absent, sans raison visible.
         * `REL_NOM_MAX` était déclarée POUR ce nom, son commentaire le dit, et ne servait qu'à
         * `commentaires.nom`. Relevé par DeepSeek, que j'avais eu tort de contredire le matin même.
         * ⚠️ LE VIDE RESTE VIDE : `rel_texte('')` rend `null`, donc `''` — et c'est ce qui
         * distingue « le lien pour tout le monde » d'un lien nommé. */
        case 'creer':
          $nom = rel_texte($in['nom'] ?? null, REL_NOM_MAX);
          if ($nom === false) rel_plainte('nom_trop_long');
          rel_rendre(['ok' => true] + lien_creer_famille($pdo, $lien, $jeton, $nom ?? ''));   // rend `id` ET `lien`
        case 'revoquer':
          $id = rel_entier($in['id'] ?? null, 1);
          if ($id === null) rel_refus();
          lien_revoquer($pdo, $lien, $jeton, $id);
          rel_rendre(['ok' => true]);
        case 'tourner': rel_rendre(['ok' => true, 'lien' => lien_tourner_maitre($pdo, $lien, $jeton)]);
      }
      rel_refus();

    case 'commenter':
      rel_rendre(rel_commenter($pdo, $carnet, $in));

    case 'ecrire':
      rel_rendre(rel_ecrire($pdo, $carnet, $in));

    case 'abonner':
      rel_rendre(rel_abonner($pdo, $carnet, $in));
  }
} catch (Throwable $e) {
  // Une exception venue du dessous ne dit jamais ce qu'elle sait.
  // rel_refus();
  /* ✚ 19/10/2026 — même raison qu'à l'ouverture du lien : pendant une RELÈVE (lecture seule, sans
   * effet si on la refait), une exception est une panne, pas un refus — 503, jamais 403. Les gestes
   * qui écrivent gardent leur 403 : on ne veut pas qu'ils soient renvoyés en boucle. */
  if ($action === 'relever') rel_rendre(['ok' => false, 'erreur' => 'Indisponible.'], 503);
  rel_refus();
}
rel_refus();
