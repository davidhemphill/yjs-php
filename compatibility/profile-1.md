# Compatibility Profile 1

The exact software this library is built to interoperate with, and every place
where it deliberately does something different.

A profile is a promise about observable behavior. When one of the packages below
moves, that is a new profile with its own manifest and its own upgrade note —
not a silent bump of the numbers in this file.

## Pinned packages

The JavaScript versions come from `tools/oracle/package-lock.json`, which is the
authoritative pin: the integrity hashes below fix the exact published artifact,
not just the version number.

| Package | Version | Integrity |
|---|---|---|
| `lib0` | 0.2.117 | `sha512-DeXj9X5xDCjgKLU/7RR+/HQEVzuuEUiwldwOGsHK/sfAfELGWEyTcf0x+uOvCvK3O2zPmZePXWL85vtia6GyZw==` |
| `yjs` | 13.6.29 | `sha512-kHqDPdltoXH+X4w1lVmMtddE3Oeqq48nM40FD5ojTd8xYhQpzIDcfE2keMSU5bAgRPJBe225WTUdyUgj1DtbiQ==` |
| `y-protocols` | 1.0.7 | `sha512-YSVsLoXxO67J6eE/nV4AtFtT3QEotZf5sK5BHxFBXso7VDUT3Tx07IfA6hsu5Q5OmBdMkQVmFZ9QOA7fikWvnw==` |
| `isomorphic.js` (transitive) | 0.2.5 | `sha512-PIeMbHqMt4DnUP3MA/Flc0HElYjMXArsw1qwJZcm9sqR8mq3l8NYizFMty0pWwE/tzIGH3EKK5+jes5mAr85yw==` |

Consumed by, but not exercised in this repository:

| Component | Version |
|---|---|
| `@hocuspocus/provider` | 3.4.4 |
| Hocuspocus server (interoperability oracle only) | 3.4.4 |
| BlockNote | 0.46.2 |
| `y-prosemirror` | 1.3.7 |

Runtime: PHP 8.4, 64-bit only. The 64-bit requirement is enforced at load time
by `Yjs\Environment`, because on a 32-bit build a clock past 2^31 would promote
to a float and the decoder would keep running while returning wrong answers.

## Fixture generation

`fixtures/profile-1/` is generated from the packages above and committed, so the
PHP suite runs without Node.

```bash
npm --prefix tools/oracle ci
node tools/oracle/generate-fixtures.mjs
```

The output is deterministic and contains no timestamp, so regeneration in CI
either produces an empty `git diff` or a real disagreement. `fixtures/profile-1/manifest.json`
records the versions the committed bytes came from.

The reverse direction is checked by `node tools/oracle/verify-php-output.mjs`,
which encodes every fixture case with PHP and decodes the result with the real
lib0 build. Byte comparison alone would not catch an error that PHP's own
encoder and decoder share; reading the value back with lib0 does.

## What Phase 1 covers

Supported, with golden-byte fixtures in both directions:

- `writeVarUint` / `readVarUint`, to 2^53 - 1
- `writeVarInt` / `readVarInt`, including the negative zero encoding
- `writeUint8`, `writeUint16`, `writeUint32`, `writeUint32BigEndian` and their readers
- `writeFloat32`, `writeFloat64`, `writeBigInt64` and their readers
- `writeVarUint8Array` / `readVarUint8Array`
- `writeVarString` / `readVarString`
- `writeAny` / `readAny`, every type tag from 116 through 127
- UTF-16 code unit length and slicing, matching JavaScript's `String.length` and `String.prototype.slice`
- UTF-16 content splitting, matching Yjs's `ContentString.prototype.splice`

## What Phase 2 covers

The Yjs V1 update structure, verified against updates built by the real Yjs and
decoded with `Y.decodeUpdate`:

- state vector and delete set read/write, including delete set normalization,
  union, and subset
- client struct sections, with clocks reconstructed by accumulating lengths
- all three struct kinds: `Item`, `GC` (ref 0), `Skip` (ref 10)
- all nine content references, with nested shared-type metadata carried opaquely
- the conditional origin, right-origin, parent, and parentSub fields

## What Phase 3 covers

The binary update algebra, verified against Yjs by differential testing rather
than by fixtures:

- `Update::stateVector()` — `encodeStateVectorFromUpdate`
- `Update::merge()` / `mergeAll()` — `mergeUpdates`
- `Update::diff()` — `diffUpdate`
- struct slicing at clock boundaries, including UTF-16 content
- `Update::contains()` — the redundancy check a read-only session needs
- `Update::validate()` — structural invariants and configurable semantic limits

Merge and diff are **byte-identical to Yjs**, not merely equivalent. That is a
stronger claim than the roadmap asks for, and it was worth the extra work:
matching bytes means a disagreement is a bug rather than a judgement call.

Not yet implemented — a later phase, not a gap in this one:

- the y-protocols sync and awareness codecs (Phase 4)

### How the algebra is checked

`node tools/oracle/differential.mjs` builds randomized three-client histories
with the real Yjs, has PHP merge and diff them, and applies the results to real
Yjs documents to compare against Yjs's own answers. Every scenario comes from a
seed, so a failure reproduces exactly; a seed that ever fails belongs in the
default list permanently.

Fixtures cannot do this job. Encoding has one right answer, so bytes suffice;
merging does not, and an implementation can be wrong in a way that still
round-trips and only surfaces later as two clients disagreeing about the text.

### Three things the algebra does that look wrong

**Adjacent Items are never coalesced.** `Item.mergeWith` requires
`this.right === right`, a link that exists only inside a materialized document.
Structs decoded from an update always have a null right pointer, so the check
cannot pass and Yjs leaves adjacent Items separate too. GC and Skip do coalesce.

**Merging normalizes the info byte; relaying does not.** Yjs derives an Item's
info byte from its fields on every write, so a rewrite drops a `parentSub` bit
whose field was never on the wire (see the Phase 2 note). A plain
decode/encode must preserve it; merge and diff must not. Both behaviors are
asserted.

**Merging is not the same as applying updates one after another.** Integrating
sequentially splits items where merging does not, and a split that lands inside
a surrogate pair replaces it with two U+FFFD. So a merged update can preserve an
emoji that sequential application destroys. Yjs behaves identically; the oracle
counts these cases rather than failing on them, and reports the count so a sharp
rise is visible.

### The delete set is never filtered by a state vector

A state vector counts a client's structs, and a deletion is not a struct, so
there is no way to know which tombstones a peer already holds. `diffUpdate`
copies the delete set whole, and so does this. Sending them all is cheap and
idempotent; dropping one would resurrect deleted text.

### Content reference map

| Ref | Yjs class | Here | Clocks |
|---|---|---|---:|
| 1 | ContentDeleted | `Content\Deleted` | its length |
| 2 | ContentJSON | `Content\Json` | element count |
| 3 | ContentBinary | `Content\Binary` | 1 |
| 4 | ContentString | `Content\Text` | UTF-16 units |
| 5 | ContentEmbed | `Content\Embed` | 1 |
| 6 | ContentFormat | `Content\Format` | 1 |
| 7 | ContentType | `Content\SharedType` | 1 |
| 8 | ContentAny | `Content\AnyValues` | element count |
| 9 | ContentDoc | `Content\SubDocument` | 1 |

### Three places the V1 format is easy to get wrong

**The parentSub bit can be set with no field on the wire.** Yjs sets info bit 6
from whether `parentSub` is non-null, but writes the field only inside the
branch taken when an Item has neither an origin nor a right origin. Reading the
field whenever the bit is set desynchronizes the rest of the stream. For the
same reason the info byte is preserved verbatim rather than recomputed on write:
recomputing it from the fields we hold would drop that bit.

**A state vector is a prefix, not a maximum.** `contiguousEndClock()` stops at
the first `Skip` and returns zero if a section does not begin at clock 0,
matching `encodeStateVectorFromUpdate`. Claiming the clock past a gap would tell
a peer we hold structs we do not have, and it would never send them.

**JSON content is not re-serialized.** Refs 2, 5, and 6 carry JSON as text.
`JSON.stringify` and PHP's `json_encode` disagree about escaping, float
formatting, and key order, so parsing and re-encoding would change the bytes.
The text is kept exactly as it arrived.

### Yjs has two round trips, and they are not the same

Yjs can put an update through a live `Doc` (`applyUpdate` then
`encodeStateAsUpdate`) or through the update itself (`mergeUpdates`). The first
rebuilds content from a materialized document; the second does not. This library
is the second kind, so `mergedByYjs` in the update fixtures records Yjs's
update-level output and every fixture asserts against it. Matching only our own
input would never catch imitating the wrong one of the two.

The two paths agree on everything Yjs originates. `ContentDoc` is where they can
come apart: its constructor rebuilds `opts` down to `gc`, `autoLoad`, and `meta`,
but it does so when the content is *created*, so options are already normalized
before they reach the wire. An update from somewhere else can carry more, and
then `mergeUpdates` preserves the extra keys while the live-document path drops
them. The `subdocument-foreign-opts` fixture pins that case.

Never in scope, per the master plan: the V2 update codec, a materialized
`Y.Doc`, and PHP APIs for the shared types.

## Deliberate deviations from lib0

Each of these is a case where copying lib0 exactly would be wrong for a server
that reads bytes from the network. All are strictly narrowing: no input a
conforming Yjs client can produce is affected.

### Variable-length integers are bounded to the safe range

lib0 checks its running magnitude only when a varint continues, so a final byte
that pushes the value past 2^53 - 1 is returned rather than rejected. It also
has no cap on how many bytes one varint may occupy.

This library rejects both: a value above `Number.MAX_SAFE_INTEGER`, and any
varint wider than 8 bytes. Every varUint on the Yjs wire is a client ID, clock,
length, or count, and none of those mean anything once they stop being exactly
representable. The byte cap additionally keeps the reader's own arithmetic
inside PHP's integer range, which is what makes the magnitude check trustworthy.

### Invalid UTF-8 is rejected rather than replaced

lib0 decodes strings through `TextDecoder`, which substitutes U+FFFD for
malformed sequences. This library throws `MalformedInput`.

Nothing is lost: every real client encodes through `TextEncoder`, which cannot
emit invalid UTF-8 — even a lone surrogate becomes U+FFFD before it reaches the
wire. Bytes that fail this check are corruption or an attack, and silently
repairing them would write the repaired text back to every other client.

Reading the same bytes as an opaque byte array still works. The check applies
only where the format promises text.

### Declared sizes are checked before allocation

Every length-prefixed read and every collection header is validated against
`DecodeLimits` first, and against the bytes actually remaining. A header
claiming a two gigabyte string costs a comparison rather than an allocation.
lib0 has no equivalent, because it is not the thing facing the socket.

### Decode failures are typed

Every failure below `Yjs\Exception\DecodeException` — truncation, out-of-range
integers, exceeded limits, malformed input. Nothing warns, returns `false`, or
escapes as a `TypeError`. This is asserted by the fuzz suite rather than only
promised here.

## PHP representation choices

JavaScript has values PHP does not, and PHP conflates values JavaScript keeps
apart. Where that happens, the wire wins: the goal is that a decoded value
re-encodes to identical bytes.

| lib0 `any` tag | PHP value |
|---|---|
| `undefined` (127) | `Yjs\Binary\AnyValue\Undefined::instance()` |
| `null` (126) | `null` |
| integer (125) | `int`, or `-0.0` for the negative zero encoding |
| float32 (124), float64 (123) | `float` |
| bigint (122) | `Yjs\Binary\AnyValue\BigInt` |
| boolean (121, 120) | `bool` |
| string (119) | `string`, valid UTF-8 |
| object (118) | `stdClass` |
| array (117) | PHP list |
| `Uint8Array` (116) | `Yjs\Binary\AnyValue\Bytes` |

Three consequences worth stating plainly:

- **Objects decode to `stdClass`, not to associative arrays.** PHP cannot tell
  an empty list from an empty map, and the two are separate tags. An object that
  arrived as `{}` has to come back as something that will not re-encode as `[]`.
- **A whole `float` encodes as an integer and decodes as an `int`.** lib0 has one
  tag for both because JavaScript has one type for both; `5.0` and `5` are the
  same `Number` there. Demanding `5.0` back would mean disagreeing with the
  format. What is guaranteed is that a second round trip changes nothing.
- **An `int` above 2^31 - 1 becomes a float.** `writeAny` only tags values up to
  `BITS31` as integers; past that lib0 falls to float32 or float64, and precision
  beyond 2^53 is lost. This is lossy in JavaScript too, it just cannot be
  observed there. Use `BigInt` when the exact 64-bit value matters.

### Object key order

`Object.keys` does not return insertion order in JavaScript: integer-like keys
come first, ascending, ahead of every string key. lib0 writes an object in that
order.

PHP preserves insertion order for all keys, so it reproduces whatever order it
decoded and stays byte-stable. The generator normalizes each object fixture into
JavaScript's enumeration order, which is why `any/object-numeric-keys` is
committed as `2, 10, b` although it is declared as `10, 2, b`.

### Splitting between surrogates

A clock counts UTF-16 units, so a split of string content can land between the
halves of a surrogate pair. There are two operations here and they answer that
differently on purpose.

**`Utf16::split()` — what the update algebra uses.** It reproduces Yjs's
`ContentString.splice` exactly: the broken pair is replaced with U+FFFD on
*both* sides ([yjs#248](https://github.com/yjs/yjs/issues/248)). The character
is genuinely destroyed — one astral character becomes two replacement
characters — but a high surrogate is one UTF-16 unit and U+FFFD is one UTF-16
unit, so each half keeps exactly the length the surrounding clocks assume.
Refusing the split, or widening it to the character boundary, would change those
lengths and invalidate every clock after it. Yjs accepts damaged text over a
document that cannot be encoded, and matching that is not optional for an
implementation that has to converge with it.

The fixtures in `fixtures/profile-1/utf16-split.json` are generated by calling
the real `ContentString.splice`, not by restating its rule, and they record the
resulting lengths so the PHP suite asserts against JavaScript's own count.
`Utf16::splitsSurrogatePair()` answers whether a given split will do this
damage, for callers that want to count or log it.

**`Utf16::slice()` — the general primitive.** It throws when a boundary falls
inside a pair, because JavaScript would hand back a lone surrogate and UTF-8 has
no encoding for one. A caller reaching for `slice()` where `split()` was meant
gets a loud, bounded failure rather than silent corruption.

A zero-length slice is always `""`, even at such a boundary, because nothing is
being extracted.

## Upgrade note policy

A change to any pinned version requires:

1. regenerating the fixtures and reviewing the resulting `git diff` byte by byte;
2. recording any change in observable behavior in this file;
3. a new profile number if the wire format or a documented behavior moved.

A fixture diff is never noise. It is the only signal that the format changed.
