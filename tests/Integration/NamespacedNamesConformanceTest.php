<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Simtabi\Laranail\DbTools\Console\Concerns\SupportsNamespacedNames as NamespacedNames;

/**
 * Conformance for the `laranail::<slug>.<command>` naming trait.
 *
 * The family carries several copies of this trait, because three packages cannot take a dependency
 * on `laranail/console` for stated reasons -- `laranail/package-tools` must stay free of any
 * `laranail/*` requirement, `laranail/db-tools` documents an independence invariant, and
 * `laranail/enumerator` targets PHP ^8.3 while console targets ^8.4.1.
 *
 * Copies are tolerable. Copies that quietly stop agreeing are not: one of them once read an
 * undeclared `$commandAliases` and fataled at boot for any command that used it without declaring
 * the property. This file is the same in every package that carries a copy, so a divergence fails
 * somewhere instead of shipping.
 */
function conformanceCommand(): SymfonyCommand
{
    return new class extends SymfonyCommand
    {
        use NamespacedNames;
    };
}

it('accepts a name Symfony validateName() would reject', function (): void {
    // ^[^:]++(:[^:]++)*$ rejects the empty segment in `::`, which is the entire reason the trait
    // exists. Dispatch still works: Symfony matches an exact name before splitting on `:`.
    expect(conformanceCommand()->setName('laranail::atlas.doctor')->getName())
        ->toBe('laranail::atlas.doctor');
});

it('accepts namespaced aliases', function (): void {
    expect(conformanceCommand()->setAliases(['laranail::atlas.dr'])->getAliases())
        ->toBe(['laranail::atlas.dr']);
});

it('takes aliases from any iterable, not just an array', function (): void {
    expect(conformanceCommand()->setAliases(new ArrayIterator(['laranail::atlas.x']))->getAliases())
        ->toBe(['laranail::atlas.x']);
});

it('returns itself so the calls chain', function (): void {
    $command = conformanceCommand();

    expect($command->setName('laranail::atlas.a'))->toBe($command)
        ->and($command->setAliases([]))->toBe($command);
});

it('does not fatal when the command declares no alias list', function (): void {
    // The bug this file exists for. A copy that reads $commandAliases without declaring it throws
    // on every command that does not declare the property -- at boot, for the whole application.
    expect(fn (): string => (string) conformanceCommand()->setName('laranail::atlas.b')->getName())
        ->not->toThrow(Throwable::class);
});

it('still rejects a plainly invalid name', function (): void {
    // The trait bypasses validateName() deliberately, but an empty name is not a namespaced name --
    // it is Symfony's own "no name" state, and getName() should report it as such.
    expect(conformanceCommand()->setName('')->getName())->toBe('');
});
