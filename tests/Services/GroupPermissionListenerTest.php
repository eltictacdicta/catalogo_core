<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\GroupPermissionListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * First-party group-gating listener matrix (multitarifa tasks 2.1, R-RG-002..005).
 *
 * The listener is invoked on ArticlePermissionFilterEvent with the existing
 * ACTION_EDIT_ARTICLE semantics (D2: the event class is NOT modified). Silent
 * return = allow; deny(reason) blocks the edit. Master-off must short-circuit
 * BEFORE any model access (R-RG-005 provable inertness: models are left
 * unset, so any read would fatal the test).
 */
#[CoversClass(GroupPermissionListener::class)]
final class GroupPermissionListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The fail-closed path logs via error_log(); keep PHPUnit output clean.
        ini_set('error_log', '/dev/null');
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/core/fs_user.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_usuario.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_articulo.php';
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED],
            $GLOBALS['config2'][CatalogoOptions::KEY_MULTI_TARIFF]
        );
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED],
            $GLOBALS['config2'][CatalogoOptions::KEY_MULTI_TARIFF]
        );
        parent::tearDown();
    }

    private function event(string $referencia = 'A', string $nick = 'juan'): ArticlePermissionFilterEvent
    {
        return new ArticlePermissionFilterEvent(
            $referencia,
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            $nick
        );
    }

    /** Groups master ON via the real config2 array path (fs_settings read). */
    private function masterOn(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED] = 'true';
    }

    /**
     * R-RG-005: master off ⇒ allow WITHOUT touching group models (they are
     * deliberately left unset: any DB read would fatal).
     */
    public function test_master_off_short_circuits_to_allow_without_model_reads(): void
    {
        $listener = new GroupPermissionListener();
        $event = $this->event();

        $listener($event);

        $this->assertTrue($event->isAllowed(), 'master off must allow (inert feature)');
    }

    /** R-RG-003: admin dominance — allow regardless of group state. */
    public function test_admin_dominance_allows_without_group_reads(): void
    {
        $this->masterOn();

        $userModel = new class extends \FSFramework\model\fs_user {
            public function get($nick = '')
            {
                $u = new \FSFramework\model\fs_user();
                $u->nick = (string) $nick;
                $u->admin = true;

                return $u;
            }
        };

        $listener = new GroupPermissionListener();
        $listener->setUserModel($userModel);
        $event = $this->event('B', 'admin1');

        $listener($event);

        $this->assertTrue($event->isAllowed(), 'admin must bypass group gating entirely');
    }

    /** R-RG-004: gestor in any group ⇒ full edit allow. */
    public function test_gestor_membership_allows_edit_of_any_article(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'gestor'],
        ]));
        $event = $this->event('REF-9');

        $listener($event);

        $this->assertTrue($event->isAllowed(), 'gestor has full edit rights on scoped articles');
    }

    /** R-RG-004: editor allowed only for articles assigned to the group. */
    public function test_editor_allowed_when_article_assigned_to_group(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'editor'],
        ]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([
            [1, 'A', true],
        ]));
        $event = $this->event('A');

        $listener($event);

        $this->assertTrue($event->isAllowed(), 'editor may edit articles explicitly assigned to the group');
    }

    /** R-RG-002: editor on an out-of-scope article is denied before persistence. */
    public function test_editor_denied_when_article_not_in_group_scope(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'editor'],
        ]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([
            [1, 'B', true],
        ]));
        $event = $this->event('A');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'out-of-scope editor must be denied');
        $this->assertNotSame('', $event->getDenialReason(), 'deny must carry a user-facing reason');
    }

    /** R-RG-004: revisor is read-only — edit denied with a read-only reason. */
    public function test_revisor_is_read_only_and_denied(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'revisor'],
        ]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([
            [1, 'A', true],
        ]));
        $event = $this->event('A');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'revisor must not edit');
    }

    /** R-RG-004: visualizador is read-only — edit denied. */
    public function test_visualizador_is_read_only_and_denied(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'visualizador'],
        ]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([
            [1, 'A', true],
        ]));
        $event = $this->event('A');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'visualizador must not edit');
    }

    /** Fail-closed: a non-member under gating is denied. */
    public function test_non_member_is_denied_when_gating_on(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('pedro', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([]));
        $event = $this->event('A', 'pedro');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'user without group membership must be denied');
    }

    /** Fail-closed (R-RG-002): any Throwable inside resolution denies. */
    public function test_throwable_fails_closed_with_deny(): void
    {
        $this->masterOn();

        $userModel = new class extends \FSFramework\model\fs_user {
            public function get($nick = '')
            {
                throw new \RuntimeException('db exploded');
            }
        };

        $listener = new GroupPermissionListener();
        $listener->setUserModel($userModel);
        $event = $this->event('A', 'juan');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'listener must fail closed on internal errors');
        $this->assertSame('permission filter error', $event->getDenialReason());
    }

    /** R-RG-005: master on activates gating (deny path reachable). */
    public function test_master_on_activates_gating(): void
    {
        $this->masterOn();

        $listener = new GroupPermissionListener();
        $listener->setUserModel($this->fakeUserModel('juan', false));
        $listener->setGrupoUsuarioModel($this->fakeMembershipModel([
            ['id_grupo' => 1, 'nick' => 'juan', 'rol' => 'visualizador'],
        ]));
        $listener->setGrupoArticuloModel($this->fakeArticleScopeModel([]));
        $event = $this->event('A');

        $listener($event);

        $this->assertFalse($event->isAllowed(), 'master on must activate the group matrix');
    }

    // ---- test doubles (@internal pattern mirrors tarifario's listener) ----

    private function fakeUserModel(string $nick, bool $admin): object
    {
        return new class($nick, $admin) extends \FSFramework\model\fs_user {
            public function __construct(private readonly string $fNick, private readonly bool $fAdmin)
            {
                parent::__construct();
            }

            public function get($nick = '')
            {
                if ((string) $nick !== $this->fNick) {
                    return false;
                }

                $u = new \FSFramework\model\fs_user();
                $u->nick = $this->fNick;
                $u->admin = $this->fAdmin;

                return $u;
            }
        };
    }

    /**
     * @param list<array{id_grupo:int, nick:string, rol:string}> $memberships
     */
    private function fakeMembershipModel(array $memberships): object
    {
        return new class($memberships) extends \FSFramework\model\catalogo_grupo_usuario {
            public function __construct(private readonly array $fMemberships)
            {
                parent::__construct();
            }

            public function all_from_nick($nick): array
            {
                $out = [];
                foreach ($this->fMemberships as $m) {
                    if ($m['nick'] === (string) $nick) {
                        $row = new static([]);
                        $row->id_grupo = $m['id_grupo'];
                        $row->nick = $m['nick'];
                        $row->rol = $m['rol'];
                        $out[] = $row;
                    }
                }

                return $out;
            }
        };
    }

    /**
     * @param list<array{0:int, 1:string, 2:bool}> $assignments (id_grupo, referencia, found)
     */
    private function fakeArticleScopeModel(array $assignments): object
    {
        return new class($assignments) extends \FSFramework\model\catalogo_grupo_articulo {
            public function __construct(private readonly array $fAssignments)
            {
                parent::__construct();
            }

            public function get($id_grupo, $referencia)
            {
                foreach ($this->fAssignments as [$gid, $ref, $found]) {
                    if ($gid === (int) $id_grupo && $ref === (string) $referencia) {
                        return $found ? new static([]) : false;
                    }
                }

                return false;
            }
        };
    }
}
