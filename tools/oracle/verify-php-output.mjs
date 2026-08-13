/**
 * Decode PHP's encoder output with the real lib0 build.
 *
 * The committed fixtures already prove that PHP produces lib0's bytes. This is
 * the converse: it proves lib0 can read what PHP writes, and it reads the value
 * back rather than comparing bytes, so an encoding that is wrong in a way the
 * PHP decoder shares would still fail here.
 */
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as decoding from 'lib0/decoding'

import { realize, sameValue } from './spec.mjs'

const require = createRequire(import.meta.url)
const here = dirname(fileURLToPath(import.meta.url))

/** The lib0 reader that corresponds to each fixture group's writer. */
const readers = {
  'var-uint': decoding.readVarUint,
  'var-int': decoding.readVarInt,
  uint8: decoding.readUint8,
  uint16: decoding.readUint16,
  uint32: decoding.readUint32,
  'uint32-big-endian': decoding.readUint32BigEndian,
  float32: decoding.readFloat32,
  float64: decoding.readFloat64,
  'big-int64': decoding.readBigInt64,
  'var-bytes': decoding.readVarUint8Array,
  'var-string': decoding.readVarString,
  any: decoding.readAny,
}

const report = JSON.parse(
  execFileSync('php', [join(here, 'emit-php-encodings.php')], {
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  }),
)

if (report.intSize < 8) {
  console.error(`PHP reports ${report.intSize}-byte integers; yjs-php requires a 64-bit build.`)
  process.exit(1)
}

const failures = []
let checked = 0

for (const [group, cases] of Object.entries(report.groups)) {
  const read = readers[group]

  if (!read) {
    failures.push(`${group}: no lib0 reader is mapped for this group`)
    continue
  }

  for (const testCase of cases) {
    const bytes = Uint8Array.from(Buffer.from(testCase.bytes, 'base64'))
    const decoder = decoding.createDecoder(bytes)

    let decoded
    try {
      decoded = read(decoder)
    } catch (error) {
      failures.push(`${testCase.name}: lib0 could not decode PHP's bytes — ${error.message}`)
      continue
    }

    const expected = realize(testCase.value)

    if (!sameValue(expected, decoded)) {
      failures.push(
        `${testCase.name}: lib0 decoded ${JSON.stringify(String(decoded))}, expected ${JSON.stringify(String(expected))}`,
      )
      continue
    }

    if (decoding.hasContent(decoder)) {
      failures.push(`${testCase.name}: PHP wrote trailing bytes lib0 did not consume`)
      continue
    }

    checked++
  }
}

if (failures.length > 0) {
  console.error(`\n${failures.length} case(s) failed:\n`)
  for (const failure of failures) {
    console.error(`  ✗ ${failure}`)
  }
  process.exit(1)
}

const lib0Version = require('lib0/package.json').version

console.log(`lib0 ${lib0Version} decoded all ${checked} cases encoded by PHP ${report.php}.`)
