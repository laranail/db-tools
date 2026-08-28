<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use RuntimeException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\ManagesTransactions;

final class ManagesTransactionsRow extends Model
{
    public $timestamps = false;

    protected $table = 'manages_transactions_rows';

    protected $guarded = [];
}

final class ManagesTransactionsTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('manages_transactions_rows', function ($t): void {
            $t->id();
            $t->string('name')->nullable();
        });

        $this->subject = new class
        {
            use ManagesTransactions {
                transaction as public;
                transactionOrFail as public;
                inTransaction as public;
                getTransactionLevel as public;
            }
        };
    }

    public function test_transaction_commits_and_persists_rows(): void
    {
        $result = $this->subject->transaction(function (): string {
            ManagesTransactionsRow::create(['name' => 'committed']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, ManagesTransactionsRow::count());
    }

    public function test_transaction_rolls_back_when_callback_throws(): void
    {
        ManagesTransactionsRow::create(['name' => 'pre-existing']);

        try {
            $this->subject->transaction(function (): never {
                ManagesTransactionsRow::create(['name' => 'should-be-rolled-back']);

                throw new RuntimeException('boom');
            });

            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // Only the row created outside the transaction survives.
        self::assertSame(1, ManagesTransactionsRow::count());
        self::assertSame('pre-existing', ManagesTransactionsRow::sole()->name);
    }

    public function test_transaction_or_fail_commits_and_persists_rows(): void
    {
        $result = $this->subject->transactionOrFail(function (): string {
            ManagesTransactionsRow::create(['name' => 'committed']);

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, ManagesTransactionsRow::count());
    }

    public function test_transaction_or_fail_rolls_back_and_rethrows(): void
    {
        try {
            $this->subject->transactionOrFail(function (): never {
                ManagesTransactionsRow::create(['name' => 'gone']);

                throw new RuntimeException('explode');
            });

            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('explode', $e->getMessage());
        }

        self::assertSame(0, ManagesTransactionsRow::count());
        // The rollback cleared the open transaction.
        self::assertSame(0, $this->subject->getTransactionLevel());
        self::assertFalse($this->subject->inTransaction());
    }

    public function test_transaction_level_detection_inside_and_outside(): void
    {
        self::assertSame(0, $this->subject->getTransactionLevel());
        self::assertFalse($this->subject->inTransaction());

        $this->subject->transaction(function (): void {
            self::assertSame(1, $this->subject->getTransactionLevel());
            self::assertTrue($this->subject->inTransaction());

            // Nested transaction increases the level via savepoints.
            $this->subject->transaction(function (): void {
                self::assertSame(2, $this->subject->getTransactionLevel());
                self::assertTrue($this->subject->inTransaction());
            });

            self::assertSame(1, $this->subject->getTransactionLevel());
        });

        self::assertSame(0, $this->subject->getTransactionLevel());
        self::assertFalse($this->subject->inTransaction());
    }
}
