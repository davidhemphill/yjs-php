# yjs-php

yjs-php reads and writes the Yjs V1 binary update format, along with the lib0
primitives that format is built on, and all of it runs inside the PHP process.

You need PHP 8.4 on a 64-bit build, and the test suite checks that requirement
on every commit.

> **Status: Phases 0–4 done.** The binary foundation, the V1 wire model, the
> update algebra, and the y-protocols codecs all work, and each has been checked
> against the real yjs, lib0, and y-protocols. Whatever comes next belongs to the
> server that consumes this package. See [Roadmap](#roadmap).

## Key features

- **lib0 binary primitives** that produce the bytes the reference implementation
  produces. Fixtures generated from a real lib0 build check this in both
  directions.
- **The Yjs V1 update format**: state vectors, delete sets, struct sections,
  every struct kind, and all nine content references. Decoding loses nothing,
  and re-encoding gives the bytes back.
- **The update algebra**. `stateVector()`, `merge()`, `diff()`, `contains()`,
  and `validate()` produce byte-identical output to
  `encodeStateVectorFromUpdate`, `mergeUpdates`, and `diffUpdate`.
- **The y-protocols codecs** for sync messages and awareness, including the
  admission decision a server has to make before it can answer a handshake from
  a read-only peer.
- **Written for a socket.** Every declared length gets bounded before anything
  is allocated, and every failure arrives as a typed exception. The fuzz suite
  checks that behavior against random, truncated, and mutated input.
- **UTF-16 arithmetic that agrees with JavaScript**, including what Yjs does
  when a split lands between the halves of a surrogate pair.

---

## Contents

- [Why it exists](#why-it-exists)
- [Tech stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Quick start](#quick-start)
  - [The primitives](#the-primitives)
  - [Working with updates](#working-with-updates)
  - [Speaking the protocol](#speaking-the-protocol)
  - [Reading untrusted bytes](#reading-untrusted-bytes)
  - [UTF-16, which is not optional](#utf-16-which-is-not-optional)
  - [Inspecting a decoded value](#inspecting-a-decoded-value)
- [Local development](#local-development)
- [Architecture](#architecture)
  - [Directory structure](#directory-structure)
  - [The layers](#the-layers)
  - [The V1 wire format, byte by byte](#the-v1-wire-format-byte-by-byte)
  - [Data flow through a sync handshake](#data-flow-through-a-sync-handshake)
  - [Where the bytes are decided](#where-the-bytes-are-decided)
- [API reference](#api-reference)
- [Limits](#limits)
- [Compatibility](#compatibility)
- [Testing](#testing)
- [The oracle](#the-oracle)
- [Running this in production](#running-this-in-production)
- [Troubleshooting](#troubleshooting)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

---

## Why it exists

A Laravel application can run a Yjs collaboration server in PHP, sitting beside
Reverb, and production then depends on PHP alone.

This library reads the structure of a Yjs update while stopping short of
interpreting what the document says. That boundary was chosen on purpose. You
can validate, merge, diff, and synchronize updates without ever knowing what
they mean, and staying on this side of the line keeps the shared types out of
scope. The shared types are where implementations drift apart from each other.

---

## Tech stack

| | |
|---|---|
| **Language** | PHP 8.4 or 8.5, 64-bit only |
| **Composer dependencies** | None |
| **Extensions** | `mbstring`, used by `Utf16`, `Encoder`, and `Decoder` |
| **Test runner** | [Pest](https://pestphp.com) 4 on PHPUnit |
| **Formatter** | [Laravel Pint](https://laravel.com/docs/pint) 1.24, `laravel` preset |
| **Dev-only oracle** | Node 22, with `lib0`, `yjs`, and `y-protocols` pinned by lockfile |
| **CI** | GitHub Actions, running a PHP matrix with no JavaScript plus a separate differential job |
| **Distribution** | Composer package `hemp/yjs`, PSR-4 under `Hemp\Yjs\` |

Node stays out of the shipped package entirely. It exists here as a
development-time oracle that generates the committed fixtures, and CI checks
that the PHP job never installs it.

---

## Prerequisites

To use the library you need:

- **PHP 8.4 or newer**, on a **64-bit** build. Yjs clocks and client IDs run up
  to 2<sup>53</sup> − 1. A 32-bit build stops at 2<sup>31</sup> − 1 and silently
  promotes past that to floats, so a decoder would keep running while handing
  back clocks that are close to right. `Hemp\Yjs\Environment::assertSupported()`
  runs at autoload time and throws `UnsupportedPlatform` before any of that can
  happen.
- **Composer 2**.
- The **`mbstring`** extension. `Utf16` converts through UTF-32BE to count code
  units, and `Encoder::writeVarString()` and `Decoder::readVarString()` both
  check UTF-8 validity with `mb_check_encoding()`. Most builds ship it enabled,
  and `composer.json` requires it so that a host without it fails during install.

Developing on the library additionally wants **Node 22** and npm, though only
for regenerating fixtures or running the differential oracle. The shipped test
suite runs without either.

To check your build:

```bash
php -r 'echo PHP_VERSION, " / ", PHP_INT_SIZE * 8, "-bit", PHP_EOL;'
```

Anything reporting `32-bit` will refuse to load the package.

---

## Installation

```bash
composer require hemp/yjs
```

Installing takes one command, and nothing follows it. You won't register a
service provider, publish a config file, compile a binary, or run a post-install
script.

The package autoloads a single file, `src/bootstrap.php`, whose only job is
asserting the 64-bit requirement at load time.

---

## Quick start

### The primitives

```php
use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Binary\Encoder;

$bytes = (new Encoder)
    ->writeVarUint(4711)
    ->writeVarString('hello 😀')
    ->writeAny(['nested' => [1, 2, 3]])
    ->toBytes();

$decoder = new Decoder($bytes, new DecodeLimits(maxByteLength: 1024 * 1024));

$decoder->readVarUint();   // 4711
$decoder->readVarString(); // 'hello 😀'
$decoder->readAny();       // stdClass { nested: [1, 2, 3] }
$decoder->assertAtEnd();
```

Every primitive produces the same bytes lib0 produces, which gets checked
against fixtures generated by a real lib0 build, reading and writing.

### Working with updates

```php
use Hemp\Yjs\Update\Update;
use Hemp\Yjs\Id\StateVector;

$update = Update::decode($bytes)->validate();

$update->stateVector();          // how much of each client's history it carries
$update->merge($another);        // one update covering both
$update->diff($peerStateVector); // only the part that peer is missing
$update->contains($candidate);   // whether it introduces anything new
```

Merge and diff produce the bytes Yjs produces. Checking that involves building
randomized multi-client histories, merging and diffing them in PHP, then
applying the results with the real Yjs and comparing against its own answers.

Something worth knowing before you rely on this: merging differs from applying
updates one after another. Sequential integration splits items where merging
leaves them whole, and when a split lands inside a surrogate pair it replaces
the pair with U+FFFD. A merged update can therefore preserve an emoji that
sequential application would destroy. Yjs behaves the same way, since this comes
out of the format itself.

### Speaking the protocol

```php
use Hemp\Yjs\Protocol\Sync\{SyncMessageReader, SyncStep1, SyncStep2, ReadOnlyPolicy, SyncAdmission};
use Hemp\Yjs\Protocol\Awareness\{AwarenessStore, AwarenessUpdate};

foreach (SyncMessageReader::decodeAll($frame) as $message) {
    if ($message instanceof SyncStep1) {
        $reply = $message->answer($resident);   // a SyncStep2 with what they lack
    }
}

// What a read-only session may accept.
ReadOnlyPolicy::admit($message, $resident) === SyncAdmission::IntroducesState;

$presence = new AwarenessStore;
$change = $presence->apply(AwarenessUpdate::decode($bytes), now: $milliseconds);
$presence->expire(now: $milliseconds);          // clients that went quiet
```

The Hocuspocus provider frames, meaning the document address, Auth, Close, and
SyncStatus, live outside this package. They belong to the provider's protocol
rather than Yjs's, and they carry session state that a server should own.

### Reading untrusted bytes

`Decoder` handles every byte that arrives from the network, and it treats that
input as hostile. Declared lengths and element counts get validated against
`DecodeLimits`, and against whatever bytes remain, before anything gets
allocated. A header claiming a two gigabyte string therefore costs a comparison.

Every failure arrives as a typed `Hemp\Yjs\Exception\DecodeException`. The fuzz
suite checks that nothing warns, returns `false`, or escapes as a `TypeError`
when fed random, truncated, or mutated input.

```php
use Hemp\Yjs\Exception\DecodeException;

try {
    $update = (new Decoder($fromTheSocket))->readAny();
} catch (DecodeException $failure) {
    // Bounded. Close the connection and move on.
}
```

### UTF-16, which is not optional

Yjs addresses string content in UTF-16 code units because JavaScript counts that
way. PHP counts UTF-8 bytes. Below U+0080 the two agree, and above it they
diverge.

```php
use Hemp\Yjs\Binary\Utf16;

Utf16::length('😀');       // 2, one surrogate pair
strlen('😀');              // 4, UTF-8 bytes
Utf16::slice('a😀b', 0, 3); // 'a😀'
```

Since a clock counts UTF-16 units, a split of string content can land between
the halves of a surrogate pair. `Utf16::split()` handles that case the way Yjs
does, putting U+FFFD on both sides:

```php
Utf16::split('a😀b', 1);  // ['a', '😀b']  (clean)
Utf16::split('a😀b', 2);  // ['a�', '�b']  (pair damaged, lengths intact)
```

Splitting this way destroys the character while preserving the arithmetic. A
high surrogate occupies one UTF-16 unit and U+FFFD occupies one as well, so each
half comes out carrying the length the surrounding clocks already assume.
Widening the split to a character boundary would change those lengths and
invalidate every clock downstream of it.

`Utf16::slice()` gives you the stricter general primitive, which throws at such
a boundary, so reaching for the wrong one of the two fails loudly.
`Utf16::splitsSurrogatePair()` answers the question ahead of time for callers
who want to count or log the damage before it happens.

### Inspecting a decoded value

`json_encode` renders `5` for an int and for a whole float, `null` for null and
for undefined, and `{}` for an empty object and for an empty map. Those are the
distinctions worth debugging, and `CanonicalJson` keeps them apart.

```php
use Hemp\Yjs\Debug\CanonicalJson;

CanonicalJson::encode(5);              // 5
CanonicalJson::encode(5.0);            // {"$float":5}
CanonicalJson::encode(-0.0);           // {"$float":"-0"}
CanonicalJson::encode(new stdClass);   // {"$object":[]}
CanonicalJson::encode([]);             // []
```

Two values render the same way when their bytes match, so diffing the rendering
tells you where two updates differ.

`UpdateDump` does the same job one level up, giving you every section, struct,
reference, and content payload as readable JSON:

```php
use Hemp\Yjs\Debug\UpdateDump;

echo UpdateDump::json($update);          // pretty by default
$tree = UpdateDump::of($update);         // the same thing as a PHP array
```

---

## Local development

### 1. Clone

```bash
git clone https://github.com/davidhemphill/yjs-php.git
cd yjs-php
```

### 2. Install PHP dependencies

```bash
composer install
```

That brings in Pest and Pint. Since the package has no runtime dependencies,
`composer install --no-dev` leaves you with a `vendor/` directory holding
nothing but the autoloader.

### 3. Run the suite

```bash
composer test
```

This runs `pint --test` and then the full Pest suite. Expect roughly 1,670 tests
and 66,600 assertions, finishing in about two seconds. Nothing here wants a
database, a service, a network, or Node.

### 4. Optional: install the oracle

You need this only for regenerating fixtures or running the differential test:

```bash
npm --prefix tools/oracle ci
```

Use `ci` rather than `install`, since the lockfile's integrity hashes pin the
exact published artifacts named in
[`compatibility/profile-1.md`](compatibility/profile-1.md), and `install` would
be free to resolve something else.

### Everyday commands

| Command | What it does |
|---|---|
| `composer test` | Format check, then the full suite. What CI runs. |
| `composer test:lint` | `pint --parallel --test`, which fails on formatting drift and changes nothing |
| `composer test:unit` | `pest`, the suite by itself |
| `composer lint` | `pint --parallel`, which rewrites the files |
| `composer fixtures` | Regenerate `fixtures/profile-1/` from the pinned lib0. Wants Node. |
| `composer fixtures:verify` | Have the real lib0 decode PHP's encoder output. Wants Node. |
| `composer oracle` | Differential test of merge and diff against real Yjs. Wants Node. |
| `vendor/bin/pest --testsuite=Unit` | One suite: `Unit`, `Fixture`, or `Property` |
| `vendor/bin/pest --filter='surrogate'` | Tests whose name matches |
| `vendor/bin/pest --bail` | Stop at the first failure |

---

## Architecture

### Directory structure

```
├── src/
│   ├── bootstrap.php            # Autoloaded; asserts the 64-bit requirement
│   ├── Environment.php          # The platform assumptions the library may make
│   │
│   ├── Binary/                  # Layer 1: lib0 primitives
│   │   ├── Encoder.php          # Append-only writer; every method returns $this
│   │   ├── Decoder.php          # Bounded reader; the only class facing the socket
│   │   ├── DecodeLimits.php     # Byte, count, depth, and allocation budgets
│   │   ├── SafeInteger.php      # Number.MAX_SAFE_INTEGER, and the varint byte cap
│   │   ├── AnyType.php          # The lib0 "any" tag enum, 116–127
│   │   ├── Utf16.php            # length / slice / split, in JavaScript's units
│   │   └── AnyValue/            # Values PHP has no native equivalent for
│   │       ├── Undefined.php    #   undefined, kept apart from null
│   │       ├── Bytes.php        #   Uint8Array, kept apart from string
│   │       └── BigInt.php       #   bigint, kept apart from number
│   │
│   ├── Id/                      # Layer 2a: identity and ranges
│   │   ├── Id.php               # (client, clock)
│   │   ├── ClockRange.php       # A half-open run of clocks for one client
│   │   ├── StateVector.php      # client → next expected clock
│   │   └── DeleteSet.php        # client → coalesced deleted ranges
│   │
│   ├── Wire/                    # Layer 2b: the struct model
│   │   ├── Struct.php           # id / length / write / sliceFrom / normalized
│   │   ├── Item.php             # A real struct: origins, parent, content
│   │   ├── Gc.php               # Collected content, ref 0
│   │   ├── Skip.php             # A declared hole, ref 10
│   │   ├── StructInfo.php       # The info byte's bit layout
│   │   ├── ParentReference.php  # A parent named by key, or by ID
│   │   └── Content/             # The nine content references
│   │       ├── Content.php      #   ref() / length() / write()
│   │       ├── Sliceable.php    #   Content that can be split at a clock
│   │       ├── ContentReader.php#   ref number → the right reader
│   │       ├── Deleted.php Json.php Binary.php Text.php Embed.php
│   │       └── Format.php SharedType.php AnyValues.php SubDocument.php
│   │
│   ├── Update/                  # Layer 3: the algebra
│   │   ├── Update.php           # The public surface: decode, merge, diff, contains
│   │   ├── ClientStructs.php    # One client's section; reconstructs clocks
│   │   ├── StructCursor.php     # Ordered traversal for the merge
│   │   ├── StructSink.php       # Collects merged structs back into sections
│   │   ├── UpdateMerger.php     # mergeUpdates
│   │   ├── UpdateDiffer.php     # diffUpdate
│   │   ├── UpdateValidator.php  # Structural invariants, then policy limits
│   │   └── SemanticLimits.php   # What an update is allowed to *be*
│   │
│   ├── Protocol/                # Layer 4: y-protocols
│   │   ├── Sync/
│   │   │   ├── SyncMessage.php SyncMessageType.php SyncMessageReader.php
│   │   │   ├── SyncStep1.php SyncStep2.php SyncUpdate.php
│   │   │   └── ReadOnlyPolicy.php SyncAdmission.php
│   │   └── Awareness/
│   │       ├── AwarenessUpdate.php AwarenessEntry.php
│   │       ├── AwarenessStore.php AwarenessChange.php
│   │       └── AwarenessLimits.php
│   │
│   ├── Debug/
│   │   ├── CanonicalJson.php    # Rendering that preserves what json_encode erases
│   │   └── UpdateDump.php       # A whole update as a readable tree
│   │
│   └── Exception/               # Every failure is one of these
│       ├── YjsException.php         # The marker interface; catch this to catch all
│       ├── DecodeException.php      # Anything wrong with input bytes
│       ├── UnexpectedEndOfInput.php #   truncation
│       ├── MalformedInput.php       #   bad UTF-8, unknown tag, trailing bytes
│       ├── IntegerOutOfRange.php    #   past the safe range, or negative
│       ├── LimitExceeded.php        #   a DecodeLimits budget
│       ├── InvalidUpdate.php        #   structurally impossible, or past SemanticLimits
│       ├── EncodeException.php      # A value the format cannot express
│       └── UnsupportedPlatform.php  # A 32-bit build
│
├── tests/
│   ├── Unit/                    # Behavior of one class, no fixtures
│   ├── Fixture/                 # Golden bytes, against the committed fixtures
│   ├── Property/                # Round-trip invariants and the fuzz suite
│   ├── Support/                 # Fixture loading, value comparison, generators
│   └── Pest.php                 # toBeSameValueAs / toBeBytes
│
├── fixtures/profile-1/          # Committed golden bytes, generated from lib0
│   ├── manifest.json            # The versions the bytes came from
│   ├── var-uint.json … any.json # One file per primitive group
│   ├── utf16.json               # 162 length/slice cases from JavaScript itself
│   ├── utf16-split.json         # 38 cases from the real ContentString.splice
│   ├── updates.json             # 16 documents built with the real Yjs API
│   └── protocol.json            # Sync and awareness transcripts from y-protocols
│
├── tools/oracle/                # Development only; never a runtime dependency
│   ├── package.json             # lib0, yjs, y-protocols, pinned by lockfile
│   ├── cases.mjs                # The value corpus
│   ├── spec.mjs                 # The tagged-value spec both languages read
│   ├── update-scenarios.mjs     # Documents built through the real Yjs API
│   ├── generate-fixtures.mjs    # Writes fixtures/profile-1/
│   ├── verify-php-output.mjs    # Has lib0 read what PHP wrote
│   ├── differential.mjs         # Randomized merge/diff comparison against Yjs
│   └── php-algebra.php          # The PHP side of the differential harness
│
├── compatibility/profile-1.md   # The interoperability contract
├── NOTICE.md                    # How this relates to lib0 and Yjs in copyright
├── .github/workflows/ci.yml
├── composer.json
├── phpunit.xml
└── pint.json
```

### The layers

Each layer depends on the ones below it, and nothing reaches upward or skips a
level.

```
  Protocol/          sync messages, awareness, the read-only decision
      │
  Update/            state vectors, merge, diff, contains, validate
      │
  Wire/              structs and content, the shape of a Yjs update
      │
  Id/                clients, clocks, ranges, delete sets
      │
  Binary/            varints, floats, strings, "any", UTF-16
```

**`Binary/`** implements lib0. It knows about bytes and about JavaScript's
number semantics, and it has never heard of Yjs.

**`Id/`** and **`Wire/`** hold the V1 format's vocabulary. A `Struct` can report
its length, write itself, slice itself at a clock boundary, and normalize its
info byte, which covers what merge and diff need while stopping well short of
interpreting a document.

**`Update/`** holds the algebra. `Update` gives you the public surface, and
`UpdateMerger`, `UpdateDiffer`, and `UpdateValidator` implement three of its
methods behind that surface. Each of those is long enough that reading it alone
is easier than reading it inline.

**`Protocol/`** wraps updates in y-protocols' framing. It also adds the one
decision a server can't make without looking inside an update, which is whether
an inbound message introduces state.

**`Debug/`** and **`Exception/`** cut across the stack. Nothing in the library
depends on `Debug/`.

### The V1 wire format, byte by byte

An update concatenates two things: the structs, then the delete set.

```
Update
├── varUint  sectionCount
├── section × sectionCount
│   ├── varUint  structCount
│   ├── varUint  client
│   ├── varUint  clock          ← only the FIRST clock is on the wire
│   └── struct × structCount
│       ├── uint8  info
│       │   ├── bits 0–4  content reference (0 = GC, 10 = Skip, 1–9 = Item)
│       │   ├── bit 5     hasParentSub
│       │   ├── bit 6     hasRightOrigin
│       │   └── bit 7     hasOrigin
│       ├── if GC or Skip:  varUint length
│       └── if Item:
│           ├── Id        origin        (if bit 7)
│           ├── Id        rightOrigin   (if bit 6)
│           ├── if neither origin is present:
│           │   ├── ParentReference     (a key, or an Id)
│           │   └── varString parentSub (if bit 5)
│           └── content                 (per the reference in bits 0–4)
└── DeleteSet
    ├── varUint  clientCount
    └── per client
        ├── varUint  client
        ├── varUint  rangeCount
        └── (varUint clock, varUint length) × rangeCount
```

Clocks after the first get **reconstructed by accumulation**, with each struct
starting where the previous one ended. `ClientStructs::read()` does this on the
way in, and `UpdateValidator` checks it again on the way out, because the merge
and diff paths assemble sections themselves. A bug in either would produce an
update that encodes cleanly while desynchronizing every client that applies it.

Three parts of this format cost real debugging time, and each now has a test
pinning it:

1. **The `parentSub` bit can be set with no field on the wire.** Yjs derives
   that bit from whether the field is non-null, while writing the field only
   inside the branch taken when an Item has neither origin. Reading the field
   whenever the bit is set will desynchronize the rest of the stream. The same
   reasoning explains why a plain re-encode preserves the info byte verbatim
   instead of recomputing it.
2. **A state vector describes a prefix.**
   `ClientStructs::contiguousEndClock()` stops at the first `Skip`, and it
   returns zero when a section starts somewhere other than clock 0. Claiming the
   clock past a gap would tell a peer we hold structs we don't have, after which
   it would never send them.
3. **JSON content survives untouched.** References 2, 5, and 6 carry JSON as
   text. `JSON.stringify` and PHP's `json_encode` disagree about escaping, float
   formatting, and key order, so parsing and re-encoding would change the bytes.
   The text stays exactly as it arrived.

The content references:

| Ref | Yjs class | Here | Clocks it spans |
|---:|---|---|---|
| 1 | `ContentDeleted` | `Content\Deleted` | its length |
| 2 | `ContentJSON` | `Content\Json` | element count |
| 3 | `ContentBinary` | `Content\Binary` | 1 |
| 4 | `ContentString` | `Content\Text` | UTF-16 units |
| 5 | `ContentEmbed` | `Content\Embed` | 1 |
| 6 | `ContentFormat` | `Content\Format` | 1 |
| 7 | `ContentType` | `Content\SharedType` | 1 |
| 8 | `ContentAny` | `Content\AnyValues` | element count |
| 9 | `ContentDoc` | `Content\SubDocument` | 1 |

References 1, 2, 4, and 8 implement `Sliceable`, so they can be split at a clock
boundary. That capability is what lets `diff()` send half of a struct.

### Data flow through a sync handshake

```
     client                                            server (this library)
       │                                                        │
       │──── SyncStep1(stateVector) ───────────────────────────▶ │
       │                                     SyncMessageReader::decodeAll()
       │                                     $message->answer($resident)
       │                                       └─ $resident->diff($theirs)
       │◀─── SyncStep2(update) ─────────────────────────────────│
       │                                                        │
       │──── SyncStep2(their update) ─────────────────────────▶ │
       │                                     ReadOnlyPolicy::admit()
       │                                       └─ $resident->contains($theirs)
       │                                     $resident->merge($theirs)
       │                                                        │
       │◀═══ Update(broadcast) ════════════ to every other peer │
       │                                                        │
       │──── AwarenessUpdate ─────────────────────────────────▶ │
       │                                     $presence->apply($update, now: …)
       │◀═══ AwarenessUpdate(fan-out) ═════════════════════════ │
```

`$resident` holds whatever the server keeps for the document, usually a single
merged `Update` loaded from storage and held in memory for the room's lifetime.
Where that lives is a decision this library leaves to you.

### Where the bytes are decided

Four things determine whether the output matches Yjs's, and each is worth
knowing before you change anything.

**Section order survives.** Yjs writes clients in descending order, so an update
that came from Yjs re-encodes byte for byte. Keeping the order also leaves a
handmade update intact when it repeats or reorders clients.

**The delete set gets written in descending client order**, matching Yjs.

**A relay preserves the info byte, while a merge normalizes it.** Yjs derives an
Item's info byte from its fields on every write, so a rewrite drops a
`parentSub` bit whose field never appeared on the wire. Plain decode and encode
has to preserve that bit, and merge and diff have to drop it. Tests cover both.

**Adjacent Items never coalesce.** `Item.mergeWith` requires
`this.right === right`, and that link exists only inside a materialized
document, so Yjs leaves adjacent Items separate as well. GC and Skip do
coalesce.

---

## API reference

Everything below lives under `Hemp\Yjs\`.

### `Binary\Encoder`

An append-only writer whose every write returns `$this`, so calls chain.

| Method | Notes |
|---|---|
| `writeUint8/16/32(int)` | Little-endian, as lib0 |
| `writeUint32BigEndian(int)` | The one big-endian primitive lib0 has |
| `writeVarUint(int\|float)` | Rejects negatives and anything past 2<sup>53</sup> − 1 |
| `writeVarInt(int\|float)` | Includes lib0's negative-zero encoding |
| `writeVarBytes(string)` | Length-prefixed |
| `writeVarString(string)` | Length-prefixed UTF-8; rejects invalid input |
| `writeFloat32/64(float)`, `writeBigInt64(int)` | |
| `writeAny(mixed)` | Self-describing; see the type table below |
| `writeBytes(string)` | Raw, no prefix |
| `toBytes(): string`, `length(): int` | |

`writeAny()` throws `EncodeException` when handed a value the format can't
express. PHP lists encode as arrays (tag 117), while associative arrays and
`stdClass` encode as objects (tag 118).

### `Binary\Decoder`

```php
new Decoder(string $bytes, DecodeLimits $limits = new DecodeLimits)
```

Every `read*` mirrors a `write*`. Three additions are worth knowing about:

| Method | Notes |
|---|---|
| `assertAtEnd(): void` | Throws `MalformedInput` when bytes remain |
| `readVarIntPreservingSign(): int\|float` | Returns `-0.0` for the negative-zero encoding |
| `readCount(int $minimumBytesPerElement): int` | A declared count, rejected when the remaining bytes couldn't hold it |
| `position()`, `remaining()`, `hasMore()` | |

### `Binary\Utf16`

| Method | Behavior |
|---|---|
| `length(string): int` | JavaScript's `String.length` |
| `slice(string, int $offset, ?int $length): string` | `String.prototype.slice`; **throws** on a surrogate boundary |
| `split(string, int $offset): array{string, string}` | Yjs's `ContentString.splice`; **U+FFFD on both sides** at a surrogate boundary |
| `splitsSurrogatePair(string, int): bool` | Whether a split will do that damage |
| `Utf16::REPLACEMENT` | `"\u{FFFD}"` |

### `Binary\SafeInteger`

| Member | Value |
|---|---|
| `MAX` | `9007199254740991` |
| `MIN` | `-9007199254740991` |
| `MAX_VARINT_BYTES` | `8` |
| `isSafe()`, `assert()`, `assertNonNegative()` | |

### `Id\StateVector`

Immutable, so every mutator hands back a new instance.

| Method | Notes |
|---|---|
| `empty()`, `fromArray(array $clocks)` | |
| `read(Decoder)`, `decode(string)`, `write(Encoder)`, `encode()` | |
| `clockFor(int $client): int` | `0` for a client it doesn't know |
| `knows(int): bool`, `clientCount()`, `isEmpty()`, `toArray()` | |
| `with(int, int)` | Set a clock outright |
| `raisedTo(int, int)` | Set it only when higher |
| `merge(self)` | Pointwise maximum |
| `isCoveredBy(self)`, `equals(self)` | |

### `Id\DeleteSet`

| Method | Notes |
|---|---|
| `empty()`, `fromArray(array $ranges)` | |
| `read`, `decode`, `write`, `encode` | Written in descending client order |
| `rangesFor(int)`, `clients()`, `toArray()`, `isEmpty()` | |
| `deletes(int $client, int $clock): bool` | |
| `normalized()` | Sorted, coalesced, empties dropped |
| `mergedFrom(self ...)`, `union(self)` | |
| `isSubsetOf(self)`, `equals(self)`, `deletedClockCount()` | |

A delete set read off the wire keeps whatever it arrived with, including
overlapping or empty ranges. Reach for `normalized()` when you want to know what
the set means.

### `Update\Update`

The main surface.

| Method | Notes |
|---|---|
| `decode(string, ?DecodeLimits): self` | Decodes, then asserts the input ended |
| `read(Decoder): self`, `write(Encoder)`, `encode(): string` | |
| `of(array $sections, DeleteSet)`, `empty()` | |
| `validate(?SemanticLimits): self` | Returns `$this`; throws `InvalidUpdate` |
| `stateVector(): StateVector` | = `encodeStateVectorFromUpdate` |
| `merge(self ...$others): self` | = `mergeUpdates` |
| `mergeAll(self ...$updates): self` | Static; accepts zero arguments |
| `diff(StateVector $have): self` | = `diffUpdate` |
| `contains(self $other): bool` | Whether `$other` adds anything new |
| `coverage(): array<int, list<ClockRange>>` | Clock runs actually carried, per client |
| `structs()`, `structCount()`, `clients()`, `isEmpty()` | |
| `->sections`, `->deleteSet` | Public readonly |

### `Protocol\Sync`

| Class | Purpose |
|---|---|
| `SyncMessage` | Interface: `type()`, `write()`, `encode()` |
| `SyncMessageType` | `Step1 = 0`, `Step2 = 1`, `Update = 2` |
| `SyncMessageReader::read/decode/decodeAll` | `decodeAll` handles several messages in one frame |
| `SyncStep1` | Carries a `StateVector`; `answer(Update $resident): SyncStep2` |
| `SyncStep2` / `SyncUpdate` | Carry raw update bytes; `update(?DecodeLimits): Update` decodes lazily |
| `ReadOnlyPolicy::admit(SyncMessage, Update $resident, ?DecodeLimits): SyncAdmission` | |
| `ReadOnlyPolicy::acknowledgesPositively(SyncAdmission): bool` | |
| `SyncAdmission` | `Allowed`, `Redundant`, `IntroducesState` |

`SyncStep2` and `SyncUpdate` hold bytes instead of a decoded `Update` on
purpose. A relay that only forwards shouldn't pay to decode, and a policy check
that does need to decode should say so at the call site.

The read-only decision table:

| Message | Verdict | Acknowledgement |
|---|---|---|
| SyncStep1 | `Allowed` | positive |
| SyncStep2 or Update, empty | `Redundant` | positive |
| SyncStep2 or Update the server already has | `Redundant` | positive |
| SyncStep2 or Update carrying anything new | `IntroducesState` | negative |

A read-only client still completes a full sync handshake, and completing one
means answering SyncStep1 with SyncStep2. Such a peer will therefore send
updates, and refusing all of them would break an exchange it's entitled to. The
line falls at whether an update would change anything.

### `Protocol\Awareness`

| Class | Purpose |
|---|---|
| `AwarenessEntry` | `(client, clock, ?string $state)`; a `null` state marks a removal |
| `AwarenessUpdate` | `decode`, `encode`, `entries`, `clients()`, `isEmpty()` |
| `AwarenessStore` | The server's view of who is present |
| `AwarenessChange` | What one operation changed: `added`, `updated`, `removed` |
| `AwarenessLimits` | Clients per update, state bytes, tracked clients |

`AwarenessStore`:

| Method | Notes |
|---|---|
| `apply(AwarenessUpdate, int $now): AwarenessChange` | Higher clock wins; a removal also wins at the same clock |
| `expire(int $now, int $timeoutMs = 30_000): AwarenessChange` | Caller supplies the clock |
| `updateFor(?array $clients = null): AwarenessUpdate` | Everything, or a subset |
| `removalFor(array $clients)`, `forget(array $clients)` | |
| `stateFor(int)`, `clockFor(int)`, `knows(int)`, `presentClients()`, `count()` | |

State stays as **JSON text** and never gets parsed. The application defines what
goes in there, so this library has no reason to look inside, and parsing then
re-serializing would change the bytes anyway. A removal arrives as the JSON
document `null`, compared after trimming JSON whitespace so that ` null ` gets
recognized too.

Expiry runs off a caller-supplied clock rather than reading the system one,
which lets a server drive it from its own loop while a test drives it without
sleeping.

### `Exception`

```
Throwable
└── YjsException (interface: catch this to catch everything)
    ├── DecodeException          extends RuntimeException
    │   ├── UnexpectedEndOfInput     truncated
    │   ├── MalformedInput           bad UTF-8, unknown tag, trailing bytes
    │   ├── IntegerOutOfRange        past the safe range, or negative
    │   ├── LimitExceeded            a DecodeLimits budget
    │   └── InvalidUpdate            structurally impossible, or past SemanticLimits
    ├── EncodeException          extends InvalidArgumentException
    └── UnsupportedPlatform      extends RuntimeException, a 32-bit build
```

Catch `DecodeException` for anything a peer could cause. `EncodeException` and
`UnsupportedPlatform` both point at bugs on your side of the wire.

---

## Limits

Three independent budgets apply at three different levels. All of them are plain
value objects with public properties, so a server can build its own.

### `Binary\DecodeLimits`, covering how many bytes it took to say it

| Field | Default | `trusted()` | `strict()` |
|---|---:|---:|---:|
| `maxByteLength` | 16 MiB | 512 MiB | 4 KiB |
| `maxElementCount` | 1,000,000 | 64,000,000 | 256 |
| `maxDepth` | 64 | 1,024 | 8 |
| `maxTotalAllocation` | 64 MiB | 1 GiB | 64 KiB |

Use `trusted()` for input that's already been vouched for, like your own
encoder's output or a blob from your own database. Those limits stay finite,
since a known source doesn't mean a corrupt row should exhaust the process.
`strict()` exists for tests that check bounded failure.

### `Update\SemanticLimits`, covering what the update is allowed to be

| Field | Default | `strict()` |
|---|---:|---:|
| `maxClients` | 10,000 | 2 |
| `maxStructs` | 500,000 | 8 |
| `maxStructLength` | 1,000,000 | 16 |
| `maxDeleteRanges` | 500,000 | 4 |

An update can be well formed, decode inside budget, and still be one no server
should accept. A hundred thousand clients would qualify, as would a single
struct spanning a billion clocks, or a delete set with more ranges than the
document has ever had characters. The defaults sit generously above what any
real document reaches, because a limit that trips legitimate traffic causes more
trouble than the attack it was meant to stop.

### `Protocol\Awareness\AwarenessLimits`, covering presence

| Field | Default | `strict()` |
|---|---:|---:|
| `maxClientsPerUpdate` | 512 | 4 |
| `maxStateBytes` | 64 KiB | 64 |
| `maxTrackedClients` | 4,096 | 8 |
| `OUTDATED_TIMEOUT_MS` | 30,000 | n/a |

Awareness gives an attacker the easiest surface in the protocol. It's ephemeral,
unversioned, and broadcast to every peer, with no delete set bounding it and no
persistence making it someone else's problem.

```php
use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Update\SemanticLimits;
use Hemp\Yjs\Update\Update;

// From a socket.
$update = Update::decode($frame, new DecodeLimits(maxByteLength: 4 * 1024 * 1024))
    ->validate(new SemanticLimits(maxClients: 200));

// From our own storage.
$resident = Update::decode($blob, DecodeLimits::trusted());
```

---

## Compatibility

[`compatibility/profile-1.md`](compatibility/profile-1.md) records the exact
software this library interoperates with, along with every place it deliberately
does something different. A profile promises observable behavior, so when one of
the pinned packages moves, that becomes a new profile carrying its own manifest
and upgrade note.

| Package | Version |
|---|---|
| `lib0` | 0.2.117 |
| `yjs` | 13.6.29 |
| `y-protocols` | 1.0.7 |

### Deliberate differences from lib0

All of these narrow what gets accepted, and no input a conforming Yjs client can
produce runs into any of them.

- **Variable-length integers stay inside the safe range.** lib0 checks its
  running magnitude only while a varint continues, so a final byte that pushes
  the value past 2<sup>53</sup> − 1 comes back to the caller anyway. lib0 also
  lets one varint occupy as many bytes as it likes. This library rejects both
  cases. Every varUint on the Yjs wire holds a client ID, clock, length, or
  count, and once any of those stops being exactly representable it has stopped
  meaning anything.
- **Invalid UTF-8 gets rejected.** lib0 decodes through `TextDecoder`, which
  substitutes U+FFFD. Refusing costs nothing here, since every real client
  encodes through `TextEncoder`, which can't emit invalid UTF-8. Bytes that fail
  this check are corrupt or hostile, and repairing them silently would write the
  repaired text back out to every other client. Reading the same bytes as an
  opaque byte array still works, because the check applies only where the format
  promises text.
- **Declared sizes get checked before allocation.** lib0 has no equivalent,
  since lib0 doesn't face the socket.
- **Decode failures carry types.** The fuzz suite checks this.

### How JavaScript values are represented

| lib0 `any` tag | PHP value |
|---|---|
| `undefined` (127) | `Binary\AnyValue\Undefined::instance()` |
| `null` (126) | `null` |
| integer (125) | `int`, or `-0.0` for the negative-zero encoding |
| float32 (124), float64 (123) | `float` |
| bigint (122) | `Binary\AnyValue\BigInt` |
| boolean (121, 120) | `bool` |
| string (119) | `string`, valid UTF-8 |
| object (118) | `stdClass` |
| array (117) | PHP list |
| `Uint8Array` (116) | `Binary\AnyValue\Bytes` |

Three consequences are worth stating plainly.

**Objects decode to `stdClass`.** PHP can't tell an empty list from an empty
map, and the wire gives those separate tags, so an object that arrived as `{}`
has to come back as something that won't re-encode as `[]`.

**A whole `float` encodes as an integer and decodes as an `int`.** lib0 has one
tag covering both, because JavaScript has one type covering both. What holds is
that a second round trip changes nothing.

**An `int` above 2<sup>31</sup> − 1 becomes a float.** `writeAny` tags values as
integers only up to that point, and past it lib0 falls through to float32 or
float64. JavaScript loses the same precision, where you just can't observe it.
Reach for `BigInt` when the exact 64-bit value matters.

---

## Testing

### The three suites

| Suite | What it proves | Size |
|---|---|---|
| `tests/Unit` | Behavior of one class, in isolation, no fixtures | 367 tests |
| `tests/Fixture` | The bytes match lib0's and Yjs's, case by case | 1,235 tests |
| `tests/Property` | Round-trip invariants and bounded failure under fuzzing | 67 tests, ~63,000 assertions |

```bash
composer test                              # format check + everything
vendor/bin/pest                            # everything
vendor/bin/pest --testsuite=Fixture        # one suite
vendor/bin/pest --filter='surrogate'       # by name
vendor/bin/pest --bail                     # stop at the first failure
vendor/bin/pest tests/Unit/EncoderTest.php # one file
```

`phpunit.xml` turns on `failOnWarning`, `failOnNotice`, and
`failOnDeprecation`, so a deprecation fails the build here.

### Two custom expectations

[`tests/Pest.php`](tests/Pest.php) defines these:

```php
expect($decoded)->toBeSameValueAs($expected);  // Object.is semantics
expect($bytes)->toBeBytes($expected);          // diffs in hex, not raw binary
```

`toBeSameValueAs` exists because PHP's `==` once said `0 == "a"` was true, and
because `===` can't tell `-0.0` from `0.0`. Both of those distinctions decide
bytes here. `toBeBytes` exists so that a failure reads as
`7c4f000000 !== 7b41e0…` instead of dumping raw binary into your terminal.

### What the fixtures contain

`fixtures/profile-1/` gets generated by running the real lib0, Yjs, and
y-protocols, then committed so the PHP suite can run without Node.

| File | Cases | Source |
|---|---:|---|
| `var-uint.json` | 31 | lib0 `writeVarUint` |
| `var-int.json` | 62 | lib0 `writeVarInt`, including negative zero |
| `uint8/16/32.json`, `uint32-big-endian.json` | 28 | lib0 fixed-width writers |
| `float32.json`, `float64.json`, `big-int64.json` | 29 | lib0 numeric writers |
| `var-bytes.json`, `var-string.json` | 22 | lib0 length-prefixed writers |
| `any.json` | 37 | lib0 `writeAny`, every tag 116–127 |
| `utf16.json` | 162 | JavaScript's own `String.length` and `slice` |
| `utf16-split.json` | 38 | The real `ContentString.splice` |
| `updates.json` | 16 | Documents built through the real Yjs API |
| `protocol.json` | sync + awareness | Transcripts from the real y-protocols |
| `manifest.json` | n/a | The versions the bytes came from |

The update fixtures record `mergedByYjs`, which holds Yjs's update-level output
from `mergeUpdates`, and every fixture asserts against it. Yjs offers two round
trips that behave differently. Going through a live `Doc` with `applyUpdate` and
then `encodeStateAsUpdate` rebuilds content from a materialized document, while
going through the update itself skips that. This library implements the second
kind, and asserting only against our own input would let us imitate the wrong
one without noticing.

---

## The oracle

Fixtures can't check merge and diff. Encoding has one right answer, so bytes
settle it. Merging has many, so an implementation can be wrong in a way that
still round-trips, still decodes, and surfaces weeks later as two clients quietly
disagreeing about the text.

Three separate oracle jobs therefore check different directions.

### 1. Regenerate the fixtures

```bash
npm --prefix tools/oracle ci
composer fixtures
git diff --stat -- fixtures/
```

Generation runs deterministically and carries no timestamp, so a clean
regeneration leaves an **empty diff**. Anything else means the committed bytes
and lib0 have come apart, and that change belongs in
`compatibility/profile-1.md` before it gets committed. Treat any diff here as a
real change in behavior upstream.

### 2. Prove lib0 can read what PHP writes

```bash
composer fixtures:verify
```

Comparing bytes alone would miss an error that PHP's own encoder and decoder
share. This script encodes every fixture case with PHP, decodes the result with
the real lib0 build, and compares the *value* that comes back.

### 3. Differential-test the algebra

```bash
composer oracle                                    # the default seeds
node tools/oracle/differential.mjs --rounds 40     # more operations per seed
node tools/oracle/differential.mjs --seeds 1337    # reproduce one failure
node tools/oracle/differential.mjs --verbose       # show each operation
```

This builds randomized three-client histories with the real Yjs, has PHP merge
and diff them through `tools/oracle/php-algebra.php`, applies the results to
real Yjs documents, and compares against the documents Yjs produced for itself.

Every scenario comes from a seed, so a failure reproduces exactly. **Any seed
that fails once belongs in `DEFAULT_SEEDS` permanently.**

Operations get batched into one PHP process, since a randomized run issues
thousands of them and paying PHP's startup cost per operation would shrink the
corpus enough to miss things.

### What CI runs

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) defines two jobs, and
splitting them apart carries the point.

**`php`** runs a matrix over PHP 8.4 and 8.5. It confirms a 64-bit runtime,
checks formatting, runs the suite, and then asserts that **no `node_modules`
directory exists**. Should that job ever want Node, the package would have lost
the property it exists to have.

**`oracle`** installs the pinned JavaScript, regenerates the fixtures, fails on
any diff, re-runs the PHP suite against the regenerated bytes, verifies the
reverse direction, and runs the differential oracle for 40 rounds.

---

## Running this in production

This ships as a library, so nothing here gets deployed on its own. A server
built on it still has choices to make, and these are them.

### Installing it

```bash
composer require hemp/yjs
composer install --no-dev --optimize-autoloader
```

Nothing else follows. Composer pulls in no other packages here, and there's
nothing to compile or supervise beside the PHP process itself. The one extension
that matters is `mbstring`, which a stock build already has. At runtime the
package ignores environment variables and configuration files entirely, and it
goes near the filesystem, the network, and the clock not at all.

### The one hard requirement

You need a 64-bit PHP build. `src/bootstrap.php` gets autoloaded and asserts it,
so a 32-bit host fails at load time with `UnsupportedPlatform` instead of
serving subtly wrong clocks. Put the same check in your smoke test when you
build your own container:

```bash
php -r 'exit(PHP_INT_SIZE >= 8 ? 0 : 1);'
```

### Choosing limits

Set these per trust boundary:

| Input | Limits |
|---|---|
| A frame off a WebSocket | `new DecodeLimits(...)` sized to your largest legitimate document, then `validate(new SemanticLimits(...))` |
| A blob from your own storage | `DecodeLimits::trusted()` |
| Awareness from a peer | `new AwarenessLimits(...)` sized to your presence payload |

Start from the defaults, then tighten. Measure your real documents before you
choose anything below the defaults, because a limit that trips legitimate
traffic will cost you more than it saves.

### Handling failure

Every failure a peer can cause arrives as a `DecodeException`. Nothing gets
partially applied, since decoding builds a value and either returns it or
throws.

```php
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\LimitExceeded;

try {
    $update = Update::decode($frame, $limits)->validate($semantics);
} catch (LimitExceeded $failure) {
    // A budget. Probably worth an alert if it happens often.
    $connection->close(1009, 'message too big');
} catch (DecodeException $failure) {
    // Malformed. Close and move on; do not retry.
    $connection->close(1002, 'protocol error');
}
```

`EncodeException` and `UnsupportedPlatform` point at bugs on your side rather
than the peer's, so let those reach your error handler.

### Memory and time

Decoding an update materializes the whole thing, every struct and every content
payload. `maxTotalAllocation` caps one pass, which makes it the number to reason
about when you size a worker.

Merging *n* updates takes a single ordered pass over all of them, so the cost
grows with total struct count rather than quadratically with how many updates
you have. One `Update::mergeAll(...$batch)` beats repeated pairwise merges.

`Update::contains()` builds a coalesced coverage map first, which makes a
read-only admission check cheap to repeat against a large resident document.

### Persistence

A document's state is one merged `Update`, so store `$update->encode()` as a
blob and reload it with `DecodeLimits::trusted()`. Nothing here carries a
schema, a migration path, or a format version beyond Yjs's own, so a blob
written today will decode with any future version of this package that still
implements Profile 1.

### Awareness

Drive `expire()` from your own loop rather than from a request:

```php
// Every few seconds, per room.
$gone = $presence->expire(now: (int) (microtime(true) * 1000));

if (! $gone->isEmpty()) {
    $broadcast($presence->removalFor($gone->removed)->encode());
}
```

Awareness carries no reliable disconnect signal, since a dropped connection
produces nothing at all. Presence has to get forgotten on a timer, or it will
pile up for the life of the process.

### What you still have to build

The Hocuspocus provider frames (document address, Auth, Stateless, Close,
SyncStatus), the WebSocket transport, per-room state, authentication,
persistence, and fan-out across nodes all live in the package that runs the
collaboration server, which consumes this one. What you get here is everything
those frames wrap, plus the decision they need, which `ReadOnlyPolicy` makes.

---

## Troubleshooting

### `UnsupportedPlatform: PHP integers are N bytes`

You're on a 32-bit PHP build. No flag will bypass this, because Yjs clocks run
past 2<sup>31</sup> and would quietly become floats. Install a 64-bit build.

```bash
php -r 'echo PHP_INT_SIZE * 8, "-bit", PHP_EOL;'
```

### `MalformedInput: trailing bytes`

`Update::decode()` and `assertAtEnd()` insist that the input ends where the
format says it should. Usually one of these explains it:

- The frame holds **several** messages, in which case reach for
  `SyncMessageReader::decodeAll()`.
- A framing header is still attached, such as a Hocuspocus document address.
  Strip the provider's envelope first, since this library reads Yjs's payload.
- The bytes went through something that appended a newline.

### `MalformedInput: invalid UTF-8`

This rejection happens on purpose. See [Compatibility](#compatibility). A real
Yjs client can't produce these bytes, so they're corrupt or hostile. When you
meant to carry opaque binary, encode it as a byte array with `writeVarBytes` or
`Bytes`, since the check applies only where the format promises text.

### `IntegerOutOfRange`

Something arrived as a varint past 2<sup>53</sup> − 1, wider than 8 bytes, or
negative where the format requires non-negative. lib0 would have handed it back
to you. A clock that's no longer exactly representable will disagree with
JavaScript's, so this library refuses.

### `LimitExceeded`

A `DecodeLimits` budget stopped the read, and the message names which one along
with what got declared. Either your legitimate documents run larger than you
sized for, in which case raise that specific limit, or something is claiming a
size it can't back up.

### `InvalidUpdate: non-contiguous`

A section's structs don't run end to end. Clocks after the first come from
accumulation, so any gap has to appear as an explicit `Skip`. On an update read
from the wire this means corrupt input, and on an update you built yourself it
means a bug in the building.

### `InvalidUpdate: duplicate client`

Two sections claim the same client, or a struct's client disagrees with its
section's. Note that a handmade update may legitimately repeat a client and
`Update` will preserve that on re-encode, so `validate()` is where it gets
rejected.

### `Utf16::slice(): boundary falls inside a surrogate pair`

You reached for `slice()` where `split()` was wanted. `slice()` gives you the
strict general primitive, and it refuses because JavaScript would hand back a
lone surrogate, which UTF-8 has no encoding for. When you're splitting content
at a clock boundary, `split()` is the operation, and it does what Yjs does.

### Merge produced different bytes than Yjs

Check which Yjs round trip you compared against. `applyUpdate` plus
`encodeStateAsUpdate` rebuilds content from a materialized document, while
`mergeUpdates` skips that step. This library implements the second kind, which
is why the fixtures record `mergedByYjs`.

Also worth ruling out: merging differs from sequential application. Integrating
one update after another splits items where merging leaves them whole, and a
split inside a surrogate pair costs the character. Yjs behaves the same way, so
the oracle counts these cases and reports the count instead of failing.

### The fixture regeneration produced a diff

Hold off on committing it until you know what moved. Generation runs
deterministically without timestamps, so the diff points at a real behavioral
change in lib0, Yjs, or y-protocols. Review it byte by byte, record what changed
in `compatibility/profile-1.md`, and give it a new profile number when the wire
format or a documented behavior has moved.

### `composer fixtures` fails with a missing module

The oracle's dependencies aren't installed. They live in `tools/oracle/` rather
than the repository root:

```bash
npm --prefix tools/oracle ci
```

### Tests fail with "Missing fixture file"

`fixtures/profile-1/` gets committed, so this usually points at a partial
checkout or an interrupted regeneration. Restore it:

```bash
git checkout -- fixtures/
```

### `pint --test` fails in CI but the code looks fine

Formatting has drifted. Fix it in place and commit the result:

```bash
composer lint
```

---

## Roadmap

The critical path runs 1 → 2 → 3 → 4. Everything after that belongs to the
package running the collaboration server, which consumes this one.

| Phase | Scope | Status |
|---|---|---|
| 0 | Charter, scaffold, pinned oracle, committed fixtures | Done |
| 1 | Bounded lib0 binary foundation | Done |
| 2 | Yjs V1 wire model: IDs, state vectors, delete sets, structs, content | Done |
| 3 | Binary update algebra: state vectors, merge, diff, validation | Done |
| 4 | y-protocols sync and awareness codecs | Done |

Phases 5 through 9 cover the ReactPHP server, the Hocuspocus provider frames,
the Laravel package, the security and performance gate, scaling across nodes,
and provider v4. Those belong to the repository that consumes this one.

Three things stay out of scope permanently, per the master plan: the V2 update
codec, a materialized `Y.Doc`, and PHP APIs for the shared types.

---

## Contributing

The bar for a change here sits somewhere unusual, because the package's whole
claim is that it agrees with software written in another language.

1. **A behavioral change wants an oracle.** When you believe the library
   disagrees with Yjs, add the case to `tools/oracle/cases.mjs` or
   `update-scenarios.mjs` and let the real implementation settle it.
2. **Leave `fixtures/` alone.** Those files are generated output, so change the
   generator, regenerate, and review the diff.
3. **Any fixture diff is a finding.** When regeneration moves bytes, document
   what moved in `compatibility/profile-1.md` before committing.
4. **Don't port upstream source.** Read the published behavior and implement it.
   [NOTICE.md](NOTICE.md) covers this: comments may name a lib0 or Yjs function
   to say which behavior is being matched, and may quote a short constant or
   byte layout, though they mustn't reproduce a function body. No derived files
   exist right now, and keeping that true is worth some effort.
5. **Keep Node out of the shipped path.** CI checks this, and if the PHP job
   ever wants JavaScript then the package has lost the property it exists to
   have.
6. **A seed that fails once stays forever.** Add it to `DEFAULT_SEEDS` in
   `differential.mjs` rather than fixing the bug and moving on.

Before you open a pull request:

```bash
composer test
npm --prefix tools/oracle ci
composer fixtures && git diff --exit-code -- fixtures/
composer fixtures:verify
composer oracle
```

---

## License

MIT. See [LICENSE](LICENSE), and [NOTICE.md](NOTICE.md) for how this package
relates to lib0 and Yjs in copyright terms.
