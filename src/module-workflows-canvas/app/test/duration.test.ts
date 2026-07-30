import { describe, expect, it } from 'vitest';
import {
  DURATION_UNITS,
  composeDuration,
  isCompositeDuration,
  isIsoDuration,
  splitDuration,
} from '../src/duration';

/**
 * The amount+unit composite over the ISO-8601 value the server validates. The
 * behaviour ported from the install form's param-duration.js peer:
 *   - only single-component P<n>D / PT<n>H / PT<n>M render in the composite;
 *   - anything else falls back to the raw-ISO escape hatch and MUST survive
 *     byte-for-byte (there is no lossy re-encoding anywhere in the path);
 *   - a half-typed amount composes to nothing, so it can never blank a stored
 *     value.
 */

describe('splitDuration / composeDuration round-trip', () => {
  it('splits the three offered units', () => {
    expect(splitDuration('PT30M')).toEqual({ amount: 30, unit: 'minutes' });
    expect(splitDuration('PT2H')).toEqual({ amount: 2, unit: 'hours' });
    expect(splitDuration('P3D')).toEqual({ amount: 3, unit: 'days' });
    expect(splitDuration('P14D')).toEqual({ amount: 14, unit: 'days' });
  });

  it('composes back to the exact same string (composite -> ISO -> composite)', () => {
    for (const iso of ['PT1M', 'PT45M', 'PT1H', 'PT36H', 'P1D', 'P7D']) {
      const parts = splitDuration(iso);
      expect(parts).not.toBeNull();
      expect(composeDuration(parts!.amount, parts!.unit)).toBe(iso);
    }
  });

  it('offers minutes/hours/days, in the install form order', () => {
    expect(DURATION_UNITS.map((u) => u.value)).toEqual(['minutes', 'hours', 'days']);
  });
});

describe('splitDuration — the escape-hatch fallback (lossless)', () => {
  it('refuses anything the composite cannot represent exactly', () => {
    // Compound intervals, unoffered units, and junk all stay in raw ISO mode,
    // which is what makes an arbitrary stored value round-trip verbatim.
    for (const iso of [
      'P1DT12H',
      'PT1H30M',
      'P1W',
      'P1M',
      'P1Y',
      'PT90S',
      'P0002-00-00T00:00:00',
      'nonsense',
      '',
    ]) {
      expect(splitDuration(iso)).toBeNull();
      expect(isCompositeDuration(iso)).toBe(false);
    }
  });

  it('refuses non-strings', () => {
    expect(splitDuration(undefined)).toBeNull();
    expect(splitDuration(null)).toBeNull();
    expect(splitDuration(90)).toBeNull();
  });
});

describe('composeDuration', () => {
  it('returns null for a half-typed or non-positive amount (never blanks a value)', () => {
    expect(composeDuration('', 'hours')).toBeNull();
    expect(composeDuration('-', 'hours')).toBeNull();
    expect(composeDuration('1.5', 'hours')).toBeNull();
    expect(composeDuration('0', 'hours')).toBeNull();
    expect(composeDuration('-2', 'days')).toBeNull();
  });

  it('returns null for a unit the composite does not offer', () => {
    expect(composeDuration(2, 'weeks')).toBeNull();
    expect(composeDuration(2, '')).toBeNull();
    expect(composeDuration(2, undefined)).toBeNull();
  });

  it('accepts a numeric amount and trims a padded one', () => {
    expect(composeDuration(2, 'hours')).toBe('PT2H');
    expect(composeDuration(' 15 ', 'minutes')).toBe('PT15M');
  });
});

describe('isIsoDuration — the definition schema grammar', () => {
  it('accepts what the server accepts', () => {
    for (const iso of ['PT1M', 'PT1H', 'P1D', 'P1W', 'P1Y2M3DT4H5M6S', 'P1DT12H']) {
      expect(isIsoDuration(iso)).toBe(true);
    }
  });

  it('rejects an empty designator, a bare T and garbage', () => {
    for (const iso of ['P', 'PT', 'P1DT', '1H', 'PT1', 'hour', '']) {
      expect(isIsoDuration(iso)).toBe(false);
    }
  });
});
