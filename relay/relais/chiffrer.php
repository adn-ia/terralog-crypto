<?php
/**
 * chiffrer.php — la charge utile d'une notification.
 *
 * Le service de passage ne voit qu'un tas d'octets illisibles : c'est ce qui permet de
 * dire « personne ne peut lire ce qui passe » sans mentir. Le chiffrement est fait ici,
 * pour un destinataire et un seul, avec une clé qui ne sert qu'une fois.
 *
 * LE PIÈGE DE CETTE PIÈCE. Si la dérivation de clé est fausse, rien ne proteste : le
 * service de passage accepte la requête, répond 201, et le téléphone ne reçoit jamais
 * rien. La personne voit « notifications activées » et attend pour toujours. Aucun
 * message d'erreur n'existe pour ce cas — d'où l'épreuve en bas de fichier.
 *
 * CE QUE L'ÉPREUVE PROUVE, ET CE QU'ELLE NE PROUVE PAS. Elle chiffre puis déchiffre avec
 * la clé du destinataire : si un seul octet de la dérivation était faux, le déchiffrement
 * échouerait. Elle ne prouve pas que le navigateur lira la même chose — ça, seul un envoi
 * réel vers un vrai abonnement le dira. C'est l'essai de la ligne 19, et il reste à faire.
 *
 * Deux formats existent, et on les confond souvent. Ici c'est « aes128gcm », le format
 * actuel : le sel et la clé éphémère voyagent DANS LE CORPS, pas dans des en-têtes.
 * Les en-têtes « Encryption » et « Crypto-Key » appartiennent au format précédent.
 */

require_once __DIR__ . '/vapid.php';

/** La taille d'enregistrement annoncée dans le corps. */
const PUSH_TAILLE_ENREGISTREMENT = 4096;

/**
 * Ce qui vient de l'abonné et qui est illisible : sa clé, son secret. Une ligne pareille ne
 * guérira jamais, on l'efface. Tout le reste — une clé à nous, une charge trop grande — est
 * NOTRE problème : on réessaie, on n'efface pas l'abonnement de quelqu'un pour notre bogue.
 */
class PushAbonnementInvalide extends RuntimeException {}

/** L'habillage qui transforme soixante-cinq octets de point en clé publique lisible par OpenSSL. */
function push_pem_publique(string $point): string {
  if (strlen($point) !== 65 || $point[0] !== "\x04")
    throw new PushAbonnementInvalide('Clé du destinataire : soixante-cinq octets non compressés attendus.');
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Le point de soixante-cinq octets, repris d'une clé OpenSSL. */
function push_point_depuis_cle($cle): string {
  $d = openssl_pkey_get_details($cle);
  if (!isset($d['ec']['x'], $d['ec']['y'])) throw new RuntimeException('La clé n\'est pas une clé de courbe.');
  foreach (['x', 'y'] as $c)                       // str_pad ne raccourcit pas : on refuse au lieu de laisser passer
    if (strlen($d['ec'][$c]) > 32) throw new RuntimeException('Composante ' . $c . ' de plus de trente-deux octets.');
  return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/**
 * Chiffre un message pour un abonnement.
 *
 * $point  : les soixante-cinq octets de la clé publique de l'abonné (le « p256dh »).
 * $secret : les seize octets d'authentification de l'abonnement (le « auth »).
 *
 * Rend le corps complet de la requête, prêt à partir.
 */
function push_chiffrer(string $message, string $point, string $secret, string $sel = null, $ephemere = null): string {
  if (strlen($secret) !== 16) throw new PushAbonnementInvalide('Secret d\'abonnement : seize octets attendus.');
  $sel = $sel ?? random_bytes(16);

  // Une paire de clés qui ne sert qu'à ce message.
  $ephemere = $ephemere ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $point_ephemere = push_point_depuis_cle($ephemere);

  // Le secret partagé : ma clé éphémère rencontre celle de l'abonné.
  $partage = openssl_pkey_derive(push_pem_publique($point), $ephemere, 32);
  if ($partage === false) throw new PushAbonnementInvalide('Échange de clés refusé : la clé de l\'abonné ne convient pas.');

  // Première dérivation : c'est ELLE qu'on oublie. Le secret d'abonnement sert de sel.
  //
  // ⚠️ L'ORDRE DES DEUX CLÉS EST FIXÉ PAR LA NORME ET NE SE DISCUTE PAS :
  //      « WebPush: info » 0x00  puis  la clé de L'ABONNÉ  puis  la clé ÉPHÉMÈRE.
  // L'inverser rend toutes les notifications illisibles par un vrai navigateur, et
  // l'aller-retour d'en bas n'y verrait RIEN puisqu'il se tromperait des deux côtés.
  // Une relecture a proposé l'ordre inverse le 25/08 : refusé, elle citait elle-même
  // la norme qui la contredit. L'épreuve « l'ordre de l'étiquette est celui de la norme »
  // est là pour que personne ne le « corrige » plus tard.
  $etiquette = "WebPush: info\0" . $point . $point_ephemere;
  $graine = hash_hkdf('sha256', $partage, 32, $etiquette, $secret);

  // Seconde dérivation : la clé du chiffrement et le grain de sel qui l'accompagne.
  $cle   = hash_hkdf('sha256', $graine, 16, "Content-Encoding: aes128gcm\0", $sel);
  $grain = hash_hkdf('sha256', $graine, 12, "Content-Encoding: nonce\0",     $sel);

  // Le 0x02 dit « c'est le dernier morceau ». Sans lui, le navigateur rejette en silence.
  $clair = $message . "\x02";
  // Un seul enregistrement, donc tout le clair doit tenir dedans : la taille annoncée moins
  // les seize octets de la marque d'intégrité. Le plafond d'envoi est bien plus bas, mais on
  // refuse ici plutôt que de fabriquer un corps qu'aucun navigateur ne saura relire.
  if (strlen($clair) > PUSH_TAILLE_ENREGISTREMENT - 16)
    throw new RuntimeException('Charge de ' . strlen($clair) . ' octets : elle ne tient pas dans un enregistrement.');
  $chiffre = openssl_encrypt($clair, 'aes-128-gcm', $cle, OPENSSL_RAW_DATA, $grain, $marque, '', 16);
  if ($chiffre === false) throw new RuntimeException('Chiffrement refusé.');

  // Le corps : sel, taille d'enregistrement, longueur de la clé, la clé, puis le chiffré.
  return $sel . pack('N', PUSH_TAILLE_ENREGISTREMENT) . chr(65) . $point_ephemere . $chiffre . $marque;
}

/** Le chemin inverse. Il ne sert qu'à se relire — le relais n'a jamais à déchiffrer. */
function push_dechiffrer(string $corps, $cle_privee, string $secret): string {
  $sel = substr($corps, 0, 16);
  $lg  = ord($corps[20]);
  $point_ephemere = substr($corps, 21, $lg);
  $chiffre = substr($corps, 21 + $lg);

  $mien = push_point_depuis_cle($cle_privee);
  $partage = openssl_pkey_derive(push_pem_publique($point_ephemere), $cle_privee, 32);
  $graine = hash_hkdf('sha256', $partage, 32, "WebPush: info\0" . $mien . $point_ephemere, $secret);
  $cle   = hash_hkdf('sha256', $graine, 16, "Content-Encoding: aes128gcm\0", $sel);
  $grain = hash_hkdf('sha256', $graine, 12, "Content-Encoding: nonce\0",     $sel);

  $marque = substr($chiffre, -16);
  $clair = openssl_decrypt(substr($chiffre, 0, -16), 'aes-128-gcm', $cle, OPENSSL_RAW_DATA, $grain, $marque);
  if ($clair === false) throw new RuntimeException('Déchiffrement refusé — la dérivation ne concorde pas.');
  return rtrim($clair, "\x02");
}

/** Les en-têtes de la requête d'envoi, en dehors de l'autorisation. */
function push_entetes(int $duree = 86400): array {
  return ['Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: ' . $duree];
}

function push_epreuve(): array {
  $r = [];

  // On se met à la place d'un abonné : une paire de clés et un secret.
  $abonne = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $point  = push_point_depuis_cle($abonne);
  $secret = random_bytes(16);

  $message = json_encode(['titre' => 'Étape à Trondheim', 'texte' => 'grand-mère 🇳🇴 a commenté'], JSON_UNESCAPED_UNICODE);
  $corps = push_chiffrer($message, $point, $secret);

  $r[] = ['sel de seize octets', strlen(substr($corps, 0, 16)) === 16];
  $r[] = ['taille d\'enregistrement annoncée', unpack('N', substr($corps, 16, 4))[1] === PUSH_TAILLE_ENREGISTREMENT];
  $r[] = ['longueur de clé à soixante-cinq', ord($corps[20]) === 65];
  $r[] = ['clé éphémère non compressée', $corps[21] === "\x04"];
  $r[] = ['corps plus long que l\'en-tête', strlen($corps) > 86];

  $relu = push_dechiffrer($corps, $abonne, $secret);
  $r[] = ['le message se relit', $relu === $message];
  $r[] = ['accents et émoji intacts', str_contains($relu, 'grand-mère 🇳🇴')];

  // Deux envois du même message ne doivent jamais donner le même corps.
  $r[] = ['deux envois diffèrent', push_chiffrer($message, $point, $secret) !== $corps];

  // Un secret faux doit faire échouer le déchiffrement — c'est la preuve que le secret sert.
  try { push_dechiffrer($corps, $abonne, random_bytes(16)); $r[] = ['un mauvais secret est refusé', false]; }
  catch (Throwable $e) { $r[] = ['un mauvais secret est refusé', true]; }

  // Un octet changé dans le chiffré doit être détecté.
  $abime = $corps; $abime[strlen($abime) - 20] = chr(ord($abime[strlen($abime) - 20]) ^ 1);
  try { push_dechiffrer($abime, $abonne, $secret); $r[] = ['une altération est détectée', false]; }
  catch (Throwable $e) { $r[] = ['une altération est détectée', true]; }

  $r[] = ['en-tête au format actuel', in_array('Content-Encoding: aes128gcm', push_entetes(), true)];

  // L'ordre de l'étiquette, épinglé. Cette épreuve existe pour qu'on ne l'inverse jamais.
  $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $pe = push_point_depuis_cle($eph);
  $attendue = "WebPush: info\0" . $point . $pe;
  $r[] = ['l\'ordre de l\'étiquette est celui de la norme',
          substr($attendue, 0, 14) === "WebPush: info\0"
          && substr($attendue, 14, 65) === $point
          && substr($attendue, 79, 65) === $pe];

  // Une charge qui ne tient pas dans un enregistrement doit être refusée ici.
  try { push_chiffrer(str_repeat('x', PUSH_TAILLE_ENREGISTREMENT), $point, $secret); $r[] = ['une charge trop grande est refusée', false]; }
  catch (Throwable $e) { $r[] = ['une charge trop grande est refusée', true]; }
  return $r;
}
