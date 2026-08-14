/**
 * Generate the Profile 1 golden-byte fixtures from the real lib0 build.
 *
 * The output is committed so that the PHP test suite can run without Node. This
 * script is what makes that safe: CI regenerates the fixtures with the pinned
 * packages and fails if anything moved.
 *
 * Deliberately deterministic. There is no timestamp anywhere in the output,
 * because a fixture file that changes on every run cannot prove that nothing
 * changed.
 */
import { createRequire } from 'node:module'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as encoding from 'lib0/encoding'
import * as Y from 'yjs'
import { ContentString } from 'yjs'
import * as syncProtocol from 'y-protocols/sync'
import * as awarenessProtocol from 'y-protocols/awareness'

import updateScenarios from './update-scenarios.mjs'

import {
  anyCases,
  bigInt64Cases,
  bytesCases,
  float32Cases,
  float64Cases,
  stringCases,
  uint16Cases,
  uint32Cases,
  uint8Cases,
  utf16Cases,
  utf16SplitCases,
  varIntCases,
  varUintCases,
} from './cases.mjs'
import { bytesToBase64, normalize, realize } from './spec.mjs'

const require = createRequire(import.meta.url)
const here = dirname(fileURLToPath(import.meta.url))
const fixtureRoot = join(here, '..', '..', 'fixtures', 'profile-1')

const versionOf = (packageName) => require(`${packageName}/package.json`).version

const encodeWith = (writer, value) => {
  const encoder = encoding.createEncoder()
  writer(encoder, value)
  return bytesToBase64(encoding.toUint8Array(encoder))
}

const write = (name, payload) => {
  const path = join(fixtureRoot, `${name}.json`)
  mkdirSync(dirname(path), { recursive: true })
  writeFileSync(path, `${JSON.stringify(payload, null, 2)}\n`)
  console.log(`wrote fixtures/profile-1/${name}.json`)
}

/**
 * Golden bytes for one lib0 writer across a table of values.
 */
const writeGroup = (name, writerName, writer, cases) => {
  write(name, {
    writer: writerName,
    cases: cases.map((testCase) => {
      const value = normalize(testCase.value)

      return {
        name: testCase.name,
        value,
        bytes: encodeWith(writer, realize(value)),
      }
    }),
  })
}

writeGroup('var-uint', 'writeVarUint', encoding.writeVarUint, varUintCases)
writeGroup('var-int', 'writeVarInt', encoding.writeVarInt, varIntCases)
writeGroup('uint8', 'writeUint8', encoding.writeUint8, uint8Cases)
writeGroup('uint16', 'writeUint16', encoding.writeUint16, uint16Cases)
writeGroup('uint32', 'writeUint32', encoding.writeUint32, uint32Cases)
writeGroup('uint32-big-endian', 'writeUint32BigEndian', encoding.writeUint32BigEndian, uint32Cases)
writeGroup('float32', 'writeFloat32', encoding.writeFloat32, float32Cases)
writeGroup('float64', 'writeFloat64', encoding.writeFloat64, float64Cases)
writeGroup('big-int64', 'writeBigInt64', encoding.writeBigInt64, bigInt64Cases)
writeGroup('var-bytes', 'writeVarUint8Array', encoding.writeVarUint8Array, bytesCases)
writeGroup('any', 'writeAny', encoding.writeAny, anyCases)

/**
 * Strings carry the two lengths as well as the bytes, because the whole point
 * of the UTF-16 helpers is that PHP cannot derive one from the other.
 */
write('var-string', {
  writer: 'writeVarString',
  cases: stringCases.map((testCase) => {
    const text = realize(testCase.value)

    return {
      name: testCase.name,
      value: testCase.value,
      bytes: encodeWith(encoding.writeVarString, text),
      utf16Length: text.length,
      utf8Length: Buffer.byteLength(text, 'utf8'),
    }
  }),
})

/**
 * A slice can land between the halves of a surrogate pair. JavaScript hands
 * back a lone surrogate; UTF-8 has no encoding for one, so PHP has to refuse.
 * Flagging those cases here is what lets the PHP suite assert the refusal
 * instead of quietly skipping them.
 */
const hasLoneSurrogate = (text) => {
  for (let index = 0; index < text.length; index++) {
    const unit = text.charCodeAt(index)

    if (unit >= 0xd800 && unit <= 0xdbff) {
      const next = text.charCodeAt(index + 1)
      if (!(next >= 0xdc00 && next <= 0xdfff)) {
        return true
      }
      index++
    } else if (unit >= 0xdc00 && unit <= 0xdfff) {
      return true
    }
  }

  return false
}

write('utf16', {
  cases: utf16Cases.map((testCase) => {
    if (testCase.kind === 'length') {
      return {
        name: testCase.name,
        kind: 'length',
        subject: testCase.subject,
        utf16Length: testCase.subject.length,
      }
    }

    const sliced = testCase.subject.slice(testCase.offset, testCase.offset + testCase.length)

    return {
      name: testCase.name,
      kind: 'slice',
      subject: testCase.subject,
      offset: testCase.offset,
      length: testCase.length,
      expected: hasLoneSurrogate(sliced) ? null : sliced,
      splitsSurrogatePair: hasLoneSurrogate(sliced),
    }
  }),
})

/**
 * The split Yjs performs when it cuts string content at a clock.
 *
 * Generated by calling the real `ContentString.splice` rather than by
 * reimplementing its rule here. Its handling of a broken surrogate pair —
 * U+FFFD substituted on both sides, so each half keeps the length the clocks
 * already assume — is the behavior these fixtures exist to pin down, and
 * restating it in this file would only prove the restatement self-consistent.
 */
write('utf16-split', {
  source: 'yjs ContentString.prototype.splice',
  cases: utf16SplitCases.map((testCase) => {
    const content = new ContentString(testCase.subject)
    const right = content.splice(testCase.offset)

    return {
      name: testCase.name,
      subject: testCase.subject,
      offset: testCase.offset,
      left: content.str,
      right: right.str,
      // Recorded so the PHP suite can assert that the lengths held, which is
      // the whole reason the substitution is shaped this way.
      leftLength: content.str.length,
      rightLength: right.str.length,
      damagedSurrogatePair: content.str !== testCase.subject.slice(0, testCase.offset),
    }
  }),
})

/**
 * Put an update through a live document and encode it back out.
 *
 * This is Yjs's *other* round trip: it materializes the update into a Doc and
 * re-derives the bytes from that, rather than working on the update itself.
 */
const reencodeThroughDocument = (update) => {
  const doc = new Y.Doc()
  Y.applyUpdate(doc, update)
  return Y.encodeStateAsUpdate(doc)
}

/**
 * Whole Yjs V1 updates, with the structure Yjs itself reads out of them.
 *
 * Recording the structure as well as the bytes is what makes a failure
 * diagnosable. A byte comparison alone says only that something moved; the
 * per-struct summary says which struct, which content reference, and which
 * clock — without needing Node to find out.
 */
write('updates', {
  source: 'yjs encodeStateAsUpdate / decodeUpdate',
  cases: updateScenarios.map((scenario) => {
    const update = scenario.produce()
    const decoded = Y.decodeUpdate(update)

    return {
      name: scenario.name,
      description: scenario.description,
      update: bytesToBase64(update),
      // Yjs's two round trips over the same update. They agree on everything
      // Yjs originates, so recording both is what makes the cases where they
      // come apart visible — and those are the only ones that can tell whether
      // an implementation copied the right one.
      mergedByYjs: bytesToBase64(Y.mergeUpdates([update])),
      viaLiveDocument: bytesToBase64(reencodeThroughDocument(update)),
      stateVector: bytesToBase64(Y.encodeStateVectorFromUpdate(update)),
      structs: decoded.structs.map((struct) => ({
        kind: struct.constructor.name,
        client: struct.id.client,
        clock: struct.id.clock,
        length: struct.length,
        contentRef: struct.content === undefined ? null : struct.content.getRef(),
        content: struct.content === undefined ? null : struct.content.constructor.name,
      })),
      deleteSet: Array.from(decoded.ds.clients.entries())
        .sort((a, b) => b[0] - a[0])
        .map(([client, ranges]) => ({
          client,
          ranges: ranges.map((range) => ({ clock: range.clock, length: range.len })),
        })),
    }
  }),
})

/**
 * y-protocols sync and awareness messages, produced by the real y-protocols.
 *
 * These are transcripts rather than values: what actually goes over a socket
 * between a provider and a server, so the PHP codec is checked against traffic
 * instead of against a reading of the spec.
 */
write('protocol', {
  source: 'y-protocols sync.js and awareness.js',
  sync: (() => {
    const doc = new Y.Doc()
    doc.clientID = 700
    doc.getText('text').insert(0, 'protocol fixtures 😀')
    doc.getMap('map').set('k', { nested: true })

    const empty = new Y.Doc()
    const cases = []

    const frame = (name, describe, build) => {
      const encoder = encoding.createEncoder()
      build(encoder)
      cases.push({ name, description: describe, bytes: bytesToBase64(encoding.toUint8Array(encoder)) })
    }

    frame('step1-empty', 'SyncStep1 from a peer holding nothing.', (e) => syncProtocol.writeSyncStep1(e, empty))
    frame('step1-populated', 'SyncStep1 from a peer that already has content.', (e) => syncProtocol.writeSyncStep1(e, doc))
    frame('step2-full', 'SyncStep2 answering a peer that holds nothing.', (e) =>
      syncProtocol.writeSyncStep2(e, doc, Y.encodeStateVector(empty)))
    frame('step2-empty', 'SyncStep2 answering a peer that is already current.', (e) =>
      syncProtocol.writeSyncStep2(e, doc, Y.encodeStateVector(doc)))
    frame('update', 'An unprompted update broadcast.', (e) =>
      syncProtocol.writeUpdate(e, Y.encodeStateAsUpdate(doc)))
    frame('two-messages', 'Two messages packed into one frame, as a provider may send.', (e) => {
      syncProtocol.writeSyncStep1(e, doc)
      syncProtocol.writeUpdate(e, Y.encodeStateAsUpdate(doc))
    })

    return cases
  })(),
  awareness: (() => {
    const cases = []

    const build = (name, describe, clientStates) => {
      const awareness = new awarenessProtocol.Awareness(new Y.Doc())
      const clients = []

      for (const [clientID, state] of clientStates) {
        awareness.meta.set(clientID, { clock: 1, lastUpdated: 0 })
        if (state !== null) {
          awareness.states.set(clientID, state)
        }
        clients.push(clientID)
      }

      cases.push({
        name,
        description: describe,
        clients: clientStates.map(([clientID, state]) => ({
          client: clientID,
          clock: 1,
          state: state === null ? null : JSON.stringify(state),
        })),
        bytes: bytesToBase64(awarenessProtocol.encodeAwarenessUpdate(awareness, clients)),
      })

      // Awareness starts an interval to expire outdated clients, which would
      // keep this process alive forever once the fixtures are written.
      awareness.destroy()
    }

    build('empty', 'An update mentioning nobody.', [])
    build('single', 'One client announcing itself.', [[11, { name: 'Ada', color: '#f00' }]])
    build('several', 'Three clients at once.', [
      [11, { name: 'Ada' }],
      [22, { name: 'Grace', cursor: { anchor: 3, head: 9 } }],
      [33, { name: '日本 😀' }],
    ])
    build('removal', 'A client going away, encoded as a null state.', [[44, null]])
    build('mixed', 'A presence and a departure in one update.', [[11, { name: 'Ada' }], [44, null]])

    return cases
  })(),
})

write('manifest', {
  profile: 1,
  description:
    'Package versions the committed fixtures were generated from. CI regenerates against these exact versions and fails on any diff.',
  packages: {
    lib0: versionOf('lib0'),
    yjs: versionOf('yjs'),
    'y-protocols': versionOf('y-protocols'),
  },
})

console.log('\nFixtures regenerated. A clean `git diff` means PHP and lib0 still agree.')
