/**
 * Public warranty verification.
 *
 * This is the only path by which anonymous internet traffic touches mattress
 * data, so the response is assembled field by field from an explicit
 * allow-list. Nothing is spread from a database row, which means adding a
 * column to `mattresses` can never start leaking it here.
 *
 * Deliberately NOT returned, even though the row contains them: the customer,
 * the dealer, the sale price, the invoice, claim history, risk indicators,
 * internal notes and every identifier the public has no use for.
 *
 * A QR code is treated as an untrusted string. It is used only as a lookup key
 * against the database; nothing it claims about the product is believed.
 */
import type { TransactionClient } from '@polyfix/database';
import { BRAND } from '@polyfix/brand';

export interface PublicVerificationResult {
  found: boolean;
  genuine: boolean;
  serialNumber?: string;
  product?: {
    name: string;
    slug: string;
    category: string;
    size: string;
    comfortLevel: string;
  };
  manufacturedOn?: string;
  warranty?: {
    status: 'NOT_ACTIVATED' | 'ACTIVE' | 'EXPIRED' | 'VOID' | 'SUPERSEDED';
    startDate: string | null;
    endDate: string | null;
    years: number | null;
    daysRemaining: number | null;
  };
  /** Plain-language note for the person holding the mattress. */
  note: string;
}

export async function verifyMattress(
  tx: TransactionClient,
  lookup: { serialNumber?: string | undefined; qrToken?: string | undefined },
): Promise<PublicVerificationResult> {
  const mattress = await tx.mattress.findFirst({
    where: lookup.serialNumber ? { serialNumber: lookup.serialNumber } : { qrToken: lookup.qrToken },
    select: {
      serialNumber: true,
      manufacturedAt: true,
      currentStatus: true,
      deletedAt: true,
      isReplacement: true,
      productVariant: {
        select: {
          sizeLabel: true,
          product: { select: { name: true, slug: true, category: true, comfortLevel: true } },
        },
      },
      warranty: {
        select: { status: true, startDate: true, endDate: true, years: true },
      },
    },
  });

  if (!mattress || mattress.deletedAt) {
    return {
      found: false,
      genuine: false,
      note:
        'We have no record of that serial number. Check the digits on the law label, or contact the dealer you bought from. Serial numbers begin with CLF.',
    };
  }

  const product = mattress.productVariant.product;
  const result: PublicVerificationResult = {
    found: true,
    genuine: true,
    serialNumber: mattress.serialNumber,
    product: {
      name: product.name,
      slug: product.slug,
      category: product.category,
      size: mattress.productVariant.sizeLabel,
      comfortLevel: product.comfortLevel,
    },
    manufacturedOn: mattress.manufacturedAt.toISOString().slice(0, 10),
    note: '',
  };

  const warranty = mattress.warranty;
  if (!warranty) {
    result.warranty = {
      status: 'NOT_ACTIVATED',
      startDate: null,
      endDate: null,
      years: null,
      daysRemaining: null,
    };
    result.note =
      `This is a genuine ${BRAND.name} product. Its warranty has not been activated yet — it starts when your dealer records the sale. If you have already bought it, ask your dealer to record the sale against this serial number.`;
    return result;
  }

  const now = Date.now();
  const end = warranty.endDate.getTime();
  const daysRemaining = Math.ceil((end - now) / (24 * 60 * 60 * 1000));

  // The stored status is authoritative for VOID and SUPERSEDED; expiry is
  // computed so the answer is right even if a nightly job has not yet run.
  let status: NonNullable<PublicVerificationResult['warranty']>['status'];
  if (warranty.status === 'VOID') status = 'VOID';
  else if (warranty.status === 'SUPERSEDED') status = 'SUPERSEDED';
  else status = daysRemaining > 0 ? 'ACTIVE' : 'EXPIRED';

  result.warranty = {
    status,
    startDate: warranty.startDate.toISOString().slice(0, 10),
    endDate: warranty.endDate.toISOString().slice(0, 10),
    years: warranty.years,
    daysRemaining: status === 'ACTIVE' ? daysRemaining : 0,
  };

  result.note = {
    ACTIVE: `This is a genuine ${BRAND.name} product and its warranty is active until ${result.warranty.endDate}.`,
    EXPIRED: `This is a genuine ${BRAND.name} product. Its warranty period ended on ${result.warranty.endDate}.`,
    VOID: `This is a genuine ${BRAND.name} product, but the warranty on this unit is no longer valid. Contact your dealer for details.`,
    SUPERSEDED:
      `This is a genuine ${BRAND.name} product. This unit was replaced under warranty, and the cover now sits with the replacement mattress.`,
    NOT_ACTIVATED: '',
  }[status];

  return result;
}
