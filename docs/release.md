# Release process

`laranail/db-tools` is released **tag-driven**: pushing a `vX.Y.Z` tag runs the release workflow, which
publishes the GitHub Release with the CHANGELOG section as its body.

`laranail/*` packages resolve through **git VCS repositories rather than Packagist**, so the tag is the
distribution mechanism. Consumers pick it up on their next `composer update`.

## Versioning & stability

[Semantic Versioning](https://semver.org). Unlike most of the family this package carries real version
history rather than a single moving tag — it is currently at `v0.7.0`.

**What SemVer covers (the public API):**

- The model concerns — `HasUuidsOrIntegerIds`, `HasArchiver`, `HasMergedFillable`, `HasMergedHidden`,
  `HasMergedCasts`, `HasDefaultAttributes`, `HasExtendedModel` — and `BaseModel`.
- `Casts\CastMoney`, including its `:@currency` parameter form.
- The registered schema macros and `Schema\BlueprintMacros`.
- `Services\CleanDatabaseService` and its contract, `Backup\SqlFileRestorer`, `Support\ConnectionContext`.
- The `laranail::db-tools.*` commands and the `config/db-tools.php` key shapes.

**What is NOT covered:**

- `Services\*` constructor signatures and anything marked `@internal`.

### The three things to weigh on every release

**A change to `CastMoney`'s stored representation is a data migration, not a release.** The cast reads
and writes a column that already holds rows. Changing whether a bare number means major or minor
units, or what `serialize()` emits, alters what every existing row means — and nothing throws. Treat
any such change as a major with an explicit `UPGRADING.md` section describing the backfill.

**`id_type` decides primary keys.** `getTypeOfId()` falls back to `BIGINT`, so a change to how that key
is read or named makes models silently issue integer primary keys against UUID columns. The migration
runs, nothing errors, and the first insert finds out. Any change here needs the doctor path exercised
against a real UUID schema.

**Protected-table defaults only ever widen.** `clean.protected_tables` exists so `doClean()` cannot
truncate `migrations`. Removing an entry is a breaking change even though no signature moved.

## Cutting a release

1. Land everything on `main` with `composer lint` (pint + phpstan + rector) and `composer test` green.
2. Add the `## [X.Y.Z]` block to `CHANGELOG.md` (Keep a Changelog), plus an `UPGRADING.md` section for
   anything breaking.
3. Commit, push, wait for CI green.
4. Tag; the release body is the CHANGELOG block, never a bare stub:

   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   gh release create vX.Y.Z --title "vX.Y.Z" \
     --notes-file <(awk '/^## \[X.Y.Z\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md) --generate-notes
   ```

## The two gates that are not optional

`tests/Unit/Architecture/FacadeSeamTest.php` asserts **exact equality against an empty allowlist** for
`DB::` / `Schema::` use and `database.default` / `database.connections` literals anywhere in `src/`.
Everything routes through `Support\ConnectionContext`. Adding an allowlist entry fails the test too —
that is deliberate, so the seam cannot be eroded one exception at a time.

`tests/Unit/Architecture/DocumentedExamplesTest.php` loads every `class X extends *Model` snippet in
the docs in a subprocess. A doc example that stopped compiling fails the build.

## Consumers to reconcile after a breaking release

`laranail/{captcha,toolkit,installer,impersonator,db-console,ai-compliance}` and the vendor-preset
packages all pin this one. Bump their constraints and run their suites before tagging them.

---

[← Docs index](../README.md#documentation)
