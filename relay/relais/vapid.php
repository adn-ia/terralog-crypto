<?php
/**
 * vapid.php — la signature des notifications.
 *
 * C'est la première pièce du relais et la plus piégeuse. Elle ne fait qu'une chose :
 * produire l'en-tête d'autorisation que le service de passage du navigateur exige.
 *
 * Trois pièges y sont désamorcés, chacun coûtant une demi-journée à qui les découvre seul :
 *
 *  1. openssl_sign rend une signature au format DER. Le jeton en attend une « brute » :
 *     r et s collés bout à bout, trente-deux octets chacun, zéros de tête compris.
 *     Sans cette conversion : 401, invalid_token, et aucune autre indication.
 *
 *  2. Le destinataire du jeton n'est PAS un service écrit en dur. C'est l'origine de
 *     l'adresse d'abonnement — celle d'Apple pour un iPhone, de Mozilla pour Firefox.
 *     Écrit en dur, cela casse précisément sur l'appareil pour lequel on a choisi
 *     l'envoi direct.
 *
 *  3. L'en-tête suit la forme actuelle, « vapid t=…, k=… ». Le préfixe « WebPush » et
 *     l'en-tête Crypto-Key appartiennent à un brouillon abandonné.
 *
 * Rien ici ne touche à la base ni au réseau : cette pièce se prouve seule.
 */

/** Base64 selon l'usage du web : sans remplissage, et deux caractères changés. */
function vapid_b64u_encoder(string $brut): string {
  return rtrim(strtr(base64_encode($brut), '+/', '-_'), '=');
}

function vapid_b64u_decoder(string $texte): string {
  return base64_decode(strtr($texte, '-_', '+/') . str_repeat('=', (4 - strlen($texte) % 4) % 4));
}

/**
 * Fabrique une paire de clés. À n'appeler QU'UNE FOIS dans la vie du relais :
 * changer de clé invalide tous les abonnements déjà pris.
 * Rend la clé privée au format PEM — c'est elle le secret — et la clé publique
 * sous la forme de soixante-cinq octets que le navigateur attend.
 */
function vapid_generer(): array {
  $cle = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if ($cle === false) throw new RuntimeException('Impossible de fabriquer la clé.');
  $d = openssl_pkey_get_details($cle);
  if (!isset($d['ec']['x'], $d['ec']['y'])) throw new RuntimeException('La clé produite n\'est pas une clé de courbe.');
  // str_pad ne RACCOURCIT jamais : une composante trop longue passerait sans un mot,
  // et l'on chercherait longtemps pourquoi le service refuse une clé de soixante-six octets.
  foreach (['x', 'y'] as $c)
    if (strlen($d['ec'][$c]) > 32) throw new RuntimeException('Composante ' . $c . ' de plus de trente-deux octets.');
  openssl_pkey_export($cle, $pem);
  $publique = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                     . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
  return ['pem' => $pem, 'publique' => vapid_b64u_encoder($publique)];
}

/**
 * Le piège numéro un. Extrait r et s d'une signature DER et les rend collés,
 * trente-deux octets chacun.
 *
 * La structure DER : 0x30 <longueur> 0x02 <longueur de r> <r> 0x02 <longueur de s> <s>.
 * r et s portent parfois un zéro de tête — l'ASN.1 le pose pour dire « nombre positif ».
 * Il faut l'ôter, puis re-remplir à gauche jusqu'à trente-deux.
 */
function vapid_der_vers_brut(string $der): string {
  $p = 0;
  if (($der[$p++] ?? '') !== "\x30") throw new RuntimeException('DER : séquence attendue.');
  $lg = ord($der[$p++]);
  // Une signature P-256 tient en soixante-douze octets : sa longueur s'écrit toujours sur un
  // seul octet, et ces deux cas ne peuvent pas survenir. On les barre quand même — un jour
  // quelqu'un passera autre chose à cette fonction.
  if ($lg === 0x80) throw new RuntimeException('DER : longueur indéfinie, refusée.');
  if ($lg > 0x80) {
    $n = $lg - 0x80;
    if ($n > 4) throw new RuntimeException('DER : longueur démesurée.');
    $p += $n;                                       // longueur écrite sur plusieurs octets
  }
  $lire = function () use ($der, &$p): string {
    if (($der[$p++] ?? '') !== "\x02") throw new RuntimeException('DER : entier attendu.');
    $n = ord($der[$p++]);
    $v = substr($der, $p, $n); $p += $n;
    $v = ltrim($v, "\0");                            // on ôte les zéros de tête
    if (strlen($v) > 32) throw new RuntimeException('DER : composante trop longue.');
    return str_pad($v, 32, "\0", STR_PAD_LEFT);      // puis on re-remplit à gauche
  };
  return $lire() . $lire();
}

/** Le chemin inverse, dont on se sert pour se relire soi-même. */
function vapid_brut_vers_der(string $brut): string {
  if (strlen($brut) !== 64) throw new RuntimeException('Signature brute : soixante-quatre octets attendus.');
  $entier = function (string $v): string {
    $v = ltrim($v, "\0");
    if ($v === '') $v = "\0";
    if (ord($v[0]) & 0x80) $v = "\0" . $v;           // sinon l'ASN.1 le lirait négatif
    return "\x02" . chr(strlen($v)) . $v;
  };
  $corps = $entier(substr($brut, 0, 32)) . $entier(substr($brut, 32, 32));
  return "\x30" . chr(strlen($corps)) . $corps;
}

/**
 * L'origine d'une adresse d'abonnement. Le port en fait partie quand il y en a un :
 * l'omettre ferait refuser le jeton par un service qui n'écoute pas sur le port habituel.
 * Aucun service connu n'en utilise aujourd'hui — mais l'oubli coûterait cher et la ligne
 * ne coûte rien.
 */
function vapid_origine(string $adresse): string {
  $u = parse_url($adresse);
  if (empty($u['scheme']) || empty($u['host'])) throw new RuntimeException('Adresse d\'abonnement illisible.');
  return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
}

/**
 * Le jeton signé. Douze heures de validité, une minute de recul sur l'heure de départ
 * pour absorber un serveur légèrement en avance.
 */
function vapid_jeton(string $pem, string $adresse, string $sujet, int $maintenant = null): string {
  // Le sujet doit être une adresse, pas un libellé : « mailto:… » ou « https://… ».
  // Un texte libre passe le chiffrement sans broncher et se fait refuser à l'arrivée.
  if (!preg_match('~^(mailto:[^\s@]+@[^\s@]+|https://\S+)$~', $sujet))
    throw new RuntimeException('Le sujet doit être « mailto:… » ou « https://… ».');
  $maintenant = $maintenant ?? time();
  $entete  = vapid_b64u_encoder(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $charge  = vapid_b64u_encoder(json_encode([
    'aud' => vapid_origine($adresse),
    'exp' => $maintenant + 12 * 3600,
    'iat' => $maintenant - 60,
    'sub' => $sujet,
  ], JSON_UNESCAPED_SLASHES));
  $a_signer = $entete . '.' . $charge;

  $cle = openssl_pkey_get_private($pem);
  if ($cle === false) throw new RuntimeException('Clé privée illisible.');
  if (!openssl_sign($a_signer, $der, $cle, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature refusée.');

  return $a_signer . '.' . vapid_b64u_encoder(vapid_der_vers_brut($der));
}

/** L'en-tête à poser sur la requête d'envoi. */
function vapid_autorisation(string $pem, string $publique, string $adresse, string $sujet): string {
  return 'vapid t=' . vapid_jeton($pem, $adresse, $sujet) . ', k=' . $publique;
}

/**
 * L'épreuve. Elle ne prouve pas que les notifications arrivent — elle prouve que
 * la signature est bien formée et qu'elle se relit. C'est ce qui manque le plus
 * souvent, et ce qui coûte le plus cher à découvrir dans un 401 muet.
 */
function vapid_epreuve(): array {
  $r = [];
  $c = vapid_generer();
  $r[] = ['clé publique de 65 octets', strlen(vapid_b64u_decoder($c['publique'])) === 65];
  $r[] = ['clé publique non compressée', vapid_b64u_decoder($c['publique'])[0] === "\x04"];

  $jeton = vapid_jeton($c['pem'], 'https://web.push.apple.com/abc', 'mailto:x@y.z');
  [$e, $ch, $sig] = explode('.', $jeton);
  $r[] = ['jeton en trois morceaux', count(explode('.', $jeton)) === 3];
  $r[] = ['signature de 64 octets', strlen(vapid_b64u_decoder($sig)) === 64];
  $r[] = ['aucun caractère de remplissage', strpos($jeton, '=') === false];
  $r[] = ['aucun + ni /', strpbrk($jeton, '+/') === false];

  $charge = json_decode(vapid_b64u_decoder($ch), true);
  $r[] = ['destinataire pris sur l\'adresse', $charge['aud'] === 'https://web.push.apple.com'];
  $r[] = ['pas de barre finale au destinataire', substr($charge['aud'], -1) !== '/'];
  $r[] = ['validité sous vingt-quatre heures', $charge['exp'] - time() < 86400];

  $ok = openssl_verify($e . '.' . $ch, vapid_brut_vers_der(vapid_b64u_decoder($sig)),
                       openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private($c['pem']))['key']),
                       OPENSSL_ALGO_SHA256);
  $r[] = ['la signature se relit', $ok === 1];

  // Le zéro de tête : le cas qui casse la conversion quand on l'oublie.
  $der = vapid_brut_vers_der(str_repeat("\x00", 31) . "\x01" . str_repeat("\xff", 32));
  $r[] = ['zéro de tête restitué', vapid_der_vers_brut($der) === str_repeat("\x00", 31) . "\x01" . str_repeat("\xff", 32)];

  // Un r plus court que trente-deux octets doit revenir rempli à gauche.
  $court = "\x30\x08\x02\x01\x7f\x02\x03\x00\x80\x01";
  $b = vapid_der_vers_brut($court);
  $r[] = ['composante courte re-remplie', strlen($b) === 64 && substr($b, 0, 32) === str_repeat("\x00", 31) . "\x7f"];

  $r[] = ['en-tête à la forme actuelle',
          str_starts_with(vapid_autorisation($c['pem'], $c['publique'], 'https://fcm.googleapis.com/x', 'mailto:x@y.z'), 'vapid t=')];

  // Le port fait partie de l'origine.
  $j = vapid_jeton($c['pem'], 'https://exemple.test:8443/abo', 'mailto:x@y.z');
  $ch = json_decode(vapid_b64u_decoder(explode('.', $j)[1]), true);
  $r[] = ['le port est gardé dans le destinataire', $ch['aud'] === 'https://exemple.test:8443'];

  // Un sujet qui n'est pas une adresse doit être refusé ici, pas par le service.
  try { vapid_jeton($c['pem'], 'https://x.test/a', 'Mon carnet de voyage'); $r[] = ['un sujet en texte libre est refusé', false]; }
  catch (Throwable $e) { $r[] = ['un sujet en texte libre est refusé', true]; }

  // Une longueur DER indéfinie n'existe pas ici, mais elle ne doit pas passer en silence.
  try { vapid_der_vers_brut("\x30\x80\x02\x01\x01\x02\x01\x01"); $r[] = ['une longueur indéfinie est refusée', false]; }
  catch (Throwable $e) { $r[] = ['une longueur indéfinie est refusée', true]; }
  return $r;
}
