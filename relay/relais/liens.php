<?php
/**
 * liens.php — ce qu'on met dans une adresse, et ce que ça donne le droit de faire.
 *
 * TROIS CHOSES, LONGTEMPS CONFONDUES EN UNE.
 *
 *   L'identité du carnet   interne. Jamais dans une adresse, jamais rendue à un navigateur.
 *   Le lien maître         celui du propriétaire. Il désigne le carnet POUR LE SERVEUR, et
 *                          ne donne le droit d'écrire qu'accompagné du jeton en en-tête.
 *   Les liens de famille   créés par le maître, en lecture seule. Nommés — « grand-mère » —
 *                          ou sans nom : c'est alors le lien pour tout le monde.
 *
 * CE QUI EST RENDU IMPOSSIBLE, PAS SEULEMENT INTERDIT. Depuis un lien de famille : on ne
 * remonte pas au carnet, on ne remonte pas au maître, on n'écrit pas, on ne crée pas de lien,
 * on n'en révoque aucun, **et on ne voit pas la liste des autres**. Cette dernière compte : la
 * liste porte des prénoms, et savoir que « tante Jeannine » a un accès est déjà une fuite.
 *
 * POURQUOI LE MAÎTRE EST UNE LIGNE COMME LES AUTRES. Parce qu'un lien qui n'est pas une ligne
 * ne se révoque pas. Un lien maître collé par mégarde dans un message donnerait la lecture du
 * carnet **pour toujours**, sans aucun moyen de la couper — il faudrait refaire le carnet.
 * C'est le défaut qu'une relecture a trouvé le 25/08, et c'est celui qu'on ferme ici.
 *
 * CE QU'ON RANGE. L'empreinte de la valeur, jamais la valeur. Cela protège d'une fuite
 * passive — un journal de serveur qui aurait gardé des adresses entières. **Cela ne protège
 * de rien contre une fuite active** : un lien recopié dans un message reste un lien qui
 * marche. On le dit plutôt que de laisser croire à une protection qui n'existe pas.
 */

require_once __DIR__ . '/base.php';
// `liens` s'appuie sur `proprietaire` pour vérifier un jeton : sans cette ligne, le fichier
// se charge très bien tout seul et tombe à la première vérification. Les épreuves ne l'ont
// pas vu parce qu'elles collaient tous les fichiers bout à bout — c'est le serveur qui l'a dit.
require_once __DIR__ . '/proprietaire.php';

/** Au-delà, ce n'est plus un partage de famille. Borne l'abus et la lenteur de la liste. */
const LIENS_MAX_VIVANTS = 200;

/** Une valeur de lien : trente-deux octets tirés au sort. Jamais un horodatage, jamais un compteur. */
function lien_valeur(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** La forme admise, vérifiée avant tout calcul : un en-tête ou une adresse hostile s'arrête ici. */
function lien_forme(string $v): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{20,64}$/', $v); }

function lien_empreinte(string $valeur): string { return hash('sha256', $valeur); }

/**
 * Pose un lien dont la VALEUR EST DONNÉE, pas tirée au sort.
 *
 * ⚠️ Même raison que `prop_creer_avec_jeton` : dans le modèle décidé par Helmy le 27/08,
 * c'est le TÉLÉPHONE qui calcule l'adresse, à partir de trois choses que la personne tape.
 * Le serveur n'en range que l'empreinte, exactement comme pour un lien ordinaire — il ne
 * peut donc toujours pas la reconstituer, et rien n'est affaibli de ce côté.
 */
function lien_poser_valeur(PDO $pdo, string $carnet, string $valeur, string $role, string $nom = ''): array {
  $q = $pdo->prepare('INSERT INTO liens (carnet,empreinte,role,nom,cree) VALUES (?,?,?,?,?)');
  $q->execute([$carnet, lien_empreinte($valeur), $role, mb_substr($nom, 0, 255), base_maintenant()]);
  return ['id' => (int)$pdo->lastInsertId(), 'lien' => $valeur];
}

/**
 * Ce qu'un lien ouvre. Rend `null` si la valeur est mal formée, inconnue, ou révoquée —
 * **le même `null` dans les trois cas** : distinguer renseignerait qui essaie.
 */
function lien_ouvrir(PDO $pdo, string $valeur): ?array {
  if (!lien_forme($valeur)) return null;
  $q = $pdo->prepare('SELECT carnet, role, nom, cree FROM liens WHERE empreinte = ? AND revoque = 0');
  $q->execute([lien_empreinte($valeur)]);
  $l = $q->fetch();
  return $l ?: null;
}

/** Le carnet visé, ou `null`. C'est la SEULE façon d'obtenir une identité de carnet. */
function lien_carnet(PDO $pdo, string $valeur): ?string {
  $l = lien_ouvrir($pdo, $valeur);
  return $l ? $l['carnet'] : null;
}

/** Ce lien est-il celui du propriétaire ? Ne dit rien du droit d'écrire : le jeton reste requis. */
function lien_est_maitre(PDO $pdo, string $valeur): bool {
  $l = lien_ouvrir($pdo, $valeur);
  return $l !== null && $l['role'] === 'maitre';
}

/** Pose un lien. Usage interne : la création d'un lien de famille passe par `lien_creer_famille`. */
function lien_poser(PDO $pdo, string $carnet, string $role, string $nom = ''): array {
  $q = $pdo->prepare('INSERT INTO liens (carnet,empreinte,role,nom,cree) VALUES (?,?,?,?,?)');
  for ($essai = 0; $essai < 3; $essai++) {
    $v = lien_valeur();
    // On rend AUSSI l'identifiant de la ligne. Sans lui, l'appareil qui garde la valeur ne
    // peut rattacher son lien qu'au prénom — et deux « Papa » se confondraient. C'est cet
    // identifiant, et lui seul, qui sert ensuite à révoquer.
    try { $q->execute([$carnet, lien_empreinte($v), $role, mb_substr($nom, 0, 255), base_maintenant()]);
          return ['id' => (int)$pdo->lastInsertId(), 'lien' => $v]; }
    catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1062) throw $e; }
  }
  throw new RuntimeException('Impossible de tirer une valeur de lien libre.');
}

function lien_compter_vivants(PDO $pdo, string $carnet): int {
  $q = $pdo->prepare('SELECT COUNT(*) FROM liens WHERE carnet = ? AND revoque = 0');
  $q->execute([$carnet]);
  return (int)$q->fetchColumn();
}

/**
 * Crée un lien de famille. **Exige le lien maître ET le jeton du propriétaire** : deux
 * preuves, pas une. Le nom peut être vide — c'est alors le lien pour tout le monde.
 */
function lien_creer_famille(PDO $pdo, string $lien_maitre, string $jeton, string $nom = ''): array {
  $carnet = lien_maitre_verifie($pdo, $lien_maitre, $jeton);
  if (lien_compter_vivants($pdo, $carnet) >= LIENS_MAX_VIVANTS)
    throw new RuntimeException('Trop de liens ouverts sur ce carnet : retirez-en avant d\'en créer.');
  return lien_poser($pdo, $carnet, 'famille', $nom);
}

/**
 * La liste des liens vivants, pour le sous-dossier « Liens partagés ».
 * **Réservée au propriétaire** : elle porte des prénoms.
 */
function lien_liste(PDO $pdo, string $lien_maitre, string $jeton): array {
  $carnet = lien_maitre_verifie($pdo, $lien_maitre, $jeton);
  $q = $pdo->prepare('SELECT id, role, nom, cree FROM liens WHERE carnet = ? AND revoque = 0 ORDER BY cree');
  $q->execute([$carnet]);
  return $q->fetchAll();
}

/**
 * Coupe un lien. Le sien excepté : on ne se ferme pas la porte au nez. Pour changer le lien
 * maître, on le TOURNE — c'est `lien_tourner_maitre`, qui en pose un neuf avant de couper.
 */
function lien_revoquer(PDO $pdo, string $lien_maitre, string $jeton, int $id): void {
  $carnet = lien_maitre_verifie($pdo, $lien_maitre, $jeton);
  $q = $pdo->prepare('SELECT role, empreinte FROM liens WHERE id = ? AND carnet = ? AND revoque = 0');
  $q->execute([$id, $carnet]);
  $l = $q->fetch();
  if (!$l) throw new RuntimeException('Lien introuvable.');
  if ($l['empreinte'] === lien_empreinte($lien_maitre))
    throw new RuntimeException('C\'est le lien qui vous sert à l\'instant : tournez-le plutôt que de le couper.');
  $pdo->prepare('UPDATE liens SET revoque = ? WHERE id = ?')->execute([base_maintenant(), $id]);
}

/**
 * Tourne le lien maître : en pose un neuf, coupe l'ancien, rend la nouvelle valeur.
 * C'est la parade au lien maître qui a fui. Les liens de famille n'en sont pas affectés :
 * ils pointent vers le carnet, pas vers le maître.
 */
function lien_tourner_maitre(PDO $pdo, string $lien_maitre, string $jeton): string {
  $carnet = lien_maitre_verifie($pdo, $lien_maitre, $jeton);
  $neuf = lien_poser($pdo, $carnet, 'maitre')['lien'];
  $pdo->prepare('UPDATE liens SET revoque = ? WHERE carnet = ? AND role = ? AND empreinte = ?')
      ->execute([base_maintenant(), $carnet, 'maitre', lien_empreinte($lien_maitre)]);
  return $neuf;
}

/**
 * Les deux preuves, ensemble. Un lien de famille avec le bon jeton **ne passe pas** : le lien
 * de famille n'écrit jamais, quoi qu'on présente à côté. Le refus est le même dans tous les
 * cas — lien inconnu, lien de famille, jeton faux — pour ne rien apprendre à qui essaie.
 */
function lien_maitre_verifie(PDO $pdo, string $lien_maitre, string $jeton): string {
  $l = lien_ouvrir($pdo, $lien_maitre);
  if ($l === null || $l['role'] !== 'maitre' || !prop_verifier($pdo, $l['carnet'], $jeton))
    throw new RuntimeException('Refusé.');
  return $l['carnet'];
}

/**
 * Crée un carnet ET son lien maître, d'un seul geste. **C'est par ici qu'on crée un carnet**,
 * jamais par `prop_creer` seul : un carnet sans lien maître n'est joignable par personne — pas
 * même par celui qui vient de le créer, puisque son identité ne paraît dans aucune adresse.
 *
 * Pourquoi ce geste est ici et non dans `proprietaire.php` : les liens ont besoin de savoir
 * vérifier un propriétaire, donc `liens` est au-dessus de `proprietaire`. Mettre l'appel dans
 * l'autre sens ferait deux fichiers qui s'appellent l'un l'autre.
 *
 * Rend les trois choses, **et c'est la seule fois où elles existent en clair** :
 *   carnet  l'identité interne. Ne sort jamais, ne se met dans aucune adresse.
 *   maitre  le lien du propriétaire, à mettre dans SON adresse à lui.
 *   jeton   ce qui prouve qu'il écrit, à garder dans son téléphone, jamais dans une adresse.
 */
function carnet_creer(PDO $pdo, string $titre = ''): array {
  $c = prop_creer($pdo, $titre);
  try {
    $maitre = lien_poser($pdo, $c['carnet'], 'maitre')['lien'];
  } catch (Throwable $e) {
    // Un carnet sans lien maître serait un carnet fantôme : personne ne pourrait plus jamais
    // l'atteindre, et il resterait en base pour rien. On défait plutôt que de laisser ça.
    $pdo->prepare('DELETE FROM carnets WHERE id = ?')->execute([$c['carnet']]);
    throw $e;
  }
  return ['carnet' => $c['carnet'], 'maitre' => $maitre, 'jeton' => $c['jeton']];
}

/** Un carnet joignable a exactement un lien maître vivant. Sert aux contrôles. */
function carnet_maitres_vivants(PDO $pdo, string $carnet): int {
  $q = $pdo->prepare('SELECT COUNT(*) FROM liens WHERE carnet = ? AND role = ? AND revoque = 0');
  $q->execute([$carnet, 'maitre']);
  return (int)$q->fetchColumn();
}

function liens_epreuve(PDO $pdo): array {
  $r = [];
  $c = carnet_creer($pdo, 'Voyage à Trondheim');
  $maitre = $c['maitre'];

  $r[] = ['créer un carnet rend les trois choses',
          isset($c['carnet'], $c['maitre'], $c['jeton']) && strlen($c['carnet']) === 24];
  $r[] = ['le carnet naît avec un lien maître, un seul', carnet_maitres_vivants($pdo, $c['carnet']) === 1];
  $r[] = ['le lien maître diffère du jeton', $c['maitre'] !== $c['jeton']];
  $r[] = ['l\'identité du carnet n\'est pas un lien', lien_ouvrir($pdo, $c['carnet']) === null];

  $r[] = ['une valeur de lien fait 43 caractères', strlen(lien_valeur()) === 43];
  $r[] = ['deux valeurs diffèrent', lien_valeur() !== lien_valeur()];
  $r[] = ['la valeur n\'est PAS rangée en clair',
          (int)$pdo->query('SELECT COUNT(*) FROM liens WHERE empreinte = ' . $pdo->quote($maitre))->fetchColumn() === 0];

  $r[] = ['le lien maître ouvre le carnet', lien_carnet($pdo, $maitre) === $c['carnet']];
  $r[] = ['le lien maître se reconnaît', lien_est_maitre($pdo, $maitre) === true];
  $r[] = ['un lien inconnu n\'ouvre rien', lien_ouvrir($pdo, lien_valeur()) === null];
  $r[] = ['un lien mal formé n\'ouvre rien', lien_ouvrir($pdo, '../../etc/passwd') === null];
  $r[] = ['un lien démesuré n\'ouvre rien', lien_ouvrir($pdo, str_repeat('a', 100000)) === null];

  $famille = lien_creer_famille($pdo, $maitre, $c['jeton'], 'grand-mère');
  $tous = lien_creer_famille($pdo, $maitre, $c['jeton'], '');
  $r[] = ['un lien de famille est créé', lien_carnet($pdo, $famille) === $c['carnet']];
  $r[] = ['le lien sans nom existe aussi', lien_carnet($pdo, $tous) === $c['carnet']];
  $r[] = ['un lien de famille n\'est pas maître', lien_est_maitre($pdo, $famille) === false];

  // 🔴 Le cœur : depuis un lien de famille, aucun chemin.
  foreach ([['créer un lien', fn() => lien_creer_famille($pdo, $famille, $c['jeton'], 'x')],
            ['voir la liste',  fn() => lien_liste($pdo, $famille, $c['jeton'])],
            ['révoquer',       fn() => lien_revoquer($pdo, $famille, $c['jeton'], 1)],
            ['tourner le maître', fn() => lien_tourner_maitre($pdo, $famille, $c['jeton'])]] as [$quoi, $f]) {
    try { $f(); $r[] = ["depuis un lien de famille : $quoi est REFUSÉ", false]; }
    catch (Throwable $e) { $r[] = ["depuis un lien de famille : $quoi est refusé", $e->getMessage() === 'Refusé.']; }
  }
  $r[] = ['le bon jeton ne sauve pas un lien de famille', lien_est_maitre($pdo, $famille) === false];

  // Le maître sans le jeton ne suffit pas non plus : deux preuves, pas une.
  try { lien_creer_famille($pdo, $maitre, lien_valeur(), 'x'); $r[] = ['le maître sans le jeton est refusé', false]; }
  catch (Throwable $e) { $r[] = ['le maître sans le jeton est refusé', $e->getMessage() === 'Refusé.']; }

  $liste = lien_liste($pdo, $maitre, $c['jeton']);
  $r[] = ['la liste montre les trois liens', count($liste) === 3];
  $r[] = ['la liste porte les noms', in_array('grand-mère', array_column($liste, 'nom'), true)];

  $idm = array_values(array_filter($liste, fn($l) => $l['nom'] === 'grand-mère'))[0]['id'];
  lien_revoquer($pdo, $maitre, $c['jeton'], (int)$idm);
  $r[] = ['le lien révoqué n\'ouvre plus rien', lien_ouvrir($pdo, $famille) === null];
  $r[] = ['les autres liens vivent toujours', lien_carnet($pdo, $tous) === $c['carnet']];
  $r[] = ['la liste n\'en montre plus que deux', count(lien_liste($pdo, $maitre, $c['jeton'])) === 2];

  // On ne coupe pas la branche sur laquelle on est assis.
  $liste2 = lien_liste($pdo, $maitre, $c['jeton']);
  $idmaitre = array_values(array_filter($liste2, fn($l) => $l['role'] === 'maitre'))[0]['id'];
  try { lien_revoquer($pdo, $maitre, $c['jeton'], (int)$idmaitre); $r[] = ['couper son propre lien est refusé', false]; }
  catch (Throwable $e) { $r[] = ['couper son propre lien est refusé', str_contains($e->getMessage(), 'tournez-le')]; }

  // Tourner le maître : la parade au lien qui a fui.
  $neuf = lien_tourner_maitre($pdo, $maitre, $c['jeton']);
  $r[] = ['le nouveau lien maître ouvre', lien_est_maitre($pdo, $neuf) === true];
  $r[] = ['l\'ancien lien maître est mort', lien_ouvrir($pdo, $maitre) === null];
  $r[] = ['les liens de famille survivent au tour', lien_carnet($pdo, $tous) === $c['carnet']];
  $r[] = ['après le tour, toujours un seul maître vivant', carnet_maitres_vivants($pdo, $c['carnet']) === 1];

  // Deux carnets créés coup sur coup ne se mélangent pas.
  $d = carnet_creer($pdo, 'Un autre voyage');
  $r[] = ['deux carnets ont des identités distinctes', $d['carnet'] !== $c['carnet']];
  $r[] = ['le maître de l\'un n\'ouvre pas l\'autre', lien_carnet($pdo, $d['maitre']) === $d['carnet']];
  try { lien_creer_famille($pdo, $d['maitre'], $c['jeton'], 'x'); $r[] = ['le jeton de l\'un ne vaut pas chez l\'autre', false]; }
  catch (Throwable $e) { $r[] = ['le jeton de l\'un ne vaut pas chez l\'autre', $e->getMessage() === 'Refusé.']; }
  foreach (['liens', 'carnets'] as $t)
    $pdo->prepare("DELETE FROM `$t` WHERE " . ($t === 'carnets' ? 'id' : 'carnet') . ' = ?')->execute([$d['carnet']]);

  foreach (['liens', 'carnets'] as $t) $pdo->prepare("DELETE FROM `$t` WHERE " . ($t === 'carnets' ? 'id' : 'carnet') . ' = ?')->execute([$c['carnet']]);
  $r[] = ['l\'épreuve n\'a rien laissé',
          (int)$pdo->query('SELECT COUNT(*) FROM liens')->fetchColumn() === 0
          && (int)$pdo->query('SELECT COUNT(*) FROM carnets')->fetchColumn() === 0];
  return $r;
}
