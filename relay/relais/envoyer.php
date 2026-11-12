<?php
/**
 * envoyer.php — porter une notification jusqu'au service de passage du navigateur.
 *
 * Cette pièce ne décide de rien. Elle envoie, lit la réponse, et rend un verdict en un
 * mot. C'est l'appelant qui agit : effacer l'abonnement, réessayer plus tard, refaire le
 * jeton. Ce partage compte, parce que c'est le verdict qui décide si la table
 * d'abonnements reste propre ou pourrit — et une table qui pourrit finit par coûter des
 * envois à des appareils qui n'existent plus.
 *
 * CE QUI SE PROUVE ICI SANS ABONNEMENT RÉEL. Le verdict est une fonction pure : on le
 * vérifie sur tous les codes, sans réseau. Et une requête vers une adresse volontairement
 * inconnue suffit à savoir si notre signature est acceptée : un jeton mal formé se voit
 * répondre 401, un jeton correct sur une adresse inconnue se voit répondre 404. C'est la
 * meilleure preuve possible avant d'avoir un vrai téléphone en main.
 */

require_once __DIR__ . '/vapid.php';
require_once __DIR__ . '/chiffrer.php';

/** La charge utile d'une notification est bornée. Au-delà, le service refuse. */
const PUSH_TAILLE_MAX = 3800;

/**
 * Que faire d'après le code rendu. Fonction pure : aucun réseau, donc éprouvable.
 *
 *   ok        — c'est parti.
 *   effacer   — cet appareil est parti pour de bon, la ligne n'a plus lieu d'être.
 *   jeton     — la signature a été refusée : on en refait une et on rejoue une fois.
 *   trop_gros — le message ne passe pas : on le raccourcit, réessayer tel quel est vain.
 *   reessayer — c'est passager. On compte l'échec ; au cinquième d'affilée, on efface.
 */
function push_verdict(int $code): string {
  if ($code >= 200 && $code < 300)      return 'ok';
  if ($code === 404 || $code === 410)   return 'effacer';
  if ($code === 401 || $code === 403)   return 'jeton';
  if ($code === 413)                    return 'trop_gros';
  if ($code === 400)                    return 'effacer';   // abonnement illisible : il ne guérira pas
  if ($code >= 300 && $code < 400)      return 'redirige';   // aucun service connu ne le fait
  if ($code === 429)                    return 'reessayer';
  if ($code >= 500)                     return 'reessayer';
  if ($code === 0)                      return 'reessayer';  // réseau coupé, délai dépassé
  return 'reessayer';
}

/** Le délai demandé par le service, s'il en réclame un. Sinon, un recul qui double. */
function push_attente(array $entetes, int $echecs): int {
  foreach ($entetes as $ligne) {
    if (stripos($ligne, 'retry-after:') === 0) {
      $v = trim(substr($ligne, 12));
      if (ctype_digit($v)) return min((int)$v, 86400);
      $t = strtotime($v);
      if ($t !== false) return max(0, min($t - time(), 86400));
    }
  }
  return min(60 * (2 ** min(max(0, $echecs), 12)), 86400);   // l'exposant est borné : sans cela il file à l'infini
}

/** Un texte venu d'ailleurs, rendu inoffensif : imprimable, court, sans balise. */
function push_nettoyer(string $t): string {
  $t = preg_replace('/[^\P{C}\n]+/u', ' ', $t) ?? '';
  return mb_substr(trim(strip_tags($t)), 0, 200);
}

/**
 * L'adresse d'abonnement vient du navigateur d'un proche : elle n'est PAS de confiance.
 * Sans ce garde, cURL irait lire un fichier local avec « file:// », ou frapper une machine
 * du réseau interne de l'hébergeur. On n'accepte que du « https » vers une adresse publique.
 */
function push_adresse_sure(string $adresse): bool {
  $u = parse_url($adresse);
  if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) return false;
  $ip = filter_var($u['host'], FILTER_VALIDATE_IP) ? $u['host'] : gethostbyname($u['host']);
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
  return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * ✚ 09/11/2026 — L'ADRESSE, EN UN SEUL VERDICT ET UNE SEULE RÉSOLUTION (DeepSeek) :
 *   'effacer'   — mauvaise pour toujours : pas https, pas d'hôte, IP privée ou réservée ;
 *   'reessayer' — l'hôte ne se résout pas : panne de notre côté, pas de l'abonné ;
 *   'ok'        — https vers une machine publique.
 * Une IPv6 littérale arrive entre crochets (`[::1]`) : on les ôte avant de la juger.
 * `push_adresse_sure` reste telle quelle (l'inscription s'en sert). */
function push_adresse_verdict(string $adresse): string {
  $u = parse_url($adresse);
  if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) return 'effacer';
  $hote = $u['host'];
  $nu   = trim($hote, '[]');
  $drapeaux = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
  if (filter_var($nu, FILTER_VALIDATE_IP))
    return filter_var($nu, FILTER_VALIDATE_IP, $drapeaux) ? 'ok' : 'effacer';
  $ip = gethostbyname($hote);
  if ($ip === $hote) return 'reessayer';
  return filter_var($ip, FILTER_VALIDATE_IP, $drapeaux) ? 'ok' : 'effacer';
}

/**
 * Envoie un message à un abonnement.
 *
 * $abonnement : ['adresse' => …, 'point' => 65 octets, 'secret' => 16 octets]
 * $cles       : ['pem' => …, 'publique' => …, 'sujet' => 'mailto:…']
 *
 * Rend : ['code', 'verdict', 'attente', 'detail'].
 */
function push_envoyer(array $abonnement, string $message, array $cles, int $echecs = 0, int $duree = 86400): array {
  if (strlen($message) > PUSH_TAILLE_MAX)
    return ['code' => 0, 'verdict' => 'trop_gros', 'attente' => 0,
            'detail' => 'Message de ' . strlen($message) . ' octets, plafond ' . PUSH_TAILLE_MAX . '.'];

  // if (!push_adresse_sure($abonnement['adresse']))
  //   return ['code' => 0, 'verdict' => 'effacer', 'attente' => 0,
  //           'detail' => 'Adresse d\'abonnement refusée : elle doit être en https vers une machine publique.'];
  /* ✚ 09/11/2026 — UN HÔTE QUI NE SE RÉSOUT PAS N'EST PAS UN HÔTE MORT (DeepSeek, Helmy).
   * `push_adresse_sure` confond deux refus : « cette adresse est mauvaise » (pas https, IP
   * privée ou réservée) et « je n'ai pas pu la résoudre ». Le second est une panne de NOTRE
   * hébergeur ; le traiter en `effacer` supprimait un abonnement valable au premier trou de
   * DNS. `push_adresse_verdict` ne résout qu'UNE fois et rend le verdict lui-même : résoudre
   * deux fois laissait une course (1re résolution ratée, 2de réussie ⇒ effacé à tort). */
  $va = push_adresse_verdict($abonnement['adresse']);
  if ($va !== 'ok')
    return ['code' => 0, 'verdict' => $va, 'attente' => $va === 'reessayer' ? 60 : 0,
            'detail' => $va === 'reessayer'
              ? 'Hôte d\'abonnement non résolu : panne passagère, on ne supprime pas.'
              : 'Adresse d\'abonnement refusée : elle doit être en https vers une machine publique.'];

  try {
    $corps = push_chiffrer($message, $abonnement['point'], $abonnement['secret']);
    $entetes = array_merge(push_entetes($duree),
      ['Authorization: ' . vapid_autorisation($cles['pem'], $cles['publique'], $abonnement['adresse'], $cles['sujet'])]);
  } catch (PushAbonnementInvalide $e) {
    return ['code' => 0, 'verdict' => 'effacer', 'attente' => 0, 'detail' => 'Abonnement illisible : ' . $e->getMessage()];
  } catch (Throwable $e) {
    // Notre bogue, pas le sien : on ne supprime l'abonnement de personne pour ça.
    return ['code' => 0, 'verdict' => 'reessayer', 'attente' => 60, 'detail' => 'Échec de notre côté : ' . $e->getMessage()];
  }

  $recus = [];
  $c = curl_init($abonnement['adresse']);
  curl_setopt_array($c, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $corps,
    CURLOPT_HTTPHEADER => $entetes,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_PROTOCOLS_STR => 'https',   // même si l'adresse changeait sous nos pieds
    CURLOPT_FOLLOWLOCATION => false,    // suivre un renvoi d'une adresse non fiable, c'est rouvrir la porte
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,          // large sous les 300 s d'exécution, serré pour ne pas bloquer une relève
    CURLOPT_HEADERFUNCTION => function ($ch, $l) use (&$recus) { $recus[] = trim($l); return strlen($l); },
  ]);
  $reponse = curl_exec($c);
  $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
  $souci = curl_error($c);
  curl_close($c);

  $verdict = push_verdict($code);
  return [
    'code' => $code,
    'verdict' => $verdict,
    'attente' => in_array($verdict, ['reessayer', 'redirige'], true) ? push_attente($recus, $echecs) : 0,
    // Ce texte vient d'un serveur qu'on ne choisit pas. On le range nettoyé de tout ce qui
    // n'est pas imprimable, pour qu'il ne devienne pas une surprise dans un écran plus tard.
    'detail' => push_nettoyer($souci !== '' ? $souci : (string)$reponse),
  ];
}

/**
 * Ce que l'appelant doit faire d'un verdict, en une phrase. Sert aux journaux et à
 * l'écran d'administration : un mot de code n'aide personne à comprendre une panne.
 */
function push_expliquer(string $verdict): string {
  return [
    'ok'        => 'Parti.',
    'effacer'   => 'Cet appareil ne répond plus : sa ligne est retirée.',
    'jeton'     => 'Signature refusée : le jeton est refait et l\'envoi rejoué une fois.',
    'trop_gros' => 'Message trop long pour une notification : il faut le raccourcir.',
    'reessayer' => 'Le service est occupé ou en panne : on retente plus tard.',
    'redirige'  => 'Le service renvoie ailleurs : on ne suit pas, et on ne compte pas d\'échec.',
  ][$verdict] ?? 'Cas inconnu.';
}

function envoyer_epreuve(bool $avec_reseau = true): array {
  $r = [];

  // Le verdict, sur tous les codes qui comptent. Aucun réseau.
  foreach ([[201,'ok'], [200,'ok'], [202,'ok'], [404,'effacer'], [410,'effacer'], [400,'effacer'],
            [401,'jeton'], [403,'jeton'], [413,'trop_gros'], [429,'reessayer'], [500,'reessayer'],
            [503,'reessayer'], [0,'reessayer']] as [$code, $attendu]) {
    $r[] = ["verdict pour $code = $attendu", push_verdict($code) === $attendu];
  }

  $r[] = ['un délai demandé est respecté', push_attente(['Retry-After: 120'], 0) === 120];
  $r[] = ['un délai en toutes lettres est lu', push_attente(['Retry-After: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 300)], 0) > 250];
  $r[] = ['sans délai, le recul double', push_attente([], 2) === 240];
  $r[] = ['le recul est plafonné à un jour', push_attente([], 30) === 86400];
  $r[] = ['chaque verdict a son explication', !str_contains(push_expliquer('effacer'), 'inconnu')];
  foreach ([[301,'redirige'], [302,'redirige'], [307,'redirige'], [308,'redirige']] as [$code, $attendu])
    $r[] = ["verdict pour $code = $attendu", push_verdict($code) === $attendu];
  $r[] = ['un renvoi a son explication', !str_contains(push_expliquer('redirige'), 'inconnu')];
  $r[] = ['l\'exposant du recul est borné', push_attente([], 1000) === 86400];

  foreach (['file:///etc/passwd', 'http://exemple.test/a', 'https://127.0.0.1/a', 'https://192.168.1.9/a',
            'gopher://exemple.test/a', 'https://169.254.169.254/latest'] as $mauvaise)
    $r[] = ['adresse refusée : ' . substr($mauvaise, 0, 22), push_adresse_sure($mauvaise) === false];
  $r[] = ['une vraie adresse est acceptée', push_adresse_sure('https://fcm.googleapis.com/fcm/send/x') === true];
  $r[] = ['le texte du service est nettoyé', push_nettoyer("<b>a</b>\x00\x07b") === 'a b'];

  // Un message trop long est arrêté avant de partir : on ne dérange pas le service pour rien.
  $c = vapid_generer();
  $faux = ['adresse' => 'https://fcm.googleapis.com/fcm/send/inexistant', 'point' => "\x04" . str_repeat("\x01", 64), 'secret' => str_repeat("\x02", 16)];
  $t = push_envoyer($faux, str_repeat('x', 5000), ['pem' => $c['pem'], 'publique' => $c['publique'], 'sujet' => 'mailto:a@b.c']);
  $r[] = ['un message trop long ne part pas', $t['verdict'] === 'trop_gros'];

  // Une clé de destinataire absurde est refusée avant l'envoi, pas après.
  $t = push_envoyer($faux, 'court', ['pem' => $c['pem'], 'publique' => $c['publique'], 'sujet' => 'mailto:a@b.c']);
  $r[] = ['un abonnement illisible est écarté', $t['verdict'] === 'effacer'];

  if ($avec_reseau) {
    // La seule épreuve qui touche un vrai service. Une adresse volontairement inconnue :
    // si notre signature était mal formée on lirait 401 ; on attend donc autre chose.
    $abonne = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $vrai = ['adresse' => 'https://fcm.googleapis.com/fcm/send/eXeMpLe-Inexistant-0000',
             'point' => push_point_depuis_cle($abonne), 'secret' => random_bytes(16)];
    $t = push_envoyer($vrai, json_encode(['t' => 'épreuve']), ['pem' => $c['pem'], 'publique' => $c['publique'], 'sujet' => 'mailto:a@b.c']);
    $r[] = ['le service répond (code ' . $t['code'] . ')', $t['code'] > 0];
    $r[] = ['la signature n\'est PAS refusée (401/403)', $t['code'] !== 401 && $t['code'] !== 403];
    $r[] = ['adresse inconnue reconnue comme telle', in_array($t['verdict'], ['effacer', 'reessayer'], true)];
  }
  return $r;
}
