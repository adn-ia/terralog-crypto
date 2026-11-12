<?php
/**
 * proprietaire.php — qui a le droit d'écrire.
 *
 * CE QU'ON REMPLACE. Dans l'application d'origine, `__isPoster` vient d'un paramètre dans
 * l'adresse : `?poster=1`. C'est un DÉCOR — il décide seulement quels boutons s'affichent.
 * Le vrai verrou était la règle du service de nuage, qui exigeait un compte connecté. En
 * partant sur un relais, ce verrou disparaît : si le PHP croyait l'adresse, **quiconque
 * tient le lien de la famille deviendrait propriétaire en ajoutant six caractères.**
 *
 * LE PRINCIPE. Un jeton long, tiré au sort à la création du carnet, rendu UNE fois. Le
 * serveur n'en garde que l'empreinte, salée. Le téléphone le renvoie dans un EN-TÊTE, jamais
 * dans l'adresse — une adresse se retrouve dans un historique, dans un message, dans le
 * journal d'un serveur ; un en-tête, non.
 *
 * POURQUOI PAS DE MOT DE PASSE. Un mot de passe se retape, s'oublie, se réutilise ailleurs.
 * Le jeton se range une fois dans le téléphone et ne se retape jamais. Pour un second
 * appareil, on le recopie. Pour révoquer un téléphone perdu, on en tire un nouveau : l'ancien
 * meurt à la seconde même.
 *
 * CE QUI EST DÉLIBÉRÉMENT ABSENT. Aucun compte, aucun courriel, aucune récupération par
 * courrier. Le jeton est la seule clé, et sa perte est définitive — c'est dit à la personne
 * au moment où on le lui donne, pas découvert plus tard.
 */

require_once __DIR__ . '/base.php';

/** La forme d'un jeton, et rien d'autre. Sert à écarter un en-tête hostile avant tout calcul. */
const PROP_FORME = '/^[A-Za-z0-9_-]{20,64}$/';

/** Un jeton : trente-deux octets tirés au sort, soit quarante-trois caractères d'adresse. */
function prop_nouveau_jeton(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** Un identifiant de carnet : dix-huit octets, soit vingt-quatre caractères pile. */
function prop_nouvel_identifiant(): string {
  return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

/** L'empreinte rangée en base. Le sel change d'un carnet à l'autre. */
function prop_empreinte(string $jeton, string $sel): string {
  return hash('sha256', $sel . $jeton);
}

/**
 * Crée un carnet et rend son jeton. **C'est la seule fois où le jeton existe en clair.**
 * Le rendre à la personne est le geste de l'appelant ; le serveur ne le reverra jamais.
 */
function prop_creer(PDO $pdo, string $titre = ''): array {
  $q = $pdo->prepare('INSERT INTO carnets (id,proprietaire,sel,titre,cree,maj) VALUES (?,?,?,?,?,?)');
  // Une collision sur 144 bits n'arrivera jamais. Mais si elle arrivait, l'insertion
  // échouerait en montrant une erreur de base à quelqu'un : on retire plutôt un autre numéro.
  for ($essai = 0; $essai < 3; $essai++) {
    $id = prop_nouvel_identifiant();
    $sel = bin2hex(random_bytes(16));
    $jeton = prop_nouveau_jeton();
    $t = base_maintenant();
    try {
      $q->execute([$id, prop_empreinte($jeton, $sel), $sel, mb_substr($titre, 0, 190), $t, $t]);
      return ['carnet' => $id, 'jeton' => $jeton];
    } catch (PDOException $e) {
      // ✚ 09/11/2026 — précédence : `??` passe après `!==`, une collision (1062) relançait au lieu de réessayer. Ancienne ligne :
      // if ($e->errorInfo[1] ?? 0 !== 1062) throw $e;
      if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
  }
  throw new RuntimeException('Impossible de tirer un identifiant libre.');
}

/**
 * Le jeton présenté vaut-il pour ce carnet ?
 *
 * La comparaison passe par `hash_equals` : une comparaison ordinaire s'arrête au premier
 * octet qui diffère, et le temps qu'elle met raconte alors combien d'octets étaient justes.
 */
/**
 * Crée un carnet dont le JETON EST DONNÉ, pas tiré au sort.
 *
 * ⚠️ C'est l'inverse de `prop_creer`, et il faut dire pourquoi. Dans le modèle décidé par
 * Helmy le 27/08, l'adresse du carnet et son jeton se CALCULENT sur le téléphone à partir de
 * trois choses que la personne tape — prénom, code à six chiffres, phrase secrète. Un
 * téléphone neuf retrouve alors le même carnet en retapant les mêmes choses, sans compte et
 * sans que le serveur ait rien à se rappeler.
 *
 * Le serveur ne voit toujours que l'EMPREINTE du jeton, jamais le jeton lui-même — le sel est
 * tiré au sort ici, comme pour un carnet ordinaire. Rien n'est affaibli de ce côté : ce qui
 * change, c'est seulement QUI décide de la valeur.
 */
function prop_creer_avec_jeton(PDO $pdo, string $jeton, string $titre = ''): array {
  $q = $pdo->prepare('INSERT INTO carnets (id,proprietaire,sel,titre,cree,maj) VALUES (?,?,?,?,?,?)');
  for ($essai = 0; $essai < 3; $essai++) {
    $id = prop_nouvel_identifiant();
    $sel = bin2hex(random_bytes(16));
    $t = base_maintenant();
    try {
      $q->execute([$id, prop_empreinte($jeton, $sel), $sel, mb_substr($titre, 0, 190), $t, $t]);
      return ['carnet' => $id];
    } catch (PDOException $e) {
      if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
  }
  throw new RuntimeException('Impossible de tirer un identifiant libre.');
}

function prop_verifier(PDO $pdo, string $carnet, string $jeton): bool {
  if ($jeton === '') return false;
  $q = $pdo->prepare('SELECT proprietaire, sel FROM carnets WHERE id = ?');
  $q->execute([$carnet]);
  $l = $q->fetch();
  if (!$l) {
    // Même sur un carnet inconnu, on calcule quand même : sinon le temps de réponse
    // dirait lesquels existent.
    prop_empreinte($jeton, str_repeat('0', 32));
    return false;
  }
  return hash_equals($l['proprietaire'], prop_empreinte($jeton, $l['sel']));
}

/** L'en-tête qui porte le jeton. Voir plus bas pourquoi ce n'est pas « Authorization ». */
const PROP_ENTETE = 'X-Carnet-Jeton';

/**
 * Le jeton présenté par la requête.
 *
 * ⚠️ POURQUOI PAS « Authorization ». Mesuré sur ce serveur le 25/08 : l'en-tête
 * `Authorization` est **avalé par la couche d'entrée de l'hébergeur** et n'atteint JAMAIS
 * PHP — ni dans `HTTP_AUTHORIZATION`, ni dans `REDIRECT_HTTP_AUTHORIZATION`, ni dans
 * `getallheaders()`. Une requête portant les deux en-têtes a montré que le nôtre arrive
 * intact et que l'autre a disparu.
 *
 * Une règle de réécriture le rattraperait sur certains hébergeurs, pas sur tous — et cette
 * application est destinée à tourner chez des gens dont on ne connaît pas le serveur. Un
 * en-tête à nous ne dépend d'aucune configuration. La propriété qui compte est intacte :
 * **le jeton n'est pas dans l'adresse.**
 *
 * On lit quand même « Authorization » en second : là où il passe, il fonctionne.
 */
function prop_jeton_presente(array $serveur = null, array $entetes = null): string {
  // On n'accepte que ce qui a la FORME d'un jeton. Sans ce garde, un en-tête de dix mégaoctets
  // partirait dans le calcul d'empreinte : de quoi occuper le serveur avec une seule requête.
  $bon = fn($v) => is_string($v) && preg_match(PROP_FORME, trim($v)) ? trim($v) : '';
  $s = $serveur ?? $_SERVER;
  $cle = 'HTTP_' . strtoupper(str_replace('-', '_', PROP_ENTETE));
  foreach ([$cle, 'REDIRECT_' . $cle] as $c)
    if (!empty($s[$c]) && ($j = $bon($s[$c])) !== '') return $j;
  foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $c)
    if (!empty($s[$c]) && preg_match('/^Bearer\s+(\S+)$/i', $s[$c], $m) && ($j = $bon($m[1])) !== '') return $j;
  $e = $entetes ?? (function_exists('getallheaders') ? getallheaders() : []);
  foreach ($e as $nom => $valeur) {
    if (strcasecmp($nom, PROP_ENTETE) === 0 && ($j = $bon($valeur)) !== '') return $j;
    if (strcasecmp($nom, 'authorization') === 0 && preg_match('/^Bearer\s+(\S+)$/i', (string)$valeur, $m)
        && ($j = $bon($m[1])) !== '') return $j;
  }
  return '';
}

/**
 * Le garde. **Il ne regarde JAMAIS l'adresse** : ni `?poster=1`, ni un jeton glissé dans les
 * paramètres. Un lien qu'on transmet à sa famille ne doit jamais pouvoir donner le droit
 * d'écrire, même par accident, même collé de travers.
 */
function prop_est_proprietaire(PDO $pdo, string $carnet, array $serveur = null, array $entetes = null): bool {
  return prop_verifier($pdo, $carnet, prop_jeton_presente($serveur, $entetes));
}

/**
 * Révoque et remplace. Le téléphone perdu cesse d'écrire à l'instant, sans qu'on ait à le
 * joindre. Rend le nouveau jeton, à rendre une fois à la personne.
 *
 * 🔴 IL FAUT PRÉSENTER LE JETON EN COURS. Sans cette exigence — c'était le cas jusqu'au
 * 25/08 au soir — **quiconque tient le lien de la famille pouvait révoquer le propriétaire
 * et prendre sa place**, puisque l'identifiant du carnet est ce lien. La fonction n'était
 * appelée par rien encore ; elle attendait le point d'entrée pour devenir une porte ouverte.
 *
 * Le message d'échec ne dit jamais si le carnet existe : le dire renseignerait qui essaie.
 */
function prop_tourner(PDO $pdo, string $carnet, string $jeton_en_cours): string {
  if (!prop_verifier($pdo, $carnet, $jeton_en_cours))
    throw new RuntimeException('Révocation refusée.');

  $jeton = prop_nouveau_jeton();
  $sel = bin2hex(random_bytes(16));
  $pdo->prepare('UPDATE carnets SET proprietaire = ?, sel = ?, maj = ? WHERE id = ?')
      ->execute([prop_empreinte($jeton, $sel), $sel, base_maintenant(), $carnet]);

  // On ne se fie pas au nombre de lignes touchées : MariaDB rend zéro quand rien n'a changé,
  // ce qui arriverait sur deux appels dans la même milliseconde. On vérifie le résultat.
  if (!prop_verifier($pdo, $carnet, $jeton)) throw new RuntimeException('Révocation refusée.');
  return $jeton;
}

function prop_epreuve(PDO $pdo): array {
  $r = [];
  $j = prop_nouveau_jeton();
  $r[] = ['un jeton fait 43 caractères', strlen($j) === 43];
  $r[] = ['un jeton porte 256 bits', strlen(base64_decode(strtr($j, '-_', '+/') . '=')) === 32];
  $r[] = ['deux jetons diffèrent', prop_nouveau_jeton() !== prop_nouveau_jeton()];
  $r[] = ['un identifiant fait 24 caractères', strlen(prop_nouvel_identifiant()) === 24];
  $r[] = ['un identifiant passe dans une adresse', prop_nouvel_identifiant() === rawurlencode(prop_nouvel_identifiant()) || !preg_match('/[^A-Za-z0-9_-]/', prop_nouvel_identifiant())];

  $c = prop_creer($pdo, 'Voyage à Trondheim 🇳🇴');
  $r[] = ['le carnet est créé', strlen($c['carnet']) === 24];

  $garde = $pdo->prepare('SELECT proprietaire, sel FROM carnets WHERE id = ?');
  $garde->execute([$c['carnet']]);
  $l = $garde->fetch();
  $r[] = ['le jeton n\'est PAS rangé en clair', $l['proprietaire'] !== $c['jeton'] && !str_contains($l['proprietaire'], $c['jeton'])];
  $r[] = ['le sel diffère du jeton', $l['sel'] !== $c['jeton']];

  $r[] = ['le bon jeton ouvre', prop_verifier($pdo, $c['carnet'], $c['jeton']) === true];
  $r[] = ['un jeton faux est refusé', prop_verifier($pdo, $c['carnet'], prop_nouveau_jeton()) === false];
  $r[] = ['un jeton vide est refusé', prop_verifier($pdo, $c['carnet'], '') === false];
  $r[] = ['le bon jeton sur un autre carnet est refusé', prop_verifier($pdo, prop_nouvel_identifiant(), $c['jeton']) === false];
  $r[] = ['la casse du jeton compte', prop_verifier($pdo, $c['carnet'], strtolower($c['jeton'])) === (strtolower($c['jeton']) === $c['jeton'])];

  // Le cœur de la ligne 13e : l'adresse ne donne AUCUN droit.
  $r[] = ['?poster=1 ne donne rien', prop_est_proprietaire($pdo, $c['carnet'], ['QUERY_STRING' => 'poster=1'], []) === false];
  $r[] = ['un jeton dans l\'adresse ne donne rien',
          prop_est_proprietaire($pdo, $c['carnet'], ['QUERY_STRING' => 'jeton=' . $c['jeton'], 'REQUEST_URI' => '/?jeton=' . $c['jeton']], []) === false];
  $r[] = ['notre en-tête donne le droit',
          prop_est_proprietaire($pdo, $c['carnet'], ['HTTP_X_CARNET_JETON' => $c['jeton']], []) === true];
  $r[] = ['« Authorization » marche là où il passe',
          prop_est_proprietaire($pdo, $c['carnet'], ['HTTP_AUTHORIZATION' => 'Bearer ' . $c['jeton']], []) === true];
  $r[] = ['la forme détournée d\'Apache marche aussi',
          prop_est_proprietaire($pdo, $c['carnet'], ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $c['jeton']], []) === true];
  $r[] = ['un « Authorization » sans Bearer est ignoré',
          prop_est_proprietaire($pdo, $c['carnet'], ['HTTP_AUTHORIZATION' => $c['jeton']], []) === false];
  $r[] = ['notre en-tête est lu aussi par getallheaders',
          prop_est_proprietaire($pdo, $c['carnet'], [], ['x-carnet-jeton' => $c['jeton']]) === true];

  // 🔴 Le cœur : révoquer EXIGE le jeton en cours. Tenir le lien ne suffit pas.
  try { prop_tourner($pdo, $c['carnet'], prop_nouveau_jeton());
        $r[] = ['révoquer sans le jeton en cours est refusé', false]; }
  catch (Throwable $e) { $r[] = ['révoquer sans le jeton en cours est refusé', true]; }
  $r[] = ['après ce refus, l\'ancien jeton vaut toujours', prop_verifier($pdo, $c['carnet'], $c['jeton']) === true];

  $neuf = prop_tourner($pdo, $c['carnet'], $c['jeton']);
  $r[] = ['le nouveau jeton ouvre', prop_verifier($pdo, $c['carnet'], $neuf) === true];
  $r[] = ['l\'ancien jeton est mort', prop_verifier($pdo, $c['carnet'], $c['jeton']) === false];
  try { prop_tourner($pdo, prop_nouvel_identifiant(), $neuf); $r[] = ['révoquer un carnet inconnu échoue', false]; }
  catch (Throwable $e) { $r[] = ['révoquer un carnet inconnu échoue', true];
        $r[] = ['l\'échec ne dit pas si le carnet existe', $e->getMessage() === 'Révocation refusée.']; }

  // Un en-tête hostile n'atteint jamais le calcul d'empreinte.
  $r[] = ['un en-tête démesuré est écarté',
          prop_jeton_presente(['HTTP_X_CARNET_JETON' => str_repeat('a', 200000)], []) === ''];
  $r[] = ['un jeton de forme étrangère est écarté',
          prop_jeton_presente(['HTTP_X_CARNET_JETON' => '../../etc/passwd'], []) === ''];
  $r[] = ['un jeton trop court est écarté',
          prop_jeton_presente(['HTTP_X_CARNET_JETON' => 'abc'], []) === ''];

  $pdo->prepare('DELETE FROM carnets WHERE id = ?')->execute([$c['carnet']]);
  $r[] = ['l\'épreuve n\'a rien laissé', (int)$pdo->query('SELECT COUNT(*) FROM carnets')->fetchColumn() === 0];
  return $r;
}
