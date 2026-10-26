# RSN : veille de l'écosystème

Extension WordPress du Réseau santé numérique (RSN). Elle collecte des sources externes (flux RSS, calendriers, pages publiques), en tire des fiches d'actualités et d'événements, puis les présente sur une page Veille. Les règles de confiance, la priorité des niveaux et la conception complète se trouvent dans `docs/`. Ce document sera complété au fil du développement.

## Lancer les tests

Les classes pures de `includes/` (espace de noms `RSN\Veille`) sont testées avec PHPUnit, sans WordPress, grâce aux bouchons de `tests/bootstrap.php`. Il faut PHP 8.0 ou plus et Composer : `composer install` installe PHPUnit (dépendance de développement seulement), puis `composer test` lance la suite. Les contenus réels servant d'exemples sont dans `tests/fixtures/`.

## Lancer wp-env

Pour essayer l'extension dans un WordPress local, il faut Docker et `@wordpress/env` : `npx @wordpress/env start` démarre un site (WordPress stable, PHP 8.2) avec l'extension active, par défaut sur http://localhost:8888 (administration : `/wp-admin`, utilisateur `admin`, mot de passe `password`, valeurs propres à l'environnement local). `npx @wordpress/env stop` l'arrête.
