<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * The pages the public website cannot render without.
 *
 * Six addresses read their heading and sections from `website_pages`: the home
 * page, the warranty check, the dealer page, the contact page, About and
 * Why POLYFIZZ. A page row that is missing is a 404, not an empty page — so a
 * freshly installed database served 404 on its own home page and, worse, on
 * /warranty/verify, which is the address printed on every QR label. This
 * seeder is what stops that.
 *
 * The copy here is deliberately plain. It describes what the software does and
 * the facts the company has given — the name, the Manesar address, that every
 * mattress carries a serial number anyone can check — and claims nothing else.
 * No prices, no product range, no figures: those are commercial decisions, and
 * an administrator enters them under Console → Content and Console → Products.
 * Treat it as a starting point to edit, not as finished marketing.
 *
 * It only ever inserts. A slug that already exists is left exactly as it is,
 * so running it again after the client has rewritten a page changes nothing.
 *
 *   php spark polyfix:seed WebsiteContent
 */
class WebsiteContent extends Seeder
{
    public function run(): void
    {
        $this->pages();
        $this->faqs();
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:list<array<string,mixed>>}> */
    private function content(): array
    {
        $brand = brand('name');
        $short = brand('shortName');

        return [
            'home' => [
                $brand . ' — premium mattresses, every one traceable',
                [
                    'eyebrow' => $brand,
                    'heading' => 'A mattress you can check, not just trust',
                    'body'    => 'Every ' . $short . ' mattress leaves our Manesar plant with its own serial number and QR code. '
                        . 'Scan it or type it in and this website will tell you whether the unit is genuine, when it was made and what warranty it carries.',
                ],
                [
                    ['type' => 'featured-products', 'heading' => 'Our range', 'body' => 'Mattresses added under Console → Products appear here.'],
                    [
                        'type'    => 'benefits',
                        'heading' => 'What comes with every unit',
                        'items'   => [
                            ['icon' => 'warranty', 'title' => 'A warranty on record', 'body' => 'The warranty is registered against the serial number at the point of sale, so the cover does not depend on finding a receipt.'],
                            ['icon' => 'materials', 'title' => 'Made in Manesar', 'body' => 'Manufactured at our own plant at Noranpur Chowk, Haryana, and numbered before it leaves the floor.'],
                            ['icon' => 'support', 'title' => 'A dealer near you', 'body' => 'Sold through authorised dealers, each one recorded against the unit they sold.'],
                            ['icon' => 'comfort', 'title' => 'Checkable by anyone', 'body' => 'No account and no app: the serial check is a public page on this website.'],
                        ],
                    ],
                    [
                        'type'    => 'steps',
                        'heading' => 'Checking a mattress takes a moment',
                        'body'    => 'The label is sewn to the side of the mattress.',
                        'items'   => [
                            ['title' => 'Find the label', 'body' => 'A QR code and a serial number beginning ' . brand('serialPrefix') . ', on the side panel.'],
                            ['title' => 'Scan or type it', 'body' => 'Your phone camera opens the check, or enter the serial on the warranty page.'],
                            ['title' => 'Read the answer', 'body' => 'Genuine or not, the model, the date it was made and the warranty position.'],
                        ],
                    ],
                    ['type' => 'verification', 'heading' => 'Check a serial number', 'body' => 'Buying second hand, or want to be sure before you accept delivery?', 'cta' => 'Check a mattress'],
                    ['type' => 'closing-cta', 'heading' => 'Looking to stock ' . $short . '?', 'body' => 'Dealership enquiries are welcome through the dealer page.'],
                ],
            ],
            'warranty' => [
                'Check a mattress and its warranty',
                [
                    'eyebrow' => 'Warranty',
                    'heading' => 'Check a mattress and its warranty',
                    'body'    => 'Enter the serial number from the label, or scan the QR code on it. '
                        . 'The answer comes from our own production records.',
                ],
                [
                    ['type' => 'verify-form'],
                    [
                        'type'    => 'steps',
                        'heading' => 'What the check tells you',
                        'items'   => [
                            ['title' => 'Whether it is ours', 'body' => 'A serial we issued is confirmed as genuine. One we did not is reported plainly as having no record.'],
                            ['title' => 'What it is', 'body' => 'The model and size, and the date it was manufactured.'],
                            ['title' => 'Where the warranty stands', 'body' => 'Whether the warranty is registered, how long it runs and whether a claim is open against it.'],
                        ],
                    ],
                    ['type' => 'text', 'heading' => 'If something is wrong', 'body' => 'Raise it with the dealer who sold you the mattress. '
                        . 'They can open a warranty claim against the serial number, and you can be kept informed as it is reviewed.'],
                ],
            ],
            'dealers' => [
                'Dealers and dealership enquiries',
                [
                    'eyebrow' => 'Dealers',
                    'heading' => 'Find a dealer, or become one',
                    'body'    => $brand . ' sells through authorised dealers. Every sale is recorded against the dealer who made it, '
                        . 'which is what lets a warranty be honoured years later.',
                ],
                [
                    ['type' => 'dealer-locator', 'heading' => 'Authorised dealers'],
                    ['type' => 'become-dealer', 'heading' => 'Apply for a dealership'],
                    [
                        'type'    => 'reasons',
                        'heading' => 'What a dealership includes',
                        'items'   => [
                            ['title' => 'Your own portal', 'body' => 'Confirm what arrives from the plant, record sales, activate warranties and follow claims.'],
                            ['title' => 'Stock you can account for', 'body' => 'Each mattress is received against its serial number, so stock on the system matches stock on the floor.'],
                            ['title' => 'Claims handled on record', 'body' => 'Raise a claim with photographs and follow its progress without chasing anyone.'],
                        ],
                    ],
                ],
            ],
            'contact' => [
                'Contact ' . $brand,
                [
                    'eyebrow' => 'Contact',
                    'heading' => 'Talk to us',
                    'body'    => 'Enquiries about a mattress, a warranty or a dealership.',
                ],
                [
                    ['type' => 'contact-details', 'heading' => 'Where to find us'],
                    ['type' => 'contact-form', 'heading' => 'Send an enquiry'],
                ],
            ],
            'about' => [
                'About ' . $brand,
                [
                    'eyebrow' => 'About us',
                    'heading' => 'About ' . $brand,
                    'body'    => $brand . ' manufactures mattresses at Manesar, Noranpur Chowk, Haryana, and sells them through authorised dealers across India.',
                ],
                [
                    ['type' => 'text', 'heading' => 'Numbered before it leaves the floor', 'body' => 'Mattresses are made in batches, and every unit in a batch is given '
                        . 'its own serial number and QR label on the production floor. Nothing is dispatched that has not been scanned, which is why a customer '
                        . 'can check a mattress years later and why a dealer can be told exactly what is on the way to them.'],
                    [
                        'type'    => 'values',
                        'heading' => 'How we work',
                        'items'   => [
                            ['title' => 'One record per mattress', 'body' => 'Manufacture, dispatch, receipt, sale, warranty and any claim all hang off the same serial number.'],
                            ['title' => 'Checkable by the customer', 'body' => 'The verification page is public, because a warranty nobody can check is not much of a warranty.'],
                            ['title' => 'Counted, not estimated', 'body' => 'Dealer stock is confirmed by a field agent scanning what is physically there.'],
                        ],
                    ],
                    ['type' => 'closing-cta', 'heading' => 'Questions?', 'body' => 'The contact page has our address and an enquiry form.'],
                ],
            ],
            'why-polyfizz' => [
                'Why ' . $short,
                [
                    'eyebrow' => 'Why ' . $short,
                    'heading' => 'Why ' . $short,
                    'body'    => 'What sits behind the label on the side of the mattress.',
                ],
                [
                    [
                        'type'    => 'craftsmanship',
                        'heading' => 'From the floor to your bedroom',
                        'body'    => 'Each step leaves a record against the serial number.',
                        'steps'   => [
                            ['title' => 'Made in a batch', 'body' => 'A batch is opened for a model and quantity, and serial numbers are issued for every unit in it.'],
                            ['title' => 'Labelled and scanned', 'body' => 'Each unit is labelled with its QR code and scanned as it is counted into stock.'],
                            ['title' => 'Dispatched to a dealer', 'body' => 'Units are scanned onto a dispatch, so the dealer knows precisely which mattresses are coming.'],
                            ['title' => 'Sold and registered', 'body' => 'The dealer records the sale against the serial, and the warranty starts from that date.'],
                        ],
                    ],
                    [
                        'type'    => 'reasons',
                        'heading' => 'What that is worth to you',
                        'items'   => [
                            ['title' => 'No lost receipts', 'body' => 'The warranty is held against the serial number, not against a piece of paper.'],
                            ['title' => 'No counterfeits', 'body' => 'A serial we never issued is reported as having no record, in plain words.'],
                            ['title' => 'No guesswork on a claim', 'body' => 'A claim is assessed against the unit\'s own history: when it was made, who sold it and when.'],
                        ],
                    ],
                    ['type' => 'verification', 'heading' => 'Try it on a mattress you own', 'cta' => 'Check a serial number'],
                ],
            ],
        ];
    }

    private function pages(): void
    {
        foreach ($this->content() as $slug => [$title, $hero, $sections]) {
            $exists = $this->db->table('website_pages')->where('slug', $slug)->countAllResults() > 0;
            if ($exists) {
                continue;
            }

            $this->db->table('website_pages')->insert([
                'id'           => uuid4(),
                'slug'         => $slug,
                'title'        => $title,
                'status'       => 'PUBLISHED',
                'hero'         => json_encode($hero, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sections'     => json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_at' => utc_now(),
            ]);
        }
    }

    /**
     * Questions about how verification and the warranty work — which is
     * behaviour of this software, not a claim about the product. The home page
     * shows the first few of the "buying" and "authenticity" categories.
     */
    private const FAQS = [
        ['authenticity', 'Where is the serial number on my mattress?', 'On the label sewn to the side panel. It carries a QR code and a serial number printed beneath it.', 10],
        ['authenticity', 'What if the check says there is no record?', 'Then we did not issue that serial number. Stop and speak to the dealer you bought it from, and to us through the contact page.', 20],
        ['authenticity', 'Do I need an account to check a mattress?', 'No. The check is a public page on this website and asks for nothing but the serial number.', 30],
        ['buying', 'When does my warranty start?', 'On the date of sale recorded by the dealer. The check page shows that date and how long the cover runs.', 10],
        ['buying', 'I have lost my invoice. Am I still covered?', 'Yes. The warranty is registered against the mattress\'s serial number, so the cover does not depend on the paperwork.', 20],
        ['buying', 'Can I buy directly from the factory?', 'Mattresses are sold through authorised dealers. The dealer page will help you find one.', 30],
        ['warranty', 'How do I make a warranty claim?', 'Through the dealer who sold you the mattress. They raise it against the serial number, with photographs, and you can be kept informed as it is reviewed.', 10],
        ['warranty', 'How long does a claim take?', 'That depends on the claim. Each one is reviewed against the mattress\'s own history, and the dealer is notified of the decision.', 20],
    ];

    private function faqs(): void
    {
        foreach (self::FAQS as [$category, $question, $answer, $order]) {
            $exists = $this->db->table('faqs')->where('question', $question)->countAllResults() > 0;
            if ($exists) {
                continue;
            }

            $this->db->table('faqs')->insert([
                'id'           => uuid4(),
                'question'     => $question,
                'answer'       => $answer,
                'category'     => $category,
                'sort_order'   => $order,
                'is_published' => 1,
            ]);
        }
    }
}
