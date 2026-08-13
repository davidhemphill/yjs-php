/**
 * The fixture value specification, shared by the generator and the verifier.
 *
 * Fixtures have to name values that JSON cannot carry on its own: `undefined`,
 * a BigInt, a Uint8Array, a NaN, a negative zero, the difference between an
 * empty array and an empty object. Every fixture value is therefore written as
 * a tagged spec that both this file and its PHP counterpart can realize into a
 * native value.
 *
 * Doubles are carried as their IEEE-754 bits rather than as JSON numbers, so a
 * fixture means exactly one value and no decimal formatting choice can change
 * which bytes we expect.
 */

const view = new DataView(new ArrayBuffer(8))

export const doubleToBits = (number) => {
  view.setFloat64(0, number)
  return view.getBigUint64(0).toString(16).padStart(16, '0')
}

export const bitsToDouble = (bits) => {
  view.setBigUint64(0, BigInt(`0x${bits}`))
  return view.getFloat64(0)
}

const base64ToBytes = (base64) => Uint8Array.from(Buffer.from(base64, 'base64'))

export const bytesToBase64 = (bytes) => Buffer.from(bytes).toString('base64')

/**
 * Turn a spec into the JavaScript value it names.
 */
export const realize = (spec) => {
  switch (spec.t) {
    case 'undefined':
      return undefined
    case 'null':
      return null
    case 'bool':
      return spec.v
    case 'int':
      return Number(spec.v)
    case 'double':
      return bitsToDouble(spec.bits)
    case 'bigint':
      return BigInt(spec.v)
    case 'string':
      return spec.v
    case 'bytes':
      return base64ToBytes(spec.v)
    case 'array':
      return spec.v.map(realize)
    case 'object':
      return Object.fromEntries(spec.v.map(([key, value]) => [key, realize(value)]))
    default:
      throw new Error(`Unknown fixture spec type: ${spec.t}`)
  }
}

/** Shorthand constructors used by the fixture tables. */
export const s = {
  undefined: () => ({ t: 'undefined' }),
  null: () => ({ t: 'null' }),
  bool: (v) => ({ t: 'bool', v }),
  int: (v) => ({ t: 'int', v: String(v) }),
  double: (v) => ({ t: 'double', bits: doubleToBits(v) }),
  bigint: (v) => ({ t: 'bigint', v: String(v) }),
  string: (v) => ({ t: 'string', v }),
  bytes: (v) => ({ t: 'bytes', v: bytesToBase64(v) }),
  array: (v) => ({ t: 'array', v }),
  object: (entries) => ({ t: 'object', v: entries }),
}

/**
 * Rewrite a spec so its object entries appear in the order JavaScript will
 * actually enumerate them.
 *
 * `Object.keys` does not return insertion order: integer-like keys come first,
 * in ascending numeric order, ahead of every string key. lib0 writes an object
 * in that order, so a fixture that declared `10, 2, b` would carry bytes in the
 * order `2, 10, b` and no other implementation could reproduce it from the
 * declaration. Normalizing here keeps each fixture self-consistent and leaves
 * the reordering visible in the committed file.
 */
export const normalize = (spec) => {
  if (spec.t === 'array') {
    return { t: 'array', v: spec.v.map(normalize) }
  }

  if (spec.t === 'object') {
    const byKey = Object.fromEntries(spec.v)

    return { t: 'object', v: Object.keys(byKey).map((key) => [key, normalize(byKey[key])]) }
  }

  return spec
}

/**
 * Deep equality that understands the values `realize` can produce, including
 * the cases `===` gets wrong for our purposes: NaN, negative zero, and the
 * two binary types.
 */
export const sameValue = (a, b) => {
  if (typeof a === 'number' && typeof b === 'number') {
    return Object.is(a, b)
  }

  if (typeof a !== typeof b) {
    return false
  }

  if (a instanceof Uint8Array || b instanceof Uint8Array) {
    if (!(a instanceof Uint8Array) || !(b instanceof Uint8Array)) {
      return false
    }
    return a.length === b.length && a.every((byte, index) => byte === b[index])
  }

  if (a === null || b === null) {
    return a === b
  }

  if (Array.isArray(a) || Array.isArray(b)) {
    if (!Array.isArray(a) || !Array.isArray(b)) {
      return false
    }
    return a.length === b.length && a.every((item, index) => sameValue(item, b[index]))
  }

  if (typeof a === 'object') {
    const aKeys = Object.keys(a)
    const bKeys = Object.keys(b)
    return aKeys.length === bKeys.length && aKeys.every((key) => key in b && sameValue(a[key], b[key]))
  }

  return a === b
}
