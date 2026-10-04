<?php

namespace Grocy\Services;

use DateTimeImmutable;
use Grocy\Controllers\Users\User;

/**
 * Household budget (Grocy Modern).
 *
 * A budget is a pot of money with its own categories and a monthly plan per
 * category - the shared household budget, and personal ones owned by a user.
 * Everyone with the BUDGET permission can see all of them; a personal budget
 * can only be changed by its owner. Transfers move money between budgets:
 * an expense on one side, an income on the other.
 *
 * Amounts are integer cents everywhere in here and in the database, signed
 * like a bank statement (money in is positive). Only the edges - API and
 * views - deal in decimals.
 */
class BudgetService extends BaseService
{
	const INTERVALS = [1, 3, 6, 12];

	// Recurring transactions catch up on everything due since they last ran,
	// but a broken date must never turn that into an endless loop
	const MAX_CATCH_UP = 240;

	// --- small helpers --------------------------------------------------

	private function Pdo(): \PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	private function Query(string $sql, array $params = []): array
	{
		$statement = $this->Pdo()->prepare($sql);
		$statement->execute($params);
		return $statement->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function QueryRow(string $sql, array $params = []): ?array
	{
		$rows = $this->Query($sql, $params);
		return count($rows) > 0 ? $rows[0] : null;
	}

	private function QueryValue(string $sql, array $params = [])
	{
		$statement = $this->Pdo()->prepare($sql);
		$statement->execute($params);
		return $statement->fetchColumn();
	}

	private function Execute(string $sql, array $params = []): int
	{
		$statement = $this->Pdo()->prepare($sql);
		$statement->execute($params);
		return $statement->rowCount();
	}

	private function Transaction(callable $work)
	{
		$pdo = $this->Pdo();

		// Already inside one (a budget created on the fly, say) - just join it
		if ($pdo->inTransaction())
		{
			return $work();
		}

		$pdo->beginTransaction();

		try
		{
			$result = $work();
			$pdo->commit();
			return $result;
		}
		catch (\Throwable $ex)
		{
			$pdo->rollBack();
			throw $ex;
		}
	}

	private function T(string $text, ...$placeholders): string
	{
		return LocalizationService::GetInstance()->__t($text, ...$placeholders);
	}

	// Accepts what an API client or a form may send: numbers, and strings
	// with a comma or a dot as decimal separator ("12,50", "1.234,56").
	public static function ToCents($value): int
	{
		if (is_int($value) || is_float($value))
		{
			return (int)round($value * 100);
		}

		$text = preg_replace('/[^0-9,.\-]/', '', trim((string)$value));

		if ($text === '' || $text === '-')
		{
			throw new \Exception('Invalid amount');
		}

		$lastComma = strrpos($text, ',');
		$lastDot = strrpos($text, '.');

		if ($lastComma !== false && $lastDot !== false)
		{
			// Whichever comes last is the decimal separator
			$decimal = $lastComma > $lastDot ? ',' : '.';
			$thousands = $decimal === ',' ? '.' : ',';
			$text = str_replace($thousands, '', $text);
			$text = str_replace($decimal, '.', $text);
		}
		elseif ($lastComma !== false)
		{
			$text = str_replace(',', '.', $text);
		}

		if (!is_numeric($text))
		{
			throw new \Exception('Invalid amount');
		}

		return (int)round(((float)$text) * 100);
	}

	public static function FromCents(?int $cents): float
	{
		return round(((int)$cents) / 100, 2);
	}

	public static function IsMonth($month): bool
	{
		return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
	}

	public static function CurrentMonth(): string
	{
		return date('Y-m');
	}

	public static function ShiftMonth(string $month, int $by): string
	{
		return (new DateTimeImmutable($month . '-01'))->modify(($by >= 0 ? '+' : '') . $by . ' months')->format('Y-m');
	}

	public static function MonthEnd(string $month): string
	{
		return (new DateTimeImmutable($month . '-01'))->format('Y-m-t');
	}

	private static function AssertDate($date): string
	{
		if (!is_string($date) || !IsIsoDate($date))
		{
			throw new \Exception('Invalid date');
		}

		return $date;
	}

	// --- permissions ----------------------------------------------------

	public function CurrentUserId(): int
	{
		return defined('GROCY_USER_ID') ? (int)GROCY_USER_ID : -1;
	}

	public function IsAdmin(): bool
	{
		return User::HasPermissions(User::PERMISSION_ADMIN);
	}

	// A personal budget whose owner no longer exists behaves like a shared one
	// - otherwise nobody could ever change it again
	public function CanEdit(array $budget): bool
	{
		if (empty($budget['owner_user_id']))
		{
			return true;
		}

		if ((int)$budget['owner_user_id'] === $this->CurrentUserId())
		{
			return true;
		}

		$ownerExists = (int)$this->QueryValue('SELECT COUNT(*) FROM users WHERE id = ?', [(int)$budget['owner_user_id']]) > 0;
		return !$ownerExists;
	}

	private function AssertCanEdit(array $budget): void
	{
		if (!$this->CanEdit($budget))
		{
			throw new BudgetPermissionException($this->T('Only the owner can change a personal budget'));
		}
	}

	// --- budgets --------------------------------------------------------

	public function GetBudget(int $budgetId): array
	{
		$budget = $this->QueryRow('SELECT * FROM budget_budgets WHERE id = ?', [$budgetId]);

		if ($budget === null)
		{
			throw new \Exception('Budget does not exist');
		}

		return $budget;
	}

	public function GetBudgets(): array
	{
		$this->EnsureDefaultBudget();

		$budgets = $this->Query('
			SELECT b.*, u.display_name AS owner_name,
				(SELECT COALESCE(SUM(t.amount), 0) FROM budget_transactions t WHERE t.budget_id = b.id AND t.date <= ?) AS balance
			FROM budget_budgets b
			LEFT JOIN users_dto u ON u.id = b.owner_user_id
			ORDER BY (b.owner_user_id IS NOT NULL), b.sort_order, b.name COLLATE NOCASE', [date('Y-m-d')]);

		foreach ($budgets as &$budget)
		{
			$budget['can_edit'] = $this->CanEdit($budget);
			$budget['balance'] = (int)$budget['balance'];
		}

		return $budgets;
	}

	// The household budget exists from the start, so the feature works without
	// any setup - and it comes with the default categories
	public function EnsureDefaultBudget(): void
	{
		if ((int)$this->QueryValue('SELECT COUNT(*) FROM budget_budgets') === 0)
		{
			$this->CreateBudgetRow($this->T('Household'), null);
		}
	}

	public function CreateBudget(string $name, ?int $ownerUserId): int
	{
		$name = trim($name);

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		// Your own personal budget, or a shared one - a personal budget for
		// somebody else is what an administrator sets up
		if ($ownerUserId !== null && $ownerUserId !== $this->CurrentUserId() && !$this->IsAdmin())
		{
			throw new BudgetPermissionException($this->T('Only an administrator can create a budget for somebody else'));
		}

		return $this->CreateBudgetRow($name, $ownerUserId);
	}

	private function CreateBudgetRow(string $name, ?int $ownerUserId): int
	{
		return $this->Transaction(function () use ($name, $ownerUserId)
		{
			$this->Execute('INSERT INTO budget_budgets (name, owner_user_id) VALUES (?, ?)', [$name, $ownerUserId]);
			$budgetId = (int)$this->Pdo()->lastInsertId();

			$order = 0;
			foreach (['Groceries', 'Drugstore', 'Housing', 'Energy', 'Insurance', 'Mobility', 'Health', 'Clothing', 'Leisure', 'Pocket money', 'Miscellaneous'] as $name)
			{
				$this->Execute('INSERT INTO budget_categories (budget_id, name, is_income, sort_order) VALUES (?, ?, 0, ?)', [$budgetId, $this->T($name), $order++]);
			}

			foreach (['Salary', 'Pocket money', 'Other income'] as $name)
			{
				$this->Execute('INSERT INTO budget_categories (budget_id, name, is_income, sort_order) VALUES (?, ?, 1, ?)', [$budgetId, $this->T($name), $order++]);
			}

			return $budgetId;
		});
	}

	public function UpdateBudget(int $budgetId, string $name, ?int $ownerUserId): void
	{
		$budget = $this->GetBudget($budgetId);
		$this->AssertCanEdit($budget);

		$name = trim($name);

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		// Changing the owner is an administrator's call - otherwise anybody could
		// turn the household into their personal budget and lock the others out
		$currentOwner = $budget['owner_user_id'] === null ? null : (int)$budget['owner_user_id'];
		if ($ownerUserId !== $currentOwner && !$this->IsAdmin())
		{
			throw new BudgetPermissionException($this->T('Only an administrator can change the owner of a budget'));
		}

		$this->Execute('UPDATE budget_budgets SET name = ?, owner_user_id = ? WHERE id = ?', [$name, $ownerUserId, $budgetId]);
	}

	// Deleting a budget takes its whole history with it, so that is reserved
	// for administrators - otherwise anybody could wipe the household
	public function DeleteBudget(int $budgetId): void
	{
		$this->GetBudget($budgetId);

		if (!$this->IsAdmin())
		{
			throw new BudgetPermissionException($this->T('Only an administrator can delete a budget'));
		}

		$this->Transaction(function () use ($budgetId)
		{
			// The other side of a transfer stays, it is real money that moved -
			// it only stops pointing at a transaction that no longer exists
			$this->Execute('UPDATE budget_transactions SET transfer_id = NULL WHERE transfer_id IN (SELECT id FROM budget_transactions WHERE budget_id = ?)', [$budgetId]);
			$this->Execute('DELETE FROM budget_transactions WHERE budget_id = ?', [$budgetId]);
			$this->Execute('DELETE FROM budget_plans WHERE category_id IN (SELECT id FROM budget_categories WHERE budget_id = ?)', [$budgetId]);
			$this->Execute('DELETE FROM budget_recurring WHERE budget_id = ? OR target_budget_id = ?', [$budgetId, $budgetId]);
			$this->Execute('DELETE FROM budget_categories WHERE budget_id = ?', [$budgetId]);
			$this->Execute('DELETE FROM budget_budgets WHERE id = ?', [$budgetId]);
		});
	}

	// --- categories -----------------------------------------------------

	public function GetCategory(int $categoryId): array
	{
		$category = $this->QueryRow('SELECT * FROM budget_categories WHERE id = ?', [$categoryId]);

		if ($category === null)
		{
			throw new \Exception('Category does not exist');
		}

		return $category;
	}

	public function GetCategories(int $budgetId): array
	{
		return $this->Query('SELECT * FROM budget_categories WHERE budget_id = ? ORDER BY is_income, sort_order, name COLLATE NOCASE', [$budgetId]);
	}

	private function AssertCategoryOfBudget(?int $categoryId, int $budgetId): void
	{
		if ($categoryId === null)
		{
			return;
		}

		if ((int)$this->GetCategory($categoryId)['budget_id'] !== $budgetId)
		{
			throw new \Exception('The category belongs to a different budget');
		}
	}

	public function CreateCategory(int $budgetId, string $name, bool $isIncome, bool $carryover): int
	{
		$this->AssertCanEdit($this->GetBudget($budgetId));

		$name = trim($name);

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		$order = (int)$this->QueryValue('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM budget_categories WHERE budget_id = ?', [$budgetId]);
		$this->Execute('INSERT INTO budget_categories (budget_id, name, is_income, carryover, sort_order) VALUES (?, ?, ?, ?, ?)', [$budgetId, $name, $isIncome ? 1 : 0, $carryover ? 1 : 0, $order]);

		return (int)$this->Pdo()->lastInsertId();
	}

	public function UpdateCategory(int $categoryId, string $name, bool $isIncome, bool $carryover, ?int $sortOrder = null): void
	{
		$category = $this->GetCategory($categoryId);
		$this->AssertCanEdit($this->GetBudget((int)$category['budget_id']));

		$name = trim($name);

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		$this->Execute('UPDATE budget_categories SET name = ?, is_income = ?, carryover = ?, sort_order = ? WHERE id = ?', [
			$name, $isIncome ? 1 : 0, $carryover ? 1 : 0, $sortOrder ?? (int)$category['sort_order'], $categoryId
		]);
	}

	// Its transactions are kept - they happened - and simply lose their category
	public function DeleteCategory(int $categoryId): void
	{
		$category = $this->GetCategory($categoryId);
		$this->AssertCanEdit($this->GetBudget((int)$category['budget_id']));

		$this->Transaction(function () use ($categoryId)
		{
			$this->Execute('UPDATE budget_transactions SET category_id = NULL WHERE category_id = ?', [$categoryId]);
			$this->Execute('UPDATE budget_recurring SET category_id = NULL WHERE category_id = ?', [$categoryId]);
			$this->Execute('UPDATE budget_recurring SET target_category_id = NULL WHERE target_category_id = ?', [$categoryId]);
			$this->Execute('DELETE FROM budget_plans WHERE category_id = ?', [$categoryId]);
			$this->Execute('DELETE FROM budget_categories WHERE id = ?', [$categoryId]);
		});
	}

	// Sets the plan from this month on - the months before keep the plan they
	// had, so the history stays what it was
	public function SetPlan(int $categoryId, string $month, int $amountCents): void
	{
		if (!self::IsMonth($month))
		{
			throw new \Exception('Invalid month');
		}

		if ($amountCents < 0)
		{
			throw new \Exception($this->T('A plan cannot be negative'));
		}

		$category = $this->GetCategory($categoryId);
		$this->AssertCanEdit($this->GetBudget((int)$category['budget_id']));

		$this->Execute('INSERT INTO budget_plans (category_id, valid_from, amount) VALUES (?, ?, ?)
			ON CONFLICT (category_id, valid_from) DO UPDATE SET amount = excluded.amount', [$categoryId, $month, $amountCents]);
	}

	// --- transactions ---------------------------------------------------

	public function GetTransaction(int $transactionId): array
	{
		$transaction = $this->QueryRow('SELECT * FROM budget_transactions WHERE id = ?', [$transactionId]);

		if ($transaction === null)
		{
			throw new \Exception('Transaction does not exist');
		}

		return $transaction;
	}

	public function GetTransactions(?int $budgetId = null, ?string $month = null, ?int $categoryId = null): array
	{
		$where = [];
		$params = [];

		if ($budgetId !== null)
		{
			$where[] = 't.budget_id = ?';
			$params[] = $budgetId;
		}

		if ($month !== null)
		{
			if (!self::IsMonth($month))
			{
				throw new \Exception('Invalid month');
			}

			$where[] = 't.date BETWEEN ? AND ?';
			$params[] = $month . '-01';
			$params[] = $month . '-31';
		}

		if ($categoryId !== null)
		{
			$where[] = 't.category_id = ?';
			$params[] = $categoryId;
		}

		$transactions = $this->Query('
			SELECT t.*, b.name AS budget_name, b.owner_user_id, c.name AS category_name, c.is_income,
				ob.name AS transfer_budget_name, o.budget_id AS transfer_budget_id, u.display_name AS user_name
			FROM budget_transactions t
			JOIN budget_budgets b ON b.id = t.budget_id
			LEFT JOIN budget_categories c ON c.id = t.category_id
			LEFT JOIN budget_transactions o ON o.id = t.transfer_id
			LEFT JOIN budget_budgets ob ON ob.id = o.budget_id
			LEFT JOIN users_dto u ON u.id = t.user_id
			' . (count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '') . '
			ORDER BY t.date DESC, t.id DESC', $params);

		$budgets = [];
		foreach ($transactions as &$transaction)
		{
			$transaction['amount'] = (int)$transaction['amount'];

			// A transfer is changed from the side the money left
			$owningBudgetId = (int)$transaction['budget_id'];
			if (!empty($transaction['transfer_id']) && $transaction['amount'] > 0 && $transaction['transfer_budget_id'] !== null)
			{
				$owningBudgetId = (int)$transaction['transfer_budget_id'];
			}

			if (!array_key_exists($owningBudgetId, $budgets))
			{
				$budgets[$owningBudgetId] = $this->CanEdit($this->GetBudget($owningBudgetId));
			}

			$transaction['can_edit'] = $budgets[$owningBudgetId];
		}

		return $transactions;
	}

	private function ValidateTransactionInput(array $data): array
	{
		$budgetId = (int)($data['budget_id'] ?? 0);
		$budget = $this->GetBudget($budgetId);
		$categoryId = isset($data['category_id']) && $data['category_id'] !== '' && $data['category_id'] !== null ? (int)$data['category_id'] : null;
		$this->AssertCategoryOfBudget($categoryId, $budgetId);

		$amount = self::ToCents($data['amount'] ?? '');

		if ($amount === 0)
		{
			throw new \Exception($this->T('The amount cannot be zero'));
		}

		return [
			'budget' => $budget,
			'budget_id' => $budgetId,
			'category_id' => $categoryId,
			'date' => self::AssertDate($data['date'] ?? ''),
			'amount' => $amount,
			'payee' => isset($data['payee']) && trim((string)$data['payee']) !== '' ? trim((string)$data['payee']) : null,
			'note' => isset($data['note']) && trim((string)$data['note']) !== '' ? trim((string)$data['note']) : null
		];
	}

	public function CreateTransaction(array $data): int
	{
		$input = $this->ValidateTransactionInput($data);
		$this->AssertCanEdit($input['budget']);

		$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, payee, note, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
			$input['budget_id'], $input['category_id'], $input['date'], $input['amount'], $input['payee'], $input['note'], $this->CurrentUserId() > 0 ? $this->CurrentUserId() : null
		]);

		return (int)$this->Pdo()->lastInsertId();
	}

	public function UpdateTransaction(int $transactionId, array $data): void
	{
		$transaction = $this->GetTransaction($transactionId);

		if (!empty($transaction['transfer_id']))
		{
			$this->UpdateTransfer($transactionId, $data);
			return;
		}

		$this->AssertCanEdit($this->GetBudget((int)$transaction['budget_id']));

		// Moving a transaction into another budget means being allowed there too
		$data['budget_id'] = $data['budget_id'] ?? $transaction['budget_id'];
		$input = $this->ValidateTransactionInput($data);
		$this->AssertCanEdit($input['budget']);

		$this->Execute('UPDATE budget_transactions SET budget_id = ?, category_id = ?, date = ?, amount = ?, payee = ?, note = ? WHERE id = ?', [
			$input['budget_id'], $input['category_id'], $input['date'], $input['amount'], $input['payee'], $input['note'], $transactionId
		]);
	}

	public function DeleteTransaction(int $transactionId): void
	{
		$transaction = $this->GetTransaction($transactionId);

		if (!empty($transaction['transfer_id']))
		{
			$source = $this->TransferSource($transaction);
			$this->AssertCanEdit($this->GetBudget((int)$source['budget_id']));

			$this->Transaction(function () use ($transaction)
			{
				$this->Execute('DELETE FROM budget_transactions WHERE id IN (?, ?)', [(int)$transaction['id'], (int)$transaction['transfer_id']]);
			});
			return;
		}

		$this->AssertCanEdit($this->GetBudget((int)$transaction['budget_id']));
		$this->Execute('DELETE FROM budget_transactions WHERE id = ?', [$transactionId]);
	}

	// A transfer is changed from the side the money left; anything else from
	// its own budget
	public function CanEditTransaction(array $transaction): bool
	{
		$owner = !empty($transaction['transfer_id']) ? $this->TransferSource($transaction) : $transaction;
		return $this->CanEdit($this->GetBudget((int)$owner['budget_id']));
	}

	// --- transfers ------------------------------------------------------

	// The side the money left, which is the one allowed to change the transfer
	private function TransferSource(array $transaction): array
	{
		if ((int)$transaction['amount'] < 0 || empty($transaction['transfer_id']))
		{
			return $transaction;
		}

		$other = $this->QueryRow('SELECT * FROM budget_transactions WHERE id = ?', [(int)$transaction['transfer_id']]);
		return $other ?? $transaction;
	}

	private function ValidateTransferInput(array $data): array
	{
		$fromBudget = $this->GetBudget((int)($data['from_budget_id'] ?? 0));
		$toBudget = $this->GetBudget((int)($data['to_budget_id'] ?? 0));

		if ((int)$fromBudget['id'] === (int)$toBudget['id'])
		{
			throw new \Exception($this->T('A transfer needs two different budgets'));
		}

		$fromCategoryId = isset($data['from_category_id']) && $data['from_category_id'] !== '' && $data['from_category_id'] !== null ? (int)$data['from_category_id'] : null;
		$toCategoryId = isset($data['to_category_id']) && $data['to_category_id'] !== '' && $data['to_category_id'] !== null ? (int)$data['to_category_id'] : null;
		$this->AssertCategoryOfBudget($fromCategoryId, (int)$fromBudget['id']);
		$this->AssertCategoryOfBudget($toCategoryId, (int)$toBudget['id']);

		// Always the amount that moves, however it was entered
		$amount = abs(self::ToCents($data['amount'] ?? ''));

		if ($amount === 0)
		{
			throw new \Exception($this->T('The amount cannot be zero'));
		}

		return [
			'from_budget' => $fromBudget,
			'to_budget' => $toBudget,
			'from_category_id' => $fromCategoryId,
			'to_category_id' => $toCategoryId,
			'amount' => $amount,
			'date' => self::AssertDate($data['date'] ?? ''),
			'note' => isset($data['note']) && trim((string)$data['note']) !== '' ? trim((string)$data['note']) : null
		];
	}

	public function CreateTransfer(array $data): array
	{
		$input = $this->ValidateTransferInput($data);

		// Moving money out of a budget is a change to that budget. Putting it
		// into someone else's personal budget is not - pocket money can be
		// given by anyone allowed to spend from the household
		$this->AssertCanEdit($input['from_budget']);

		$userId = $this->CurrentUserId() > 0 ? $this->CurrentUserId() : null;

		return $this->Transaction(function () use ($input, $userId)
		{
			$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, note, user_id) VALUES (?, ?, ?, ?, ?, ?)', [
				(int)$input['from_budget']['id'], $input['from_category_id'], $input['date'], -$input['amount'], $input['note'], $userId
			]);
			$fromId = (int)$this->Pdo()->lastInsertId();

			$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, note, user_id, transfer_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
				(int)$input['to_budget']['id'], $input['to_category_id'], $input['date'], $input['amount'], $input['note'], $userId, $fromId
			]);
			$toId = (int)$this->Pdo()->lastInsertId();

			$this->Execute('UPDATE budget_transactions SET transfer_id = ? WHERE id = ?', [$toId, $fromId]);

			return ['from_transaction_id' => $fromId, 'to_transaction_id' => $toId];
		});
	}

	// Amount, date and note change on both sides; the categories per side
	private function UpdateTransfer(int $transactionId, array $data): void
	{
		$transaction = $this->GetTransaction($transactionId);
		$source = $this->TransferSource($transaction);
		$target = $this->GetTransaction((int)$source['transfer_id']);
		$this->AssertCanEdit($this->GetBudget((int)$source['budget_id']));

		$amount = abs(self::ToCents($data['amount'] ?? ''));

		if ($amount === 0)
		{
			throw new \Exception($this->T('The amount cannot be zero'));
		}

		$date = self::AssertDate($data['date'] ?? '');
		$note = isset($data['note']) && trim((string)$data['note']) !== '' ? trim((string)$data['note']) : null;

		// The category sent belongs to the side that was edited
		$categoryId = isset($data['category_id']) && $data['category_id'] !== '' && $data['category_id'] !== null ? (int)$data['category_id'] : null;
		$this->AssertCategoryOfBudget($categoryId, (int)$transaction['budget_id']);

		$this->Transaction(function () use ($source, $target, $transaction, $amount, $date, $note, $categoryId)
		{
			$this->Execute('UPDATE budget_transactions SET amount = ?, date = ?, note = ? WHERE id = ?', [-$amount, $date, $note, (int)$source['id']]);
			$this->Execute('UPDATE budget_transactions SET amount = ?, date = ?, note = ? WHERE id = ?', [$amount, $date, $note, (int)$target['id']]);
			$this->Execute('UPDATE budget_transactions SET category_id = ? WHERE id = ?', [$categoryId, (int)$transaction['id']]);
		});
	}

	// --- recurring transactions -----------------------------------------

	public function GetRecurringRule(int $recurringId): array
	{
		$rule = $this->QueryRow('SELECT * FROM budget_recurring WHERE id = ?', [$recurringId]);

		if ($rule === null)
		{
			throw new \Exception('Recurring transaction does not exist');
		}

		return $rule;
	}

	public function GetRecurring(?int $budgetId = null): array
	{
		$rules = $this->Query('
			SELECT r.*, b.name AS budget_name, c.name AS category_name, c.is_income, tb.name AS target_budget_name, tc.name AS target_category_name
			FROM budget_recurring r
			JOIN budget_budgets b ON b.id = r.budget_id
			LEFT JOIN budget_categories c ON c.id = r.category_id
			LEFT JOIN budget_budgets tb ON tb.id = r.target_budget_id
			LEFT JOIN budget_categories tc ON tc.id = r.target_category_id
			' . ($budgetId !== null ? 'WHERE r.budget_id = ? OR r.target_budget_id = ?' : '') . '
			ORDER BY r.active DESC, r.next_due_date, r.name COLLATE NOCASE', $budgetId !== null ? [$budgetId, $budgetId] : []);

		foreach ($rules as &$rule)
		{
			$rule['amount'] = (int)$rule['amount'];
			$rule['can_edit'] = $this->CanEdit($this->GetBudget((int)$rule['budget_id']));
		}

		return $rules;
	}

	// The first due date on or after the start; the day is clamped to the
	// length of the month, so "on the 31st" means the last day in February
	public static function FirstDueDate(string $startDate, int $dayOfMonth): string
	{
		$start = new DateTimeImmutable($startDate);
		$candidate = self::DayInMonth($start->format('Y-m'), $dayOfMonth);

		if ($candidate < $startDate)
		{
			$candidate = self::DayInMonth(self::ShiftMonth($start->format('Y-m'), 1), $dayOfMonth);
		}

		return $candidate;
	}

	public static function NextDueDate(string $dueDate, int $intervalMonths, int $dayOfMonth): string
	{
		return self::DayInMonth(self::ShiftMonth(substr($dueDate, 0, 7), $intervalMonths), $dayOfMonth);
	}

	private static function DayInMonth(string $month, int $dayOfMonth): string
	{
		$first = new DateTimeImmutable($month . '-01');
		$day = min(max(1, $dayOfMonth), (int)$first->format('t'));
		return $first->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
	}

	private function ValidateRecurringInput(array $data): array
	{
		$budget = $this->GetBudget((int)($data['budget_id'] ?? 0));
		$categoryId = isset($data['category_id']) && $data['category_id'] !== '' && $data['category_id'] !== null ? (int)$data['category_id'] : null;
		$this->AssertCategoryOfBudget($categoryId, (int)$budget['id']);

		$targetBudgetId = isset($data['target_budget_id']) && $data['target_budget_id'] !== '' && $data['target_budget_id'] !== null ? (int)$data['target_budget_id'] : null;
		$targetCategoryId = null;

		if ($targetBudgetId !== null)
		{
			$this->GetBudget($targetBudgetId);

			if ($targetBudgetId === (int)$budget['id'])
			{
				throw new \Exception($this->T('A transfer needs two different budgets'));
			}

			$targetCategoryId = isset($data['target_category_id']) && $data['target_category_id'] !== '' && $data['target_category_id'] !== null ? (int)$data['target_category_id'] : null;
			$this->AssertCategoryOfBudget($targetCategoryId, $targetBudgetId);
		}

		$name = trim((string)($data['name'] ?? ''));

		if ($name === '')
		{
			throw new \Exception($this->T('A name is required'));
		}

		$amount = self::ToCents($data['amount'] ?? '');

		if ($amount === 0)
		{
			throw new \Exception($this->T('The amount cannot be zero'));
		}

		// A transfer always moves a positive amount out of its budget
		if ($targetBudgetId !== null)
		{
			$amount = abs($amount);
		}

		$interval = (int)($data['interval_months'] ?? 1);

		if (!in_array($interval, self::INTERVALS, true))
		{
			throw new \Exception('Invalid interval');
		}

		$startDate = self::AssertDate($data['start_date'] ?? '');
		$dayOfMonth = (int)($data['day_of_month'] ?? (int)substr($startDate, 8, 2));

		if ($dayOfMonth < 1 || $dayOfMonth > 31)
		{
			throw new \Exception('Invalid day of month');
		}

		$endDate = isset($data['end_date']) && $data['end_date'] !== '' && $data['end_date'] !== null ? self::AssertDate($data['end_date']) : null;

		if ($endDate !== null && $endDate < $startDate)
		{
			throw new \Exception($this->T('The end cannot be before the start'));
		}

		return [
			'budget' => $budget,
			'budget_id' => (int)$budget['id'],
			'category_id' => $categoryId,
			'target_budget_id' => $targetBudgetId,
			'target_category_id' => $targetCategoryId,
			'name' => $name,
			'amount' => $amount,
			'payee' => isset($data['payee']) && trim((string)$data['payee']) !== '' ? trim((string)$data['payee']) : null,
			'note' => isset($data['note']) && trim((string)$data['note']) !== '' ? trim((string)$data['note']) : null,
			'interval_months' => $interval,
			'day_of_month' => $dayOfMonth,
			'start_date' => $startDate,
			'end_date' => $endDate,
			'active' => !isset($data['active']) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0
		];
	}

	public function CreateRecurring(array $data): int
	{
		$input = $this->ValidateRecurringInput($data);
		$this->AssertCanEdit($input['budget']);

		$this->Execute('INSERT INTO budget_recurring (budget_id, category_id, target_budget_id, target_category_id, name, amount, payee, note, interval_months, day_of_month, start_date, end_date, next_due_date, active)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
			$input['budget_id'], $input['category_id'], $input['target_budget_id'], $input['target_category_id'], $input['name'], $input['amount'], $input['payee'], $input['note'],
			$input['interval_months'], $input['day_of_month'], $input['start_date'], $input['end_date'], self::FirstDueDate($input['start_date'], $input['day_of_month']), $input['active']
		]);
		$recurringId = (int)$this->Pdo()->lastInsertId();

		// A start date in the past books what was due since right away
		$this->ProcessDueRecurring();

		return $recurringId;
	}

	public function UpdateRecurring(int $recurringId, array $data): void
	{
		$rule = $this->GetRecurringRule($recurringId);
		$this->AssertCanEdit($this->GetBudget((int)$rule['budget_id']));

		$input = $this->ValidateRecurringInput($data);
		$this->AssertCanEdit($input['budget']);

		// What was booked stays booked. A changed schedule only decides what
		// comes next: the next due date is recalculated from whichever is later
		// - the new start, or the day after the last booking.
		$lastBooked = $this->QueryValue('SELECT MAX(date) FROM budget_transactions WHERE recurring_id = ? AND (transfer_id IS NULL OR amount < 0)', [$recurringId]);
		$from = $input['start_date'];

		if ($lastBooked && $lastBooked >= $from)
		{
			$from = (new DateTimeImmutable($lastBooked))->modify('+1 day')->format('Y-m-d');
		}

		$this->Execute('UPDATE budget_recurring SET budget_id = ?, category_id = ?, target_budget_id = ?, target_category_id = ?, name = ?, amount = ?, payee = ?, note = ?,
			interval_months = ?, day_of_month = ?, start_date = ?, end_date = ?, next_due_date = ?, active = ? WHERE id = ?', [
			$input['budget_id'], $input['category_id'], $input['target_budget_id'], $input['target_category_id'], $input['name'], $input['amount'], $input['payee'], $input['note'],
			$input['interval_months'], $input['day_of_month'], $input['start_date'], $input['end_date'], self::FirstDueDate($from, $input['day_of_month']), $input['active'], $recurringId
		]);

		$this->ProcessDueRecurring();
	}

	// Its bookings stay - they are real - they just stop being linked to it
	public function DeleteRecurring(int $recurringId): void
	{
		$rule = $this->GetRecurringRule($recurringId);
		$this->AssertCanEdit($this->GetBudget((int)$rule['budget_id']));

		$this->Transaction(function () use ($recurringId)
		{
			$this->Execute('UPDATE budget_transactions SET recurring_id = NULL WHERE recurring_id = ?', [$recurringId]);
			$this->Execute('DELETE FROM budget_recurring WHERE id = ?', [$recurringId]);
		});
	}

	/**
	 * Books every recurring transaction that has become due.
	 *
	 * Grocy has no scheduler, so this runs whenever the budget is looked at
	 * and catches up on everything due since then - a rule due on the 1st is
	 * booked on the 1st as soon as somebody opens the budget, even if that is
	 * the 5th. Each occurrence is claimed by moving the rule's due date on
	 * with a guarded UPDATE in the same database transaction as the booking:
	 * two requests at the same moment can never book the same month twice.
	 */
	public function ProcessDueRecurring(): int
	{
		$today = date('Y-m-d');
		$booked = 0;

		$rules = $this->Query('SELECT * FROM budget_recurring WHERE active = 1 AND next_due_date <= ?', [$today]);

		foreach ($rules as $rule)
		{
			$due = $rule['next_due_date'];

			for ($i = 0; $i < self::MAX_CATCH_UP && $due <= $today; $i++)
			{
				if ($rule['end_date'] !== null && $due > $rule['end_date'])
				{
					$this->Execute('UPDATE budget_recurring SET active = 0 WHERE id = ?', [(int)$rule['id']]);
					break;
				}

				$next = self::NextDueDate($due, (int)$rule['interval_months'], (int)$rule['day_of_month']);

				$claimed = $this->Transaction(function () use ($rule, $due, $next)
				{
					if ($this->Execute('UPDATE budget_recurring SET next_due_date = ? WHERE id = ? AND next_due_date = ?', [$next, (int)$rule['id'], $due]) !== 1)
					{
						return false;
					}

					$this->BookRecurring($rule, $due);
					return true;
				});

				if (!$claimed)
				{
					// Somebody else is booking this rule right now
					break;
				}

				$booked++;
				$due = $next;
			}

			if ($rule['end_date'] !== null && $due > $rule['end_date'])
			{
				$this->Execute('UPDATE budget_recurring SET active = 0 WHERE id = ?', [(int)$rule['id']]);
			}
		}

		return $booked;
	}

	// Runs inside ProcessDueRecurring's transaction - so no nested one here
	private function BookRecurring(array $rule, string $date): void
	{
		if (!empty($rule['target_budget_id']))
		{
			$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, note, recurring_id) VALUES (?, ?, ?, ?, ?, ?)', [
				(int)$rule['budget_id'], $rule['category_id'], $date, -abs((int)$rule['amount']), $rule['note'] ?? $rule['name'], (int)$rule['id']
			]);
			$fromId = (int)$this->Pdo()->lastInsertId();

			$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, note, recurring_id, transfer_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
				(int)$rule['target_budget_id'], $rule['target_category_id'], $date, abs((int)$rule['amount']), $rule['note'] ?? $rule['name'], (int)$rule['id'], $fromId
			]);
			$toId = (int)$this->Pdo()->lastInsertId();

			$this->Execute('UPDATE budget_transactions SET transfer_id = ? WHERE id = ?', [$toId, $fromId]);
			return;
		}

		$this->Execute('INSERT INTO budget_transactions (budget_id, category_id, date, amount, payee, note, recurring_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
			(int)$rule['budget_id'], $rule['category_id'], $date, (int)$rule['amount'], $rule['payee'], $rule['note'] ?? $rule['name'], (int)$rule['id']
		]);
	}

	// --- the month overview ---------------------------------------------

	/**
	 * Plan, spent and what is left per category for one month, plus the totals.
	 *
	 * - The plan of a month is the latest plan row from that month or before.
	 * - Without carryover what is left is simply plan minus spent; with it,
	 *   whatever was left (or overspent) the month before is added - from the
	 *   first month the category had a plan or a booking on.
	 * - "Available" is the balance minus what the plan still has reserved:
	 *   the money that is really free.
	 */
	public function GetMonthOverview(int $budgetId, string $month): array
	{
		if (!self::IsMonth($month))
		{
			throw new \Exception('Invalid month');
		}

		$budget = $this->GetBudget($budgetId);
		$categories = $this->GetCategories($budgetId);
		$monthEnd = self::MonthEnd($month);

		$plans = [];
		foreach ($this->Query('SELECT p.category_id, p.valid_from, p.amount FROM budget_plans p JOIN budget_categories c ON c.id = p.category_id WHERE c.budget_id = ? AND p.valid_from <= ? ORDER BY p.valid_from', [$budgetId, $month]) as $row)
		{
			$plans[(int)$row['category_id']][] = ['from' => $row['valid_from'], 'amount' => (int)$row['amount']];
		}

		$activity = [];
		foreach ($this->Query('SELECT category_id, substr(date, 1, 7) AS month, SUM(amount) AS amount FROM budget_transactions WHERE budget_id = ? AND date <= ? GROUP BY category_id, substr(date, 1, 7)', [$budgetId, $monthEnd]) as $row)
		{
			$activity[$row['category_id'] === null ? 0 : (int)$row['category_id']][$row['month']] = (int)$row['amount'];
		}

		$planFor = function (int $categoryId, string $forMonth) use ($plans): int
		{
			$amount = 0;
			foreach ($plans[$categoryId] ?? [] as $plan)
			{
				if ($plan['from'] > $forMonth)
				{
					break;
				}
				$amount = $plan['amount'];
			}
			return $amount;
		};

		$result = [];
		$totals = ['income' => 0, 'expenses' => 0, 'planned' => 0, 'remaining' => 0, 'committed' => 0];

		foreach ($categories as $category)
		{
			$categoryId = (int)$category['id'];
			$thisMonth = $activity[$categoryId][$month] ?? 0;

			if ((int)$category['is_income'] === 1)
			{
				$totals['income'] += $thisMonth;
				$result[] = [
					'id' => $categoryId,
					'name' => $category['name'],
					'is_income' => true,
					'carryover' => false,
					'received' => $thisMonth
				];
				continue;
			}

			$plan = $planFor($categoryId, $month);
			$carriedIn = 0;

			if ((int)$category['carryover'] === 1)
			{
				// Walk from the first month the category was used in
				$months = array_keys($activity[$categoryId] ?? []);
				foreach ($plans[$categoryId] ?? [] as $row)
				{
					$months[] = $row['from'];
				}
				sort($months);

				if (count($months) > 0 && $months[0] < $month)
				{
					$running = 0;
					for ($m = $months[0]; $m < $month; $m = self::ShiftMonth($m, 1))
					{
						$running += $planFor($categoryId, $m) + ($activity[$categoryId][$m] ?? 0);
					}
					$carriedIn = $running;
				}
			}

			$remaining = $carriedIn + $plan + $thisMonth;

			$totals['expenses'] += -$thisMonth;
			$totals['planned'] += $plan;
			$totals['remaining'] += $remaining;
			$totals['committed'] += max(0, $remaining);

			$result[] = [
				'id' => $categoryId,
				'name' => $category['name'],
				'is_income' => false,
				'carryover' => (int)$category['carryover'] === 1,
				'plan' => $plan,
				'spent' => -$thisMonth,
				'carried_in' => $carriedIn,
				'remaining' => $remaining
			];
		}

		// Bookings without a category count, but show up separately
		$uncategorized = $activity[0][$month] ?? 0;
		$uncategorizedIn = (int)$this->QueryValue('SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE budget_id = ? AND category_id IS NULL AND amount > 0 AND date BETWEEN ? AND ?', [$budgetId, $month . '-01', $monthEnd]);
		$uncategorizedOut = $uncategorized - $uncategorizedIn;
		$totals['income'] += $uncategorizedIn;
		$totals['expenses'] += -$uncategorizedOut;

		// The balance today for the running month, at its end for any other
		$balanceDate = $month === self::CurrentMonth() ? date('Y-m-d') : $monthEnd;
		$balance = (int)$this->QueryValue('SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE budget_id = ? AND date <= ?', [$budgetId, $balanceDate]);

		$budget['can_edit'] = $this->CanEdit($budget);

		return [
			'budget' => $budget,
			'month' => $month,
			'categories' => $result,
			'uncategorized' => ['income' => $uncategorizedIn, 'expenses' => -$uncategorizedOut],
			'totals' => $totals + [
				'saldo' => $totals['income'] - $totals['expenses'],
				'balance' => $balance,
				'available' => $balance - $totals['committed']
			]
		];
	}

	// --- reports --------------------------------------------------------

	// Income and expenses per month, by the same rules as the month overview:
	// a refund in an expense category lowers the expenses instead of counting
	// as income. Across all budgets transfers are left out - pocket money is an
	// expense of the household and an income of the person, and counting both
	// would inflate either side.
	public function GetMonthlyReport(?int $budgetId, string $toMonth, int $months): array
	{
		if (!self::IsMonth($toMonth) || $months < 1 || $months > 60)
		{
			throw new \Exception('Invalid period');
		}

		$fromMonth = self::ShiftMonth($toMonth, -($months - 1));
		$params = [$fromMonth . '-01', self::MonthEnd($toMonth)];
		$filter = 'AND t.transfer_id IS NULL';

		if ($budgetId !== null)
		{
			$filter = 'AND t.budget_id = ?';
			$params[] = $budgetId;
		}

		$rows = [];
		foreach ($this->Query('SELECT substr(t.date, 1, 7) AS month,
				SUM(CASE WHEN c.is_income = 1 OR (c.id IS NULL AND t.amount > 0) THEN t.amount ELSE 0 END) AS income,
				SUM(CASE WHEN c.is_income = 0 OR (c.id IS NULL AND t.amount < 0) THEN -t.amount ELSE 0 END) AS expenses
			FROM budget_transactions t
			LEFT JOIN budget_categories c ON c.id = t.category_id
			WHERE t.date BETWEEN ? AND ? ' . $filter . '
			GROUP BY substr(t.date, 1, 7)', $params) as $row)
		{
			$rows[$row['month']] = $row;
		}

		$result = [];
		for ($m = $fromMonth; $m <= $toMonth; $m = self::ShiftMonth($m, 1))
		{
			$income = (int)($rows[$m]['income'] ?? 0);
			$expenses = (int)($rows[$m]['expenses'] ?? 0);
			$result[] = ['month' => $m, 'income' => $income, 'expenses' => $expenses, 'saldo' => $income - $expenses];
		}

		return $result;
	}

	// What the money went on in a period, per expense category
	public function GetCategoryReport(?int $budgetId, string $fromDate, string $toDate): array
	{
		self::AssertDate($fromDate);
		self::AssertDate($toDate);

		$params = [$fromDate, $toDate];
		$filter = '';

		if ($budgetId !== null)
		{
			$filter = 'AND t.budget_id = ?';
			$params[] = $budgetId;
		}
		else
		{
			$filter = 'AND t.transfer_id IS NULL';
		}

		// Across budgets, categories of the same name are the same thing.
		// Refunds lower their category; a category that got more back than it
		// spent is not an expense and drops out.
		$without = $this->T('Without category');
		$rows = $this->Query('
			SELECT COALESCE(c.name, ?) AS name, SUM(-t.amount) AS amount
			FROM budget_transactions t
			LEFT JOIN budget_categories c ON c.id = t.category_id
			WHERE t.date BETWEEN ? AND ? AND (c.is_income = 0 OR (c.id IS NULL AND t.amount < 0)) ' . $filter . '
			GROUP BY COALESCE(c.name, ?)
			HAVING SUM(-t.amount) > 0
			ORDER BY SUM(-t.amount) DESC', array_merge([$without], $params, [$without]));

		foreach ($rows as &$row)
		{
			$row['amount'] = (int)$row['amount'];
		}

		return $rows;
	}
}
