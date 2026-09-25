<?php

namespace App\Controllers\Dealer;

use App\Controllers\BaseController;
use App\Libraries\DealerScope;
use CodeIgniter\Database\BaseBuilder;

/**
 * Shared by the dealer portal.
 *
 * The dealer id comes from the session record, never from the request, and
 * every query is scoped through DealerScope. A record belonging to another
 * dealer answers exactly as a record that does not exist.
 */
abstract class DealerController extends BaseController
{
    protected const PER_PAGE = 20;

    protected DealerScope $scope;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->scope = DealerScope::current();
    }

    protected function render(string $view, string $title, string $nav, array $data = []): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        return view($view, $data + ['title' => $title, 'nav' => $nav, 'ctx' => $this->ctx]);
    }

    /** @return array{rows: list<array>, total: int, page: int, pages: int, per: int} */
    protected function paginate(BaseBuilder $builder, int $per = self::PER_PAGE): array
    {
        $page  = max(1, (int) $this->request->getGet('page'));
        $total = (clone $builder)->countAllResults(false);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);

        return [
            'rows'  => $builder->limit($per, ($page - 1) * $per)->get()->getResultArray(),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per,
        ];
    }

    protected function post(string $name, int $max = 2000): string
    {
        return mb_substr(trim((string) $this->request->getPost($name)), 0, $max);
    }
}
