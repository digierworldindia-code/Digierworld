<?php

namespace App\Controllers\Masters;

use App\Controllers\BaseController;
use App\Libraries\Paging;
use Throwable;

/**
 * Generic master data screens. The route filter checks that the user may edit
 * at least one master type; the per-type permission is checked here.
 */
class MasterController extends BaseController
{
    public function overview(): string
    {
        $service = service('masterData');

        return $this->render('masters/overview', [
            'title'       => 'Master data',
            'definitions' => $service->definitions(),
            'counts'      => $service->counts(),
        ]);
    }

    public function index(string $slug): string
    {
        $service = service('masterData');
        $def     = $service->definition($slug);
        $paging  = Paging::fromRequest($this->request, 30);
        $q       = trim((string) $this->request->getGet('q'));
        $status  = (string) ($this->request->getGet('status') ?? 'active');

        return $this->render('masters/index', [
            'title'   => $def['title'],
            'slug'    => $slug,
            'def'     => $def,
            'rows'    => $service->list($slug, $q, $status, $paging),
            'options' => $service->selectOptions($slug),
            'q'       => $q,
            'status'  => $status,
            'paging'  => $paging,
            'canEdit' => service('authorization')->can($def['permission']),
        ]);
    }

    public function new(string $slug): string
    {
        $service = service('masterData');
        $def     = $service->definition($slug);
        service('authorization')->authorize($def['permission']);

        return $this->form($slug, $def, null);
    }

    public function create(string $slug)
    {
        $service = service('masterData');
        $def     = $service->definition($slug);

        try {
            service('authorization')->authorize($def['permission']);
            $service->create($slug, (array) $this->request->getPost(array_keys($def['fields'])), $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url('masters/' . $slug))->with('success', ucfirst($def['singular']) . ' created.');
    }

    public function edit(string $slug, int $id): string
    {
        $service = service('masterData');
        $def     = $service->definition($slug);
        service('authorization')->authorize($def['permission']);

        return $this->form($slug, $def, $service->find($slug, $id));
    }

    public function update(string $slug, int $id)
    {
        $service = service('masterData');
        $def     = $service->definition($slug);

        return $this->perform(function () use ($service, $def, $slug, $id): void {
            service('authorization')->authorize($def['permission']);
            $service->update($slug, $id, (array) $this->request->getPost(array_keys($def['fields'])), $this->currentUser());
        }, site_url('masters/' . $slug), ucfirst($def['singular']) . ' saved.');
    }

    public function toggle(string $slug, int $id)
    {
        $service = service('masterData');
        $def     = $service->definition($slug);

        try {
            service('authorization')->authorize($def['permission']);
            $active = $service->toggle($slug, $id, $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->back()->with('success', ucfirst($def['singular']) . ($active ? ' re-activated.' : ' retired. It stays on existing reports but cannot be chosen for new ones.'));
    }

    /**
     * @param array<string, mixed>      $def
     * @param array<string, mixed>|null $row
     */
    private function form(string $slug, array $def, ?array $row): string
    {
        return $this->render('masters/form', [
            'title'   => ($row === null ? 'New ' : 'Edit ') . $def['singular'],
            'slug'    => $slug,
            'def'     => $def,
            'row'     => $row,
            'options' => service('masterData')->selectOptions($slug),
            'errors'  => session()->getFlashdata('errors') ?? [],
        ]);
    }
}
