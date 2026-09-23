/**
 * Inline icons.
 *
 * Drawn here rather than pulled from an icon package: five shapes do not
 * justify a dependency, and inlining them means no extra request and no flash
 * of missing glyphs. Each is marked aria-hidden because the adjacent text
 * already carries the meaning.
 */
const base = {
  width: 22,
  height: 22,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.6,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  'aria-hidden': true,
};

export function IconComfort() {
  return (
    <svg {...base}>
      <path d="M3 13V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4" />
      <rect x="2" y="13" width="20" height="6" rx="2" />
      <path d="M7 10h4" />
    </svg>
  );
}

export function IconMaterials() {
  return (
    <svg {...base}>
      <path d="M3 8.5 12 4l9 4.5-9 4.5z" />
      <path d="M3 12.5 12 17l9-4.5" />
      <path d="M3 16.5 12 21l9-4.5" />
    </svg>
  );
}

export function IconSupport() {
  return (
    <svg {...base}>
      <path d="M4 18V7a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v11" />
      <path d="M4 14h16" />
      <path d="M8 18v2M16 18v2" />
    </svg>
  );
}

export function IconWarranty() {
  return (
    <svg {...base}>
      <path d="M12 3 5 6v5.5c0 4.2 2.9 7.9 7 9.5 4.1-1.6 7-5.3 7-9.5V6z" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}

export function IconScan() {
  return (
    <svg {...base}>
      <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
      <path d="M4 12h16" />
    </svg>
  );
}

export function IconPin() {
  return (
    <svg {...base}>
      <path d="M20 10c0 5.2-8 12-8 12s-8-6.8-8-12a8 8 0 1 1 16 0Z" />
      <circle cx="12" cy="10" r="2.6" />
    </svg>
  );
}

export const ICONS: Record<string, () => React.JSX.Element> = {
  comfort: IconComfort,
  materials: IconMaterials,
  support: IconSupport,
  warranty: IconWarranty,
  scan: IconScan,
  pin: IconPin,
};
