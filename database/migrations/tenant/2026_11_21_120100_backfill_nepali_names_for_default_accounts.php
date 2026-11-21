<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills name_np for the default chart (todo/erp-parity, chunk S2). Accounts are
 * matched by code, heads by their seeded English name, and groups and
 * subgroups by their English name inside their seeded parent (a group named
 * "Direct Expenses" a tenant filed under another head is not the default one).
 * Only rows whose name_np is still null are touched, so a Nepali name a tenant
 * already typed is never overwritten. The lists are frozen literals (they do
 * not read the seeder), so the result does not depend on when this runs.
 * ChartOfAccountsSeeder carries the same values for new tenants.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const HEADS = [
        'Assets' => 'सम्पत्ति',
        'Liabilities' => 'दायित्व',
        'Capital' => 'पुँजी',
        'Income' => 'आय',
        'Expenses' => 'खर्च',
    ];

    /**
     * Group name => [head name, Nepali name].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const GROUPS = [
        'Current Assets' => ['Assets', 'चालु सम्पत्ति'],
        'Fixed Assets' => ['Assets', 'स्थिर सम्पत्ति'],
        'Current Liabilities' => ['Liabilities', 'चालु दायित्व'],
        'Non-Current Liabilities' => ['Liabilities', 'गैर-चालु दायित्व'],
        'Sales Accounts' => ['Income', 'बिक्री खाताहरू'],
        'Indirect Income' => ['Income', 'अप्रत्यक्ष आय'],
        'Purchase Accounts' => ['Expenses', 'खरिद खाताहरू'],
        'Direct Expenses' => ['Expenses', 'प्रत्यक्ष खर्च'],
        'Indirect Expenses' => ['Expenses', 'अप्रत्यक्ष खर्च'],
        'Capital Account' => ['Capital', 'पुँजी खाता'],
    ];

    /**
     * Subgroup name => [group name, Nepali name].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SUBGROUPS = [
        'Sundry Debtors' => ['Current Assets', 'विविध देनदार'],
        'Cash-In-Hand' => ['Current Assets', 'हातमा नगद'],
        'Bank Accounts' => ['Current Assets', 'बैंक खाताहरू'],
        'Stock' => ['Current Assets', 'मौज्दात'],
        'Sundry Creditors' => ['Current Liabilities', 'विविध लेनदार'],
        'Sales Agents' => ['Current Liabilities', 'बिक्री एजेन्टहरू'],
        'Salary Payable' => ['Current Liabilities', 'तलब भुक्तानी योग्य'],
        'Reserve Surplus' => ['Capital Account', 'जगेडा तथा बचत'],
    ];

    /**
     * @var array<string, string>
     */
    private const ACCOUNTS = [
        'AS1' => 'हातमा नगद',
        'CA2' => 'नाफा नोक्सान',
        'AS11' => 'हातमा रहेको मौज्दात',
        'AS12' => 'कच्चा पदार्थ मौज्दात',
        'AS13' => 'प्रक्रियामा रहेको काम',
        'AS14' => 'तयारी मालसामान मौज्दात',
        'INI20' => 'बिक्री',
        'INI21' => 'बिक्री फिर्ता',
        'INI22' => 'अन्तिम मौज्दात',
        'EXE8' => 'खरिद',
        'EXE81' => 'खरिद फिर्ता',
        'EXE9' => 'प्रारम्भिक मौज्दात',
        'EXE10' => 'बिक्री गरिएको मालको लागत',
        'LIA20' => 'भ्याट भुक्तानी योग्य',
        'LIA21' => 'टीडीएस भुक्तानी योग्य',
        'ASA23' => 'भ्याट प्राप्य',
        'AS31' => 'सञ्चित ह्रासकट्टी',
        'EXE20' => 'ह्रासकट्टी खर्च',
        'EXE21' => 'सम्पत्ति बिक्रीमा नोक्सान',
        'INI30' => 'सम्पत्ति बिक्रीमा नाफा',
        'INI31' => 'पूर्णांकन (राउन्ड अफ)',
        'EXE22' => 'बिक्री कमिसन खर्च',
        'EXE23' => 'मौज्दात समायोजन',
        'EXE24' => 'उत्पादन नोक्सान',
        'LIA22' => 'तलब भुक्तानी योग्य',
        'ASA24' => 'कर्मचारी पेश्की प्राप्य',
        'EXE25' => 'तलब तथा ज्याला',
        'EXE26' => 'कर्मचारी बोनस',
        'EXE27' => 'कर्मचारी भत्ता',
        'EXE28' => 'प्रत्यक्ष ज्याला',
        'EXE29' => 'आवक ढुवानी',
        'EXE30' => 'उप-ठेक्का शुल्क',
        'EXE31' => 'उपकरण भाडा',
        'LIA23' => 'एसएसएफ भुक्तानी योग्य',
        'LIA24' => 'सीआईटी भुक्तानी योग्य',
        'LIA25' => 'दीर्घकालीन ऋण',
        'ASA25' => 'अन्तर-शाखा क्लियरिङ',
        'INI23' => 'शाखामा हस्तान्तरित मौज्दात',
        'EXE11' => 'शाखाबाट प्राप्त मौज्दात',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->fill('account_heads', 'name', self::HEADS);
        $this->fillScoped('account_groups', 'account_head_id', 'account_heads', self::GROUPS);
        $this->fillScoped('account_subgroups', 'account_group_id', 'account_groups', self::SUBGROUPS);
        $this->fill('accounts', 'code', self::ACCOUNTS);
    }

    /**
     * Reverse the migrations.
     *
     * Nulls only a name_np that still equals the value this migration sets, so
     * a Nepali name a tenant edited since is kept.
     */
    public function down(): void
    {
        $this->clear('account_heads', 'name', self::HEADS);
        $this->clearScoped('account_groups', 'account_head_id', 'account_heads', self::GROUPS);
        $this->clearScoped('account_subgroups', 'account_group_id', 'account_groups', self::SUBGROUPS);
        $this->clear('accounts', 'code', self::ACCOUNTS);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $table, string $matchColumn, array $values): void
    {
        if (! $this->ready($table)) {
            return;
        }

        foreach ($values as $key => $nepali) {
            DB::table($table)
                ->where($matchColumn, $key)
                ->whereNull('name_np')
                ->update(['name_np' => $nepali]);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function clear(string $table, string $matchColumn, array $values): void
    {
        if (! $this->ready($table)) {
            return;
        }

        foreach ($values as $key => $nepali) {
            DB::table($table)
                ->where($matchColumn, $key)
                ->where('name_np', $nepali)
                ->update(['name_np' => null]);
        }
    }

    /**
     * Fills name_np on rows matched by name inside a parent of the given name.
     *
     * @param  array<string, array{0: string, 1: string}>  $values  name => [parent name, Nepali name]
     */
    private function fillScoped(string $table, string $parentColumn, string $parentTable, array $values): void
    {
        if (! $this->ready($table)) {
            return;
        }

        foreach ($values as $name => [$parentName, $nepali]) {
            DB::table($table)
                ->where('name', $name)
                ->whereNull('name_np')
                ->whereIn($parentColumn, DB::table($parentTable)->select('id')->where('name', $parentName))
                ->update(['name_np' => $nepali]);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $values  name => [parent name, Nepali name]
     */
    private function clearScoped(string $table, string $parentColumn, string $parentTable, array $values): void
    {
        if (! $this->ready($table)) {
            return;
        }

        foreach ($values as $name => [$parentName, $nepali]) {
            DB::table($table)
                ->where('name', $name)
                ->where('name_np', $nepali)
                ->whereIn($parentColumn, DB::table($parentTable)->select('id')->where('name', $parentName))
                ->update(['name_np' => null]);
        }
    }

    private function ready(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'name_np');
    }
};
