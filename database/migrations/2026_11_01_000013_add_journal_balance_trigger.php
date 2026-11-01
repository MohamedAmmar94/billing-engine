<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
DECLARE
    v_debit  bigint;
    v_credit bigint;
BEGIN
    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
    INTO v_debit, v_credit
    FROM journal_lines
    WHERE journal_entry_id = NEW.journal_entry_id;

    IF v_debit <> v_credit THEN
        RAISE EXCEPTION 'Journal entry % is not balanced: debit % <> credit %',
            NEW.journal_entry_id, v_debit, v_credit
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('
    CREATE CONSTRAINT TRIGGER journal_lines_must_balance
    AFTER INSERT ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance()
');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_must_balance ON journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_entry_must_balance()');
    }
};
