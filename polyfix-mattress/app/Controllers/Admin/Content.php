<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Website content: the CMS pages, the marketing copy on each product, and the
 * FAQs. Page sections are edited as JSON because that is what the public site
 * renders — the editor validates it before saving, so a typo cannot take the
 * site down.
 */
class Content extends AdminController
{
    public function index(): string
    {
        $db = db_connect();

        return $this->render('admin/content/index', 'Website content', 'admin/content', [
            'pages'    => $db->table('website_pages')->select('id, slug, title, status, updated_at')->orderBy('slug')->get()->getResultArray(),
            'products' => $db->table('products p')->select('p.id, p.name, p.slug, p.status, wp.is_published, wp.headline, wp.updated_at')
                ->join('website_products wp', 'wp.product_id = p.id', 'left')
                ->where('p.deleted_at', null)->orderBy('p.sort_order')->get()->getResultArray(),
            'faqs'     => $db->table('faqs f')->select('f.id, f.question, f.answer, f.category, f.is_published, f.sort_order, p.name product')
                ->join('products p', 'p.id = f.product_id', 'left')->orderBy('f.category')->orderBy('f.sort_order')->get()->getResultArray(),
        ]);
    }

    public function page(string $slug): string
    {
        $page = db_connect()->table('website_pages')->where('slug', $slug)->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/content/page', 'Page: ' . $page['title'], 'admin/content', ['page' => $page]);
    }

    public function savePage(string $slug): RedirectResponse
    {
        $title    = $this->post('title', 200);
        $status   = (string) $this->request->getPost('status');
        $hero     = (string) $this->request->getPost('hero');
        $sections = (string) $this->request->getPost('sections');

        return $this->act(function () use ($slug, $title, $status, $hero, $sections): void {
            if (! in_array($status, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
                throw AppException::rule('Choose a valid status.');
            }
            $heroData     = self::decode($hero, 'hero');
            $sectionsData = self::decode($sections, 'sections');
            if (! array_is_list($sectionsData)) {
                throw AppException::rule('Sections must be a list of blocks, each with a "type".');
            }
            foreach ($sectionsData as $section) {
                if (! is_array($section) || ! isset($section['type']) || ! is_string($section['type'])) {
                    throw AppException::rule('Every section needs a "type" the website knows how to render.');
                }
                if (! is_file(APPPATH . 'Views/sections/' . preg_replace('/[^a-z-]/', '', $section['type']) . '.php')) {
                    throw AppException::rule('There is no renderer for section type "' . $section['type'] . '". Publishing it would leave a gap on the page.');
                }
            }

            Tx::run(function (BaseConnection $db) use ($slug, $title, $status, $heroData, $sectionsData): void {
                $before = $db->table('website_pages')->where('slug', $slug)->get()->getRowArray() ?? throw AppException::notFound('page');
                $audit  = Audit::instance($db);
                $audit->version('website_page', $before['id'], $before, 'content edited');
                $db->table('website_pages')->where('slug', $slug)->update([
                    'title'        => $title,
                    'status'       => $status,
                    'hero'         => json_encode($heroData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sections'     => json_encode($sectionsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'published_at' => $status === 'PUBLISHED' ? ($before['published_at'] ?? utc_now()) : $before['published_at'],
                    'updated_by'   => $this->ctx->userId(),
                ]);
                $audit->record('CMS_PAGE_UPDATED', 'website_page', $before['id'],
                    ['status' => $before['status']], ['status' => $status, 'slug' => $slug, 'sections' => count($sectionsData)]);
            }, db_connect());
        }, 'Page saved.', site_url('admin/content/pages/' . $slug));
    }

    public function product(string $id): string
    {
        $db      = db_connect();
        $product = $db->table('products')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/content/product', 'Website copy: ' . $product['name'], 'admin/content', [
            'p'       => $product,
            'website' => $db->table('website_products')->where('product_id', $id)->get()->getRowArray(),
        ]);
    }

    public function saveProduct(string $id): RedirectResponse
    {
        $in = [
            'headline'    => $this->post('headline', 200),
            'subheadline' => $this->post('subheadline', 300),
            'body_html'   => $this->post('body_html', 20000),
            'gallery'     => (string) $this->request->getPost('gallery'),
            'highlights'  => (string) $this->request->getPost('highlights'),
            'published'   => $this->request->getPost('is_published') === '1',
        ];

        return $this->act(function () use ($id, $in): void {
            $gallery    = self::decode($in['gallery'], 'gallery');
            $highlights = self::decode($in['highlights'], 'highlights');
            foreach ($gallery as $image) {
                if (! is_array($image) || ! isset($image['src'], $image['alt'])) {
                    throw AppException::rule('Every gallery image needs "src" and "alt" (the alt text is what a screen reader announces).');
                }
                if (! preg_match('#^/images/[A-Za-z0-9/_-]+\.(svg|png|jpg|jpeg|webp)$#', (string) $image['src'])) {
                    throw AppException::rule('Image paths must point inside /images.');
                }
            }

            Tx::run(function (BaseConnection $db) use ($id, $in, $gallery, $highlights): void {
                $product = $db->table('products')->select('name')->where('id', $id)->get()->getRowArray() ?? throw AppException::notFound('product');
                $existing = $db->table('website_products')->where('product_id', $id)->get()->getRowArray();
                $row = [
                    'headline'     => $in['headline'] !== '' ? $in['headline'] : null,
                    'subheadline'  => $in['subheadline'] !== '' ? $in['subheadline'] : null,
                    'body_html'    => $in['body_html'] !== '' ? $in['body_html'] : null,
                    'gallery'      => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'highlights'   => json_encode($highlights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_published' => $in['published'] ? 1 : 0,
                    'updated_by'   => $this->ctx->userId(),
                ];
                $audit = Audit::instance($db);
                if ($existing === null) {
                    $db->table('website_products')->insert($row + ['id' => uuid4(), 'product_id' => $id, 'sort_order' => 0]);
                } else {
                    $audit->version('website_product', $existing['id'], $existing, 'website copy edited');
                    $db->table('website_products')->where('product_id', $id)->update($row);
                }
                $audit->record('CMS_PRODUCT_UPDATED', 'website_product', $id, null, [
                    'product' => $product['name'], 'published' => $in['published'], 'images' => count($gallery),
                ]);
            }, db_connect());
        }, 'Website copy saved.', site_url('admin/content/products/' . $id));
    }

    public function saveFaq(): RedirectResponse
    {
        $id        = (string) $this->request->getPost('id');
        $question  = $this->post('question', 300);
        $answer    = $this->post('answer', 4000);
        $category  = $this->post('category', 40);
        $sort      = (int) $this->request->getPost('sort_order');
        $published = $this->request->getPost('is_published') === '1';

        return $this->act(function () use ($id, $question, $answer, $category, $sort, $published): void {
            if (mb_strlen($question) < 5 || mb_strlen($answer) < 5) {
                throw AppException::rule('Write both the question and the answer.');
            }
            $db  = db_connect();
            $row = [
                'question' => $question, 'answer' => $answer, 'category' => $category !== '' ? $category : 'buying',
                'sort_order' => $sort, 'is_published' => $published ? 1 : 0, 'updated_by' => $this->ctx->userId(),
            ];
            if (is_uuid($id)) {
                $db->table('faqs')->where('id', $id)->update($row);
            } else {
                $id = uuid4();
                $db->table('faqs')->insert($row + ['id' => $id]);
            }
            Audit::instance()->record('CMS_FAQ_SAVED', 'faq', $id, null, ['question' => $question, 'published' => $published]);
        }, 'FAQ saved.', site_url('admin/content') . '#faqs');
    }

    /** @return array<mixed> */
    private static function decode(string $json, string $field): array
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return [];
        }
        $value = json_decode($trimmed, true);
        if (! is_array($value)) {
            throw AppException::rule('The ' . $field . ' field is not valid JSON: ' . json_last_error_msg() . '.');
        }

        return $value;
    }
}
