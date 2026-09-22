/**
 * The immutable list primitives the row-based config editors share (switch
 * cases, approval payload fields, approval notify emails). Framework-free and
 * total: an out-of-range index is a no-op that returns the SAME array, so a
 * stale click from a row that has since been removed or reordered can never
 * scramble the list or throw.
 *
 * Same contract as graphOps: never mutate the input, always hand back the next
 * value (the caller commits it).
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

/** Whether an index addresses a real element of the list. */
export function inRange(list: readonly unknown[], index: number): boolean {
  return Number.isInteger(index) && index >= 0 && index < list.length;
}

/** The list without its `index`th element. */
export function removeAt<T>(list: readonly T[], index: number): T[] {
  if (!inRange(list, index)) {
    return list as T[];
  }
  return [...list.slice(0, index), ...list.slice(index + 1)];
}

/** The list with its `index`th element replaced. */
export function replaceAt<T>(list: readonly T[], index: number, value: T): T[] {
  if (!inRange(list, index)) {
    return list as T[];
  }
  return list.map((item, i) => (i === index ? value : item));
}

/**
 * The list with the `index`th element moved by `delta` positions (-1 = up).
 * A move that would leave the list is a no-op — the caller renders the
 * corresponding button disabled, and this is the belt to that braces.
 */
export function moveItem<T>(list: readonly T[], index: number, delta: number): T[] {
  const target = index + delta;
  if (!inRange(list, index) || !inRange(list, target) || delta === 0) {
    return list as T[];
  }
  const next = [...list];
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  return next;
}
