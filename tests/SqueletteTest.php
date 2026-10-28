<?php
declare(strict_types=1);

final class SqueletteTest extends \PHPUnit\Framework\TestCase {
    public function test_autochargement_et_version(): void {
        $this->assertSame('0.4.0', RSN_VEILLE_VERSION);
        $this->assertSame('2026-10-25', rsn_veille_aujourdhui());
        $this->assertFileExists(RSN_VEILLE_DOSSIER . '/tests/fixtures/dhc.json');
    }
}
