@php require_frontend_packages(['chartjs']); @endphp

@extends('layout.default')

@section('title', $__t('Budget reports'))

@section('content')
@include('components.budgetassets')
@include('components.budgetheader', ['active' => 'reports', 'budgetId' => $selectedBudgetId === 'all' ? null : $selectedBudgetId])

<script>
	var BudgetPage = {
		BudgetId: @json($selectedBudgetId),
		CurrentMonth: '{{ date('Y-m') }}'
	};
</script>

<div class="budget-switcher"
	role="tablist"
	aria-label="{{ $__t('Budgets') }}">
	@if(count($budgets) > 1)
	<a class="btn btn-sm @if($selectedBudgetId === 'all') btn-primary @else btn-outline-dark @endif"
		role="tab"
		href="{{ $U('/budget/reports?budget=all') }}">
		<i class="fa-solid fa-layer-group"></i>
		{{ $__t('All budgets') }}
	</a>
	@endif
	@foreach($budgets as $budget)
	<a class="btn btn-sm @if($selectedBudgetId === (int)$budget['id']) btn-primary @else btn-outline-dark @endif"
		role="tab"
		href="{{ $U('/budget/reports?budget=' . $budget['id']) }}">
		@if(empty($budget['owner_user_id']))
		<i class="fa-solid fa-house"></i>
		@else
		<i class="fa-solid fa-user"></i>
		@endif
		{{ $budget['name'] }}
	</a>
	@endforeach
</div>

@if($selectedBudgetId === 'all')
<p class="text-muted small">{{ $__t('All budgets together - transfers between them are left out, they only move money around.') }}</p>
@endif

<div class="budget-chart-card">
	<div class="budget-chart-head">
		<h3>{{ $__t('Income and expenses') }}</h3>
		<select class="custom-control custom-select custom-select-sm"
			id="monthly-range"
			aria-label="{{ $__t('Period') }}">
			<option value="6">{{ $__n(6, 'Last %s month', 'Last %s months') }}</option>
			<option value="12"
				selected>{{ $__n(12, 'Last %s month', 'Last %s months') }}</option>
			<option value="24">{{ $__n(24, 'Last %s month', 'Last %s months') }}</option>
		</select>
	</div>
	<div id="monthly-report">
		<div class="budget-report-stats"
			id="monthly-stats"></div>
		<div class="budget-chart-wrap">
			<canvas id="monthly-chart"></canvas>
		</div>
		<div class="table-responsive mt-3">
			<table class="table table-sm budget-report-table mb-0"
				id="monthly-table">
				<thead>
					<tr>
						<th>{{ $__t('Month') }}</th>
						<th class="text-right">{{ $__t('Income') }}</th>
						<th class="text-right">{{ $__t('Expenses') }}</th>
						<th class="text-right">{{ $__t('Net') }}</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</div>
	<div class="budget-empty d-none"
		id="monthly-empty">{{ $__t('Nothing booked in this period') }}</div>
</div>

<div class="budget-chart-card">
	<div class="budget-chart-head">
		<h3>{{ $__t('Expenses by category') }}</h3>
		<select class="custom-control custom-select custom-select-sm"
			id="category-range"
			aria-label="{{ $__t('Period') }}">
			<option value="this-month">{{ $__t('This month') }}</option>
			<option value="last-month">{{ $__t('Last month') }}</option>
			<option value="last-3-months">{{ $__n(3, 'Last %s month', 'Last %s months') }}</option>
			<option value="this-year">{{ $__t('This year') }}</option>
			<option value="last-12-months">{{ $__n(12, 'Last %s month', 'Last %s months') }}</option>
		</select>
	</div>
	<div class="budget-category-report">
		<div class="budget-chart-wrap budget-chart-wrap-doughnut">
			<canvas id="category-chart"></canvas>
		</div>
		<div class="budget-rows"
			id="category-list"></div>
	</div>
	<div class="budget-empty d-none"
		id="category-empty">{{ $__t('No expenses in this period') }}</div>
</div>
@stop
