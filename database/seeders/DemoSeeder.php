<?php

namespace Database\Seeders;

use App\Actions\SaveDocument;
use App\Enums\AccountType;
use App\Enums\ContractorType;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DEMO data for local development: a fictional company with about 12 months of activity.
 * Deterministic (seeded random generator) so every `migrate:fresh --seed` gives the same story.
 */
class DemoSeeder extends Seeder
{
    /** Dev-only password, documented in the README. Never use it anywhere real. */
    public const DEMO_PASSWORD = 'Demo#2026pass';

    /**
     * A demo input for the VAT column of seeded documents (12.00%). It is sample data typed
     * into demo documents, not a tax rule: the application never reads it as a rate.
     */
    private const DEMO_VAT_BP = 1200;

    private string $companyName = 'Namuna Savdo MChJ (DEMO)';

    private int $variant = 0;

    /** Seeds a differently named company with its own random story (used for the demo companies). */
    public function forCompany(string $name, int $variant): static
    {
        $this->companyName = $name;
        $this->variant = $variant;

        return $this;
    }

    public function run(SaveDocument $saveDocument): void
    {
        mt_srand(2026 + $this->variant);

        DB::transaction(function () use ($saveDocument) {
            $this->company();
            $users = $this->users();
            $contractors = $this->contractors();
            [$cash, $bank] = $this->accounts();
            $categories = $this->categories();

            $this->documents($saveDocument, $contractors, $bank, $categories, $users['chief']);
            $this->transactions($cash, $bank, $categories, $users['cashier']);
            $this->employees();
            $this->notifications($users);
        });
    }

    private function company(): void
    {
        $existing = CompanySetting::query()->first();

        $company = $existing ?? new CompanySetting;
        $company->fill([
            'name' => $this->companyName,
            'legal_form' => 'MChJ',
            'stir' => $existing->stir ?? '300000001',
            'address' => 'Toshkent sh., Chilonzor tumani, Bunyodkor koʻchasi 12',
            'phone' => '+998712000001',
            'email' => 'info@namuna-savdo.test',
            'bank_name' => 'Demo Bank',
            'bank_account' => '20208000900000000001',
            'mfo' => '00001',
            'director_name' => 'Akmal Turayev',
            'chief_accountant_name' => 'Dilnoza Karimova',
            'vat_payer' => true,
            'vat_registration_code' => 'DEMO-0001',
            'default_vat_rate_bp' => self::DEMO_VAT_BP,
        ]);
        $company->forceFill(['is_demo' => true])->save();
    }

    /** @return array{owner: User, chief: User, cashier: User} */
    private function users(): array
    {
        // The owner may already exist (a provisioned company is created with an invited owner).
        $make = function (string $name, string $email, string $phone, Role $role) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'phone' => $phone, 'password' => self::DEMO_PASSWORD, 'role' => $role, 'locale' => 'uz', 'is_active' => true]);
            $user->forceFill(['email_verified_at' => now(), 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();

            return $user;
        };

        return [
            'owner' => $make('Akmal Turayev', 'owner@bugalter.test', '+998901112233', Role::Owner),
            'chief' => $make('Dilnoza Karimova', 'accountant@bugalter.test', '+998901112244', Role::ChiefAccountant),
            'cashier' => $make('Sardor Rahimov', 'cashier@bugalter.test', '+998901112255', Role::Cashier),
        ];
    }

    /** @return Collection<int, Contractor> */
    private function contractors(): Collection
    {
        $rows = [
            ['Oltin Vodiy Savdo MChJ', ContractorType::Customer, 'Toshkent'],
            ['Silk Road Logistics MChJ', ContractorType::Customer, 'Samarqand'],
            ['Registon Qurilish XK', ContractorType::Customer, 'Samarqand'],
            ['Bahor Market YaTT', ContractorType::Customer, 'Namangan'],
            ['Toshkent Texno Servis MChJ', ContractorType::Customer, 'Toshkent'],
            ['Navoiy Agro Trade MChJ', ContractorType::Customer, 'Navoiy'],
            ['Fargʻona Tekstil MChJ', ContractorType::Customer, 'Fargʻona'],
            ['Buxoro Mevalari MChJ', ContractorType::Customer, 'Buxoro'],
            ['Andijon Avto Detal MChJ', ContractorType::Customer, 'Andijon'],
            ['Xorazm Paxta Klaster MChJ', ContractorType::Customer, 'Urganch'],
            ['Chinor Restoran YaTT', ContractorType::Customer, 'Toshkent'],
            ['Sirdaryo Don Mahsulotlari MChJ', ContractorType::Customer, 'Guliston'],
            ['Uzbek Print Group MChJ', ContractorType::Supplier, 'Toshkent'],
            ['Mega Ofis Jihozlari MChJ', ContractorType::Supplier, 'Toshkent'],
            ['Texnopark Kompyuter MChJ', ContractorType::Supplier, 'Toshkent'],
            ['Yangi Asr Reklama MChJ', ContractorType::Supplier, 'Toshkent'],
            ['Qarshi Yoqilgʻi Servis MChJ', ContractorType::Supplier, 'Qarshi'],
            ['Ipak Yoʻli Transport MChJ', ContractorType::Supplier, 'Samarqand'],
            ['Madina Konsalting YaTT', ContractorType::Both, 'Toshkent'],
            ['Zarafshon Biznes Hamkor MChJ', ContractorType::Both, 'Navoiy'],
        ];

        return collect($rows)->map(fn (array $row, int $i) => Contractor::create([
            'name' => $row[0],
            'stir' => (string) (301000000 + $i * 7919),
            'type' => $row[1],
            'phone' => '+99890'.str_pad((string) (1000000 + $i * 37317), 7, '0', STR_PAD_LEFT),
            'email' => 'office'.($i + 1).'@demo-contractor.test',
            'address' => $row[2].' (demo)',
            'bank_name' => 'Demo Bank',
            'bank_account' => '2020800090000'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
            'mfo' => '00001',
            'notes' => 'Demo ma’lumot',
            'is_active' => $i !== 11,
        ]));
    }

    /** @return array{0: Account, 1: Account} */
    private function accounts(): array
    {
        $cash = Account::create([
            'name' => 'Kassa',
            'type' => AccountType::Cash,
            'opening_balance_minor' => $this->som(5_000_000),
        ]);

        $bank = Account::create([
            'name' => 'Asosiy hisob raqami',
            'type' => AccountType::Bank,
            'bank_name' => 'Demo Bank',
            'account_number' => '20208000900000000001',
            'opening_balance_minor' => $this->som(150_000_000),
        ]);

        return [$cash, $bank];
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $income = ['sales' => 'Tovarlar savdosi', 'services' => 'Xizmatlar', 'other_income' => 'Boshqa daromad'];
        $expense = [
            'rent' => 'Ijara', 'salaries' => 'Ish haqi', 'utilities' => 'Kommunal xizmatlar',
            'supplies' => 'Xomashyo va materiallar', 'marketing' => 'Marketing', 'transport' => 'Transport',
            'taxes_fees' => 'Soliq va yigʻimlar', 'other_expense' => 'Boshqa xarajatlar',
        ];

        $categories = [];
        foreach ($income as $key => $name) {
            $categories[$key] = Category::create(['key' => $key, 'name' => $name, 'type' => TransactionType::Income]);
        }
        foreach ($expense as $key => $name) {
            $categories[$key] = Category::create(['key' => $key, 'name' => $name, 'type' => TransactionType::Expense]);
        }

        return $categories;
    }

    private function documents(SaveDocument $save, $contractors, Account $bank, array $categories, User $creator): void
    {
        $items = [
            ['Konsalting xizmati', 'xizmat', 1, 4_000_000, 25_000_000],
            ['Dasturiy taʼminot litsenziyasi', 'dona', 1, 6_000_000, 40_000_000],
            ['Ofis jihozlari', 'dona', 5, 700_000, 6_000_000],
            ['Yuk tashish xizmati', 'reys', 3, 1_500_000, 5_000_000],
            ['Reklama xizmati', 'xizmat', 1, 3_000_000, 12_000_000],
            ['Qurilish materiallari', 'kg', 200, 15_000, 90_000],
            ['Kompyuter taʼmirlash', 'soat', 8, 120_000, 300_000],
            ['Moliyaviy audit', 'xizmat', 1, 8_000_000, 30_000_000],
        ];

        $customers = $contractors->filter(fn (Contractor $c) => $c->type !== ContractorType::Supplier && $c->is_active)->values();
        $suppliers = $contractors->filter(fn (Contractor $c) => $c->type !== ContractorType::Customer && $c->is_active)->values();

        $days = collect(range(1, 40))->map(fn () => mt_rand(1, 355))->sortDesc()->values();

        foreach ($days as $index => $age) {
            $incoming = $index % 4 === 3;
            $pool = $incoming ? $suppliers : $customers;
            $contractor = $pool[mt_rand(0, $pool->count() - 1)];
            $issued = now()->subDays($age)->startOfDay();

            $lines = [];
            foreach (range(1, mt_rand(1, 3)) as $position) {
                [$name, $unit, $quantity, $min, $max] = $items[mt_rand(0, count($items) - 1)];
                $lines[] = [
                    'name' => $name,
                    'unit' => $unit,
                    'quantity' => number_format($quantity, 3, '.', ''),
                    'unit_price_minor' => $this->som(intdiv(mt_rand($min, $max), 1000) * 1000),
                    'vat_rate_bp' => self::DEMO_VAT_BP,
                ];
            }

            /** @var Document $document */
            $document = $save([
                'type' => $index % 3 === 2 ? 'act' : 'invoice',
                'direction' => $incoming ? 'in' : 'out',
                'contractor_id' => $contractor->id,
                'issue_date' => $issued->toDateString(),
                'due_date' => $issued->addDays(10)->toDateString(),
                'notes' => null,
                'lines' => $lines,
            ], $creator);

            $status = $this->documentStatus($age, $index);
            $paidAt = $issued->addDays(mt_rand(3, 10))->setTime(mt_rand(9, 17), mt_rand(0, 59));

            if ($status === DocumentStatus::Paid && $paidAt->isFuture()) {
                $status = DocumentStatus::Sent;
            }

            $document->update([
                'status' => $status,
                'paid_at' => $status === DocumentStatus::Paid ? $paidAt : null,
            ]);

            if ($status === DocumentStatus::Paid) {
                Transaction::create([
                    'account_id' => $bank->id,
                    'category_id' => $incoming ? $categories['supplies']->id : $categories[mt_rand(0, 1) ? 'sales' : 'services']->id,
                    'contractor_id' => $contractor->id,
                    'document_id' => $document->id,
                    'type' => $incoming ? TransactionType::Expense : TransactionType::Income,
                    'amount_minor' => $document->total_minor,
                    'occurred_at' => $paidAt,
                    'description' => $document->number,
                    'status' => 'completed',
                    'created_by' => $creator->id,
                ]);
            }
        }
    }

    private function documentStatus(int $age, int $index): DocumentStatus
    {
        if ($index % 13 === 5) {
            return DocumentStatus::Cancelled;
        }

        $roll = mt_rand(1, 100);

        return match (true) {
            $age > 60 => DocumentStatus::Paid,
            $age > 20 => $roll <= 60 ? DocumentStatus::Paid : DocumentStatus::Sent,
            default => $roll <= 15 ? DocumentStatus::Paid : ($roll <= 75 ? DocumentStatus::Sent : DocumentStatus::Draft),
        };
    }

    private function transactions(Account $cash, Account $bank, array $categories, User $cashier): void
    {
        $at = fn (Carbon $month, int $day) => $month->copy()->day(min($day, $month->daysInMonth))->setTime(mt_rand(9, 18), mt_rand(0, 59));

        for ($m = 11; $m >= 0; $m--) {
            $month = now()->startOfMonth()->subMonths($m);

            $recurring = [
                ['rent', 'Ofis ijarasi', 12_000_000, 5],
                ['salaries', 'Oylik ish haqi', 28_000_000, 25],
                ['utilities', 'Kommunal toʻlovlar', mt_rand(1_800_000, 3_200_000), 12],
                ['taxes_fees', 'Soliq va yigʻimlar boʻyicha toʻlov', mt_rand(6_000_000, 11_000_000), 20],
            ];

            foreach ($recurring as [$key, $text, $amount, $day]) {
                $this->expense($bank, $categories[$key], $text, $amount, $at($month, $day), $cashier);
            }

            foreach (range(1, mt_rand(2, 4)) as $_) {
                $key = ['supplies', 'marketing', 'transport', 'other_expense'][mt_rand(0, 3)];
                $account = $key === 'transport' || $key === 'other_expense' ? $cash : $bank;
                $this->expense($account, $categories[$key], __('ui.demo.expense_note'), mt_rand(150, 4_000) * 1000, $at($month, mt_rand(2, 27)), $cashier);
            }

            foreach (range(1, mt_rand(2, 4)) as $_) {
                $when = $at($month, mt_rand(2, 27));
                $amount = $this->som(mt_rand(300, 3_500) * 1000);

                if ($when->isFuture()) {
                    continue;
                }

                Transaction::create([
                    'account_id' => $cash->id,
                    'category_id' => $categories['sales']->id,
                    'type' => TransactionType::Income,
                    'amount_minor' => $amount,
                    'occurred_at' => $when,
                    'description' => __('ui.demo.cash_sale_note'),
                    'status' => 'completed',
                    'created_by' => $cashier->id,
                ]);
            }
        }

        // A couple of recent unconfirmed payments, so the "pending" state is visible.
        foreach ([[2, 4_500_000], [1, 1_200_000]] as [$daysAgo, $amount]) {
            $this->expense($bank, $categories['supplies'], __('ui.demo.pending_note'), $amount, now()->subDays($daysAgo), $cashier, 'pending');
        }
    }

    private function expense(Account $account, Category $category, string $description, int $som, Carbon $when, User $user, string $status = 'completed'): void
    {
        // The demo story ends today: nothing is dated in the future.
        if ($when->isFuture()) {
            return;
        }

        Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Expense,
            'amount_minor' => $this->som($som),
            'occurred_at' => $when,
            'description' => $description,
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }

    private function employees(): void
    {
        $rows = [
            ['Abdullayev Jasur Rustamovich', 'Direktor oʻrinbosari', 'Boshqaruv', 9_000_000, 900],
            ['Karimova Dilnoza Aliyevna', 'Bosh buxgalter', 'Buxgalteriya', 7_500_000, 700],
            ['Rahimov Sardor Qodirovich', 'Kassir', 'Buxgalteriya', 4_000_000, 500],
            ['Yusupova Malika Botirovna', 'Savdo menejeri', 'Savdo', 5_500_000, 400],
            ['Tursunov Bekzod Ilxomovich', 'Omborchi', 'Ombor', 4_200_000, 300],
            ['Nazarova Shahlo Anvarovna', 'Marketing mutaxassisi', 'Marketing', 5_000_000, 200],
        ];

        foreach ($rows as $i => [$name, $position, $department, $salary, $daysAgo]) {
            Employee::create([
                'full_name' => $name,
                'pinfl' => '3'.str_pad((string) (10101900000 + $i * 104729), 13, '0', STR_PAD_LEFT),
                'position' => $position,
                'department' => $department,
                'phone' => '+99893'.str_pad((string) (1000000 + $i * 7717), 7, '0', STR_PAD_LEFT),
                'hired_on' => now()->subDays($daysAgo * 2)->toDateString(),
                'status' => 'active',
                'salary_minor' => $this->som($salary),
            ]);
        }
    }

    /** @param  array{owner: User, chief: User, cashier: User}  $users */
    private function notifications(array $users): void
    {
        foreach ([$users['owner'], $users['chief']] as $user) {
            $user->notify(new AppNotification('ui.notifications.demo_overdue', [], '/documents?status=sent'));
            $user->notify(new AppNotification('ui.notifications.demo_welcome', []));
            $user->notify(new AppNotification('ui.notifications.demo_tax_rules', [], '/reports'));
        }
    }

    private function som(int $som): int
    {
        return $som * 100;
    }
}
