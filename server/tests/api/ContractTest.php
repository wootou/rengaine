<?php
// La table des routes (Api\Routes) et le contrat (api/openapi.yaml) disent la même chose :
// mêmes opérations, même accès sans authentification, mêmes rôles.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Api\Routes;

require_once __DIR__ . '/Contract.php';

final class ContractTest extends TestCase
{
    /** @return array<string, array{public: bool, roles: ?list<string>, implemented: bool}> */
    private static function routes(): array
    {
        $out = [];
        foreach (Routes::build()->routes() as $r) {
            $out[$r['method'] . ' ' . $r['path']] = [
                'public' => (bool) ($r['options']['public'] ?? false),
                'roles' => $r['options']['roles'] ?? null,
                'implemented' => $r['handler'] !== null,
            ];
        }
        return $out;
    }

    public function testMemesOperations(): void
    {
        $routes = self::routes();
        $contract = [];
        foreach (Contract::operations() as $o) {
            $contract[$o['method'] . ' ' . $o['path']] = $o['op'];
        }
        $this->assertSame([], array_values(array_diff(array_keys($contract), array_keys($routes))), 'opérations du contrat sans route');
        $this->assertSame([], array_values(array_diff(array_keys($routes), array_keys($contract))), 'routes absentes du contrat');
        $this->assertSame(111, count($routes));
    }

    public function testAccesEtRoles(): void
    {
        $routes = self::routes();
        foreach (Contract::operations() as $o) {
            $key = $o['method'] . ' ' . $o['path'];
            $public = ($o['op']['security'] ?? null) === [];
            $this->assertSame($public, $routes[$key]['public'], "$key : accès sans authentification");
            $this->assertSame($o['op']['x-roles'] ?? null, $routes[$key]['roles'], "$key : rôles");
        }
    }

    public function testVersionAnnoncee(): void
    {
        $this->assertSame(Contract::spec()['info']['version'], \Musique\Api\Kernel::VERSION, 'GET /server annonce la version du contrat');
    }

    public function testControleursExistent(): void
    {
        foreach (Routes::build()->routes() as $r) {
            if ($r['handler'] !== null) {
                [$class, $method] = $r['handler'];
                $this->assertTrue(method_exists($class, $method), "$class::$method manquant");
            }
        }
    }
}
