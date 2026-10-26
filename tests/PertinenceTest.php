<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Pertinence;

final class PertinenceTest extends TestCase {
    public function test_statut_selon_type_de_source(): void {
        $p = new Pertinence(Pertinence::config_par_defaut());
        $this->assertSame('publie', $p->statut('sante_numerique', 'Office Hours Online'));
        $this->assertSame('publie', $p->statut('sante', 'Mini-retraite sur l’intelligence artificielle et les technologies du sommeil'));
        $this->assertSame('a_approuver', $p->statut('sante', 'Somnoclub : insomnie chez les personnes âgées'));
        $this->assertSame('publie', $p->statut('numerique', 'Humains, IA et santé au travail'));
        $this->assertSame('a_approuver', $p->statut('numerique', 'Hackathon EcoHull'));
        $this->assertSame('ecarte', $p->statut('generaliste', 'Vulnérabilité critique de PHP'));
        $this->assertSame('a_approuver', $p->statut('generaliste', 'Mise en garde : Listeria dans du pain'));
        $this->assertSame('a_approuver', $p->statut('proposition', 'Colloque IA en santé'));
    }

    public function test_statut_generaliste_et_type_inconnu(): void {
        $p = new Pertinence(Pertinence::config_par_defaut());
        $this->assertSame('publie', $p->statut('generaliste', 'IA et santé : un colloque'));
        $this->assertSame('a_approuver', $p->statut('generaliste', 'Atelier sur les données ouvertes'));
        $this->assertSame('a_approuver', $p->statut('inconnu', 'Rien de pertinent ici'));
    }

    public function test_touche(): void {
        $p = new Pertinence(Pertinence::config_par_defaut());
        $this->assertSame(['sante' => true, 'numerique' => true], $p->touche('IA et santé'));
        $this->assertSame(['sante' => false, 'numerique' => false], $p->touche('Vulnérabilité critique de PHP'));
        $this->assertSame(['sante' => true, 'numerique' => false], $p->touche('ÉPIDÉMIOLOGIE'));
    }

    public function test_themes(): void {
        $p = new Pertinence(Pertinence::config_par_defaut());
        $this->assertSame(['IA', 'Vieillissement'], $p->themes("L'IA au service de l'autonomie des aînés"));
        $this->assertSame(['IA', 'Vieillissement'], $p->themes('L’IA au service de l’autonomie des aînés'));
    }

    public function test_themes_respecte_le_maximum_et_l_ordre_de_la_configuration(): void {
        $p = new Pertinence(Pertinence::config_par_defaut());
        $texte = 'Atelier de formation sur les données et le financement';
        $this->assertSame(['Données', 'Formation'], $p->themes($texte));
        $this->assertSame(['Données', 'Formation', 'Financement'], $p->themes($texte, 3));
        $this->assertSame([], $p->themes('Sans rapport'));
    }

    public function test_configuration_partielle_et_personnalisee(): void {
        $p = new Pertinence(['mots_sante' => ['zèbre']]);
        $this->assertSame(['sante' => true, 'numerique' => true], $p->touche('Un zèbre numérique'));
        $this->assertSame(['sante' => false, 'numerique' => true], $p->touche('Un patient numérique'));

        $p = new Pertinence(['themes' => [['nom' => 'Faune', 'mots' => ['zèbre']]]]);
        $this->assertSame(['Faune'], $p->themes('Le zèbre'));
        $this->assertSame([], $p->themes("L'IA"));
    }
}
