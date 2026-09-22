import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import { getPage } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema } from '@/lib/structured-data';

export const revalidate = 600;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('about');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: 'About COLIFEES — Mattress Manufacturer',
    fallbackDescription:
      'COLIFEES manufactures premium mattresses and sells through appointed dealers. Every unit is serialised and traceable from production to sale.',
    path: '/about',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'About', path: '/about' },
];

export default async function AboutPage() {
  const page = await getPage('about');
  const hero = page?.page.hero ?? {};
  const sections = (page?.page.sections ?? []) as Record<string, any>[];

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">{hero.eyebrow ?? 'About'}</p>
            <h1>{hero.heading ?? 'A mattress company that can account for every unit it makes'}</h1>
            <p className="lede">
              {hero.body ??
                'COLIFEES manufactures mattresses and sells them through appointed dealers. Every mattress is serialised at the end of the line, and that serial stays with it through dispatch, sale, warranty and any claim.'}
            </p>
          </div>

          <div className="hero__media">
            <Image
              src="/images/about/colifees-factory.svg"
              alt="COLIFEES production floor with mattress assembly in progress"
              width={1600}
              height={1000}
              priority
              sizes="(max-width: 62rem) 100vw, 50vw"
              style={{ width: '100%', height: 'auto' }}
            />
          </div>
        </div>
      </section>

      <section className="section">
        <div className="container-narrow stack-lg">
          {sections
            .filter((section) => section.type === 'text')
            .map((section) => (
              <div key={section.heading} className="stack">
                <h2>{section.heading}</h2>
                <div className="prose">
                  <p>{section.body}</p>
                </div>
              </div>
            ))}

          {sections.length === 0 ? (
            <>
              <div className="stack">
                <h2>What we make</h2>
                <div className="prose">
                  <p>
                    Five mattress constructions: pocketed-spring hybrid, memory foam, natural latex,
                    high-density orthopaedic and a flippable dual-firmness model. Each is specified by
                    material, density and firmness rating, and each is offered in five standard sizes.
                  </p>
                </div>
              </div>
              <div className="stack">
                <h2>How we sell</h2>
                <div className="prose">
                  <p>
                    Through appointed dealers rather than direct. A dealer holds stock, records the
                    sale against the mattress serial number, and is the first point of contact if a
                    warranty question comes up. That keeps a named business accountable for the
                    mattress after it leaves us.
                  </p>
                </div>
              </div>
            </>
          ) : null}
        </div>
      </section>

      <section className="section section-sand">
        <div className="container">
          <div className="section-head">
            <h2>What we hold ourselves to</h2>
          </div>
          <div className="grid grid-3">
            {(
              (sections.find((section) => section.type === 'values')?.items as { title: string; body: string }[] | undefined) ?? [
                { title: 'Specify it, then publish it', body: 'Material and density appear on the product page. If it is not specified, we do not claim it.' },
                { title: 'Traceability by default', body: 'Serial number, manufacturing batch, dispatch, dealer and sale are all linked on one record.' },
                { title: 'Warranty you can check yourself', body: 'Status is verifiable online without contacting anyone.' },
              ]
            ).map((value) => (
              <div className="card stack" key={value.title}>
                <h3>{value.title}</h3>
                <p className="muted">{value.body}</p>
              </div>
            ))}
          </div>

          <div className="cluster mt-7">
            <Link href="/mattresses" className="btn btn-primary">See the range</Link>
            <Link href="/dealers#apply" className="btn btn-secondary">Partner with us</Link>
          </div>
        </div>
      </section>

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
    </>
  );
}
