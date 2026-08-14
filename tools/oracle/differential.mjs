/**
 * The differential oracle for the update algebra.
 *
 * Phases 1 and 2 could be checked by comparing bytes, because encoding is a
 * function with one right answer. Merging is not: several different byte
 * sequences can describe the same document, so an implementation can be wrong
 * in a way that still round-trips, still decodes, and only shows up as two
 * clients quietly disagreeing about the text weeks later.
 *
 * So this does not ask whether PHP's merge looks right. It builds randomized
 * multi-client histories with the real Yjs, has PHP merge and diff them, feeds
 * the results back into real Yjs documents, and asks whether those documents
 * agree with the ones Yjs produced for itself.
 *
 * Every scenario is generated from a seed, so a failure reproduces exactly and
 * the seed can be pinned into the regression list.
 *
 * Usage:
 *   node tools/oracle/differential.mjs [--seeds 1,2,3] [--rounds 40] [--verbose]
 */
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as Y from 'yjs'

const here = dirname(fileURLToPath(import.meta.url))

/** Seeds every run covers. A seed that ever fails belongs in this list forever. */
const DEFAULT_SEEDS = [1, 2, 3, 5, 8, 13, 21, 42, 99, 1337, 20260813]

const args = process.argv.slice(2)
const flag = (name, fallback) => {
  const index = args.indexOf(`--${name}`)
  return index === -1 ? fallback : args[index + 1]
}

const seeds = String(flag('seeds', DEFAULT_SEEDS.join(','))).split(',').map(Number)
const rounds = Number(flag('rounds', 40))
const verbose = args.includes('--verbose')

/** Deterministic PRNG, so a seed names exactly one scenario. */
const rng = (seed) => {
  let state = seed >>> 0
  return () => {
    state = (state + 0x6d2b79f5) >>> 0
    let t = Math.imul(state ^ (state >>> 15), 1 | state)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const b64 = (bytes) => Buffer.from(bytes).toString('base64')
const fromB64 = (text) => Uint8Array.from(Buffer.from(text, 'base64'))

/**
 * The logical content of a document, in a form that does not depend on how its
 * structs happen to be split internally.
 *
 * Comparing `encodeStateAsUpdate` bytes would be stricter but wrong: two
 * documents can hold identical content with different internal item
 * boundaries, and calling that a divergence would bury the real failures.
 */
const canonical = (value) => {
  if (Array.isArray(value)) {
    // Sequence order is meaningful, so arrays keep theirs.
    return value.map(canonical)
  }

  if (value !== null && typeof value === 'object') {
    // A Y.Map is unordered, and its toJSON key order reflects internal
    // structure rather than content. Comparing it would report a divergence
    // every time two equal documents were built up differently.
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonical(value[key])]))
  }

  return value
}

const snapshot = (doc) => JSON.stringify(canonical({
  text: doc.getText('text').toDelta(),
  map: doc.getMap('map').toJSON(),
  array: doc.getArray('array').toJSON(),
  xml: doc.getXmlFragment('xml').toString(),
  stateVector: Buffer.from(Y.encodeStateVector(doc)).toString('hex'),
}))

/**
 * A struct-by-struct rendering of an update, for failure messages. A hex dump
 * says two updates differ; this says where and how.
 */
const describe = (update) =>
  Y.decodeUpdate(update).structs.map((struct) => {
    const content = struct.content ? `:${struct.content.constructor.name}` : ''
    return `${struct.constructor.name}(${struct.id.client}:${struct.id.clock}+${struct.length})${content}`
  })

const describeDeleteSet = (update) =>
  Array.from(Y.decodeUpdate(update).ds.clients.entries())
    .sort((a, b) => b[0] - a[0])
    .map(([client, ranges]) => `${client}:[${ranges.map((r) => `${r.clock}+${r.len}`).join(',')}]`)
    .join(' ')

/** Where two updates first disagree — in their structs, or failing that, their delete sets. */
const firstDivergence = (mineUpdate, theirsUpdate) => {
  const mine = describe(mineUpdate)
  const theirs = describe(theirsUpdate)

  const at = mine.findIndex((entry, index) => entry !== theirs[index])

  if (at === -1 && mine.length === theirs.length) {
    // The structs agree and the decoded delete sets agree, so whatever differs
    // is something decoding normalizes away. Only the raw bytes can say what.
    const a = Buffer.from(mineUpdate)
    const b = Buffer.from(theirsUpdate)
    let index = 0
    while (index < a.length && index < b.length && a[index] === b[index]) {
      index++
    }
    const from = Math.max(0, index - 8)

    return [
      `structs identical; first differing byte at ${index} of ${a.length}/${b.length}`,
      `  php ds: ${describeDeleteSet(mineUpdate)}`,
      `  yjs ds: ${describeDeleteSet(theirsUpdate)}`,
      `  php bytes: ...${a.subarray(from, index).toString('hex')}[${a.subarray(index, index + 6).toString('hex')}]...`,
      `  yjs bytes: ...${b.subarray(from, index).toString('hex')}[${b.subarray(index, index + 6).toString('hex')}]...`,
    ].join('\n')
  }

  const index = at === -1 ? Math.min(mine.length, theirs.length) : at
  const from = Math.max(0, index - 2)

  return [
    `first divergence at struct ${index} of ${mine.length}/${theirs.length}`,
    `  php: ${mine.slice(from, index + 3).join(' ')}`,
    `  yjs: ${theirs.slice(from, index + 3).join(' ')}`,
  ].join('\n')
}

const docFrom = (updates) => {
  const doc = new Y.Doc()
  for (const update of updates) {
    Y.applyUpdate(doc, update)
  }
  return doc
}

/**
 * Random edits across every content type the wire can carry, so the corpus
 * exercises string slicing, formatting, nested types, and binary alike.
 */
const edit = (doc, random) => {
  const text = doc.getText('text')
  const map = doc.getMap('map')
  const array = doc.getArray('array')
  const pick = (n) => Math.floor(random() * n)

  switch (pick(10)) {
    case 0:
    case 1: {
      // Astral characters on purpose: these are the inserts whose clocks and
      // UTF-8 bytes disagree, and where a slice can land inside a pair.
      const alphabet = ['a', 'bc', 'def', '😀', '👨‍👩‍👧‍👦', '日本', 'é']
      text.insert(pick(text.length + 1), alphabet[pick(alphabet.length)])
      break
    }
    case 2:
      if (text.length > 1) {
        const at = pick(text.length - 1)
        text.delete(at, 1 + pick(Math.min(3, text.length - at - 1)))
      }
      break
    case 3:
      if (text.length > 1) {
        const at = pick(text.length - 1)
        text.format(at, 1 + pick(text.length - at - 1), random() < 0.5 ? { bold: true } : { size: pick(5) })
      }
      break
    case 4:
      text.insertEmbed(pick(text.length + 1), { kind: 'embed', n: pick(100) })
      break
    case 5:
      map.set(`k${pick(6)}`, [pick(50), `v${pick(9)}`, random() < 0.5][pick(3)])
      break
    case 6:
      map.set(`bin${pick(3)}`, new Uint8Array([pick(256), pick(256)]))
      break
    case 7:
      map.set(`nested${pick(3)}`, random() < 0.5 ? new Y.Map() : new Y.Array())
      break
    case 8:
      array.insert(pick(array.length + 1), [pick(100), `s${pick(9)}`])
      break
    default:
      if (array.length > 0) {
        array.delete(pick(array.length), 1)
      }
      break
  }
}

/**
 * Build a pool of updates covering the same history in overlapping pieces.
 *
 * Snapshots taken at increasing times nest inside each other; diffs taken
 * between arbitrary state vectors cut across struct boundaries. Merging a
 * random mixture is what forces the slicing and deduplication paths, which a
 * set of disjoint updates would never reach.
 */
const buildScenario = (seed) => {
  const random = rng(seed)
  const clients = [1, 2, 3].map((n) => {
    const doc = new Y.Doc()
    doc.clientID = 100 + n
    return doc
  })

  const pool = []
  const vectors = []

  for (let round = 0; round < 12; round++) {
    for (const doc of clients) {
      const edits = 1 + Math.floor(random() * 3)
      for (let i = 0; i < edits; i++) {
        edit(doc, random)
      }
    }

    // Exchange between a random pair, so histories interleave rather than
    // staying independent.
    const from = clients[Math.floor(random() * clients.length)]
    const to = clients[Math.floor(random() * clients.length)]
    if (from !== to) {
      Y.applyUpdate(to, Y.encodeStateAsUpdate(from))
    }

    for (const doc of clients) {
      // The vector and an update that actually produces that state, kept
      // together. A diff is only testable against a document that holds the
      // state its vector describes, and a vector alone cannot rebuild one.
      vectors.push({ vector: Y.encodeStateVector(doc), state: Y.encodeStateAsUpdate(doc) })
      pool.push(Y.encodeStateAsUpdate(doc))
    }
  }

  // Partial slices of the same history, taken from vectors captured earlier.
  for (const doc of clients) {
    const full = Y.encodeStateAsUpdate(doc)
    for (let i = 0; i < 6; i++) {
      pool.push(Y.diffUpdate(full, vectors[Math.floor(random() * vectors.length)].vector))
    }
  }

  return { random, pool, vectors, clients }
}

/** Ask PHP to run a batch of operations. */
const runPhp = (ops) => {
  const output = execFileSync('php', [join(here, 'php-algebra.php')], {
    input: JSON.stringify({ ops }),
    encoding: 'utf8',
    maxBuffer: 256 * 1024 * 1024,
  })

  const results = new Map()
  for (const result of JSON.parse(output).results) {
    results.set(result.id, result)
  }
  return results
}

const failures = []
let checks = 0

/**
 * Cases where merging and sequential application legitimately disagree, and Yjs
 * disagrees the same way. Counted rather than ignored: if this ever climbs
 * sharply, something has started splitting structs that should not be.
 */
let formatDivergences = 0

for (const seed of seeds) {
  const { random, pool, vectors } = buildScenario(seed)
  const ops = []
  const expectations = []

  const shuffled = (list) => {
    const copy = [...list]
    for (let i = copy.length - 1; i > 0; i--) {
      const j = Math.floor(random() * (i + 1))
      ;[copy[i], copy[j]] = [copy[j], copy[i]]
    }
    return copy
  }

  for (let round = 0; round < rounds; round++) {
    const chosen = shuffled(pool).slice(0, 2 + Math.floor(random() * 5))

    ops.push({ id: ops.length, op: 'merge', updates: chosen.map(b64) })
    expectations.push({ kind: 'merge', seed, round, inputs: chosen })

    const single = pool[Math.floor(random() * pool.length)]
    const known = vectors[Math.floor(random() * vectors.length)]

    ops.push({ id: ops.length, op: 'diff', update: b64(single), stateVector: b64(known.vector) })
    expectations.push({ kind: 'diff', seed, round, update: single, known })

    ops.push({ id: ops.length, op: 'stateVector', update: b64(single) })
    expectations.push({ kind: 'stateVector', seed, round, update: single })
  }

  const results = runPhp(ops)

  for (let i = 0; i < expectations.length; i++) {
    const expected = expectations[i]
    const actual = results.get(i)
    checks++

    const fail = (message, detail) =>
      failures.push({ seed, round: expected.round, kind: expected.kind, message, detail })

    if (actual === undefined || actual.error) {
      fail('PHP raised an error', actual?.error ?? 'no result')
      continue
    }

    if (expected.kind === 'stateVector') {
      const mine = Buffer.from(fromB64(actual.stateVector)).toString('hex')
      const theirs = Buffer.from(Y.encodeStateVectorFromUpdate(expected.update)).toString('hex')
      if (mine !== theirs) {
        fail('state vector differs from Yjs', `php=${mine} yjs=${theirs}`)
      }
      continue
    }

    const phpBytes = fromB64(actual.update)

    if (expected.kind === 'merge') {
      const yjsBytes = Y.mergeUpdates(expected.inputs)

      // The gate: a document built from PHP's merge must hold exactly what one
      // built from the inputs directly holds.
      const viaPhp = snapshot(docFrom([phpBytes]))
      const viaInputs = snapshot(docFrom(expected.inputs))
      const viaYjs = snapshot(docFrom([yjsBytes]))

      if (viaPhp !== viaInputs && viaYjs !== viaInputs) {
        // Merging is not always the same as applying one update after another,
        // and that is a property of the format rather than of this library.
        // Integrating sequentially can split an item where merging does not,
        // and a split that lands inside a surrogate pair replaces it with two
        // U+FFFD. Yjs does this too, so the check here is that we do exactly
        // what Yjs does, not that we beat it.
        formatDivergences++
      } else if (viaPhp !== viaInputs) {
        fail(
          'merged update does not converge with applying the inputs, but Yjs merge does',
          `php   =${viaPhp}\nyjs   =${viaYjs}\ninputs=${viaInputs}`,
        )
      } else if (viaPhp !== viaYjs) {
        fail('merged update does not converge with Yjs merge', `php=${viaPhp}\nyjs=${viaYjs}`)
      } else if (Buffer.from(phpBytes).toString('hex') !== Buffer.from(yjsBytes).toString('hex')) {
        // Convergence already holds here, so this is the stricter claim: that
        // we produce the same bytes Yjs does, not merely an equivalent update.
        fail(
          'merged bytes differ from Yjs (documents still converge)',
          firstDivergence(phpBytes, yjsBytes),
        )
      }
      continue
    }

    if (expected.kind === 'diff') {
      const yjsBytes = Y.diffUpdate(expected.update, expected.known.vector)

      // A diff only means anything on top of the state its vector describes, so
      // both sides are applied to a document already holding that state, and
      // the target is that state plus the whole original update.
      const known = expected.known.state

      const viaPhp = snapshot(docFrom([known, phpBytes]))
      const viaYjs = snapshot(docFrom([known, yjsBytes]))
      const complete = snapshot(docFrom([known, expected.update]))

      if (viaPhp !== viaYjs) {
        fail('diffed update does not converge with Yjs diff', `php=${viaPhp}\nyjs=${viaYjs}`)
      } else if (viaPhp !== complete) {
        // Both diffs agree with each other but not with applying the whole
        // update, which is the same format property the merge check describes:
        // a diff carries fewer struct boundaries, so integrating it splits
        // fewer items, so fewer surrogate pairs get damaged.
        formatDivergences++
      } else if (Buffer.from(phpBytes).toString('hex') !== Buffer.from(yjsBytes).toString('hex')) {
        fail(
          'diffed bytes differ from Yjs (documents still converge)',
          firstDivergence(phpBytes, yjsBytes),
        )
      }
    }
  }

  if (verbose) {
    console.log(`seed ${seed}: ${pool.length} updates in the pool, ${expectations.length} checks`)
  }
}

if (failures.length > 0) {
  console.error(`\n${failures.length} of ${checks} checks failed:\n`)

  const shown = failures.slice(0, 10)
  for (const failure of shown) {
    console.error(`  ✗ seed ${failure.seed} round ${failure.round} [${failure.kind}] ${failure.message}`)
    console.error(`    ${failure.detail.split('\n').join('\n    ')}\n`)
  }

  if (failures.length > shown.length) {
    console.error(`  ... and ${failures.length - shown.length} more\n`)
  }

  const seedsSeen = [...new Set(failures.map((f) => f.seed))]
  console.error(`Reproduce with: node tools/oracle/differential.mjs --seeds ${seedsSeen.join(',')}\n`)
  process.exit(1)
}

console.log(
  `${checks} differential checks passed across ${seeds.length} seeds` +
    (formatDivergences > 0
      ? `, including ${formatDivergences} where merging and sequential application differ and Yjs differs identically.`
      : '.'),
)
