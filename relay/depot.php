<?php
/**
 * ═══ LE DÉPÔT — un fichier existe comme fichier, avec sa propre adresse ═══════════════
 *
 * Jusqu'ici une photo voyageait EN TEXTE, encodée dans une colonne de la base
 * (`publications.vignette`). D'où tout le reste : le gonflement d'un tiers, le plafond de
 * 700 000 octets, la vidéo impossible, la voix qu'on enregistre sans pouvoir l'envoyer.
 *
 * Foss n'avait pas ce problème — il avait Firebase Storage. On l'a retiré exprès (commit
 * `8f46ced`) : le produit se vend 29,99 € UNE FOIS, sans compte et sans abonnement, quand
 * Firebase suppose un compte Google et une facture qui monte avec chaque client. Alors on
 * refait la même chose, chez nous.
 * ✚ 12/11/2026 — Le prix ci-dessus est d'une autre époque : abonnement et prix ont été
 *   supprimés le 09/11/2026 (Helmy). Terralog est gratuit ; seul reste un café, facultatif.
 *   La raison, elle, tient toujours : pas de compte Google, pas de facture qui monte.
 *
 * ── CE QU'ON NE PROMET PAS ────────────────────────────────────────────────────────────
 * Sur un mutualisé, la configuration d'Apache ne nous appartient pas. `depot/.htaccess`
 * empile quatre couches contre l'exécution et elles ont été ÉPROUVÉES avec un vrai fichier
 * PHP (403, y compris en double extension). Ce n'est pas une garantie : c'est une très
 * forte improbabilité, mesurée. La garantie demanderait un sous-domaine sans PHP — le jour
 * du VPS (ligne 125).
 *
 * ── LE DROIT D'ÉCRIRE ─────────────────────────────────────────────────────────────────
 * Exactement celui du relais, ni plus ni moins : un lien MAÎTRE **et** un jeton de
 * propriétaire vérifié en base. Vérifiés à CHAQUE requête, pas seulement à l'ouverture —
 * sinon quiconque connaîtrait un numéro de dépôt pourrait y verser ses morceaux.
 *
 * ── POURQUOI DES MORCEAUX ─────────────────────────────────────────────────────────────
 * `max_input_time` vaut 60 s ici (mesuré). Une vidéo de 80 Mo en 3G ne rentre pas dans ce
 * temps : l'envoi d'un seul bloc échouerait toujours, sans jamais dire pourquoi. Un morceau
 * d'1 Mo passe en ~23 s sur une 3G montante à 384 kbit/s — il reste de la marge.
 */

require_once __DIR__ . '/relais/base.php';
require_once __DIR__ . '/relais/proprietaire.php';
require_once __DIR__ . '/relais/liens.php';

const DEP_PUBLIC     = __DIR__ . '/depot';
const DEP_MORCEAUX   = __DIR__ . '/relais/morceaux';
const DEP_MORCEAU_MAX = 4 * 1024 * 1024;      // un morceau ne dépasse jamais cela
const DEP_TOTAL_MAX  = 200 * 1024 * 1024;     // sous `post_max_size`, avec de la marge
const DEP_TTL        = 24 * 3600;             // un dépôt abandonné disparaît après un jour

/**
 * LA LISTE BLANCHE. Le type est celui que le SERVEUR lit dans les octets, jamais celui que
 * le client annonce ni celui que l'extension raconte. Un `.php` renommé `.mp4` ne ressemble
 * pas à une vidéo : il est refusé ici, avant d'avoir un nom.
 */
const DEP_TYPES = [
  'image/jpeg' => 'jpg',  'image/png'  => 'png',  'image/webp' => 'webp',  'image/gif' => 'gif',
  'video/mp4'  => 'mp4',  'video/webm' => 'webm', 'video/quicktime' => 'mov',
  'audio/webm' => 'weba', 'audio/ogg'  => 'ogg',  'audio/mpeg' => 'mp3',   'audio/mp4' => 'm4a',
];

function dep_rendre(array $corps, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['maintenant' => base_maintenant()] + $corps, JSON_UNESCAPED_UNICODE);
  exit;
}
/** Un refus qui ne dit rien : on ne renseigne pas celui qui essaie. */
function dep_refus(): void { dep_rendre(['ok' => false, 'erreur' => 'Refusé.'], 403); }
/** Un refus qui DIT pourquoi — seulement après que le droit d'écrire a été prouvé. */
function dep_plainte(string $motif): void { dep_rendre(['ok' => false, 'motif' => $motif], 422); }

function dep_id(): string { return bin2hex(random_bytes(16)); }   // 128 bits : indevinable
// Clé d'idempotence -> id 32 hex. carnet = isolation ; taille = stable malgré l'IV aléatoire
// (re-chiffrer la même image donne des octets différents mais la MÊME taille). uid haute entropie.
function dep_cle_id(string $carnet, string $cle, int $taille): string {
  return substr(hash('sha256', $carnet.'|'.$cle.'|'.$taille), 0, 32);
}

/**
 * Le ménage, faute de tâche programmée. `crontab` n'existe pas sur cet hébergement (ligne
 * 14b), donc il se fait ICI, à l'ouverture d'un dépôt. Ce n'est PAS une garantie de délai :
 * si personne ne dépose rien pendant un mois, rien n'est balayé pendant un mois. C'est dit.
 */
function dep_menage(): void {
  $limite = time() - DEP_TTL;
  foreach (glob(DEP_MORCEAUX . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $etat = $d . '/etat.json';
    $vu = is_file($etat) ? filemtime($etat) : filemtime($d);
    if ($vu !== false && $vu < $limite) {
      foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
      @rmdir($d);
    }
  }
}

function dep_dossier(string $id): string { return DEP_MORCEAUX . '/' . $id; }

function dep_etat_lire(string $id): ?array {
  $f = dep_dossier($id) . '/etat.json';
  if (!is_file($f)) return null;
  $v = json_decode((string)@file_get_contents($f), true);
  return is_array($v) ? $v : null;
}
function dep_etat_ecrire(string $id, array $etat): bool {
  $d = dep_dossier($id);
  if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) return false;
  return @file_put_contents($d . '/etat.json', json_encode($etat), LOCK_EX) !== false;
}

/* ─────────────────────────────────────────────────────────────────────────────
   LE CHEMIN COMMUN — même forme que `relais.php` : on ouvre, puis on décide
   ───────────────────────────────────────────────────────────────────────────── */

$quoi = is_string($_POST['quoi'] ?? null) ? $_POST['quoi'] : '';
$lien = is_string($_POST['lien'] ?? null) ? $_POST['lien'] : '';
$connue = in_array($quoi, ['debut', 'morceau', 'etat', 'finir', 'abandonner'], true);

try { $pdo = base(); $ouvert = lien_ouvrir($pdo, $lien); }
catch (Throwable $e) { dep_refus(); }
if (!$connue || $ouvert === null) dep_refus();

$carnet = $ouvert['carnet'];
$jeton  = prop_jeton_presente();
// DEUX preuves pour déposer, comme pour écrire dans le relais. Un lien famille ne dépose pas.
if ($ouvert['role'] !== 'maitre' || $jeton === '' || !prop_verifier($pdo, $carnet, $jeton)) dep_refus();

if (!is_dir(DEP_MORCEAUX) && !@mkdir(DEP_MORCEAUX, 0700, true) && !is_dir(DEP_MORCEAUX)) {
  dep_plainte('depot_indisponible');
}

/* ── 1. On ouvre un dépôt ─────────────────────────────────────────────────── */
if ($quoi === 'debut') {
  dep_menage();
  $taille   = (int)($_POST['taille'] ?? 0);
  $morceaux = (int)($_POST['morceaux'] ?? 0);
  if ($taille <= 0 || $taille > DEP_TOTAL_MAX) dep_plainte('fichier_trop_gros');
  if ($morceaux <= 0 || $morceaux > 4096)      dep_plainte('decoupage_invalide');
  // Mode IDEMPOTENT : le client fournit une clé stable (uid du média). Le même média
  // retombe sur le MÊME dépôt et le MÊME fichier final => un renvoi ÉCRASE, ne double pas ;
  // et si un 'finir' a été perdu, on renvoie l'URL déjà finalisée. Sans clé : legacy inchangé.
  $cle = is_string($_POST['cle'] ?? null) ? trim($_POST['cle']) : '';
  if ($cle !== '') {
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $cle)) dep_plainte('cle_invalide');
    $id = dep_cle_id($carnet, $cle, $taille);
    $final = DEP_PUBLIC . '/' . $id . '.rtc';
    if (is_file($final)) dep_rendre(['ok' => true, 'fini' => true, 'url' => 'depot/' . $id . '.rtc',
                                     'type' => 'application/octet-stream', 'octets' => filesize($final)]);
    if (dep_etat_lire($id) !== null) dep_rendre(['ok' => true, 'depot' => $id, 'morceau_max' => DEP_MORCEAU_MAX]);
    if (!dep_etat_ecrire($id, ['carnet' => $carnet, 'taille' => $taille, 'morceaux' => $morceaux,
                               'ouvert' => base_maintenant(), 'idem' => 1])) dep_plainte('depot_indisponible');
    dep_rendre(['ok' => true, 'depot' => $id, 'morceau_max' => DEP_MORCEAU_MAX]);
  }
  $id = dep_id();
  $ok = dep_etat_ecrire($id, ['carnet' => $carnet, 'taille' => $taille,
                              'morceaux' => $morceaux, 'ouvert' => base_maintenant()]);
  if (!$ok) dep_plainte('depot_indisponible');
  dep_rendre(['ok' => true, 'depot' => $id, 'morceau_max' => DEP_MORCEAU_MAX]);
}

/* Les quatre autres gestes portent sur un dépôt existant, qui doit être CELUI DE CE CARNET. */
$id = is_string($_POST['depot'] ?? null) ? $_POST['depot'] : '';
if (!preg_match('/^[0-9a-f]{32}$/', $id)) dep_refus();
// IDEMPOTENCE de 'finir' : le dossier de morceaux est supprimé après coup, mais le nom final
// déterministe <id>.rtc permet de répondre l'URL même si l'ack précédent a été perdu.
if ($quoi === 'finir' && is_file(DEP_PUBLIC . '/' . $id . '.rtc'))
  dep_rendre(['ok' => true, 'url' => 'depot/' . $id . '.rtc',
              'type' => 'application/octet-stream', 'octets' => filesize(DEP_PUBLIC . '/' . $id . '.rtc')]);
$etat = dep_etat_lire($id);
if ($etat === null || ($etat['carnet'] ?? '') !== $carnet) dep_refus();

/* ── 2. Un morceau arrive ─────────────────────────────────────────────────── */
if ($quoi === 'morceau') {
  $index = (int)($_POST['index'] ?? -1);
  if ($index < 0 || $index >= (int)$etat['morceaux']) dep_plainte('morceau_hors_bornes');
  $f = $_FILES['fichier'] ?? null;
  if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) dep_plainte('morceau_perdu');
  if (($f['size'] ?? 0) <= 0 || $f['size'] > DEP_MORCEAU_MAX) dep_plainte('morceau_trop_gros');
  // `move_uploaded_file` et rien d'autre : elle refuse tout chemin qui ne vient pas d'un envoi.
  if (!move_uploaded_file($f['tmp_name'], dep_dossier($id) . '/' . $index . '.part')) {
    dep_plainte('morceau_non_range');
  }
  @touch(dep_dossier($id) . '/etat.json');            // le dépôt vit : le ménage l'épargne
  dep_rendre(['ok' => true, 'index' => $index]);
}

/* ── 3. Où en est-on ? — pour reprendre après une coupure ─────────────────── */
if ($quoi === 'etat') {
  $recus = [];
  for ($i = 0; $i < (int)$etat['morceaux']; $i++) {
    if (is_file(dep_dossier($id) . '/' . $i . '.part')) $recus[] = $i;
  }
  dep_rendre(['ok' => true, 'recus' => $recus, 'morceaux' => (int)$etat['morceaux']]);
}

/* ── 4. On abandonne — le client le dit quand il le peut ──────────────────── */
if ($quoi === 'abandonner') {
  foreach (glob(dep_dossier($id) . '/*') ?: [] as $x) @unlink($x);
  @rmdir(dep_dossier($id));
  dep_rendre(['ok' => true, 'abandonne' => true]);
}

/* ── 5. On recolle, on vérifie, et SEULEMENT ALORS on publie ──────────────── */
if ($quoi === 'finir') {
  $n = (int)$etat['morceaux'];
  // On ne recolle rien tant qu'il manque un morceau : mieux vaut le dire que livrer un
  // fichier tronqué qui s'ouvrira à moitié dans six mois.
  $manquants = [];
  for ($i = 0; $i < $n; $i++) if (!is_file(dep_dossier($id) . '/' . $i . '.part')) $manquants[] = $i;
  if ($manquants) dep_rendre(['ok' => false, 'motif' => 'morceaux_manquants',
                              'manquants' => array_slice($manquants, 0, 50)], 422);

  $provisoire = dep_dossier($id) . '/entier.tmp';
  $sortie = @fopen($provisoire, 'wb');
  if (!$sortie) dep_plainte('recollage_impossible');
  for ($i = 0; $i < $n; $i++) {
    $morceau = @fopen(dep_dossier($id) . '/' . $i . '.part', 'rb');
    if (!$morceau) { fclose($sortie); @unlink($provisoire); dep_plainte('recollage_impossible'); }
    stream_copy_to_stream($morceau, $sortie);
    fclose($morceau);
  }
  fclose($sortie);

  /* LE TYPE SE LIT DANS LES OCTETS. Ni le nom, ni ce que le client annonce.
   * ⚠️ IL NE DÉCIDE PLUS DE RIEN depuis le 28/08 — voir juste en dessous : seul le coffre
   * entre. On le lit encore parce qu'un refus qui NOMME ce qu'il a reçu se dépanne, et
   * qu'un refus muet se devine. `DEP_TYPES` sert désormais à cela seulement : dire, dans la
   * plainte, que ce qui est arrivé ressemblait à une photo ordinaire. */
  $type = (new finfo(FILEINFO_MIME_TYPE))->file($provisoire);
  $ext  = DEP_TYPES[$type] ?? null;

  /* ── LE COFFRE — 28/08, Helmy : « je ne veux pas voir les médias visibles sur le serveur,
   * en aucun cas ; s'il y a une brèche, on est mal. »
   *
   * Un média chiffré sur l'appareil n'est plus un JPEG : c'est du bruit, et `finfo` le rend
   * pour ce qu'il est — `application/octet-stream`. La porte au-dessus le refusait donc, et
   * c'est bien ce qu'elle doit faire pour tout le reste. On ne l'ouvre pas : on reconnaît
   * UNE forme précise, et une seule.
   *
   * ⚠️ ON NE FAIT PAS CONFIANCE AU CLIENT POUR AUTANT. On lit les quatre premiers octets du
   * fichier REÇU : ils doivent valoir exactement « RTC1 ». Un fichier arbitraire renommé ne
   * passe pas, et ce qui passe reste inexécutable — `depot/.htaccess` interdit le reniflage
   * et sert ces fichiers en pièce jointe. Le serveur, lui, ne peut rien en lire : c'est le
   * but même de l'opération. */
  /* 🔴 LA PORTE ÉTAIT RESTÉE OUVERTE, ET JE L'AI DÉCRITE FERMÉE — 28/08, mesuré.
   *
   * J'ai dit à Helmy ce matin que ce fichier n'acceptait que du chiffré. C'était FAUX : la
   * reconnaissance du coffre n'était qu'un REPLI, atteint seulement quand `finfo` ne
   * reconnaissait pas le type. Une image ordinaire passait donc par la liste blanche
   * au-dessus et se rangeait telle quelle. Éprouvé en retirant la clé maîtresse de
   * l'appareil : la photo est partie en `image/jpeg`, lisible par quiconque connaît son
   * adresse. Le pilier du produit, ouvert, en silence.
   *
   * ⚠️ LA LISTE BLANCHE NE SUFFISAIT PAS PARCE QU'ELLE NE RÉPOND PAS À LA MÊME QUESTION.
   * Elle demande « est-ce dangereux ? » — un `.php` renommé est refusé, et c'est bien. Elle
   * ne demande pas « est-ce lisible ? ». Il fallait les deux. On ne garde donc que la seconde,
   * qui contient la première : du bruit n'est ni lisible ni exécutable.
   *
   * ⚠️ ET LE SERVEUR NE CROIT TOUJOURS PAS LE CLIENT. On lit les quatre premiers octets du
   * fichier REÇU ; ils doivent valoir exactement « RTC1 ». Ce qui entre reste inexécutable —
   * `depot/.htaccess` interdit le reniflage et sert ces fichiers en pièce jointe.
   *
   * ⚠️ CE QUI EST DÉJÀ EN LIGNE EN CLAIR RESTE EN CLAIR. On ne chiffre pas après coup : c'est
   * écrit dans les CGU et dans la politique de confidentialité. Republier, ou purger, remplace. */
  $tete = @file_get_contents($provisoire, false, null, 0, 4);
  if ($tete !== 'RTC1') {
    @unlink($provisoire);
    foreach (glob(dep_dossier($id) . '/*') ?: [] as $x) @unlink($x);
    @rmdir(dep_dossier($id));
    dep_rendre(['ok' => false, 'motif' => 'non_chiffre',
                'recu' => (string)$type, 'connu' => $ext !== null], 422);
  }
  $ext = 'rtc'; $type = 'application/octet-stream';

  // ⚠️ UN WEBM AUDIO N'EST PAS UN WEBM VIDÉO, ET `finfo` NE SAIT PAS LES DISTINGUER.
  //
  // Il rend `video/webm` pour les deux — c'est le conteneur qu'il lit, pas son contenu. Un son
  // servi en `video/webm` alors qu'il n'a AUCUNE piste vidéo est rejeté par le navigateur, qui
  // ne peut pas deviner mieux puisque `depot/.htaccess` lui interdit de renifler (`nosniff`).
  // Constaté le 26/08 : `networkState = 3`, « aucune source utilisable », sans la moindre
  // erreur — un lecteur figé à 0:00 pour un fichier dont `decodeAudioData` disait « 6,00 s,
  // 2 canaux, 48 000 Hz ». Le fichier était parfait ; c'est son étiquette qui mentait.
  //
  // Le client dit donc lequel des deux c'est. **Ce n'est PAS lui faire confiance sur le type** :
  // `finfo` garde la porte, et ce choix ne départage que deux variantes du MÊME conteneur déjà
  // validé — aucune des deux n'est exécutable. Un client qui mentirait ne gagnerait qu'un
  // fichier mal étiqueté, à lui.
  /* ⚠️ LA DISTINCTION SON/VIDÉO NE SE JOUE PLUS ICI. Elle servait à étiqueter un `.webm`
   * audio autrement qu'un `.webm` vidéo, parce que le navigateur ne peut pas renifler. Un
   * média chiffré n'a plus d'étiquette du tout : son type voyage À L'INTÉRIEUR du bloc
   * (`18-coffre.js`), et c'est le travailleur de service qui le rend au moment d'afficher.
   * Le paramètre `genre` reste accepté sans effet, pour ne pas casser un envoi en attente
   * parti d'une version d'avant. */

  if (!is_dir(DEP_PUBLIC)) { @unlink($provisoire); dep_plainte('depot_indisponible'); }
  $idem = !empty($etat['idem']);
  $nom = ($idem ? $id : dep_id()) . '.' . $ext;       // idem => <id>.rtc déterministe (écrase) ; sinon tiré au sort
  if (!@rename($provisoire, DEP_PUBLIC . '/' . $nom)) {
    @unlink($provisoire); dep_plainte('publication_impossible');
  }
  @chmod(DEP_PUBLIC . '/' . $nom, 0644);
  foreach (glob(dep_dossier($id) . '/*') ?: [] as $x) @unlink($x);
  @rmdir(dep_dossier($id));

  // On ne répond « c'est là » qu'une fois que c'est vraiment là.
  dep_rendre(['ok' => true, 'url' => 'depot/' . $nom, 'type' => $type,
              'octets' => filesize(DEP_PUBLIC . '/' . $nom)]);
}

dep_refus();
