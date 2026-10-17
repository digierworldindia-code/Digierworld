<?php

namespace App\Filters;

use App\Libraries\Rbac;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps each panel to the people it was built for.
 *
 * There are four: the admin console, the dealer portal, the warehouse panel
 * and the field-agent panel. Permissions already decide what each route does,
 * so this is not the security boundary — it is what stops a warehouse
 * operator landing in a console full of greyed-out links, and a manager
 * wandering into a scanning screen meant for a phone.
 *
 * Declared on the route group, like `staff`, so a route added later cannot
 * forget it. Anyone sent away goes to their own panel with a line saying why,
 * never to a bare 403, because being in the wrong place is not an offence.
 *
 *   ['filter' => 'area:warehouse']
 */
class AreaFilter implements FilterInterface
{
    use MarksPrivate;

    private const ROLES = [
        'admin'     => Rbac::CONSOLE_ROLES,
        'warehouse' => Rbac::WAREHOUSE_ROLES,
        'agent'     => Rbac::AGENT_ROLES,
    ];

    private const LABEL = [
        'admin'     => 'the admin console',
        'warehouse' => 'the warehouse panel',
        'agent'     => 'the field agent panel',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $area    = $arguments[0] ?? '';
        $context = service('requestContext');
        $user    = $context->user();

        if ($user === null || ! isset(self::ROLES[$area])) {
            return null;   // auth runs first; an unknown area is a routing bug, not a decision to make here.
        }

        $roles = $user['roles'] ?? [];

        // Super Admin reaches every panel, because somebody has to be able to
        // stand at the scanning station and see what the operator sees.
        if (array_intersect($roles, self::ROLES[$area]) !== [] || in_array('SUPER_ADMIN', $roles, true)) {
            return null;
        }

        $home = Rbac::homeArea($roles, $context->isDealer());

        log_message('info', 'area.REDIRECTED user={user} wanted={area} sent={home}', [
            'user' => $context->userId() ?? 'anonymous', 'area' => $area, 'home' => $home,
        ]);

        return $this->private(
            redirect()->to(site_url($home))
                ->with('notice', ucfirst(self::LABEL[$area]) . ' is for a different role. This is your own screen.'),
        );
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
