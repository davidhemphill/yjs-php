import * as Y from 'yjs'
import * as encoding from 'lib0/encoding'

/**
 * Documents built with the real Yjs API, each chosen to put a particular struct
 * or content reference on the wire.
 *
 * Building these through the public API rather than by hand is what makes them
 * evidence. A fixture we assembled ourselves would only prove that our own
 * understanding is self-consistent.
 */

/** A deterministic client ID, so regenerating produces identical bytes. */
const docWith = (clientID, build) => {
  const doc = new Y.Doc()
  doc.clientID = clientID
  build(doc)
  return doc
}

const scenarios = []

const scenario = (name, description, produce) => {
  scenarios.push({ name, description, produce })
}

scenario('empty', 'A document nobody has touched: no structs, no deletions.', () =>
  Y.encodeStateAsUpdate(docWith(1, () => {})),
)

scenario('text-plain', 'ContentString across ASCII and astral text.', () =>
  Y.encodeStateAsUpdate(
    docWith(101, (doc) => {
      doc.getText('doc').insert(0, 'Hello 😀 world')
    }),
  ),
)

scenario('text-formatted', 'ContentFormat marks around ContentString.', () =>
  Y.encodeStateAsUpdate(
    docWith(102, (doc) => {
      const text = doc.getText('doc')
      text.insert(0, 'bold and italic')
      text.format(0, 4, { bold: true })
      text.format(9, 6, { italic: { size: 2 } })
    }),
  ),
)

scenario('text-deleted', 'ContentDeleted plus a populated delete set.', () =>
  Y.encodeStateAsUpdate(
    docWith(103, (doc) => {
      const text = doc.getText('doc')
      text.insert(0, 'keep delete keep')
      text.delete(4, 7)
    }),
  ),
)

scenario('text-embed', 'ContentEmbed, written as JSON text rather than lib0 any.', () =>
  Y.encodeStateAsUpdate(
    docWith(104, (doc) => {
      const text = doc.getText('doc')
      text.insert(0, 'before after')
      text.insertEmbed(6, { image: 'https://example.test/a.png', width: 100 })
    }),
  ),
)

scenario('map-any', 'ContentAny across every lib0 any shape a map value can take.', () =>
  Y.encodeStateAsUpdate(
    docWith(105, (doc) => {
      const map = doc.getMap('meta')
      map.set('string', 'value')
      map.set('int', 42)
      map.set('float', 0.5)
      map.set('bool', true)
      map.set('null', null)
      map.set('array', [1, 2, 3])
      map.set('object', { nested: { deep: true } })
    }),
  ),
)

scenario('map-binary', 'ContentBinary from a Uint8Array map value.', () =>
  Y.encodeStateAsUpdate(
    docWith(106, (doc) => {
      doc.getMap('meta').set('blob', new Uint8Array([0, 1, 2, 253, 254, 255]))
    }),
  ),
)

scenario('nested-types', 'ContentType for every shared type that has no name.', () =>
  Y.encodeStateAsUpdate(
    docWith(107, (doc) => {
      const map = doc.getMap('root')
      map.set('array', new Y.Array())
      map.set('map', new Y.Map())
      map.set('text', new Y.Text())
      map.set('fragment', new Y.XmlFragment())
      map.set('xmltext', new Y.XmlText())
    }),
  ),
)

scenario('xml-named-types', 'ContentType for the two shared types that carry a name.', () =>
  Y.encodeStateAsUpdate(
    docWith(108, (doc) => {
      const fragment = doc.getXmlFragment('page')
      const element = new Y.XmlElement('paragraph')
      element.setAttribute('align', 'center')
      fragment.insert(0, [element, new Y.XmlHook('embed-hook')])
    }),
  ),
)

scenario('subdocument', 'ContentDoc carrying a nested document guid and its options.', () =>
  Y.encodeStateAsUpdate(
    docWith(109, (doc) => {
      const sub = new Y.Doc({ guid: 'fixed-guid-for-determinism', meta: { kind: 'note' } })
      sub.autoLoad = true
      doc.getMap('docs').set('child', sub)
    }),
  ),
)

scenario('multi-client', 'Three clients merged into one update, exercising section ordering.', () => {
  const merged = new Y.Doc()

  for (const [clientID, word] of [[7, 'alpha'], [900, 'beta'], [55, 'gamma']]) {
    const doc = docWith(clientID, (d) => d.getText('doc').insert(0, word))
    Y.applyUpdate(merged, Y.encodeStateAsUpdate(doc))
  }

  return Y.encodeStateAsUpdate(merged)
})

scenario('garbage-collected', 'GC structs, from deleting a nested type in a gc-enabled document.', () => {
  const doc = docWith(110, (d) => {
    const array = d.getArray('list')
    array.insert(0, [new Y.Map(), new Y.Map(), 'plain'])
    array.get(0).set('key', 'value')
    array.get(1).set('other', 'value')
  })

  doc.getArray('list').delete(0, 2)

  return Y.encodeStateAsUpdate(doc)
})

scenario('skip-structs', 'Skip structs, from merging two updates with a gap between them.', () => {
  const doc = docWith(111, (d) => d.getText('doc').insert(0, 'first '))
  const text = doc.getText('doc')

  // Three separate runs of clocks for one client, captured as three updates.
  const first = Y.encodeStateAsUpdate(doc)
  const afterFirst = Y.encodeStateVector(doc)

  text.insert(6, 'second ')
  const second = Y.encodeStateVector(doc)

  text.insert(13, 'third')
  const third = Y.diffUpdate(Y.encodeStateAsUpdate(doc), second)

  // Merging the first and third runs leaves the middle run's clocks unaccounted
  // for. A state vector cannot express an interior hole — it only says how far
  // a client has been read — so the gap has to be written into the update
  // itself, which is exactly what a Skip is for.
  return Y.mergeUpdates([first, third])
})

scenario('deletes-across-clients', 'A delete set spanning several clients and disjoint ranges.', () => {
  const merged = new Y.Doc()

  for (const clientID of [3, 200, 41]) {
    const doc = docWith(clientID, (d) => d.getText('doc').insert(0, 'abcdefghij'))
    Y.applyUpdate(merged, Y.encodeStateAsUpdate(doc))
  }

  const text = merged.getText('doc')
  text.delete(1, 2)
  text.delete(6, 3)
  text.delete(15, 4)

  return Y.encodeStateAsUpdate(merged)
})

/**
 * ContentJSON has no route through the modern public API — `ContentAny`
 * superseded it — so this update is assembled by hand.
 *
 * That makes it the one fixture we cannot simply trust, which is why the
 * generator applies it to a real document and records what Yjs read back. If
 * Yjs rejects it or reads something else, generation fails rather than
 * committing a fixture that only agrees with itself.
 */
scenario('content-json', 'ContentJSON, the legacy JSON-encoded content reference.', () => {
  const encoder = encoding.createEncoder()
  const values = ['{"legacy":true}', 'undefined', '42']

  encoding.writeVarUint(encoder, 1) // one client section
  encoding.writeVarUint(encoder, 1) // one struct
  encoding.writeVarUint(encoder, 112) // client
  encoding.writeVarUint(encoder, 0) // clock

  // info: content ref 2 (ContentJSON), parentSub set, no origins.
  encoding.writeUint8(encoder, 2 | 0b0010_0000)
  encoding.writeVarUint(encoder, 1) // parent is a root key
  encoding.writeVarString(encoder, 'root')
  encoding.writeVarString(encoder, 'field') // parentSub
  encoding.writeVarUint(encoder, values.length)
  for (const value of values) {
    encoding.writeVarString(encoder, value)
  }

  encoding.writeVarUint(encoder, 0) // empty delete set

  const update = encoding.toUint8Array(encoder)

  // Prove Yjs accepts it before it becomes a fixture.
  const doc = new Y.Doc()
  Y.applyUpdate(doc, update)

  const readBack = doc.getMap('root').get('field')
  if (readBack !== 42) {
    throw new Error(`Hand-built ContentJSON update did not read back as expected: got ${JSON.stringify(readBack)}`)
  }

  return update
})

/**
 * A ContentDoc whose options carry keys Yjs never writes itself.
 *
 * ContentDoc's constructor rebuilds `opts` from the live document, keeping only
 * `gc`, `autoLoad`, and `meta`. That normalization happens when the content is
 * created, so everything Yjs originates is already normalized by the time it
 * reaches the wire — which is why its own round trips are byte-identical.
 *
 * Foreign options are the only way to tell the two code paths apart: the
 * update-level path preserves them, and the live-document path drops them. This
 * fixture exists to pin which of those an update-level library has to match.
 */
scenario('subdocument-foreign-opts', 'A hand-built ContentDoc carrying options Yjs would not write.', () => {
  const encoder = encoding.createEncoder()

  encoding.writeVarUint(encoder, 1) // one client section
  encoding.writeVarUint(encoder, 1) // one struct
  encoding.writeVarUint(encoder, 600) // client
  encoding.writeVarUint(encoder, 0) // clock

  encoding.writeUint8(encoder, 9 | 0b0010_0000) // ContentDoc, parentSub set
  encoding.writeVarUint(encoder, 1) // parent is a root key
  encoding.writeVarString(encoder, 'root')
  encoding.writeVarString(encoder, 'child') // parentSub
  encoding.writeVarString(encoder, 'guid-1')
  encoding.writeAny(encoder, { shouldLoad: true, extraKey: 'x', gc: false })

  encoding.writeVarUint(encoder, 0) // empty delete set

  const update = encoding.toUint8Array(encoder)

  // Yjs must accept it before it becomes a fixture.
  Y.applyUpdate(new Y.Doc(), update)

  return update
})

export default scenarios
