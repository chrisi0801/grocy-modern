@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit recurring transaction'))
@else
@section('title', $__t('New recurring transaction'))
@endif

@section('content')
@include('components.budgetassets')

@php
if ($rule === null)
{
	$type = 'expense';
}
elseif (!empty($rule['target_budget_id']))
{
	$type = 'transfer';
}
else
{
	$type = $rule['amount'] > 0 ? 'income' : 'expense';
}
$selectedBudgetId = (int)$selectedBudget['id'];
$formBudgets = $canEdit ? $editableBudgets : $budgets;
@endphp

<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">

		<script>
			var BudgetForm = {
				Mode: '{{ $mode }}',
				RecurringId: {{ $rule !== null ? $rule['id'] : 'null' }},
				CategoriesByBudget: @json($categoriesByBudget),
				SelectedCategoryId: {{ $rule !== null && $rule['category_id'] !== null ? $rule['category_id'] : 'null' }},
				SelectedTargetCategoryId: {{ $rule !== null && $rule['target_category_id'] !== null ? $rule['target_category_id'] : 'null' }},
				ReturnUrl: @json($U('/budget/recurring?budget=' . $selectedBudgetId))
			};
		</script>

		@if(!$canEdit)
		<div class="budget-readonly-note">
			<i class="fa-solid fa-eye mt-1"></i>
			<div>{{ $__t('This is the personal budget of %s - you can look at it, but only they can change it.', $selectedBudget['owner_name'] ?? '?') }}</div>
		</div>
		@endif

		<form id="budget-recurring-form"
			novalidate>
			<fieldset @if(!$canEdit) disabled @endif>

				<div class="budget-type-switch"
					role="radiogroup"
					aria-label="{{ $__t('Type') }}">
					<input type="radio"
						name="type"
						id="type-expense"
						value="expense"
						@if($type === 'expense') checked @endif>
					<label for="type-expense">{{ $__t('Expense') }}</label>
					<input type="radio"
						name="type"
						id="type-income"
						value="income"
						@if($type === 'income') checked @endif>
					<label for="type-income">{{ $__t('Deposit') }}</label>
					@if(count($budgets) > 1)
					<input type="radio"
						name="type"
						id="type-transfer"
						value="transfer"
						@if($type === 'transfer') checked @endif>
					<label for="type-transfer">{{ $__t('Budget transfer') }}</label>
					@endif
				</div>

				<div class="form-group">
					<label for="name">{{ $__t('Name') }}</label>
					<input type="text"
						class="form-control"
						required
						id="name"
						value="{{ $rule['name'] ?? '' }}"
						placeholder="{{ $__t('e.g. Rent') }}">
					<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
				</div>

				<div class="form-group">
					<label for="amount">{{ $__t('Sum') }}</label>
					<div class="input-group">
						<input type="text"
							inputmode="decimal"
							autocomplete="off"
							class="form-control budget-amount-input"
							required
							id="amount"
							value="@if($rule !== null){{ number_format(abs($rule['amount']) / 100, 2, ',', '') }}@endif"
							placeholder="0,00">
						<div class="input-group-append">
							<span class="input-group-text">{{ $currency }}</span>
						</div>
						<div class="invalid-feedback">{{ $__t('Please enter an amount') }}</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group col-md-6 @if(count($formBudgets) < 2) d-none @endif">
						<label for="budget_id"
							class="budget-label-plain">{{ $__t('Budget') }}</label>
						<label for="budget_id"
							class="budget-label-transfer d-none">{{ $__t('From budget') }}</label>
						<select class="custom-control custom-select"
							id="budget_id">
							@foreach($formBudgets as $budget)
							<option value="{{ $budget['id'] }}"
								@if((int)$budget['id'] === $selectedBudgetId) selected @endif>{{ $budget['name'] }}</option>
							@endforeach
						</select>
					</div>
					<div class="form-group col-md-6">
						<label for="category_id">{{ $__t('Category') }}</label>
						<select class="custom-control custom-select"
							id="category_id"></select>
					</div>
				</div>

				<div class="form-row budget-transfer-only d-none">
					<div class="form-group col-md-6">
						<label for="target_budget_id">{{ $__t('To budget') }}</label>
						<select class="custom-control custom-select"
							id="target_budget_id">
							@foreach($budgets as $budget)
							<option value="{{ $budget['id'] }}"
								@if($rule !== null && (int)$rule['target_budget_id'] === (int)$budget['id']) selected @endif>{{ $budget['name'] }}</option>
							@endforeach
						</select>
					</div>
					<div class="form-group col-md-6">
						<label for="target_category_id">{{ $__t('Booked as') }}</label>
						<select class="custom-control custom-select"
							id="target_category_id"></select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group col-6">
						<label for="interval_months">{{ $__t('Interval') }}</label>
						<select class="custom-control custom-select"
							id="interval_months">
							@foreach([1 => 'Monthly', 3 => 'Quarterly', 6 => 'Half-yearly', 12 => 'Yearly'] as $months => $label)
							<option value="{{ $months }}"
								@if((int)($rule['interval_months'] ?? 1) === $months) selected @endif>{{ $__t($label) }}</option>
							@endforeach
						</select>
					</div>
					<div class="form-group col-6">
						<label for="day_of_month">{{ $__t('On day') }}</label>
						<input type="number"
							inputmode="numeric"
							class="form-control"
							required
							min="1"
							max="31"
							id="day_of_month"
							value="{{ $rule['day_of_month'] ?? (int)date('j') }}">
						<div class="invalid-feedback">{{ $__t('A day between 1 and 31') }}</div>
					</div>
				</div>
				<small class="form-text text-muted mt-n2 mb-3">{{ $__t('Months without that day use their last day - the 31st means the end of every month.') }}</small>

				<div class="form-row">
					<div class="form-group col-6">
						<label for="start_date">{{ $__t('Starts') }}</label>
						<input type="date"
							class="form-control"
							required
							id="start_date"
							value="{{ $rule['start_date'] ?? date('Y-m-d') }}">
						<div class="invalid-feedback">{{ $__t('A date is required') }}</div>
					</div>
					<div class="form-group col-6">
						<label for="end_date">{{ $__t('Ends') }} <span class="text-muted small">({{ $__t('optional') }})</span></label>
						<input type="date"
							class="form-control"
							id="end_date"
							value="{{ $rule['end_date'] ?? '' }}">
					</div>
				</div>
				<small id="catch-up-hint"
					class="form-text text-info mt-n2 mb-3 d-none">{{ $__t('The start lies in the past - everything due since then is booked right away when saving.') }}</small>

				<div class="form-group budget-plain-only">
					<label for="payee">{{ $__t('Payee') }}</label>
					<input type="text"
						class="form-control"
						id="payee"
						value="{{ $rule['payee'] ?? '' }}">
				</div>

				<div class="form-group">
					<label for="note">{{ $__t('Note') }}</label>
					<input type="text"
						class="form-control"
						id="note"
						value="{{ $rule['note'] ?? '' }}">
				</div>

				<div class="form-group">
					<div class="custom-control custom-checkbox">
						<input class="form-check-input custom-control-input"
							type="checkbox"
							id="active"
							@if($rule === null || $rule['active']) checked @endif>
						<label class="form-check-label custom-control-label"
							for="active">{{ $__t('Active') }}</label>
					</div>
				</div>

				@if($rule !== null && $rule['active'])
				<p class="small text-muted">
					{{ $__t('Next booking') }}: {{ $rule['next_due_date'] }}
				</p>
				@endif

				<div class="d-flex flex-wrap"
					style="gap: 0.5rem;">
					<button id="save-budget-recurring-button"
						class="btn btn-success">{{ $__t('Save') }}</button>
					@if($mode == 'edit')
					<button id="delete-budget-recurring-button"
						type="button"
						class="btn btn-outline-danger ml-auto"
						data-name="{{ $rule['name'] }}">
						<i class="fa-solid fa-trash"></i>&nbsp;{{ $__t('Delete') }}
					</button>
					@endif
				</div>

			</fieldset>
		</form>
	</div>
</div>
@stop
