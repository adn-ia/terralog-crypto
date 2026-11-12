<?php
/**
 * base.php — les cinq tables, et rien de plus.
 *
 * CINQ, PARCE QUE CINQ SUFFIT ET QUE QUATRE NE SUFFIRAIT PAS.
 *
 *   carnets       le voyage lui-même. Son identifiant est INTERNE : il ne paraît jamais
 *                 dans une adresse, il n'est jamais rendu à un navigateur.
 *   liens         ce qu'on met dans une adresse. Un lien maître pour le propriétaire, autant
 *                 de liens de famille qu'on veut — nommés, ou sans nom pour « tout le monde ».
 *                 **Le maître est une ligne comme les autres**, donc révocable : sans cela,
 *                 un lien maître qui fuit une fois donnerait la lecture pour toujours, sans
 *                 aucun moyen de la couper. C'est le défaut qu'une relecture a trouvé le 25/08.
 *   documents     l'itinéraire et le budget. Deux écrits d'un bloc, jamais par morceaux :
 *                 une seule table les porte tous les deux, et portera les suivants.
 *   publications  ce que le propriétaire publie : un mot, une photo, un son, une vidéo.
 *   commentaires  ce que la famille écrit. SÉPARÉ des publications, et c'est voulu.
 *   abonnements   les appareils qui veulent être prévenus.
 *
 * POURQUOI L'IDENTIFIANT DU CARNET NE SORT JAMAIS.
 * Il était, jusqu'au 25/08, à la fois l'identité en base ET le lien donné à la famille : une
 * seule chaîne pour trois rôles. C'est pour cela que la révocation du propriétaire était
 * seulement ATTEIGNABLE depuis un lien familial — il désignait le carnet. Un garde a été
 * ajouté ; mais un garde n'est pas une architecture. Désormais le serveur seul fait la
 * correspondance entre un lien et un carnet, et depuis un lien de famille il n'y a **aucun
 * chemin** vers l'identité du carnet, vers le lien maître, ni vers la révocation.
 *
 * DEUX CHOIX QUI S'ÉCARTENT DE L'AUDIT.
 *
 * 1. Les commentaires ne sont PAS des publications à qui l'on aurait mis un parent.
 *    Ils n'ont ni les mêmes colonnes, ni les mêmes droits : le propriétaire seul publie,
 *    la famille seule commente. Les fondre, c'est confier au code une frontière que la
 *    table saurait tenir toute seule.
 *
 * 2. La table des visites n'existe pas. Les visites ne sont pas des passages de la
 *    famille : ce sont des LIEUX du voyageur, rattachés à une étape, avec date et heure.
 *    Elles vivent dans l'itinéraire, comme dans le code d'origine.
 *
 * LA COLONNE QUI SAUVE LA RELÈVE : « retire ».
 * On n'efface pas une ligne quand la personne la retire, on la marque. Sans cela, un
 * téléphone qui demande « qu'est-ce qui a changé depuis telle heure » ne verrait jamais
 * les suppressions : la ligne aurait disparu, donc rien à signaler, et l'étape effacée
 * resterait à l'écran des autres pour toujours. C'est précisément l'essai de la ligne 17.
 *
 * CE QUI DOIT ARRIVER APRÈS UNE PURGE, ET QU'ON OUBLIE.
 * Le carnet garde l'instant de sa purge. À la relève suivante, un téléphone qui demande
 * « depuis tel instant » et dont l'instant précède la purge doit être renvoyé les mains
 * vides et prévenu : il lui faut EFFACER son état local et tout reprendre. Sans cette
 * règle, il continuerait d'afficher un carnet qui n'existe plus, indéfiniment.
 *
 * POURQUOI LES SECRETS SONT EN « utf8mb4_bin ».
 * Le classement ordinaire de la base ne distingue pas les majuscules des minuscules. Un
 * identifiant de lien comparé sous ce classement accepterait « Abc » pour « abc » : on
 * perdrait une partie de ce qui fait qu'un lien secret est secret. L'identifiant du carnet,
 * l'empreinte du jeton, son sel et l'empreinte d'un abonnement se comparent donc octet pour
 * octet. C'est la seule colonne qui ait besoin de cette rigueur, mais elle en a besoin.
 *
 * TOUTES LES DATES SONT DES MILLISECONDES. Aucune colonne de calendrier : MariaDB annonce
 * son fuseau comme « SYSTEM » et le voyageur traverse les fuseaux. Un instant absolu ne se
 * discute pas.
 */

/**
 * La connexion. Les identifiants ne sont PAS ici : ils vivent dans config.php, à côté,
 * et le dossier entier est fermé à la lecture directe par son .htaccess. Un fichier de
 * configuration servi en clair est la fuite la plus banale qui soit.
 */
function base(): PDO {
  static $pdo = null;
  if ($pdo !== null) return $pdo;
  require_once __DIR__ . '/config.php';
  $pdo = new PDO('mysql:host=' . BASE_HOTE . ';dbname=' . BASE_NOM . ';charset=utf8mb4',
    BASE_UTILISATEUR, BASE_MOT_DE_PASSE,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false]);
  return $pdo;
}

/** L'heure du serveur, en millisecondes. C'est ELLE qui fait foi, jamais celle du téléphone. */
function base_maintenant(): int { return (int)round(microtime(true) * 1000); }

const BASE_TABLES = <<<'SQL'
CREATE TABLE IF NOT EXISTS carnets (
  id              VARCHAR(24)  COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,  -- l'adresse du lien, tirée au sort
  proprietaire    CHAR(64)     COLLATE utf8mb4_bin NOT NULL,              -- empreinte du jeton, jamais le jeton
  sel             CHAR(32)     COLLATE utf8mb4_bin NOT NULL,
  titre           VARCHAR(190) NOT NULL DEFAULT '',
  cree            BIGINT       NOT NULL,
  maj             BIGINT       NOT NULL,
  purgee          BIGINT       NOT NULL DEFAULT 0      -- l'instant de la purge, 0 si jamais
                                                          -- (« purge » tout court est un mot réservé de MariaDB)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liens (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  carnet    VARCHAR(24)  NOT NULL,
  empreinte CHAR(64)     COLLATE utf8mb4_bin NOT NULL,   -- SHA-256 de la valeur, jamais la valeur
  role      VARCHAR(8)   NOT NULL,                       -- « maitre » ou « famille »
  -- ⚠️ 255 ET NON 60, POUR LA MÊME RAISON QUE `titre` : ce nom part chiffré depuis le 28/08.
  -- « Maman » désigne quelqu'un, et le relais ne s'en sert pas — il le range, le rend au
  -- propriétaire, et l'efface à la purge. Rien ne s'opposait à le chiffrer.
  nom       VARCHAR(255) NOT NULL DEFAULT '',            -- vide = le lien pour tout le monde
  cree      BIGINT       NOT NULL,
  revoque   BIGINT       NOT NULL DEFAULT 0,             -- l'instant de la coupure, 0 tant qu'il vit
  UNIQUE KEY idx_lien_unique (empreinte),
  KEY idx_lien_vivants (carnet, revoque)                 -- la liste ne parcourt pas les révoqués
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  carnet   VARCHAR(24) NOT NULL,
  nom      VARCHAR(32) NOT NULL,                       -- « itineraire », « budget »
  contenu  LONGTEXT    NOT NULL,
  maj      BIGINT      NOT NULL,
  PRIMARY KEY (carnet, nom),
  KEY idx_doc_maj (carnet, maj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publications (
  id        VARCHAR(24)  NOT NULL PRIMARY KEY,
  carnet    VARCHAR(24)  NOT NULL,
  etape     VARCHAR(32)      NULL,   -- PAS un nombre. Une étape s'appelle « 178768011340601 » :
                                    -- un identifiant tiré de l'horloge. Demain ce sera peut-être
                                    -- autre chose. Du texte accepte les deux ; un entier, non.
  visite    VARCHAR(24)      NULL,
  -- ⚠️ `lat` ET `lng` NE SONT PLUS ALIMENTÉES — 28/08. Elles disaient OÙ QUELQU'UN EST ALLÉ,
  -- en clair, sur un serveur qui n'en fait rien : il ne trie ni ne filtre dessus, il les
  -- rangeait et les rendait. Un trajet se reconnaît — là où l'on dort chaque nuit, c'est
  -- chez soi. Les coordonnées voyagent désormais chiffrées dans `pos`. Les colonnes restent
  -- pour les publications d'AVANT : on ne chiffre pas après coup.
  lat       DECIMAL(9,7)     NULL,
  lng       DECIMAL(10,7)    NULL,
  -- Le couple de coordonnées, chiffré par l'appareil. Du texte, parce qu'un chiffré n'est
  -- pas un nombre : DECIMAL ne peut pas le recevoir. C'est pour cela qu'il fallait une
  -- colonne de plus plutôt que réemployer les deux d'à côté.
  pos       TEXT             NULL,
  -- ⚠️ 512 ET NON 190 : le chiffrement allonge un texte d'environ 60 % (12 octets de vecteur,
  -- 16 de sceau, puis base64). À 190, un titre un peu long serait REFUSÉ par `rel_texte` une
  -- fois chiffré — refusé bruyamment, jamais tronqué, mais refusé quand même.
  lieu      VARCHAR(512) NOT NULL DEFAULT '',
  titre     VARCHAR(512) NOT NULL DEFAULT '',
  note      TEXT             NULL,
  genre     VARCHAR(16)      NULL,                     -- image, video, audio, ou rien
  media     VARCHAR(255)     NULL,                     -- le chemin du fichier déposé
  vignette  MEDIUMTEXT       NULL,                     -- la petite image, quand elle tient
  reactions TEXT             NULL,                     -- objet JSON, cœurs et pouces
  cree      BIGINT       NOT NULL,
  maj       BIGINT       NOT NULL,
  retire    TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_pub_releve (carnet, maj),
  KEY idx_pub_etape  (carnet, etape, cree)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commentaires (
  id      VARCHAR(24)  NOT NULL PRIMARY KEY,
  carnet  VARCHAR(24)  NOT NULL,
  etape   VARCHAR(32)      NULL,   -- voir publications : un identifiant, jamais un rang
  visite  VARCHAR(24)      NULL,
  parent  VARCHAR(24)      NULL,                       -- la réponse à un autre commentaire
  -- ⚠️ 255 ET 2000 DEPUIS LE 28/08 : ces deux colonnes portent du CHIFFRÉ depuis que les
  -- commentaires passent par le coffre, et personne n'avait élargi derrière. Le chiffrement
  -- allonge d'environ 60 % : à 1000, un mot de la famille un peu long était REFUSÉ —
  -- bruyamment, mais refusé, et sans que la cause soit devinable depuis l'écran.
  nom     VARCHAR(255)  NOT NULL DEFAULT '',
  texte   VARCHAR(2000) NOT NULL,
  cree    BIGINT       NOT NULL,
  maj     BIGINT       NOT NULL,
  retire  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_cmt_releve (carnet, maj),
  KEY idx_cmt_etape  (carnet, etape, cree),
  KEY idx_cmt_reponse (carnet, parent)   -- lister les réponses sans parcourir la table
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abonnements (
  id       BIGINT AUTO_INCREMENT PRIMARY KEY,
  carnet   VARCHAR(24)   NOT NULL,
  adresse  VARCHAR(500)  NOT NULL,                     -- l'adresse d'abonnement du navigateur
  empreinte CHAR(64)     COLLATE utf8mb4_bin NOT NULL,  -- pour l'unicité : l'adresse est trop longue pour un index
  point    VARBINARY(65) NOT NULL,
  secret   VARBINARY(16) NOT NULL,
  cree     BIGINT        NOT NULL,
  dernier  BIGINT        NOT NULL DEFAULT 0,
  echecs   SMALLINT      NOT NULL DEFAULT 0,
  UNIQUE KEY idx_abo_unique (carnet, empreinte),
  KEY idx_abo_carnet (carnet),
  KEY idx_abo_menage (carnet, echecs)    -- le nettoyage borné cherche les cinq échecs d'affilée
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

/** Pose les tables. Sans effet si elles existent déjà. */
function base_installer(PDO $pdo = null): array {
  $pdo = $pdo ?? base();
  $faits = [];
  foreach (array_filter(array_map('trim', explode(";\n", BASE_TABLES))) as $ordre) {
    if ($ordre === '') continue;
    // Un découpage sur le point-virgule est fragile : si un jour il en traîne un dans un
    // commentaire, on enverrait un ordre tronqué. On refuse plutôt que de l'exécuter.
    if (!preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', $ordre, $m))
      throw new RuntimeException('Ordre SQL mal découpé : « ' . substr($ordre, 0, 40) . ' ».');
    $pdo->exec($ordre);
    $faits[] = $m[1];
  }
  return $faits;
}

/**
 * ── CE QUE « CREATE TABLE IF NOT EXISTS » NE FAIT PAS ────────────────────────────────────
 *
 * Il ne touche pas une table qui existe déjà. Poser une colonne dans `BASE_TABLES` sert donc
 * aux installations neuves, et à elles seules : sur une base en service, la colonne n'arrive
 * jamais. C'est l'écart que `base_ecarts` sait dire depuis le 25/08 — mais dire n'est pas
 * réparer, et il n'y avait rien pour réparer.
 *
 * Cette fonction est ce rien. Elle n'ajoute et n'élargit QUE : jamais de colonne retirée,
 * jamais de type rétréci, jamais de donnée touchée. Élargir un VARCHAR ne coupe rien ;
 * ajouter une colonne NULL ne remplit rien. On peut la rejouer autant de fois qu'on veut —
 * chaque ordre est gardé par ce que la base contient réellement, pas par une marque qu'on
 * aurait posée à côté et qui pourrait mentir.
 *
 * ⚠️ ELLE NE TOURNE PAS À CHAQUE REQUÊTE. Un ALTER coûte, et la relève passe ici cent fois
 * par jour. Elle s'appelle à la main, une fois, depuis un fichier posé puis retiré.
 */
function base_ajuster(PDO $pdo): array {
  $faits = [];
  $col = function (string $table, string $colonne) use ($pdo) {
    $q = $pdo->prepare('SELECT COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $colonne]);
    $l = $q->fetch();
    return $l === false ? null : $l;
  };

  // 1. La colonne des coordonnées chiffrées. Absente = on la pose.
  if ($col('publications', 'pos') === null) {
    $pdo->exec('ALTER TABLE publications ADD COLUMN pos TEXT NULL AFTER lng');
    $faits[] = 'publications.pos posée';
  }

  // 2. Les colonnes que le chiffrement allonge. On n'élargit que vers le haut.
  foreach ([['publications', 'lieu', 512], ['publications', 'titre', 512],
            ['liens', 'nom', 255], ['commentaires', 'nom', 255], ['commentaires', 'texte', 2000]] as [$t, $c, $large]) {
    $l = $col($t, $c);
    if ($l === null) { $faits[] = "$t.$c ABSENTE — rien fait"; continue; }
    if ((int)$l['CHARACTER_MAXIMUM_LENGTH'] >= $large) continue;
    $pdo->exec("ALTER TABLE `$t` MODIFY `$c` VARCHAR($large) NOT NULL DEFAULT ''");
    $faits[] = "$t.$c élargie de " . (int)$l['CHARACTER_MAXIMUM_LENGTH'] . " à $large";
  }
  return $faits ?: ['rien à faire — la base est déjà au schéma'];
}

/**
 * ✚ 09/11/2026 — OUBLIER LES DATES DÉJÀ ÉCRITES DANS `abonnements` (Helmy, principe n° 1).
 *
 * Cette table ne garde plus aucune date : `rel_abonner` écrit 0 depuis ce jour. Mais la base
 * EN SERVICE porte encore, pour chaque inscription d'avant, son instant en millisecondes.
 *
 * ⚠️ PAS DANS `base_ajuster` : elle promet de n'ajouter et d'élargir QUE, « jamais de donnée
 * touchée ». Un second geste, nommé, appelé à la main une fois, comme l'autre. Les colonnes
 * restent (on ne retire jamais une colonne) ; seules leurs valeurs passent à 0. Rejouable.
 */
function base_oublier_dates_abo(PDO $pdo): array {
  $eu = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abonnements'
                       AND COLUMN_NAME IN ('cree','dernier')")->fetchAll(PDO::FETCH_COLUMN);
  if (!$eu) return ['ni cree ni dernier : rien à oublier'];
  $q = $pdo->prepare('UPDATE abonnements SET ' . implode(', ', array_map(fn($c) => "`$c` = 0", $eu))
                     . ' WHERE ' . implode(' OR ', array_map(fn($c) => "`$c` <> 0", $eu)));
  $q->execute();
  $n = $q->rowCount();
  return $n === 0 ? ['aucune date à oublier — la table est déjà muette']
                  : ["$n abonnement(s) : " . implode(' et ', $eu) . ' remis à 0'];
}

/**
 * ✚ 09/11/2026 — LES PIERRES TOMBALES D'AVANT CE JOUR (Helmy, choix « B », principe n° 1).
 *
 * La purge laisse désormais une pierre sans date (`rel_purger_vraiment`). Les carnets purgés
 * AVANT gardent encore : l'instant de la purge, leurs dates de création et de modification, et
 * les lignes de leurs liens de famille révoqués (avec leurs dates). Ce geste les aligne.
 *
 * ⚠️ SEULEMENT SUR UN CARNET VRAIMENT VIDE : `purgee > 0` ET ni publication, ni commentaire, ni
 * document. Un carnet purgé autrefois puis REPUBLIÉ est vivant : on n'y touche pas. Sur un vide,
 * on ne supprime que les liens RÉVOQUÉS ; un carnet qui a un lien de famille VIVANT a été
 * repartagé depuis : on n'y touche pas davantage. Sinon, dates à 0 et `purgee` à 1 (« vidé »).
 * Appel manuel, une fois, comme `base_oublier_dates_abo`. Rejouable.
 */
function base_oublier_dates_purgees(PDO $pdo): array {
  $vides = $pdo->query("SELECT c.id FROM carnets c
      WHERE c.purgee > 0
        AND NOT EXISTS (SELECT 1 FROM publications p WHERE p.carnet = c.id)
        AND NOT EXISTS (SELECT 1 FROM commentaires m WHERE m.carnet = c.id)
        AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.carnet = c.id)")->fetchAll(PDO::FETCH_COLUMN);
  $liens = 0; $dates = 0; $vivants = 0;
  foreach ($vides as $id) {
    $pdo->beginTransaction();
    try {
      // Les liens de famille révoqués sont morts, partout : ils partent.
      $q = $pdo->prepare("DELETE FROM liens WHERE carnet = ? AND role = 'famille' AND revoque <> 0");
      $q->execute([$id]); $liens += $q->rowCount();
      /* ⚠️ UN LIEN DE FAMILLE VIVANT = UN CARNET VIVANT (DeepSeek). L'ancienne purge révoquait
       * TOUS les liens de famille : un lien vivant a donc été donné APRÈS elle — le carnet a été
       * repartagé, même sans rien publier. Son nom (« Maman »), le titre : légitimes. On n'y
       * touche pas. */
      $q = $pdo->prepare("SELECT COUNT(*) FROM liens WHERE carnet = ? AND role = 'famille' AND revoque = 0");
      $q->execute([$id]);
      if ((int)$q->fetchColumn() > 0) { $vivants++; $pdo->commit(); continue; }
      // Une vraie pierre : les maîtres révoqués partent aussi, s'il reste un maître vivant
      // (c'est lui qui garde l'adresse — voir rel_purger_vraiment).
      $q = $pdo->prepare("SELECT COUNT(*) FROM liens WHERE carnet = ? AND role = 'maitre' AND revoque = 0");
      $q->execute([$id]);
      if ((int)$q->fetchColumn() > 0) {
        $q = $pdo->prepare("DELETE FROM liens WHERE carnet = ? AND role = 'maitre' AND revoque <> 0");
        $q->execute([$id]); $liens += $q->rowCount();
      }
      $pdo->prepare("UPDATE liens SET nom = '', cree = 0 WHERE carnet = ? AND role = 'maitre'")->execute([$id]);
      $q = $pdo->prepare("UPDATE carnets SET titre = '', cree = 0, maj = 0, purgee = 1 WHERE id = ?
                            AND (cree <> 0 OR maj <> 0 OR purgee <> 1 OR titre <> '')");
      $q->execute([$id]); $dates += $q->rowCount();
      $pdo->commit();
      // Les deux repères (des dates, et le nom du carnet), comme `rel_purger_residus`.
      if (preg_match('/^[A-Za-z0-9_-]{1,24}$/', $id)) {
        @unlink(__DIR__ . '/reperes/' . $id . '.txt');
        @unlink(__DIR__ . '/reperes/' . $id . '.avis');
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
  return [count($vides) . ' carnet(s) purgé(s) sans contenu', "$vivants repartagé(s) depuis : laissé(s) en vie",
          "$liens lien(s) révoqué(s) supprimé(s)", "$dates pierre(s) remise(s) sans date"];
}

/**
 * Ce que la base contient VRAIMENT, comparé à ce qu'on croit y avoir mis.
 *
 * « CREATE TABLE IF NOT EXISTS » ne touche pas une table qui existe déjà : le jour où l'on
 * ajoute un index ou change un classement, la base garde l'ancien et personne n'en sait rien.
 * On relève donc l'état réel, et on le dit.
 */
function base_ecarts(PDO $pdo): array {
  $ecarts = [];
  $vues = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
  foreach (base_noms() as $t) if (!in_array($t, $vues, true)) $ecarts[] = "table absente : $t";

  $attendus = ['carnets' => [], 'liens' => ['PRIMARY', 'idx_lien_unique', 'idx_lien_vivants'], 'documents' => ['PRIMARY', 'idx_doc_maj'],
    'publications' => ['PRIMARY', 'idx_pub_releve', 'idx_pub_etape'],
    'commentaires' => ['PRIMARY', 'idx_cmt_releve', 'idx_cmt_etape', 'idx_cmt_reponse'],
    'abonnements'  => ['PRIMARY', 'idx_abo_unique', 'idx_abo_carnet', 'idx_abo_menage']];
  foreach ($attendus as $t => $index) {
    if (!in_array($t, $vues, true)) continue;
    $vus = array_unique(array_column($pdo->query("SHOW INDEX FROM `$t`")->fetchAll(), 'Key_name'));
    foreach ($index as $i) if (!in_array($i, $vus, true)) $ecarts[] = "index absent : $t.$i";
  }

  /* Les colonnes que le chiffrement exige. Sans cette vérification, une base restée à
   * l'ancien schéma refuserait les titres un peu longs sans qu'on sache pourquoi. */
  foreach ([['publications', 'pos', 0], ['publications', 'lieu', 512], ['publications', 'titre', 512],
            ['liens', 'nom', 255], ['commentaires', 'nom', 255], ['commentaires', 'texte', 2000]] as [$t, $c, $large]) {
    if (!in_array($t, $vues, true)) continue;
    $q = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$t, $c]);
    $l = $q->fetch(PDO::FETCH_NUM);
    if ($l === false) { $ecarts[] = "colonne absente : $t.$c"; continue; }
    if ($large && (int)$l[0] < $large) $ecarts[] = "colonne trop étroite : $t.$c (" . (int)$l[0] . " < $large)";
  }

  $binaires = [['carnets', 'id'], ['carnets', 'proprietaire'], ['carnets', 'sel'],
               ['abonnements', 'empreinte'], ['liens', 'empreinte']];
  foreach ($binaires as [$t, $c]) {
    $q = $pdo->prepare('SELECT COLLATION_NAME FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$t, $c]);
    $col = $q->fetchColumn();
    if ($col !== false && $col !== 'utf8mb4_bin') $ecarts[] = "$t.$c compare sans distinguer la casse ($col)";
  }
  return $ecarts;
}

/** Les cinq noms, pour qui veut vérifier. */
function base_noms(): array { return ['carnets', 'liens', 'documents', 'publications', 'commentaires', 'abonnements']; }

/**
 * Repose les tables à neuf. Refuse net s'il reste la moindre ligne : on ne détruit pas
 * le carnet de quelqu'un pour rattraper un changement de schéma.
 */
function base_refaire(PDO $pdo): void {
  foreach (base_noms() as $t) {
    $existe = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    if ($existe && (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() > 0)
      throw new RuntimeException("La table $t contient des lignes : refus de la refaire.");
  }
  foreach (array_reverse(base_noms()) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
  base_installer($pdo);
}

function base_epreuve(PDO $pdo): array {
  $r = [];
  $posees = base_installer($pdo);
  $r[] = ['les six tables sont posées', count($posees) === 6];

  $vues = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
  foreach (base_noms() as $t) $r[] = ["la table $t existe", in_array($t, $vues, true)];

  // Le classement doit être explicite partout : le défaut de la base trie mal les accents.
  $mal = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'")->fetchColumn();
  $r[] = ['toutes en utf8mb4_unicode_ci', (int)$mal === 0];

  // Aucune colonne de calendrier : les dates sont des instants absolus.
  $cal = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('datetime','timestamp','date')")->fetchColumn();
  $r[] = ['aucune colonne de calendrier', (int)$cal === 0];

  $t = base_maintenant();
  $carnet = 'epr' . bin2hex(random_bytes(8));
  $pdo->prepare('INSERT INTO carnets (id,proprietaire,sel,titre,cree,maj) VALUES (?,?,?,?,?,?)')
      ->execute([$carnet, str_repeat('a', 64), str_repeat('b', 32), 'Voyage à Trondheim 🇳🇴', $t, $t]);

  $pdo->prepare('INSERT INTO documents (carnet,nom,contenu,maj) VALUES (?,?,?,?)')
      ->execute([$carnet, 'itineraire', json_encode(['visits' => [['id' => 'v1', 'parent' => 3, 'date' => '2026-07-14']]], JSON_UNESCAPED_UNICODE), $t]);
  $doc = json_decode($pdo->query("SELECT contenu FROM documents WHERE carnet='$carnet' AND nom='itineraire'")->fetchColumn(), true);
  $r[] = ['les visites vivent dans l\'itinéraire', $doc['visits'][0]['parent'] === 3];

  $pdo->prepare('INSERT INTO commentaires (id,carnet,etape,nom,texte,cree,maj) VALUES (?,?,?,?,?,?,?)')
      ->execute(['c' . bin2hex(random_bytes(8)), $carnet, 3, 'grand-mère', 'Quelle lumière ! 🌅', $t, $t]);
  $lu = $pdo->query("SELECT nom,texte FROM commentaires WHERE carnet='$carnet'")->fetch();
  $r[] = ['accents et émoji tiennent en base', $lu['nom'] === 'grand-mère' && str_contains($lu['texte'], '🌅')];

  // La relève par delta : ce qui a changé après un instant donné.
  $pub = 'p' . bin2hex(random_bytes(8));
  $pdo->prepare('INSERT INTO publications (id,carnet,etape,lieu,cree,maj) VALUES (?,?,?,?,?,?)')
      ->execute([$pub, $carnet, 3, 'Nidaros', $t, $t]);
  $apres = $pdo->prepare('SELECT COUNT(*) FROM publications WHERE carnet=? AND maj > ?');
  $apres->execute([$carnet, $t]); $r[] = ['rien de neuf après l\'instant courant', (int)$apres->fetchColumn() === 0];

  // On retire, et la relève DOIT le voir. C'est tout l'intérêt de « retire ».
  $pdo->prepare('UPDATE publications SET retire=1, maj=? WHERE id=?')->execute([$t + 1, $pub]);
  $apres->execute([$carnet, $t]);
  $r[] = ['une suppression se voit dans la relève', (int)$apres->fetchColumn() === 1];
  $r[] = ['la ligne retirée existe encore', (int)$pdo->query("SELECT COUNT(*) FROM publications WHERE id='$pub'")->fetchColumn() === 1];

  // Un même appareil ne s'inscrit pas deux fois.
  $ins = $pdo->prepare('INSERT IGNORE INTO abonnements (carnet,adresse,empreinte,point,secret,cree) VALUES (?,?,?,?,?,?)');
  $emp = hash('sha256', 'https://exemple/abo');
  // $ins->execute([$carnet, 'https://exemple/abo', $emp, str_repeat("\x04", 65), str_repeat("\x05", 16), $t]);
  // $ins->execute([$carnet, 'https://exemple/abo', $emp, str_repeat("\x04", 65), str_repeat("\x05", 16), $t]);
  /* ✚ 09/11/2026 — 0 et non $t : cette table ne garde plus de date. */
  $ins->execute([$carnet, 'https://exemple/abo', $emp, str_repeat("\x04", 65), str_repeat("\x05", 16), 0]);
  $ins->execute([$carnet, 'https://exemple/abo', $emp, str_repeat("\x04", 65), str_repeat("\x05", 16), 0]);
  $r[] = ['un appareil ne s\'inscrit qu\'une fois', (int)$pdo->query("SELECT COUNT(*) FROM abonnements WHERE carnet='$carnet'")->fetchColumn() === 1];
  /* ✚ 09/11/2026 — le principe lui-même : plus une seule date dans cette table. */
  $r[] = ['aucune date n\'est gardée pour un abonnement',
          (int)$pdo->query("SELECT COUNT(*) FROM abonnements WHERE carnet='$carnet' AND (cree <> 0 OR dernier <> 0)")->fetchColumn() === 0];

  // Les octets bruts ressortent bruts : un point de clé abîmé rendrait tout envoi muet.
  $pt = $pdo->query("SELECT point FROM abonnements WHERE carnet='$carnet'")->fetchColumn();
  $r[] = ['la clé ressort intacte, 65 octets', strlen($pt) === 65 && $pt === str_repeat("\x04", 65)];

  // La purge : tout le carnet disparaît, et il ne reste rien.
  foreach (['abonnements', 'commentaires', 'publications', 'documents'] as $t2)
    $pdo->prepare("DELETE FROM $t2 WHERE carnet=?")->execute([$carnet]);
  $pdo->prepare('DELETE FROM carnets WHERE id=?')->execute([$carnet]);
  $reste = 0;
  foreach (base_noms() as $t2) {
    $col = $t2 === 'carnets' ? 'id' : 'carnet';
    $q = $pdo->prepare("SELECT COUNT(*) FROM $t2 WHERE $col=?"); $q->execute([$carnet]);
    $reste += (int)$q->fetchColumn();
  }
  $r[] = ['après la purge il ne reste rien', $reste === 0];

  // Ce que la base rend VRAIMENT : entier ou chaîne ? On mesure au lieu de croire.
  $t3 = $pdo->query('SELECT CAST(3 AS SIGNED) e, CAST(1787000000000 AS SIGNED) g')->fetch();
  $r[] = ['un entier revient en entier', is_int($t3['e'])];
  $r[] = ['un grand entier revient en entier', is_int($t3['g'])];

  // L'identifiant du lien se compare octet pour octet, sinon « Abc » vaudrait « abc ».
  $c2 = 'Epr' . bin2hex(random_bytes(6));
  $pdo->prepare('INSERT INTO carnets (id,proprietaire,sel,cree,maj) VALUES (?,?,?,?,?)')
      ->execute([$c2, str_repeat('a', 64), str_repeat('b', 32), $t, $t]);
  $q = $pdo->prepare('SELECT COUNT(*) FROM carnets WHERE id = ?');
  $q->execute([strtolower($c2)]);
  $r[] = ['la casse du lien compte', (int)$q->fetchColumn() === 0];
  $pdo->prepare('DELETE FROM carnets WHERE id = ?')->execute([$c2]);

  // Et l'état réel de la base, comparé à ce qu'on croit y avoir mis.
  $ecarts = base_ecarts($pdo);
  $r[] = ['la base est conforme au schéma' . ($ecarts ? ' (' . implode(' · ', $ecarts) . ')' : ''), $ecarts === []];
  return $r;
}
