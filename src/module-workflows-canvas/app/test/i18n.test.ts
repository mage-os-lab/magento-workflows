/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { afterEach, describe, expect, it } from 'vitest';
import { setTranslations, t } from '../src/i18n';

/**
 * The canvas translation seam: the server's phrase map (PhraseCatalog via
 * Mount.php) is installed once at mount; t() looks phrases up and NEVER
 * invents text — a missing row is the English phrase unchanged, which is also
 * why the whole vitest suite runs untranslated without any setup.
 */
describe('t / setTranslations', () => {
  afterEach(() => setTranslations({}));

  it('falls back to the English phrase when no map is installed', () => {
    expect(t('Save')).toBe('Save');
    expect(t('A phrase nobody catalogued')).toBe('A phrase nobody catalogued');
  });

  it('swaps a phrase once the server map is installed', () => {
    setTranslations({ Save: 'Speichern' });
    expect(t('Save')).toBe('Speichern');
    // Uncatalogued phrases still pass through.
    expect(t('Undo')).toBe('Undo');
  });

  it('ignores an empty translation (identity beats a blanked-out row)', () => {
    setTranslations({ Save: '' });
    expect(t('Save')).toBe('Save');
  });

  it('replaces the whole map on each install', () => {
    setTranslations({ Save: 'Speichern' });
    setTranslations({ Undo: 'Rückgängig' });
    expect(t('Save')).toBe('Save');
    expect(t('Undo')).toBe('Rückgängig');
  });
});
