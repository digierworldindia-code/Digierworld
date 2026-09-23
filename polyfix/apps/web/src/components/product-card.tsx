import Image from 'next/image';
import Link from 'next/link';
import { formatPrice } from '@/lib/site';
import type { ProductSummary } from '@/lib/api';

/**
 * Product card.
 *
 * The image carries explicit width and height so the browser reserves the exact
 * space before it downloads, which is what keeps Cumulative Layout Shift at
 * zero. Only the first row is eager; everything below the fold is lazy.
 */
export function ProductCard({ product, priority = false }: { product: ProductSummary; priority?: boolean }) {
  const image = product.gallery[0];

  return (
    <Link href={`/mattresses/${product.slug}`} className="card-link">
      <article className="card product-card">
        <div className="product-card__media">
          {image ? (
            <Image
              src={image.src}
              alt={image.alt}
              width={image.width}
              height={image.height}
              sizes="(max-width: 48rem) 100vw, (max-width: 72rem) 50vw, 33vw"
              priority={priority}
              loading={priority ? 'eager' : 'lazy'}
              style={{ width: '100%', height: '100%', objectFit: 'cover' }}
            />
          ) : null}
        </div>

        <div className="product-card__body">
          <span className="eyebrow">{product.category}</span>
          <h3>{product.name}</h3>
          <p className="muted" style={{ fontSize: 'var(--step--1)' }}>{product.shortDescription}</p>

          <div className="product-card__meta">
            <span className="muted">
              {product.comfortLevel} · {product.warrantyYears} year warranty
            </span>
            {product.fromPrice ? (
              <span className="product-card__price">from {formatPrice(product.fromPrice)}</span>
            ) : null}
          </div>
        </div>
      </article>
    </Link>
  );
}
