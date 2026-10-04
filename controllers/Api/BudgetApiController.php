<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\BudgetPermissionException;
use Grocy\Services\BudgetService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;

/**
 * Household budget (Grocy Modern).
 *
 * Its own endpoints rather than the generic /objects API: a personal budget
 * may be seen by everyone with the BUDGET permission but only changed by its
 * owner, and that has to be enforced per row - which the generic API cannot.
 *
 * Amounts are decimals here (12.5 = 12,50) - the service works in cents.
 */
class BudgetApiController extends BaseApiController
{
	// Every key holding an amount, so they can be converted on the way out
	const AMOUNT_KEYS = ['amount', 'balance', 'plan', 'spent', 'received', 'carried_in', 'remaining', 'income', 'expenses', 'saldo', 'planned', 'committed', 'available'];

	private function Service(): BudgetService
	{
		return BudgetService::GetInstance();
	}

	// Gatekeeping for every endpoint: the feature has to be on and the user
	// needs the permission. Due recurring transactions are booked before
	// anything is read, so every answer is up to date.
	private function Guard(Request $request): void
	{
		if (!GROCY_FEATURE_FLAG_BUDGET)
		{
			throw new HttpNotFoundException($request);
		}

		User::CheckPermission($request, User::PERMISSION_BUDGET);
		$this->Service()->ProcessDueRecurring();
	}

	public static function ToDecimals($data)
	{
		if (!is_array($data))
		{
			return $data;
		}

		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				$data[$key] = self::ToDecimals($value);
			}
			elseif (is_string($key) && in_array($key, self::AMOUNT_KEYS, true) && $value !== null && is_numeric($value))
			{
				$data[$key] = BudgetService::FromCents((int)$value);
			}
		}

		return $data;
	}

	private function Run(Request $request, Response $response, callable $work)
	{
		try
		{
			$this->Guard($request);
			$result = $work();

			if ($result === null)
			{
				return $this->EmptyApiResponse($response);
			}

			return $this->ApiResponse($response, self::ToDecimals($result));
		}
		catch (BudgetPermissionException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 403);
		}
		catch (HttpException $ex)
		{
			// A missing permission or a disabled feature is answered by Slim
			throw $ex;
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	private function Body(Request $request): array
	{
		$body = $this->GetParsedAndFilteredRequestBody($request);
		return is_array($body) ? $body : [];
	}

	private static function NullableInt($value): ?int
	{
		return $value === null || $value === '' ? null : (int)$value;
	}

	private static function Flag($value): bool
	{
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	// --- budgets --------------------------------------------------------

	public function GetBudgets(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => $this->Service()->GetBudgets());
	}

	public function CreateBudget(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request)
		{
			$body = $this->Body($request);
			return ['created_object_id' => $this->Service()->CreateBudget((string)($body['name'] ?? ''), self::NullableInt($body['owner_user_id'] ?? null))];
		});
	}

	public function UpdateBudget(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$body = $this->Body($request);
			$this->Service()->UpdateBudget((int)$args['budgetId'], (string)($body['name'] ?? ''), self::NullableInt($body['owner_user_id'] ?? null));
			return null;
		});
	}

	public function DeleteBudget(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($args)
		{
			$this->Service()->DeleteBudget((int)$args['budgetId']);
			return null;
		});
	}

	public function GetOverview(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$month = $request->getQueryParams()['month'] ?? BudgetService::CurrentMonth();
			return $this->Service()->GetMonthOverview((int)$args['budgetId'], $month);
		});
	}

	// --- categories -----------------------------------------------------

	public function GetCategories(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => $this->Service()->GetCategories((int)$args['budgetId']));
	}

	public function CreateCategory(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$body = $this->Body($request);
			return ['created_object_id' => $this->Service()->CreateCategory((int)$args['budgetId'], (string)($body['name'] ?? ''), self::Flag($body['is_income'] ?? false), self::Flag($body['carryover'] ?? false))];
		});
	}

	public function UpdateCategory(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$body = $this->Body($request);
			$this->Service()->UpdateCategory((int)$args['categoryId'], (string)($body['name'] ?? ''), self::Flag($body['is_income'] ?? false), self::Flag($body['carryover'] ?? false), self::NullableInt($body['sort_order'] ?? null));
			return null;
		});
	}

	public function DeleteCategory(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($args)
		{
			$this->Service()->DeleteCategory((int)$args['categoryId']);
			return null;
		});
	}

	public function SetPlan(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$body = $this->Body($request);
			$this->Service()->SetPlan((int)$args['categoryId'], (string)($body['month'] ?? ''), BudgetService::ToCents($body['amount'] ?? '0'));
			return null;
		});
	}

	// --- transactions ---------------------------------------------------

	public function GetTransactions(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request)
		{
			$query = $request->getQueryParams();
			return $this->Service()->GetTransactions(self::NullableInt($query['budget_id'] ?? null), $query['month'] ?? null, self::NullableInt($query['category_id'] ?? null));
		});
	}

	public function CreateTransaction(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => ['created_object_id' => $this->Service()->CreateTransaction($this->Body($request))]);
	}

	public function UpdateTransaction(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$this->Service()->UpdateTransaction((int)$args['transactionId'], $this->Body($request));
			return null;
		});
	}

	public function DeleteTransaction(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($args)
		{
			$this->Service()->DeleteTransaction((int)$args['transactionId']);
			return null;
		});
	}

	public function CreateTransfer(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => $this->Service()->CreateTransfer($this->Body($request)));
	}

	// --- recurring transactions -----------------------------------------

	public function GetRecurring(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => $this->Service()->GetRecurring(self::NullableInt($request->getQueryParams()['budget_id'] ?? null)));
	}

	public function CreateRecurring(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, fn () => ['created_object_id' => $this->Service()->CreateRecurring($this->Body($request))]);
	}

	public function UpdateRecurring(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request, $args)
		{
			$this->Service()->UpdateRecurring((int)$args['recurringId'], $this->Body($request));
			return null;
		});
	}

	public function DeleteRecurring(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($args)
		{
			$this->Service()->DeleteRecurring((int)$args['recurringId']);
			return null;
		});
	}

	// --- reports --------------------------------------------------------

	public function MonthlyReport(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request)
		{
			$query = $request->getQueryParams();
			return $this->Service()->GetMonthlyReport(self::NullableInt($query['budget_id'] ?? null), $query['to'] ?? BudgetService::CurrentMonth(), (int)($query['months'] ?? 12));
		});
	}

	public function CategoryReport(Request $request, Response $response, array $args)
	{
		return $this->Run($request, $response, function () use ($request)
		{
			$query = $request->getQueryParams();
			$month = BudgetService::CurrentMonth();
			return $this->Service()->GetCategoryReport(self::NullableInt($query['budget_id'] ?? null), $query['from'] ?? $month . '-01', $query['to'] ?? BudgetService::MonthEnd($month));
		});
	}
}
