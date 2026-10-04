<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Grocy\Services\BudgetService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Household budget pages (Grocy Modern). The data comes from BudgetService;
 * everything that changes something goes through the budget API.
 */
class BudgetController extends BaseController
{
	const LAST_BUDGET_SETTING = 'budget_last_budget_id';

	private function Service(): BudgetService
	{
		return BudgetService::GetInstance();
	}

	private function Guard(Request $request): void
	{
		if (!GROCY_FEATURE_FLAG_BUDGET)
		{
			throw new HttpNotFoundException($request);
		}

		User::CheckPermission($request, User::PERMISSION_BUDGET);
		$this->Service()->ProcessDueRecurring();
	}

	// The budget asked for, otherwise the one last looked at (remembered per
	// user, so the phone and the desktop agree), otherwise the household
	private function SelectedBudget(Request $request, array $budgets): array
	{
		$ids = array_map(fn ($budget) => (int)$budget['id'], $budgets);
		$requested = $request->getQueryParams()['budget'] ?? null;
		$usersService = UsersService::GetInstance();

		if ($requested !== null && in_array((int)$requested, $ids, true))
		{
			$selectedId = (int)$requested;
			$usersService->SetUserSetting(GROCY_USER_ID, self::LAST_BUDGET_SETTING, $selectedId);
		}
		else
		{
			$remembered = (int)($usersService->GetUserSettings(GROCY_USER_ID)[self::LAST_BUDGET_SETTING] ?? 0);
			$selectedId = in_array($remembered, $ids, true) ? $remembered : $ids[0];
		}

		foreach ($budgets as $budget)
		{
			if ((int)$budget['id'] === $selectedId)
			{
				return $budget;
			}
		}

		return $budgets[0];
	}

	private function SelectedMonth(Request $request): string
	{
		$month = $request->getQueryParams()['month'] ?? null;
		return BudgetService::IsMonth($month) ? $month : BudgetService::CurrentMonth();
	}

	// Money and month names the way the user's language writes them
	private function Formatters(): array
	{
		$money = new \NumberFormatter(GROCY_LOCALE, \NumberFormatter::CURRENCY);
		$monthName = new \IntlDateFormatter(GROCY_LOCALE, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL yyyy');

		return [
			'money' => fn ($cents) => $money->formatCurrency(BudgetService::FromCents((int)$cents), GROCY_CURRENCY),
			'monthLabel' => fn (string $month) => $monthName->format(new \DateTime($month . '-01'))
		];
	}

	private function RenderBudget(Response $response, string $view, array $data)
	{
		return $this->RenderPage($response, $view, array_merge($this->Formatters(), [
			'currency' => GROCY_CURRENCY,
			'isAdmin' => $this->Service()->IsAdmin(),
			'currentUserId' => (int)GROCY_USER_ID
		], $data));
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$budget = $this->SelectedBudget($request, $budgets);
		$month = $this->SelectedMonth($request);

		return $this->RenderBudget($response, 'budget', [
			'budgets' => $budgets,
			'selectedBudget' => $budget,
			'month' => $month,
			'previousMonth' => BudgetService::ShiftMonth($month, -1),
			'nextMonth' => BudgetService::ShiftMonth($month, 1),
			'isCurrentMonth' => $month === BudgetService::CurrentMonth(),
			'isPastMonth' => $month < BudgetService::CurrentMonth(),
			'overview' => $this->Service()->GetMonthOverview((int)$budget['id'], $month)
		]);
	}

	public function Transactions(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$budget = $this->SelectedBudget($request, $budgets);
		$month = $this->SelectedMonth($request);
		$categoryId = $request->getQueryParams()['category'] ?? null;
		$categoryId = $categoryId === null || $categoryId === '' ? null : (int)$categoryId;

		return $this->RenderBudget($response, 'budgettransactions', [
			'budgets' => $budgets,
			'selectedBudget' => $budget,
			'month' => $month,
			'previousMonth' => BudgetService::ShiftMonth($month, -1),
			'nextMonth' => BudgetService::ShiftMonth($month, 1),
			'categories' => $this->Service()->GetCategories((int)$budget['id']),
			'selectedCategoryId' => $categoryId,
			'transactions' => $this->Service()->GetTransactions((int)$budget['id'], $month, $categoryId)
		]);
	}

	private function EditableBudgets(array $budgets): array
	{
		return array_values(array_filter($budgets, fn ($budget) => $budget['can_edit']));
	}

	private function CategoriesByBudget(array $budgets): array
	{
		$categories = [];
		foreach ($budgets as $budget)
		{
			$categories[(int)$budget['id']] = $this->Service()->GetCategories((int)$budget['id']);
		}
		return $categories;
	}

	public function TransactionForm(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$transaction = null;
		$counterpart = null;

		if ($args['transactionId'] !== 'new')
		{
			$transaction = $this->Service()->GetTransaction((int)$args['transactionId']);

			if (!empty($transaction['transfer_id']))
			{
				$counterpart = $this->Service()->GetTransaction((int)$transaction['transfer_id']);
			}
		}

		$selected = $transaction !== null ? $this->Service()->GetBudget((int)$transaction['budget_id']) : $this->SelectedBudget($request, $budgets);

		// Payees used before in this budget, offered while typing
		$payees = [];
		foreach ($this->Service()->GetTransactions((int)$selected['id']) as $row)
		{
			if (!empty($row['payee']) && !in_array($row['payee'], $payees, true))
			{
				$payees[] = $row['payee'];
			}
		}
		sort($payees, SORT_NATURAL | SORT_FLAG_CASE);

		return $this->RenderBudget($response, 'budgettransactionform', [
			'mode' => $transaction === null ? 'create' : 'edit',
			'transaction' => $transaction,
			'counterpart' => $counterpart,
			'counterpartBudget' => $counterpart !== null ? $this->Service()->GetBudget((int)$counterpart['budget_id']) : null,
			'budgets' => $transaction === null ? $this->EditableBudgets($budgets) : $budgets,
			'selectedBudget' => $selected,
			'canEdit' => $transaction === null || $this->Service()->CanEditTransaction($transaction),
			'categoriesByBudget' => $this->CategoriesByBudget($budgets),
			'payees' => $payees,
			'initialType' => ($request->getQueryParams()['type'] ?? '') === 'income' ? 'income' : 'expense'
		]);
	}

	public function TransferForm(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$editable = $this->EditableBudgets($budgets);
		$from = $this->SelectedBudget($request, $budgets);
		$to = null;

		// Opened from a budget that cannot be changed (someone else's): the
		// money then most likely goes there - pocket money for them
		if (!$from['can_edit'] && count($editable) > 0)
		{
			$to = $from;
			$from = $editable[0];
		}

		return $this->RenderBudget($response, 'budgettransferform', [
			'budgets' => $budgets,
			'editableBudgets' => $editable,
			'fromBudget' => $from,
			'toBudgetId' => $to !== null ? (int)$to['id'] : null,
			'categoriesByBudget' => $this->CategoriesByBudget($budgets),
			'pocketMoneyName' => $this->LocalizationService()->__t('Pocket money')
		]);
	}

	public function Recurring(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$budget = $this->SelectedBudget($request, $budgets);

		return $this->RenderBudget($response, 'budgetrecurring', [
			'budgets' => $budgets,
			'selectedBudget' => $budget,
			'rules' => $this->Service()->GetRecurring((int)$budget['id'])
		]);
	}

	public function RecurringForm(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$rule = $args['recurringId'] === 'new' ? null : $this->Service()->GetRecurringRule((int)$args['recurringId']);
		$selected = $rule !== null ? $this->Service()->GetBudget((int)$rule['budget_id']) : $this->SelectedBudget($request, $budgets);

		return $this->RenderBudget($response, 'budgetrecurringform', [
			'mode' => $rule === null ? 'create' : 'edit',
			'rule' => $rule,
			'budgets' => $budgets,
			'editableBudgets' => $this->EditableBudgets($budgets),
			'selectedBudget' => $selected,
			'canEdit' => $rule === null || $this->Service()->CanEdit($this->Service()->GetBudget((int)$rule['budget_id'])),
			'categoriesByBudget' => $this->CategoriesByBudget($budgets)
		]);
	}

	public function Settings(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$budget = $this->SelectedBudget($request, $budgets);

		return $this->RenderBudget($response, 'budgetsettings', [
			'budgets' => $budgets,
			'selectedBudget' => $budget,
			'categories' => $this->Service()->GetCategories((int)$budget['id'])
		]);
	}

	public function CategoryForm(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$category = $args['categoryId'] === 'new' ? null : $this->Service()->GetCategory((int)$args['categoryId']);
		$budgetId = $category !== null ? (int)$category['budget_id'] : (int)($request->getQueryParams()['budget'] ?? 0);
		$budget = $this->Service()->GetBudget($budgetId);

		return $this->RenderBudget($response, 'budgetcategoryform', [
			'mode' => $category === null ? 'create' : 'edit',
			'category' => $category,
			'budget' => $budget,
			'canEdit' => $this->Service()->CanEdit($budget),
			'initialType' => ($request->getQueryParams()['type'] ?? '') === 'income' ? 'income' : 'expense'
		]);
	}

	public function BudgetForm(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budget = $args['budgetId'] === 'new' ? null : $this->Service()->GetBudget((int)$args['budgetId']);

		return $this->RenderBudget($response, 'budgetbudgetform', [
			'mode' => $budget === null ? 'create' : 'edit',
			'budget' => $budget,
			'canEdit' => $budget === null || $this->Service()->CanEdit($budget),
			'users' => UsersService::GetInstance()->GetUsersAsDto()
		]);
	}

	public function Reports(Request $request, Response $response, array $args)
	{
		$this->Guard($request);

		$budgets = $this->Service()->GetBudgets();
		$requested = $request->getQueryParams()['budget'] ?? null;

		return $this->RenderBudget($response, 'budgetreports', [
			'budgets' => $budgets,
			// "all" is a real choice here - every budget together
			'selectedBudgetId' => $requested === 'all' ? 'all' : (int)$this->SelectedBudget($request, $budgets)['id']
		]);
	}

	private function LocalizationService()
	{
		return \Grocy\Services\LocalizationService::GetInstance();
	}
}
