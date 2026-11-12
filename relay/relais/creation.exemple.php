<?php
/**
 * creation.exemple.php — LE MODÈLE. Ce fichier-ci ne sert à rien : c'est `creation.php`,
 * posé à la main sur le serveur, à côté de lui, qui ouvre la création de carnets.
 *
 * Tant que `creation.php` est absent, `relais.php` refuse `creer_carnet` exactement comme il
 * refuse une action inconnue. C'est voulu, et c'est le vrai verrou : le produit se vendra une
 * fois pour la vie, mais la licence n'existe pas encore (ligne 30). Une porte ouverte
 * laisserait fabriquer des carnets à l'infini.
 *
 * Pour poser la porte :
 *   1. copier ce fichier en `relais/creation.php` SUR LE SERVEUR, jamais dans le dépôt ;
 *   2. remplacer le secret par une valeur tirée au sort — par exemple, sur son Mac :
 *        openssl rand -base64 32
 *   3. régler le plafond au nombre TOTAL de carnets qu'on accepte sur ce serveur ;
 *   4. pour refermer la porte : effacer `creation.php`. Rien d'autre à défaire.
 *
 * ⚠️ Ce fichier ne doit JAMAIS entrer dans le dépôt avec un vrai secret dedans.
 */

const CREATION_SECRET   = 'REMPLACER-PAR-UNE-VALEUR-TIREE-AU-SORT';
/* ⚠️ 08/10/2026 — CETTE CONSTANTE NE PLAFONNE PLUS RIEN (Helmy : « pas de chiffre ») : les deux tests
 * `COUNT(*) >= CREATION_PLAFOND` de relais.php sont commentés. ELLE RESTE EXIGÉE — relais.php la teste
 * par `defined()`, et sa disparition fermerait la création et l'adoption SANS RIEN DIRE. Ne pas l'enlever.
 * (L'étape 3 de l'en-tête, « régler le plafond », n'a donc plus d'effet.) */
const CREATION_PLAFOND  = 5;    // au-delà, la création refuse — un emballement s'arrête net
